(function()
{
    'use strict';

    angular
        .module('arts-people.rollovers')
        .constant('ROLLOVER_STATUS', {
            IN_PROGRESS: 0,
            PROCESSING: 1,
            COMPLETED: 2
        });
})();