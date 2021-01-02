(function()
{
    'use strict';

    angular
        .module('arts-people.rollovers')
        .component(
            'apRolloverMap'
            ,{
                'templateUrl': 'rollovers/map.html'
                ,controller: apRolloverMap
                ,bindings:
                {
                    rollover: '<'
                }
            }
        );

    apRolloverMap.$inject = ['$http', '$filter', 'rolloversService', '$state', '$uibModal'];
    function apRolloverMap($http, $filter, rolloversService, $state, $uibModal)
    {
        var vm = this;

        vm.priorSeason = null;
        vm.processing = false;
        vm.newSeason = null;
        vm.packages = {};
        vm.series = {};
        vm.venues = {};
        vm.personTypes = {};
        vm.gaSections = {};
        vm.saveMapping = saveMapping;
        vm.changeSeason = changeSeason;
        vm.duplicateValueFound = duplicateValueFound;
        activate();

        /**
         * Set saved map, if present
         */
        function activate()
        {
            //add "Don't roll over option"
            for (var i=0; i < vm.rollover.seasons.length; i++) {
                vm.rollover.seasons[i].packages.push({id: "0", name: "-Don't roll over-"});
                vm.rollover.seasons[i].series.push({id: "0", name: "-Don't roll over-"});
                vm.rollover.seasons[i].venues.push({id: "0", name: "-Don't roll over-"});
                vm.rollover.seasons[i].gaSections.push({id: null, name: "-Don't roll over-"});
            }
            if (Array.isArray(vm.rollover.personTypes)) {
                vm.rollover.personTypes.push({id: "0", name: "-Don't roll over-"});
            } else {
                vm.rollover.personTypes = [{id: "0", name: "-Don't roll over-"}];
            }

            if (vm.rollover.saved) {
                if (vm.rollover.saved.season.prior) {
                    vm.priorSeason = $filter('filter')(vm.rollover.seasons, {id: vm.rollover.saved.season.prior}, true)[0];
                }
                if (vm.rollover.saved.season.new) {
                    vm.newSeason = $filter('filter')(vm.rollover.seasons, {id: vm.rollover.saved.season.new}, true)[0];
                }
                if (vm.rollover.saved.packages) {
                    vm.packages = vm.rollover.saved.packages;
                }
                if (vm.rollover.saved.series) {
                    vm.series = vm.rollover.saved.series;
                }
                if (vm.rollover.saved.venues) {
                    vm.venues = vm.rollover.saved.venues;
                }
                if (vm.rollover.saved.personTypes) {
                    vm.personTypes = vm.rollover.saved.personTypes;
                }
                if (vm.rollover.saved.gaSections) {
                    vm.gaSections = vm.rollover.saved.gaSections;
                }
                if (vm.rollover.saved.mappingId) {
                    openModal();
                }
            }

        }

        /**
         * Send maps to endpoint
         */
        function saveMapping()
        {
            $('body').css('cursor', 'progress');
            vm.processing = true;

            rolloversService
                .Save(
                    vm.rollover.theatreId
                    , vm.priorSeason.id
                    , vm.newSeason.id
                    , vm.packages
                    , vm.series
                    , vm.venues
                    , vm.personTypes
                    , vm.gaSections
                    , vm.rollover.saved.mappingId
                )
                .then(saveSuccess)
                .catch(saveError)
                .finally(saveDone);

            function saveSuccess(data)
            {
                vm.rollover.saved.mappingId = data.data;
                $state.go('rollovers.process.review');
                $('body').css('cursor', 'default');
            }

            function saveError()
            {
                $('body').css('cursor', 'default');
            }

            function saveDone()
            {
                vm.processing = false;
            }
        }
        
        function changeSeason(season)
        {
            vm.packages = {};
            vm.series = {};
            vm.personTypes = {};
            vm.gaSections = {};

            vm.rollover.saved.packages = vm.packages;
        }

        function duplicateValueFound(haystack, needle)
        {
            if (false == angular.isObject(haystack)) {
                return false;
            }

            var keys = Object.keys(haystack);
            for (var i=0; i<keys.length; i++) {
                var value = haystack[keys[i]];
                if (value == 0) { //0 is "Don't Roll Over" which is excluded
                    continue;
                }
                for (var j=0; j<keys.length; j++) {
                    //If the key, value pair isn't the same and the values match
                    if (keys[i] != keys[j] && haystack[keys[j]] === value && needle === value) {
                        return true;
                    }
                }
            }
            return false;
        }

        function openModal() {
            $uibModal.open({
                templateUrl: 'rollovers/map.alert.html',
                controller: function ($scope, $uibModalInstance) {
                    $scope.ok = function () {
                        $uibModalInstance.close();
                    };
                }
            })
        };

    }
})();
