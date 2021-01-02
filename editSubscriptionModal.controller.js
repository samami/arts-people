(function()
 {
    'use strict';

    angular
        .module('arts-people.rollovers')
        .controller('editSubscriptionModalController', editSubscriptionModalController);

    editSubscriptionModalController.$inject = ['$uibModalInstance', 'detailId', 'season', 'rolloversService', 'rolloverDetails', 'rolloversConflictService', 'editMode'];

    function editSubscriptionModalController($uibModalInstance, detailId, season, rolloversService, rolloverDetails, rolloversConflictService, editMode)
    {
        var edit = this;
        edit.detailId = {};
        edit.rolloverDetails = [];
        var isDirty = false;

        angular.copy(rolloverDetails, edit.rolloverDetails);
        edit.detailId = findDetail(detailId, edit.rolloverDetails);
        edit.season = season;
        edit.editMode = editMode;
        
        edit.findDetail = findDetail;
        edit.getSelectedPackage = getSelectedPackage;
        edit.onChange = onChange;
        edit.updateDetail = updateDetail;

        /**
         * Find a rollover row based on id
         */
        function findDetail(needle, haystack) {
            for (var i=0; i< haystack.length; i++) {
                if (haystack[i].details_id == needle) {
                    return haystack[i];
                    break;
                }
            }
        }

        function getSelectedPackage()
        {
            for (var i = 0; i < edit.season.packages.length; i++) {
                if (edit.detailId.new_package_id == edit.season.packages[i].id) {
                    return edit.season.packages[i];
                }
            }

            return null;
        }
 
        function onChange() {
            isDirty = true;
            edit.rolloverDetails = rolloversConflictService.checkAllConflicts(edit.rolloverDetails, edit.season);
        }

        /**
         * Update rollover row in back and front end
         */
        function updateDetail(detailObj)
        {
            $('body').css('cursor', 'progress');
            if (isDirty) {
                //Update db
                rolloversService
                    .UpdateDetail(detailObj)
                    .then(updateSuccess, updateError);

                //Update names to match any updated ids
                for (var i=0; i < edit.season.packages.length; i++) {
                    if (edit.season.packages[i].id == edit.detailId.new_package_id) {
                        edit.detailId.new_package_name = edit.season.packages[i].name;
                        break;
                    }
                }

                for (var i=0; i < edit.season.series.length; i++) {
                    if (edit.season.series[i].id == edit.detailId.new_series_id) {
                        edit.detailId.new_series_name = edit.season.series[i].name;
                        break;
                    }
                }

                for (var i=0; i < edit.season.personTypes.length; i++) {
                    if (edit.season.personTypes[i].id == edit.detailId.new_person_type_id) {
                        edit.detailId.new_person_type = edit.season.personTypes[i].name;
                        break;
                    }
                }

                for (var i=0; i < edit.season.venues.length; i++) {
                    if (edit.season.venues[i].id == edit.detailId.new_venue_id) {
                        edit.detailId.new_venue_name = edit.season.venues[i].name;
                        break;
                    }
                }

                //Update frontend
                angular.copy(edit.rolloverDetails, rolloverDetails);
            }

            function updateSuccess()
            {
                $('body').css('cursor', 'default');
                $uibModalInstance.close('detail row updated');
            }

            function updateError()
            {
                $('body').css('cursor', 'default');
                $uibModalInstance.dismiss('could not update db');
            }
        }
    }

 })();