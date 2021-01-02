(function () {
    'use strict';

    angular
        .module('arts-people.rollovers')
        .component(
            'apRolloverReview'
            , {
                'templateUrl': 'rollovers/review.html'
                , controller: apRolloverReview
                , bindings:
                    {
                        rolloverDetails: '<'
                        , rollover: '<'
                    }
            }
        );

    apRolloverReview.$inject =
        [
            '$filter',
            '$http',
            '$scope',
            '$state',
            '$timeout',
            '$uibModal',
            'rolloversService',
            'rolloversConflictService',
            'uiGridConstants',
            'uiGridExporterConstants'
        ];

    function apRolloverReview(
        $filter,
        $http,
        $scope,
        $state,
        $timeout,
        $uibModal,
        rolloversService,
        rolloversConflictService,
        uiGridConstants,
        uiGridExporterConstants
    ) {
        var vm = this;
        vm.alerts = [];
        vm.isDirty = false;
        vm.processRollovers = processRollovers;
        vm.exportCsv = exportCsv;
        vm.exportConflictsCsv = exportConflictsCsv;
        vm.rolloversConflictService = rolloversConflictService;
        vm.rolloversConflictCount = 0;
        vm.editSubscriptionModalDetail = editSubscriptionModalDetail;
        vm.viewSubscriptionModalDetail = viewSubscriptionModalDetail;
        vm.toggleRemove = toggleRemove;
        vm.season = vm.rollover.seasons.filter(function (season) {
            return season.id == vm.rollover.saved.season.new;
        });
        vm.season = vm.season[0];
        vm.rolloversConflictService.checkAllConflicts(vm.rolloverDetails, vm.season);
        vm.countConflicts = countConflicts;
        vm.countConflicts();
        vm.season.personTypes = vm.rollover.personTypes;
        vm.gridOptions = {
            data: vm.rolloverDetails,
            enableColumnMenus: false,
            enableGridMenu: false,
            enableRowHeaderSelection: false,
            onRegisterApi: onRegisterApi,
            exporterCsvFilename: 'Rollover.csv',
            exporterFieldCallback: exporterFieldCallback,
            exporterHeaderFilter: exporterHeaderFilter,
            exporterHeaderFilterUseName: false,
            rowHeight: 50,
            rowTemplate: 'rollovers/review.rowTemplate.html',
            enableHorizontalScrollbar: uiGridConstants.scrollbars.NEVER,
            enableVerticalScrollbar: uiGridConstants.scrollbars.WHEN_NEEDED,
            columnDefs: [
                {
                    field: 'name',
                    name: 'Name',
                    defaultSort: {
                        direction: uiGridConstants.ASC,
                        priority: 0
                    }
                },
                {
                    field: 'new_seat_no',
                    name: 'Seat',
                    defaultSort: {
                        direction: uiGridConstants.ASC,
                        priority: 4
                    }
                },
                {
                    field: 'new_package_name',
                    name: 'Package',
                    defaultSort: {
                        direction: uiGridConstants.ASC,
                        priority: 1
                    }
                },
                {
                    field: 'new_series_name',
                    name: 'Series',
                    defaultSort: {
                        direction: uiGridConstants.ASC,
                        priority: 2
                    }
                },
                {
                    field: 'new_person_type',
                    name: 'Person type',
                    defaultSort: {
                        direction: uiGridConstants.DESC,
                        priority: 3
                    }
                },
                {
                    field: 'new_venue_name',
                    name: 'Venue',
                    defaultSort: {
                        direction: uiGridConstants.DESC,
                        priority: 4
                    }
                },
                {
                    field: 'conflicts',
                    cellTemplate: 'rollovers/review.cellTemplate.html',
                    name: 'More',
                    displayName: '',
                    exporterSuppressExport: false
                }
            ],
            appScopeProvider: vm
        };
        
        function countConflicts() {
            vm.rolloversConflictCount = 0;
            for (var i = 0; i < vm.rolloverDetails.length; i++) {
                if (vm.rolloverDetails[i].conflicts.length > 0) {
                    vm.rolloversConflictCount++;
                }
            }
        }

        function editSubscriptionModalDetail(detailId) {
            $uibModal.open({
                animation: true
                , templateUrl: 'rollovers/editSubscriptionModal.html'
                , controller: 'editSubscriptionModalController'
                , controllerAs: 'edit'
                , windowClass: 'ap-left-drawer pc-detail-modal'
                , resolve: {
                    detailId: function () {
                        return detailId;
                    },
                    season: function () {
                        return vm.season;
                    },
                    rolloverDetails: function () {
                        return vm.rolloverDetails;
                    },
                    editMode: function () {
                        return true;
                    },
                }
            }).closed.then(function() {
                vm.countConflicts();
            });
        }

        function exportConflictsCsv() {
            var grid = vm.gridApi.grid;
            var rowTypes = uiGridExporterConstants.SELECTED;
            var colTypes = uiGridExporterConstants.ALL;
            var rows = vm.gridOptions.data;
            for (var i=0; i < rows.length; i++) {
                if (rows[i].conflicts.length > 0) {
                    grid.api.selection.selectRow(rows[i]);
                }
            }
            grid.api.exporter.csvExport(rowTypes, colTypes);
        }

        function exportCsv() {
            var grid = vm.gridApi.grid;
            var rowTypes = uiGridExporterConstants.ALL;
            var colTypes = uiGridExporterConstants.ALL;
            grid.api.exporter.csvExport(rowTypes, colTypes);
        }

        function exporterFieldCallback(grid, row, col, value) {
            if ( col.name === 'More' ) {
                var conflictList = "";
                for (var i = 0; i < value.length; i++) {
                    if (i > 0) {
                        conflictList += "; ";
                    }
                    conflictList += value[i].conflictType;
                }
                value = conflictList;
            }
            return value;
        }

        function exporterHeaderFilter(displayName) { 
            if (displayName === '') {
                displayName = 'More';
            }
            return displayName;
        }

        function onRegisterApi(gridApi)
        {
            vm.gridApi = gridApi;
        }

        /**
         * Process rollovers
         */
        function processRollovers() {
            $('body').css('cursor', 'progress');

            rolloversService
                .Process()
                .then(processSuccess, processError);

            function processSuccess() {
                $('body').css('cursor', 'default');
                $state.go('rollovers.confirmation', {"mappingId": vm.rollover.saved.mappingId});
            }

            function processError() {
                $('body').css('cursor', 'default');
            }
        }

        function toggleRemove(detailRow)
        {
            $('body').css('cursor', 'progress');

            if (detailRow.status == 'not_started') {
                detailRow.status = 'inactive';
            } else if (detailRow.status == 'inactive') {
                detailRow.status = 'not_started';
            }
            rolloversService
                .UpdateDetail(detailRow)
                .then(updateSuccess, updateError);

            function updateSuccess() {
                $('body').css('cursor', 'default');
                vm.alerts.push({
                    type: 'success',
                    message: 'Saved successfully'
                });
                $timeout(function () {
                    vm.alerts.splice(vm.alerts.findIndex(function (ele, i) {
                        if (
                            ele.type == 'success' &&
                            ele.message == 'Saved successfully'
                        ) {
                            return true;
                        }
                        return false;
                    }), 1);
                }, 3000);
                vm.rolloversConflictService.checkAllConflicts(vm.rolloverDetails);
                vm.countConflicts();
            }

            function updateError() {
                $('body').css('cursor', 'default');
            }

            vm.rolloversConflictService.checkAllConflicts(vm.rolloverDetails);
            countConflicts();
        }

        function viewSubscriptionModalDetail(detailId) {
            $uibModal.open({
                animation: true
                , templateUrl: 'rollovers/editSubscriptionModal.html'
                , controller: 'editSubscriptionModalController'
                , controllerAs: 'edit'
                , windowClass: 'ap-left-drawer pc-detail-modal'
                , resolve: {
                    detailId: function () { return detailId; },
                    season: function() { return vm.season; },
                    rolloverDetails: function() { return vm.rolloverDetails; },
                    editMode: function () { return false; },
                }
            }).closed.then(function(){
                countConflicts();
            });
        }

    }
})();
