(function ()
{
    "use strict";

    angular
        .module( 'arts-people.rollovers' )
        .config( routeConfig );

    /**
     * Routes for Subscription Rollovers
     */
    routeConfig.$inject = ['$stateProvider'];
    function routeConfig($stateProvider)
    {
        var states =
        [
            {
                'name': 'rollovers'
                ,'url': '/rollovers'
                ,'templateUrl': 'rollovers/rollovers.html'
                ,abstract: true
                ,resolve:
                {
                    authorized: ['AdminUser', function(adminUser)
                    {
                        var allowedUserTypes =
                        [
                            'mega_turtle'
                            ,'turtle'
                            ,'primary'
                        ];

                        function authorized(userRole)
                        {
                            return -1 !== allowedUserTypes.indexOf(userRole);
                        }
                        return adminUser.fetchUserType(authorized);
                    }]
                }
            }
            ,{
                'name': 'rollovers.history'
                ,'url': '/history'
                ,views:
                {
                    step: {component: 'apRolloverHistory'}
                }
                ,resolve:
                {
                    history: ['rolloversService', function(rolloversService)
                    {
                        return rolloversService.History();
                    }]
                }
            }
            ,{
                name: 'rollovers.process'
                ,url: '/process'
                ,abstract: true
                ,views:
                {
                    step: {template: '<arts-people-steps class="steps steps-default"></arts-people-steps><ui-view></ui-view>'}
                }
                ,onEnter: onEnterProcess
            }
            ,{
                'name': 'rollovers.process.precheck'
                ,'url': '/precheck'
                ,component: 'apRolloverPrecheck'
                ,onEnter: ['stepService', function(stepService) { stepService.setActive(0); }]
            }
            ,{
                'name': 'rollovers.process.map'
                ,'url': '/map'
                ,component: 'apRolloverMap'
                ,onEnter: ['stepService', function(stepService) { stepService.setActive(1); }]
                ,resolve:
                {
                    rollover: ['rolloversService', function(rolloversService) { return rolloversService.Seasons(); }]
                }
            }
            ,{
                'name': 'rollovers.process.review'
                ,'url': '/review'
                ,'component': 'apRolloverReview'
                ,onEnter: ['stepService', function(stepService) { stepService.setEnabled(1); stepService.setActive(2); }]
                ,resolve:
                {
                    rolloverDetails: ['rolloversService', function(rolloversService) { return rolloversService.Details(); }]
                    ,rollover: ['rolloversService', function(rolloversService) { return rolloversService.Seasons(); }]
                    ,soldSeats: ['rolloversConflictService', function(Service) {return Service.getSoldSeats(); }]
                    ,venueSeats: ['rolloversConflictService', function(Service) {return Service.getSeatsInVenue(); }]
                }
            }
            ,{
                'name': 'rollovers.confirmation'
                ,'url': '/confirmation/{mappingId}'
                ,views:
                {
                    step: {component: 'apRolloverConfirmation'}
                }
                ,resolve:
                {
                    confirmation: ['rolloversService', '$transition$', function(rolloversService, $transition$)
                    {
                        return rolloversService.ConfirmationDetails($transition$.params().mappingId);
                    }]
                }
            }
        ];

        // Loop over the state definitions and register them
        states.forEach(function(state)
        {
            $stateProvider.state(state);
        });

        /**
         * Sets Document Title
         */
        onEnterProcess.$inject = ['$document', 'stepService'];
        function onEnterProcess($document, stepService)
        {
            $document[0].title = 'Subscription Rollovers';
            stepService.init
            (
                [
                    {
                        name: 'Pre-check'
                        ,url: '/admin/app/#/rollovers/process/precheck'
                        ,state: 'active'
                    }
                    ,{
                        name: 'Mapping'
                        ,url: '/admin/app/#/rollovers/process/map'
                        ,state: 'disabled'
                    }
                    ,{
                        name: 'Review'
                        ,url: '/admin/app/#/rollovers/process/review'
                        ,state: 'disabled'
                    }
                ]
            );

            stepService.setActive(0);

            return true;
        }
    };

})();
