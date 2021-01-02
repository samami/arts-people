(function()
{
    'use strict';

    angular
        .module('arts-people.rollovers')
        .component(
            'apRolloverConfirmation'
            ,{
                'templateUrl': 'rollovers/confirmation.html'
                ,controller: apRolloverConfirmation
                ,bindings:
                {
                    confirmation: '<'
                }
            }
        );

    apRolloverConfirmation.$inject = ['rolloversService'];
    function apRolloverConfirmation(rolloversService)
    {
        var vm = this;
    }

})();
