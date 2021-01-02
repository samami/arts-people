(function()
{
    'use strict';

    angular
        .module('arts-people.rollovers')
        .component(
            'apRolloverHistory'
            ,{
                'templateUrl': 'rollovers/history.html'
                ,controller: apRolloverHistory
                ,bindings:
                {
                    history: '<'
                }
            }
        );

    apRolloverHistory.$inject = ['rolloversService'];
    function apRolloverHistory(rolloversService)
    {
        var vm = this;
        vm.hasIncompleteRollover = rolloversService.HasIncompleteRollover();
        vm.deleteMapping = deleteMapping;

        /**
         * Send maps to endpoint
         */
        function deleteMapping(mappingId)
        {
            $('body').css('cursor', 'progress');

            rolloversService
                .DeleteMapping(mappingId)
                .then(saveSuccess, saveError);

            function saveSuccess()
            {
                for (var i=0; i<vm.history.length; i++) {
                    if (vm.history[i].mapping_id == mappingId) {
                        vm.history.splice(i, 1);
                        break;
                    }
                }
                vm.hasIncompleteRollover = rolloversService.HasIncompleteRollover();
                $('body').css('cursor', 'default');
            }

            function saveError()
            {
                $('body').css('cursor', 'default');
            }
        }
    }

})();
