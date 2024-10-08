<?php

namespace Fleetbase\FleetOps\Http\Controllers\Api\v1;

use Fleetbase\FleetOps\Events\OrderDispatchFailed;
use Fleetbase\FleetOps\Events\OrderReady;
use Fleetbase\FleetOps\Events\OrderStarted;
use Fleetbase\FleetOps\Http\Requests\CreateOrderRequest;
use Fleetbase\FleetOps\Http\Requests\ScheduleOrderRequest;
use Fleetbase\FleetOps\Http\Requests\UpdateOrderRequest;
use Fleetbase\FleetOps\Http\Resources\v1\DeletedResource;
use Fleetbase\FleetOps\Http\Resources\v1\Order as OrderResource;
use Fleetbase\FleetOps\Http\Resources\v1\Proof as ProofResource;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Entity;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Payload;
use Fleetbase\FleetOps\Models\Place;
use Fleetbase\FleetOps\Models\Proof;
use Fleetbase\FleetOps\Models\ServiceQuote;
use Fleetbase\FleetOps\Models\Waypoint;
use Fleetbase\FleetOps\Support\Flow;
use Fleetbase\FleetOps\Support\Utils;
use Fleetbase\Http\Controllers\Controller;
use Fleetbase\Models\Company;
use Fleetbase\Models\File;
use Fleetbase\Support\Auth;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class OrderController extends Controller
{
    /**
     * Creates a new Fleetbase Order resource.
     *
     * @param \Illuminate\Http\Request|\Fleetbase\Http\Requests\CreateOrderRequest $request
     *
     * @return \Fleetbase\Http\Resources\Order
     */
    public function create(CreateOrderRequest $request)
    {
        // get request input
        $input = $request->only(['internal_id','quantity', 'payload', 'service_quote', 'purchase_rate', 'adhoc', 'adhoc_distance', 'pod_method', 'pod_required', 'scheduled_at', 'type', 'status', 'meta', 'notes']);

        // make sure company is set
        $input['company_uuid'] = session('company');

        // resolve service quote if applicable
        $serviceQuote          = ServiceQuote::resolveFromRequest($request);
        $integratedVendorOrder = null;

        // if service quote is applied, resolve it
        if ($serviceQuote instanceof ServiceQuote && $serviceQuote->fromIntegratedVendor()) {
            // create order with integrated vendor, then resume fleetbase order creation
            try {
                $integratedVendorOrder = $serviceQuote->integratedVendor->api()->createOrderFromServiceQuote($serviceQuote, $request);
            } catch (\Exception $e) {
                return response()->apiError($e->getMessage());
            }
        }

        // create payload
        if ($request->has('payload') && $request->isArray('payload')) {
            $payload      = new Payload();
            $payloadInput = $request->input('payload');
            $entities     = data_get($payloadInput, 'entities', []);
            $waypoints    = data_get($payloadInput, 'waypoints', []);
            $pickup       = data_get($payloadInput, 'pickup');
            $dropoff      = data_get($payloadInput, 'dropoff');
            $return       = data_get($payloadInput, 'return');

            if ($pickup) {
                $payload->setPickup($pickup, [
                    'callback' => function ($pickup, $payload) {
                        $payload->setCurrentWaypoint($pickup);
                    },
                ]);
            }

            if ($dropoff) {
                $payload->setDropoff($dropoff);
            }

            if ($return) {
                $payload->setReturn($return);
            }

            $payload->save();

            // set waypoints and entities after payload is saved
            $payload->setWaypoints($waypoints);
            $payload->setEntities($entities);

            // set the first / current waypoint
            $firstWaypoint = $payload->getPickupOrFirstWaypoint();
            if ($firstWaypoint instanceof Place) {
                $payload->setCurrentWaypoint($firstWaypoint);
            }

            $input['payload_uuid'] = $payload->uuid;
        } elseif ($request->isString('payload')) {
            $input['payload_uuid'] = Utils::getUuid('payloads', [
                'public_id'    => $request->input('payload'),
                'company_uuid' => session('company'),
            ]);
            unset($input['payload']);
        }

        // create a payload if missing payload[] but has pickup/dropoff/etc
        if ($request->missing('payload')) {
            $payload      = new Payload();
            $payloadInput = $request->only(['pickup', 'dropoff', 'return', 'waypoints', 'entities']);
            $entities     = data_get($payloadInput, 'entities', []);
            $waypoints    = data_get($payloadInput, 'waypoints', []);
            $pickup       = data_get($payloadInput, 'pickup');
            $dropoff      = data_get($payloadInput, 'dropoff');
            $return       = data_get($payloadInput, 'return');

            if ($pickup) {
                $payload->setPickup($pickup, [
                    'callback' => function ($pickup, $payload) {
                        $payload->setCurrentWaypoint($pickup);
                    },
                ]);
            }

            if ($dropoff) {
                $payload->setDropoff($dropoff);
            }

            if ($return) {
                $payload->setReturn($return);
            }

            $payload->save();

            // set waypoints and entities after payload is saved
            $payload->setWaypoints($waypoints);
            $payload->setEntities($entities);

            $input['payload_uuid'] = $payload->uuid;

            // set the first / current waypoint
            $firstWaypoint = $payload->getPickupOrFirstWaypoint();
            if ($firstWaypoint instanceof Place) {
                $payload->setCurrentWaypoint($firstWaypoint);
            }
        }

        // driver assignment
        if ($request->has('driver') && $integratedVendorOrder === null) {
            $input['driver_assigned_uuid'] = Utils::getUuid('drivers', [
                'public_id'    => $request->input('driver'),
                'company_uuid' => session('company'),
            ]);
        }

        // facilitator assignment
        if ($request->has('facilitator') && $integratedVendorOrder === null) {
            $facilitator = Utils::getUuid(
                ['contacts', 'vendors', 'integrated_vendors'],
                [
                    'public_id'    => $request->input('facilitator'),
                    'company_uuid' => session('company'),
                ]
            );

            if (is_array($facilitator)) {
                $input['facilitator_uuid'] = Utils::get($facilitator, 'uuid');
                $input['facilitator_type'] = Utils::getModelClassName(Utils::get($facilitator, 'table'));
            }
        } elseif ($integratedVendorOrder) {
            $input['facilitator_uuid'] = $serviceQuote->integratedVendor->uuid;
            $input['facilitator_type'] = Utils::getModelClassName('integrated_vendors');
        }

        // customer assignment
        if ($request->has('customer')) {
            $customer = Utils::getUuid(
                ['contacts', 'vendors'],
                [
                    'public_id'    => $request->input('customer'),
                    'company_uuid' => session('company'),
                ]
            );

            if (is_array($customer)) {
                $input['customer_uuid'] = Utils::get($customer, 'uuid');
                $input['customer_type'] = Utils::getModelClassName(Utils::get($customer, 'table'));
            }
        }

        // if no type is set its default to default
        if (!isset($input['type'])) {
            $input['type'] = 'default';
        }

        // if no status is set its default to `created`
        if (!isset($input['status'])) {
            $input['status'] = 'created';
        }

        // if adhoc set convert to sql ready boolean value 1 or 0
        if (isset($input['adhoc']) && $integratedVendorOrder === null) {
            $input['adhoc'] = Utils::isTrue($input['adhoc']) ? 1 : 0;
        }

        if (!isset($input['payload_uuid'])) {
            return response()->apiError('Attempted to attach invalid payload to order.');
        }

        // create the order
        $order = Order::create($input);

        // notify driver if assigned
        $order->notifyDriverAssigned();

        // set driving distance and time
        $order->setPreliminaryDistanceAndTime();

        // if service quote attached purchase
        $order->purchaseServiceQuote($serviceQuote);

        // if it's integrated vendor order apply to meta
        if ($integratedVendorOrder) {
            $order->updateMeta([
                'integrated_vendor'       => $serviceQuote->integratedVendor->public_id,
                'integrated_vendor_order' => $integratedVendorOrder,
            ]);
        }

        // dispatch if flagged true
        if ($request->boolean('dispatch') && $integratedVendorOrder === null) {
            $order->dispatchWithActivity();
        }

        // load required relations
        $order->load(['trackingNumber', 'driverAssigned', 'purchaseRate', 'customer', 'facilitator']);

        // Trigger order created event
        event(new OrderReady($order));

        // response the driver resource
        return new OrderResource($order);
    }

    /**
     * Updates a Fleetbase Order resource.
     *
     * @param string                                      $id
     * @param \Fleetbase\Http\Requests\UpdateOrderRequest $request
     *
     * @return \Fleetbase\Http\Resources\Order
     */
    public function update($id, UpdateOrderRequest $request)
    {
        // find for the order
        try {
            $order = Order::findRecordOrFail($id);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $exception) {
            return response()->json(
                [
                    'error' => 'Order resource not found.',
                ],
                404
            );
        }

        // get request input
        $input = $request->only(['internal_id', 'payload', 'adhoc', 'adhoc_distance', 'pod_method', 'pod_required', 'scheduled_at', 'meta', 'type', 'status', 'notes']);

        // update payload if new input or change payload by id
        if ($request->isArray('payload')) {
            $payload      = data_get($order, 'payload', new Payload());
            $payloadInput = $request->input('payload');
            $entities     = data_get($payloadInput, 'entities', []);
            $waypoints    = data_get($payloadInput, 'waypoints', []);
            $pickup       = data_get($payloadInput, 'pickup');
            $dropoff      = data_get($payloadInput, 'dropoff');
            $return       = data_get($payloadInput, 'return');

            // if no pickup and dropoff extract from waypoints
            if (empty($pickup) && empty($dropoff) && count($waypoints)) {
                $pickup  = array_shift($waypoints);
                $dropoff = array_pop($waypoints);
            }

            if ($pickup) {
                $payload->setPickup($pickup);
            }

            if ($dropoff) {
                $payload->setDropoff($dropoff);
            }

            if ($return) {
                $payload->setReturn($return);
            }

            $payload->save();

            // set waypoints and entities after payload is saved
            if ($waypoints) {
                $payload->setWaypoints($waypoints);
            }

            if ($entities) {
                $payload->setEntities($entities);
            }

            $input['payload_uuid'] = $payload->uuid;
        } elseif ($request->has('payload')) {
            $input['payload_uuid'] = Utils::getUuid('payloads', [
                'public_id'    => $request->input('payload'),
                'company_uuid' => session('company'),
            ]);
            unset($input['payload']);
        }

        // create a payload if missing payload[] but has pickup/dropoff/etc
        if ($request->missing('payload')) {
            $payload      = data_get($order, 'payload', new Payload());
            $payloadInput = $request->only(['pickup', 'dropoff', 'return', 'waypoints', 'entities']);
            $entities     = data_get($payloadInput, 'entities', []);
            $waypoints    = data_get($payloadInput, 'waypoints', []);
            $pickup       = data_get($payloadInput, 'pickup');
            $dropoff      = data_get($payloadInput, 'dropoff');
            $return       = data_get($payloadInput, 'return');

            // if no pickup and dropoff extract from waypoints
            if (empty($pickup) && empty($dropoff) && count($waypoints)) {
                $pickup  = array_shift($waypoints);
                $dropoff = array_pop($waypoints);
            }

            if ($pickup) {
                $payload->setPickup($pickup);
            }

            if ($dropoff) {
                $payload->setDropoff($dropoff);
            }

            if ($return) {
                $payload->setReturn($return);
            }

            $payload->save();

            // set waypoints and entities after payload is saved
            if ($waypoints) {
                $payload->setWaypoints($waypoints);
            }

            if ($entities) {
                $payload->setEntities($entities);
            }

            $input['payload_uuid'] = $payload->uuid;
        }

        // driver assignment
        if ($request->has('driver')) {
            $input['driver_assigned_uuid'] = Utils::getUuid('drivers', [
                'public_id'    => $request->input('driver'),
                'company_uuid' => session('company'),
            ]);
        }

        // facilitator assignment
        if ($request->has('facilitator')) {
            $facilitator = Utils::getUuid(
                ['contacts', 'vendors'],
                [
                    'public_id'    => $request->input('facilitator'),
                    'company_uuid' => session('company'),
                ]
            );

            if (is_array($facilitator)) {
                $input['facilitator_uuid'] = Utils::get($facilitator, 'uuid');
                $input['facilitator_type'] = Utils::getModelClassName(Utils::get($facilitator, 'table'));
            }
        }

        // customer assignment
        if ($request->has('customer')) {
            $customer = Utils::getUuid(
                ['contacts', 'vendors'],
                [
                    'public_id'    => $request->input('customer'),
                    'company_uuid' => session('company'),
                ]
            );

            if (is_array($customer)) {
                $input['customer_uuid'] = Utils::get($customer, 'uuid');
                $input['customer_type'] = Utils::getModelClassName(Utils::get($customer, 'table'));
            }
        }

        // dispatch if flagged true
        if ($request->boolean('dispatch')) {
            $order->dispatch();
        }

        // update the order
        $order->update($input);
        $order->flushAttributesCache();

        // response the order resource
        return new OrderResource($order);
    }

    /**
     * Query for Fleetbase Order resources.
     *
     * @return \Fleetbase\Http\Resources\OrderCollection
     */
    public function query(Request $request)
    {
        $results = Order::queryWithRequest($request, function (&$query, $request) {
            $query->where('company_uuid', session('company'));

            if ($request->has('payload')) {
                $query->whereHas('payload', function ($q) use ($request) {
                    $q->where('public_id', $request->input('payload'));
                });
            }

            if ($request->has('pickup')) {
                $query->whereHas('payload.pickup', function ($q) use ($request) {
                    $q->where('public_id', $request->input('pickup'));
                });
            }

            if ($request->has('dropoff')) {
                $query->whereHas('payload.dropoff', function ($q) use ($request) {
                    $q->where('public_id', $request->input('dropoff'));
                });
            }

            if ($request->has('return')) {
                $query->whereHas('payload.return', function ($q) use ($request) {
                    $q->where('public_id', $request->input('return'));
                });
            }

            if ($request->has('facilitator')) {
                $query->whereHas('facilitator', function ($q) use ($request) {
                    $q->where(function ($q) use ($request) {
                        $q->where('public_id', $request->input('facilitator'));
                        $q->orWhere('internal_id', $request->input('facilitator'));
                    });
                });
            }

            if ($request->has('customer')) {
                $query->whereHas('customer', function ($q) use ($request) {
                    $q->where(function ($q) use ($request) {
                        $q->where('public_id', $request->input('customer'));
                        $q->orWhere('internal_id', $request->input('customer'));
                    });
                });
            }

            if ($request->has('entity')) {
                $query->whereHas('payload.entities', function ($q) use ($request) {
                    $q->where(function ($q) use ($request) {
                        $q->where('public_id', $request->input('entity'));
                        $q->orWhere('internal_id', $request->input('entity'));
                    });
                });
            }

            if ($request->has('entity_status')) {
                $query->whereHas('payload.entities.trackingNumber.status', function ($q) use ($request) {
                    if ($request->isArray('entity_status')) {
                        $q->whereIn('code', $request->input('entity_status'));
                    } else {
                        $q->where('code', $request->input('entity_status'));
                    }
                });
            }

            if ($request->has('on')) {
                $on = Carbon::fromString($request->input('on'));

                $query->where(function ($q) use ($on) {
                    $q->whereDate('created_at', $on);
                    $q->orWhereDate('scheduled_at', $on);
                });
            }

            if ($request->boolean('pod_required')) {
                $query->where('pod_required', 1);
            }

            if ($request->boolean('dispatched')) {
                $query->where('dispatched', 1);
            }

            if ($request->has('nearby')) {
                $nearby           = $request->input('nearby');
                $distance         = 6000; // default in meters
                $company          = Company::currentSession();
                $addedNearbyQuery = false;

                if ($company) {
                    $distance = $company->getOption('fleetops.adhoc_distance', 6000);
                }

                // if wants to find nearby place or coordinates
                if (Utils::isCoordinates($nearby)) {
                    $location = Utils::getPointFromMixed($nearby);

                    $query->whereHas('payload', function ($q) use ($location, $distance) {
                        $q->whereHas('pickup', function ($q) use ($location, $distance) {
                            $q->distanceSphere('location', $location, $distance);
                            $q->distanceSphereValue('location', $location);
                        })->orWhereHas('waypoints', function ($q) use ($location, $distance) {
                            $q->distanceSphere('location', $location, $distance);
                            $q->distanceSphereValue('location', $location);
                        });
                    });

                    // Update so additional nearby queries are not added
                    $addedNearbyQuery = true;
                }

                // request wants to find orders nearby a driver ?
                if ($addedNearbyQuery === false && is_string($nearby) && Str::startsWith($nearby, 'driver_')) {
                    $driver = Driver::where('public_id', $nearby)->first();

                    if ($driver) {
                        $query->whereHas('payload', function ($q) use ($driver, $distance) {
                            $q->whereHas('pickup', function ($q) use ($driver, $distance) {
                                $q->distanceSphere('location', $driver->location, $distance);
                                $q->distanceSphereValue('location', $driver->location);
                            })->orWhereHas('waypoints', function ($q) use ($driver, $distance) {
                                $q->distanceSphere('location', $driver->location, $distance);
                                $q->distanceSphereValue('location', $driver->location);
                            });
                        });

                        // Update so additional nearby queries are not added
                        $addedNearbyQuery = true;
                    }
                }

                // if is a string like address string
                if ($addedNearbyQuery === false && is_string($nearby)) {
                    $nearby = Place::createFromMixed($nearby, [], false);

                    if ($nearby instanceof Place) {
                        $query->whereHas('payload', function ($q) use ($nearby, $distance) {
                            $q->whereHas('pickup', function ($q) use ($nearby, $distance) {
                                $q->distanceSphere('location', $nearby->location, $distance);
                                $q->distanceSphereValue('location', $nearby->location);
                            })->orWhereHas('waypoints', function ($q) use ($nearby, $distance) {
                                $q->distanceSphere('location', $nearby->location, $distance);
                                $q->distanceSphereValue('location', $nearby->location);
                            });
                        });

                        // Update so additional nearby queries are not added
                        $addedNearbyQuery = true;
                    }
                }
            }
        });

        return OrderResource::collection($results);
    }

    /**
     * Finds a single Fleetbase Order resources.
     *
     * @return \Fleetbase\Http\Resources\OrderCollection
     */
    public function find($id, Request $request)
    {
        // find for the order
        try {
            $order = Order::findRecordOrFail($id);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $exception) {
            return response()->json(
                [
                    'error' => 'Order resource not found.',
                ],
                404
            );
        }

        // response the order resource
        return new OrderResource($order);
    }

    /**
     * Deletes a Fleetbase Order resources.
     *
     * @return \Fleetbase\Http\Resources\OrderCollection
     */
    public function delete($id, Request $request)
    {
        // find for the driver
        try {
            $order = Order::findRecordOrFail($id);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $exception) {
            return response()->json(
                [
                    'error' => 'Order resource not found.',
                ],
                404
            );
        }

        // delete the order
        $order->delete();

        // response the order resource
        return new DeletedResource($order);
    }

    /**
     * Returns current distance and time matrix for an order.
     *
     * @return \Illuminate\Http\Response $response
     */
    public function getDistanceMatrix(string $id)
    {
        // find the order
        try {
            $order = Order::findRecordOrFail($id);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $exception) {
            return response()->json(
                [
                    'error' => 'Order resource not found.',
                ],
                404
            );
        }

        $order->load(['payload', 'payload.waypoints', 'payload.pickup', 'payload.dropoff']);

        $origin      = $order->payload->pickup ?? $order->payload->waypoints->first();
        $destination = $order->payload->dropoff ?? $order->payload->waypoints->firstWhere('current_waypoint_uuid', $order->current_waypoint_uuid);

        $matrix = Utils::getDrivingDistanceAndTime($origin, $destination);

        $order->update(['distance' => $matrix->distance, 'time' => $matrix->time]);

        // response distance and time matrix
        return response()->json($matrix);
    }

    /**
     * Dispatches an order.
     *
     * @return \Fleetbase\Http\Resources\v1\Order
     */
    public function dispatchOrder(string $id)
    {
        try {
            $order = Order::findRecordOrFail($id);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $exception) {
            return response()->json(
                [
                    'error' => 'Order resource not found.',
                ],
                404
            );
        }

        if (!$order->hasDriverAssigned && !$order->adhoc) {
            return response()->apiError('No driver assigned to dispatch!');
        }

        if ($order->dispatched) {
            return response()->apiError('Order has already been dispatched!');
        }

        $order->dispatch();
        $order->insertDispatchActivity();

        return new OrderResource($order);
    }

    /**
     * Schedules an order using date and time.
     *
     * @param ScheduleOrderRequest
     *
     * @return \Fleetbase\Http\Resources\v1\Order
     */
    public function scheduleOrder(string $id, ScheduleOrderRequest $request)
    {
        $dateInput = $request->input('date');
        $timeInput = $request->input('time');

        // get the default tz
        $company       = Auth::getCompany();
        $defaultTz     = data_get($company, 'timezone', config('app.timezone'));
        $timezoneInput = $request->input('timezone', $defaultTz);

        try {
            $order = Order::findRecordOrFail($id);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $exception) {
            return response()->json(
                [
                    'error' => 'Order resource not found.',
                ],
                404
            );
        }

        // Parse date and time
        $date = Carbon::parse($dateInput);
        if ($timeInput) {
            $time = Carbon::parse($timeInput);
            // Combine date and time
            $date->setTime($time->hour, $time->minute, $time->second);
        }

        // Set the timezone
        $date->shiftTimezone($timezoneInput);

        // Update order with new date and time
        $order->scheduled_at = $date;
        $order->save();

        return new OrderResource($order);
    }

    /**
     * Request to start order, this assumes order is dispatched.
     * Unless there is a param to skip dispatch throw a order not dispatched error.
     *
     * @return \Fleetbase\Http\Resources\v1\Order
     */
    public function startOrder(string $id, Request $request)
    {
        $skipDispatch      = $request->or(['skip_dispatch', 'skipDispatch'], false);
        $assignAdhocDriver = $request->input('assign');

        try {
            $order = Order::findRecordOrFail($id, ['payload.waypoints'], []);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $exception) {
            return response()->json(
                [
                    'error' => 'Order resource not found.',
                ],
                404
            );
        }

        if ($order->started) {
            return response()->apiError('Order has already started.');
        }

        // if the order is adhoc and the parameter of `assign` is set with a valid driver id, assign the driver and continue
        if ($order->adhoc && $assignAdhocDriver && Str::startsWith($assignAdhocDriver, 'driver_')) {
            $order->assignDriver($assignAdhocDriver, true);
        }

        /** @var \Fleetbase\Models\Driver */
        $driver = Driver::where('uuid', $order->driver_assigned_uuid)->withoutGlobalScopes()->first();

        /** @var \Fleetbase\Models\Payload */
        $payload = Payload::where('uuid', $order->payload_uuid)->withoutGlobalScopes()->with(['waypoints', 'waypointMarkers', 'entities'])->first();

        if ($order->adhoc && !$driver) {
            return response()->apiError('You must send driver to accept adhoc order.');
        }

        if (!$driver) {
            return response()->apiError('No driver assigned to order.');
        }

        // get the next order activity
        $flow = $activity = Flow::getNextActivity($order);

        // order is not dispatched if next activity code is dispatch or order is not flagged as dispatched
        $isNotDispatched = $activity['code'] === 'dispatched' || $order->isNotDispatched;

        // if order is not dispatched yet $activity['code'] === 'dispatched' || $order->dispatched === true
        // and not skipping throw order not dispatched error
        if ($isNotDispatched && !$skipDispatch) {
            return response()->apiError('Order has not been dispatched yet and cannot be started.');
        }

        // if we're going to skip the dispatch get the next activity status and flow and continue
        if ($isNotDispatched && $skipDispatch) {
            $flow = $activity = Flow::getAfterNextActivity($order);
        }

        // set order to started
        $order->started    = true;
        $order->started_at = now();
        $order->save();

        // trigger start event
        event(new OrderStarted($order));

        // set order as drivers current order
        $driver->current_job_uuid = $order->uuid;
        $driver->save();

        /** @var \Fleetbase\LaravelMysqlSpatial\Types\Point */
        $location = $order->getLastLocation();

        // set first destination for payload
        $payload->setFirstWaypoint($activity, $location);
        $order->setRelation('payload', $payload);

        // update order activity
        $updateActivityRequest = new Request(['activity' => $flow]);

        // update activity
        return $this->updateActivity($order, $updateActivityRequest);
    }

    /**
     * Update an order activity.
     *
     * @return \Fleetbase\Http\Resources\v1\Order
     */
    public function updateActivity($id, Request $request)
    {
        $skipDispatch = $request->or(['skip_dispatch', 'skipDispatch'], false);
        $proof        = $request->input('proof', null);
        $order        = null;

        // if instance of order is passed directly to this method
        if ($id instanceof Order) {
            /** @var Order $order */
            $order = $id;
        }

        // if string $id
        if (!$order) {
            try {
                $order = Order::findRecordOrFail($id, ['driverAssigned', 'payload.entities', 'payload.currentWaypoint', 'payload.waypoints']);
            } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $exception) {
                return response()->json(
                    [
                        'error' => 'Order resource not found.',
                    ],
                    404
                );
            }
        }

        // if no order found
        if (!$order) {
            return response()->apiError('No resource not found.');
        }

        // if order is still status of `created` trigger started flag
        if ($order->status === 'created') {
            $order->started    = true;
            $order->started_at = now();
        }

        // if order is already completed
        if ($order->status === 'completed') {
            return response()->apiError('Order is already completed.');
        }

        $activity = $request->input('activity', Flow::getNextActivity($order));

        // if we're going to skip the dispatch get the next activity status and flow and continue
        if (is_array($activity) && $activity['code'] === 'dispatched' && $skipDispatch) {
            $activity = Flow::getAfterNextActivity($order);
        }

        // handle pickup/dropoff order activity update as normal
        if (is_array($activity) && $activity['code'] === 'dispatched') {
            // make sure driver is assigned if not trigger failed dispatch
            if (!$order->hasDriverAssigned && !$order->adhoc) {
                event(new OrderDispatchFailed($order, 'No driver assigned for order to dispatch to.'));

                return response()->apiError('No driver assigned for order to dispatch to.');
            }

            $order->dispatch();

            return new OrderResource($order);
        }

        /** @var \Fleetbase\LaravelMysqlSpatial\Types\Point */
        $location = $order->getLastLocation();

        // if is multi drop order and no current destination set it
        if ($order->payload->isMultipleDropOrder && !$order->payload->current_waypoint_uuid) {
            $order->payload->setFirstWaypoint($activity, $location);
        }

        if (is_array($activity) && $activity['code'] === 'completed' && $order->payload->isMultipleDropOrder) {
            // confirm every waypoint is completed
            $isCompleted = $order->payload->waypointMarkers->every(function ($waypoint) {
                return $waypoint->status_code === 'COMPLETED';
            });

            // only update activity for waypoint
            if (!$isCompleted) {
                $order->payload->updateWaypointActivity($activity, $location, $proof);
                $order->payload->setNextWaypointDestination();
                $order->payload->refresh();

                // recheck if order is completed
                $isFullyCompleted = $order->payload->waypointMarkers->every(function ($waypoint) {
                    return $waypoint->status_code === 'COMPLETED';
                });

                if (!$isFullyCompleted) {
                    return new OrderResource($order);
                }
            }
        }

        if (is_array($activity) && $activity['code'] === 'completed' && $order->driverAssigned) {
            // unset from driver current job
            $order->driverAssigned->unassignCurrentOrder();
            $order->notifyCompleted();
        }

        $order->updateActivity($activity, $proof);

        // also update for each order entities if not multiple drop order
        // all entities will share the same activity status as is one drop order
        if (!$order->payload->isMultipleDropOrder) {
            foreach ($order->payload->entities as $entity) {
                $entity->insertActivity($activity['status'], $activity['details'] ?? '', $location, $activity['code'], $proof);
            }
        } else {
            $order->payload->updateWaypointActivity($activity, $location);
        }

        return new OrderResource($order);
    }

    /**
     * Retrieve the next activity for the order flow.
     *
     * @return \Illuminate\Http\Response
     */
    public function getNextActivity(string $id, Request $request)
    {
        $waypointId = $request->input('waypoint');

        try {
            $order = Order::findRecordOrFail($id, ['payload']);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $exception) {
            return response()->json(
                [
                    'error' => 'Order resource not found.',
                ],
                404
            );
        }

        $isMultipleDropOrder = $order->payload->isMultipleDropOrder;

        if ($waypointId && $isMultipleDropOrder) {
            $waypoint = Waypoint::where('payload_uuid', $order->payload_uuid)->where(function ($q) use ($waypointId) {
                $q->whereHas('place', function ($q) use ($waypointId) {
                    $q->where('public_id', $waypointId);
                });
                $q->orWhere('public_id', $waypointId);
            })->withoutGlobalScopes()->first();

            $activity = Flow::getOrderWaypointFlow($order, $waypoint);

            return response()->json($activity);
        }

        $activity = Flow::getOrderFlow($order);

        return response()->json($activity);
    }

    /**
     * Confirms and completes an order.
     *
     * @return \Fleetbase\Http\Resources\v1\Order
     */
    public function completeOrder(string $id)
    {
        try {
            $order = Order::findRecordOrFail($id);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $exception) {
            return response()->json(
                [
                    'error' => 'Order resource not found.',
                ],
                404
            );
        }

        // confirm every waypoint is completed
        $isCompleted = $order->payload->waypointMarkers->every(function ($waypoint) {
            return $waypoint->status_code === 'COMPLETED';
        });

        // if not completed respond with error
        if (!$isCompleted) {
            return response()->apiError('Not all waypoints completed for order.');
        }

        $activity = [
            'status'  => 'Order completed',
            'details' => 'Driver has completed order for all waypoints',
            'code'    => 'completed',
        ];

        if ($order->driverAssigned) {
            // unset from driver current job
            $order->driverAssigned->unassignCurrentOrder();
        }

        $order->notifyCompleted();

        /** @var \Fleetbase\LaravelMysqlSpatial\Types\Point */
        $location = $order->getLastLocation();

        $order->setStatus($activity['code']);
        $order->insertActivity($activity['status'], $activity['details'] ?? '', $location, $activity['code']);

        return new OrderResource($order);
    }

    /**
     * Updates a order to canceled and updates order activity.
     *
     * @return \Fleetbase\Http\Resources\v1\Order
     */
    public function cancelOrder(string $id)
    {
        try {
            $order = Order::findRecordOrFail($id);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $exception) {
            return response()->json(
                [
                    'error' => 'Order resource not found.',
                ],
                404
            );
        }

        $order->cancel();

        return new OrderResource($order);
    }

    /**
     * Updates the order payload destination with a valid place.
     *
     * @return \Fleetbase\Http\Resources\v1\Order
     */
    public function setDestination(string $id, string $placeId)
    {
        try {
            $order = Order::findRecordOrFail($id);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $exception) {
            return response()->json(
                [
                    'error' => 'Order resource not found.',
                ],
                404
            );
        }

        $place = $order->payload->waypoints->firstWhere('public_id', $placeId);

        if (!$place) {
            return response()->apiError('Place resource is not a valid destination.');
        }

        $order->payload->setCurrentWaypoint($place);

        return new OrderResource($order);
    }

    /**
     * Sends request for route optimization and re-sorts waypoints.
     *
     * @return \Fleetbase\Http\Resources\v1\Order
     */
    public function optimize(string $id)
    {
        try {
            $order = Order::findRecordOrFail($id);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $exception) {
            return response()->json(
                [
                    'error' => 'Order resource not found.',
                ],
                404
            );
        }

        // do this code

        return new OrderResource($order);
    }

    /**
     * Verify & Capture QR Code Scan.
     *
     * @return void
     */
    public function captureQrScan(string $id, ?string $subjectId = null, Request $request)
    {
        $code    = $request->input('code');
        $data    = $request->input('data', []);
        $rawData = $request->input('raw_data');
        $type    = $subjectId ? strtok($subjectId, '_') : null;

        try {
            $order = Order::findRecordOrFail($id);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $exception) {
            return response()->json(
                [
                    'error' => 'Order resource not found.',
                ],
                404
            );
        }

        if (!$code) {
            return response()->apiError('No QR code data to capture.');
        }

        $subject = $type === null ? $order : null;

        switch ($type) {
            case 'place':
            case 'waypoint':
                $subject = Waypoint::where('payload_uuid', $order->payload_uuid)->where(function ($q) use ($code) {
                    $q->whereHas('place', function ($q) use ($code) {
                        $q->where('uuid', $code);
                    });
                    $q->orWhere('uuid', $code);
                })->withoutGlobalScopes()->first();
                break;

            case 'entity':
                $subject = Entity::where('uuid', $code)->withoutGlobalScopes()->first();
                break;

            default:
                break;
        }

        if (!$subject) {
            return response()->apiError('Unable to capture QR code data.');
        }

        // validate
        if ($subject && $code === $subject->uuid) {
            // create verification proof
            $proof = Proof::create([
                'company_uuid' => session('company'),
                'order_uuid'   => $order->uuid,
                'subject_uuid' => $subject->uuid,
                'subject_type' => Utils::getModelClassName($subject),
                'remarks'      => 'Verified by QR Code Scan',
                'raw_data'     => $rawData,
                'data'         => $data,
            ]);

            return new ProofResource($proof);
        }

        return response()->apiError('Unable to validate QR code data.');
    }

    /**
     * Validate a QR code.
     *
     * @return void
     */
    public function captureSignature(string $id, ?string $subjectId = null, Request $request)
    {
        $disk         = $request->input('disk', config('filesystems.default'));
        $bucket       = $request->input('bucket', config('filesystems.disks.' . $disk . '.bucket', config('filesystems.disks.s3.bucket')));
        $signature    = $request->input('signature');
        $data         = $request->input('data', []);
        $remarks      = $request->input('remarks', 'Verified by Signature');
        $type         = $subjectId ? strtok($subjectId, '_') : null;

        try {
            $order = Order::findRecordOrFail($id);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $exception) {
            return response()->json(
                [
                    'error' => 'Order resource not found.',
                ],
                404
            );
        }

        if (!$signature) {
            return response()->apiError('No signature data to capture.');
        }

        $subject = $type === null ? $order : null;

        switch ($type) {
            case 'place':
            case 'waypoint':
                $subject = Waypoint::where('payload_uuid', $order->payload_uuid)->where(function ($q) use ($subjectId) {
                    $q->whereHas('place', function ($q) use ($subjectId) {
                        $q->where('public_id', $subjectId);
                    });
                    $q->orWhere('public_id', $subjectId);
                })->withoutGlobalScopes()->first();
                break;

            case 'entity':
                $subject = Entity::where('public_id', $subjectId)->withoutGlobalScopes()->first();
                break;

            default:
                break;
        }

        if (!$subject) {
            return response()->apiError('Unable to capture signature data.');
        }

        // create proof instance
        $proof = Proof::create([
            'company_uuid' => session('company'),
            'order_uuid'   => $order->uuid,
            'subject_uuid' => $subject->uuid,
            'subject_type' => Utils::getModelClassName($subject),
            'remarks'      => $remarks,
            'raw_data'     => $signature,
            'data'         => $data,
        ]);

        // set the signature storage path
        $path = 'uploads/' . session('company') . '/signatures/' . $proof->public_id . '.png';

        // upload signature
        Storage::disk($disk)->put($path, base64_decode($signature));

        // create file record for upload
        $file = File::create([
            'company_uuid'      => session('company'),
            'uploader_uuid'     => session('user'),
            'name'              => basename($path),
            'original_filename' => basename($path),
            'extension'         => 'png',
            'content_type'      => 'image/png',
            'path'              => $path,
            'bucket'            => $bucket,
            'type'              => 'signature',
            'size'              => Utils::getBase64ImageSize($signature),
        ])->setKey($proof);

        // set file to proof
        $proof->file_uuid = $file->uuid;
        $proof->save();

        return new ProofResource($proof);
    }
}
