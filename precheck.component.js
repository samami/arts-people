(function()
{
    'use strict';

    angular
        .module('arts-people.rollovers')
        .component(
            'apRolloverPrecheck'
            ,{
                'templateUrl': 'rollovers/precheck.html'
                ,controller: apRolloverPrecheck
                ,bindings:{}
            }
        );

    apRolloverPrecheck.$inject = [];
    function apRolloverPrecheck()
    {
        var vm = this;
    }
})();
