import BaseController from '@yogeshsahu/fleetops-engine/controllers/base-controller';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { isBlank } from '@ember/utils';
import { task, timeout } from 'ember-concurrency';

export default class ManagementPlacesIndexController extends BaseController {
    /**
     * Inject the `notifications` service
     *
     * @var {Service}
     */
    @service notifications;

    /**
     * Inject the `modals-manager` service
     *
     * @var {Service}
     */
    @service modalsManager;

    /**
     * Inject the `intl` service
     *
     * @var {Service}
     */
    @service intl;

    /**
     * Inject the `store` service
     *
     * @var {Service}
     */
    @service store;

    /**
     * Inject the `fetch` service
     *
     * @var {Service}
     */
    @service fetch;

    /**
     * Inject the `filters` service
     *
     * @var {Service}
     */
    @service filters;

    /**
     * Inject the `hostRouter` service
     *
     * @var {Service}
     */
    @service hostRouter;

    /**
     * Inject the `crud` service
     *
     * @var {Service}
     */
    @service crud;

    /**
     * Queryable parameters for this controller's model
     *
     * @var {Array}
     */
    queryParams = ['name', 'page', 'limit', 'sort', 'query', 'public_id', 'country', 'phone', 'created_at', 'updated_at', 'city', 'neighborhood', 'state'];

    /**
     * The current page of data being viewed
     *
     * @var {Integer}
     */
    @tracked page = 1;

    /**
     * The maximum number of items to show per page
     *
     * @var {Integer}
     */
    @tracked limit;

    /**
     * The param to sort the data on, the param with prepended `-` is descending
     *
     * @var {String}
     */
    @tracked sort = '-created_at';

    /**
     * The filterable param `public_id`
     *
     * @var {String}
     */
    @tracked public_id;

    /**
     * The filterable param `public_id`
     *
     * @var {String}
     */
    @tracked postal_code;

    /**
     * The filterable param `phone`
     *
     * @var {String}
     */
    @tracked phone;

    /**
     * The filterable param `city`
     *
     * @var {String}
     */
    @tracked city;

    /**
     * The filterable param `name`
     *
     * @var {String}
     */
    @tracked name;

    /**
     * The filterable param `country`
     *
     * @var {String}
     */
    @tracked country;

    /**
     * The filterable param `country`
     *
     * @var {String}
     */
    @tracked neighborhood;

    /**
     * All columns applicable for orders
     *
     * @var {Array}
     */
    @tracked columns = [
        {
            label: this.intl.t('fleet-ops.common.name'),
            valuePath: 'name',
            width: '200px',
            cellComponent: 'table/cell/anchor',
            action: this.viewPlace,
            resizable: true,
            sortable: true,
            filterable: true,
            filterParam: 'name',
            filterComponent: 'filter/string',
        },
        {
            label: this.intl.t('fleet-ops.common.address'),
            valuePath: 'address',
            cellComponent: 'table/cell/anchor',
            action: this.viewPlace,
            width: '320px',
            resizable: true,
            sortable: true,
            filterable: true,
            filterParam: 'address',
            filterComponent: 'filter/string',
        },
        {
            label: this.intl.t('fleet-ops.common.state'),
            valuePath: 'state',
            cellComponent: 'table/cell/anchor',
            action: this.viewPlace,
            width: '100px',
            resizable: true,
            sortable: true,
            filterable: true,
            filterParam: 'state',
            filterComponent: 'filter/string',
        },
        {
            label: this.intl.t('fleet-ops.common.city'),
            valuePath: 'city',
            cellComponent: 'table/cell/anchor',
            action: this.viewPlace,
            width: '100px',
            resizable: true,
            sortable: true,
            filterable: true,
            filterParam: 'city',
            filterComponent: 'filter/string',
        },
        {
            label: this.intl.t('fleet-ops.common.id'),
            valuePath: 'public_id',
            width: '120px',
            cellComponent: 'click-to-copy',
            resizable: true,
            sortable: true,
            filterable: true,
            filterComponent: 'filter/string',
        },
        {
            label: this.intl.t('fleet-ops.common.phone'),
            valuePath: 'phone',
            cellComponent: 'table/cell/base',
            width: '120px',
            resizable: true,
            sortable: true,
            hidden: true,
            filterable: true,
            filterComponent: 'filter/string',
        },
        {
            label: this.intl.t('fleet-ops.common.country'),
            valuePath: 'country_name',
            cellComponent: 'table/cell/base',
            cellClassNames: 'uppercase',
            width: '120px',
            resizable: true,
            sortable: true,
            filterable: true,
            filterComponent: 'filter/country',
            filterParam: 'country',
        },
        {
            label: this.intl.t('fleet-ops.common.neighborhood'),
            valuePath: 'neighborhood',
            cellComponent: 'table/cell/anchor',
            action: this.viewPlace,
            width: '100px',
            resizable: true,
            sortable: true,
            filterable: true,
            filterParam: 'neighborhood',
            filterComponent: 'filter/string',
        },
        {
            label: this.intl.t('fleet-ops.common.postal-code'),
            valuePath: 'postal_code',
            cellComponent: 'table/cell/anchor',
            action: this.viewPlace,
            width: '100px',
            resizable: true,
            sortable: true,
            filterable: true,
            filterComponent: 'filter/string',
        },
        {
            label: this.intl.t('fleet-ops.common.created-at'),
            valuePath: 'createdAt',
            sortParam: 'created_at',
            width: '10%',
            resizable: true,
            sortable: true,
            filterable: true,
            filterComponent: 'filter/date',
        },
        {
            label: this.intl.t('fleet-ops.common.updated-at'),
            valuePath: 'updatedAt',
            sortParam: 'updated_at',
            width: '10%',
            resizable: true,
            sortable: true,
            hidden: true,
            filterable: true,
            filterComponent: 'filter/date',
        },

        {
            label: '',
            cellComponent: 'table/cell/dropdown',
            ddButtonText: false,
            ddButtonIcon: 'ellipsis-h',
            ddButtonIconPrefix: 'fas',
            ddMenuLabel: 'Place Actions',
            cellClassNames: 'overflow-visible',
            wrapperClass: 'flex items-center justify-end mx-2',
            width: '10%',
            actions: [
                {
                    label: this.intl.t('fleet-ops.management.places.index.view-details'),
                    fn: this.viewPlace,
                },
                {
                    label: this.intl.t('fleet-ops.management.places.index.edit-place'),
                    fn: this.editPlace,
                },
                {
                    separator: true,
                },
                {
                    label: this.intl.t('fleet-ops.management.places.index.view-place'),
                    fn: this.viewOnMap,
                },
                {
                    separator: true,
                },
                {
                    label: this.intl.t('fleet-ops.management.places.index.delete'),
                    fn: this.deletePlace,
                },
            ],
            sortable: false,
            filterable: false,
            resizable: false,
            searchable: false,
        },
    ];

    /**
     * The search task.
     *
     * @void
     */
    @task({ restartable: true }) *search({ target: { value } }) {
        // if no query don't search
        if (isBlank(value)) {
            this.query = null;
            return;
        }

        // timeout for typing
        yield timeout(250);

        // reset page for results
        if (this.page > 1) {
            this.page = 1;
        }

        // update the query param
        this.query = value;
    }

    /**
     * Toggles dialog to export `place`
     *
     * @void
     */
    @action exportPlaces() {
        this.crud.export('place');
    }

    /**
     * View a place details
     *
     * @param {PlaceModel} place
     * @return {Promise}
     * @memberof ManagementPlacesIndexController
     */
    @action viewPlace(place) {
        return this.transitionToRoute('management.places.index.details', place);
    }

    /**
     * Reload layout view.
     */
    @action reload() {
        return this.hostRouter.refresh();
    }
    /**
     * Create a new place
     *
     * @return {Promise}
     * @memberof ManagementPlacesIndexController
     */
    @action createPlace() {
        return this.transitionToRoute('management.places.index.new');
    }

    /**
     *Edit place details
     *
     * @param {PlaceModel} place
     * @return {Promise}
     * @memberof ManagementPlacesIndexController
     */
    @action editPlace(place) {
        return this.transitionToRoute('management.places.index.edit', place);
    }

    /**
     * Delete a `place` via confirm prompt
     *
     * @param {PlaceModel} place
     * @param {Object} options
     * @void
     */
    @action deletePlace(place, options = {}) {
        this.crud.delete(place, {
            onConfirm: () => {
                return this.hostRouter.refresh();
            },
            ...options,
        });
    }

    /**
     * Bulk deletes selected `place` via confirm prompt
     *
     * @param {Array} selected an array of selected models
     * @void
     */
    @action bulkDeletePlaces() {
        const selected = this.table.selectedRows;

        this.crud.bulkDelete(selected, {
            modelNamePath: `address`,
            acceptButtonText: this.intl.t('fleet-ops.management.places.index.delete-button'),
            onSuccess: () => {
                return this.hostRouter.refresh();
            },
        });
    }

    /**
     * Prompt user to assign a `vendor` to a `place`
     *
     * @param {PlaceModel} place
     * @param {Object} options
     * @void
     */
    @action assignVendor(place, options = {}) {
        this.modalsManager.show('modals/place-assign-vendor', {
            title: this.intl.t('fleet-ops.management.places.index.title'),
            acceptButtonText: this.intl.t('fleet-ops.management.places.index.confirm-button'),
            hideDeclineButton: true,
            place,
            confirm: (modal) => {
                modal.startLoading();
                return place.save().then(() => {
                    this.notifications.success(this.intl.t('fleet-ops.management.places.index.success-message', { placeName: place.name }));
                });
            },
            ...options,
        });
    }

    /**
     * View a place location on map
     *
     * @param {PlaceModel} place
     * @param {Object} options
     * @void
     */
    @action viewOnMap(place, options = {}) {
        const { latitude, longitude } = place;

        this.modalsManager.show('modals/point-map', {
            title: this.intl.t('fleet-ops.management.places.index.locate-title', { placeName: place.name }),
            acceptButtonText: 'Done',
            hideDeclineButton: true,
            latitude,
            longitude,
            location: [latitude, longitude],
            ...options,
        });
    }

    /**
     * View information about a place vendor
     *
     * @param {PlaceModel} place
     * @void
     */
    @action async viewPlaceVendor(place) {
        const vendor = await this.store.findRecord('vendor', place.vendor_uuid);

        if (vendor) {
            this.contextPanel.focus(vendor);
        }
    }
}
