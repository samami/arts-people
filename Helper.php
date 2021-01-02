<?php
namespace ArtsPeople\Rollovers;

use Artspeople\Core\DB as APDB;
use ArtsPeople\Purchases;

/**
 * Actions related to Subscription Rollovers
 */
class Helper
{
    public static function completeRollover($mappingId)
    {
        $detailCount = APDB::QueryArray(
            "SELECT * FROM tickets.count_processed($1)",
            [$mappingId]
        );

        if (($detailCount['complete'] + $detailCount['error']) == $detailCount['total']) {
            APDB::Query(
                "UPDATE tickets.rollover
                SET date_completed = now()
                WHERE mapping_id = $1",
                [$mappingId]
            );
        }
    }//end completeRollover()

    /**
     * Inserts data to db for individual patrons
     * @param  integer $theatreId A theatre ID
     */
    public static function createRolloverTickets($theatreId)
    {
        if (empty((integer) $theatreId)) {
            throw new TheatreException;
        }

        $rolloverData = self::getRolloverData($theatreId);

        foreach ($rolloverData['saved']['packages'] as $packagePrior => $packageNew) {
            if (0 == $packageNew) continue;

            foreach ($rolloverData['saved']['series'] as $seriesPrior => $seriesNew) {
                if (0 == $seriesNew) continue;

                foreach ($rolloverData['saved']['personTypes'] as $personTypesPrior => $personTypesNew) {
                    if (0 == $personTypesNew) continue;

                    $personTypeName = APDB::QueryOne(
                        "SELECT person_type FROM t_person_type WHERE person_type_id = $1",
                        [$personTypesPrior]
                    );

                    foreach ($rolloverData['saved']['venues'] as $venuePrior => $venueNewArray) {
                        foreach ($venueNewArray as $venueNew) {
                            if (0 == $venueNew) continue;

                            self::createRolloverTicketsFromSeatMap(
                                $venuePrior
                                , $venueNew
                                , $packagePrior
                                , $packageNew
                                , $seriesPrior
                                , $seriesNew
                                , $personTypesPrior
                                , $personTypesNew
                                , $personTypeName
                                , $rolloverData['saved']['mappingId']
                            );

                            foreach ($rolloverData['saved']['gaSections'] as $gaSectionPrior => $gaSectionNew) {
                                if (!is_null($gaSectionNew) && 0 == $gaSectionNew) continue;

                                self::createRolloverTicketsFromPackageInstance(
                                    $venuePrior
                                    , $venueNew
                                    , $packagePrior
                                    , $packageNew
                                    , $seriesPrior
                                    , $seriesNew
                                    , $personTypesPrior
                                    , $personTypesNew
                                    , $personTypeName
                                    , '' . $gaSectionPrior
                                    , '' . $gaSectionNew
                                    , $rolloverData['saved']['mappingId']
                                );
                            }
                        }
                    }
                }
            }
        }

        foreach ($rolloverData['saved']['series'] as $seriesPrior => $seriesNew) {
            if ($seriesNew != 0) {
                //Add tickets that are in the sub seat map but are missing either package or person type
                $q = <<<'SQL'
                    INSERT INTO tickets.rollover_tickets(
                        person_id 
                        ,prior_venue_id
                        ,prior_seat_no
                        ,new_venue_id
                        ,new_seat_no
                        ,prior_series_id
                        ,new_series_id
                        ,mapping_id)
                    SELECT
                        ssm.person_id
                        ,ssm.venue_id
                        ,ssm.seat_no
                        ,ssm.venue_id
                        ,ssm.seat_no
                        ,$1
                        ,$2
                        ,$3
                    FROM t_subscription_seat_map ssm
                    WHERE (ssm.package_id IS NULL OR ssm.person_type IS NULL)
                        AND ssm.series_id = $1
SQL;
                APDB::Query(
                    $q,
                    [
                        $seriesPrior
                        , (int) $seriesNew
                        , $rolloverData['saved']['mappingId']
                    ]
                );
            }
        }
    }//end createRolloverTickets()

    public static function createRolloverTicketsFromSeatMap(
        int $venuePrior
        , int $venueNew
        , int $packagePrior
        , int $packageNew
        , int $seriesPrior
        , int $seriesNew
        , int $personTypesPrior
        , int $personTypesNew
        , string $personTypeName
        , int $mappingId
    ): void
    {
        //Add tickets from subscription seat map that match person type, series, and package
        $q = <<<'SQL'
        INSERT INTO tickets.rollover_tickets(
            person_id 
            ,prior_venue_id
            ,prior_seat_no
            ,new_venue_id
            ,new_seat_no
            ,prior_package_id
            ,new_package_id
            ,prior_series_id
            ,new_series_id
            ,prior_person_type_id
            ,new_person_type_id
            ,mapping_id)
        SELECT
            ssm.person_id
            ,ssm.venue_id
            ,ssm.seat_no
            ,$1
            ,ssm.seat_no
            ,$2
            ,$3
            ,$4
            ,$5
            ,$6
            ,$7
            ,$8
        FROM
            public.t_subscription_seat_map ssm
        INNER JOIN
            public.t_purchase purchase ON ssm.reference_purchase_id = purchase.purchase_id
        WHERE
            ssm.package_id = $2
            AND ssm.series_id = $4
            AND ssm.person_type = $9
            AND ssm.venue_id = $10
            AND purchase.purchase_type != 'hold'
            AND purchase.purchase_type IS NOT NULL
SQL;
        APDB::Query(
            $q,
            [
                $venueNew
                , $packagePrior
                , $packageNew
                , $seriesPrior
                , $seriesNew
                , $personTypesPrior
                , $personTypesNew
                , $mappingId
                , $personTypeName
                , $venuePrior
            ]
        );
    }

    public static function createRolloverTicketsFromPackageInstance(
        int $venuePrior
        , int $venueNew
        , int $packagePrior
        , int $packageNew
        , int $seriesPrior
        , int $seriesNew
        , int $personTypesPrior
        , int $personTypesNew
        , string $personTypeName
        , ?string $gaSectionPrior
        , ?string $gaSectionNew                        
        , int $mappingId
    ): int
    {
        //Add tickets from jt_package_instance that aren't in sub seat map. Example would be tickets that made a package but weren't bought through sub buy path, or GA tickets.
        
        $q = <<<'SQL'
        WITH rows AS (
            INSERT INTO tickets.rollover_tickets (
                person_id 
                ,prior_package_id
                ,prior_venue_id
                ,prior_series_id
                ,prior_ga_section_pricing_name
                ,prior_person_type_id

                ,new_venue_id
                ,new_package_id
                ,new_series_id
                ,new_ga_section_pricing_name
                ,new_person_type_id

                ,mapping_id)
            SELECT
                pur.person_id
                ,pi.package_id
                ,perf.venue_id
                ,pi.series_id
                ,ga.pricing_section
                ,$1

                ,$2
                ,$3
                ,$4
                ,$5
                ,$6

                ,$7
            FROM
                jt_package_instance pi
            INNER JOIN
                t_purchase pur USING (purchase_id)
            INNER JOIN
                t_ticket t USING (jt_package_instance_id, purchase_id)
            INNER JOIN
                t_performance perf USING (performance_id)
            INNER JOIN
                t_show show USING (show_id)
            LEFT JOIN
                t_general_admission_section ga USING (general_admission_section_id)
            WHERE
                pi.package_id = $8
                AND pi.series_id = $9
                AND t.seat_no IS NULL
                AND t.person_type = $11
                AND perf.venue_id = $12
                AND show.general_admission = 1
                AND pur.purchase_type != 'hold'
                AND pur.purchase_type IS NOT NULL
                AND pur.person_id IS NOT NULL
                AND (
                    CASE WHEN
                        COALESCE($10::text, '') = ''
                    THEN
                        ga.general_admission_section_id IS NULL
                    ELSE
                        ga.pricing_section = $10
                    END
                )

                /* Exclude anyone who already has a package instance in the new season */
                AND NOT EXISTS (
                    SELECT
                        spi.jt_package_instance_id
                    FROM
                        jt_package_instance spi
                    INNER JOIN
                        t_purchase sp USING (purchase_id)
                    INNER JOIN
                        t_package spp USING (package_id)
                    WHERE
                        sp.person_id = pur.person_id
                        AND spp.season_start = (
                            SELECT
                                season_start
                            FROM
                                t_package
                            WHERE
                                package_id = $3
                        )
                        AND sp.purchase_type IS NOT NULL
                )
            GROUP BY
                t.jt_package_instance_id
                , pur.person_id
                , pi.package_id
                , pi.series_id
                , t.person_type
                , perf.venue_id
                , ga.pricing_section
            RETURNING
                1
        )
        SELECT COUNT(1) FROM rows;
SQL;
        $affected = APDB::QueryOne(
            $q,
            [
                $personTypesPrior

                , $venueNew
                , $packageNew
                , $seriesNew
                , $gaSectionNew
                , $personTypesNew

                , $mappingId
                
                , $packagePrior
                , $seriesPrior
                , $gaSectionPrior
                , $personTypeName
                , $venuePrior
            ]
        );

        return $affected;
    }

    /**
     * Creates and returns a rollover id for subscription rollovers
     * @param  string JSON string that represents $rolloverMapping A mapping object
     * @return integer Mapping ID
     */
    public static function createRolloverMapping($rolloverMapping)
    {
        if ($rolloverMapping->mappingId) {
            self::deleteRollover($rolloverMapping->mappingId);
        }

        $mappingId = APDB::QueryOne(<<<'SQL'
            INSERT INTO
                tickets.rollover
                (prior_season_id, new_season_id, theatre_id)
            VALUES
                ($1, $2, $3)
            RETURNING mapping_id
SQL
            , [$rolloverMapping->season->prior, $rolloverMapping->season->new, $rolloverMapping->theatreId]
        );
        
        return (int) $mappingId;
    }//end createRolloverMapping()

    /**
     * Deletes a rollover mapping from the DB
     * @param  integer $mappingId A mapping ID
     * @return string Response from a query when applicable
     */
    public static function deleteRollover($mappingId)
    {
        $response = APDB::Query(<<<'SQL'
            DELETE FROM tickets.rollover
            WHERE mapping_id = $1
SQL
            , [$mappingId]
        );
        return $response;
    }//end deleteRollover()

    private static function enqueueRolloverSet($mappingId)
    {
        $query = <<<'SQL'
            WITH rollover_set AS (
                SELECT 
                    details.person_id
                    ,details.new_package_id
                    ,details.new_series_id
                FROM tickets.rollover_tickets details
                WHERE mapping_id = $1
                    AND details.status = 'not_started'
                GROUP BY 
                    details.person_id
                    ,details.new_package_id
                    ,details.new_series_id
                LIMIT 50
            ), updt AS (
                UPDATE tickets.rollover_tickets rd
                SET status = 'in_process'
                FROM rollover_set
                WHERE (
                    rd.person_id = rollover_set.person_id
                    AND rd.new_package_id = rollover_set.new_package_id
                    AND rd.new_series_id = rollover_set.new_series_id
                    )
                    AND mapping_id = $1
                    AND rd.status = 'not_started'
            )
            SELECT * FROM rollover_set
SQL;
        $rolloverDetails = APDB::QueryAll($query, [$mappingId]);
        if ($rolloverDetails) {
            return $rolloverDetails;
        } else {
            return [];
        }
    }

    public static function getActiveRollover($theatreId)
    {
        $mappingId = APDB::QueryOne(<<<'SQL'
            SELECT mapping_id
            FROM tickets.rollover
            WHERE date_completed IS NULL
                AND theatre_id = $1
            ORDER BY mapping_id DESC
SQL
            , [$theatreId]
        );
        return $mappingId;
    }//end getActiveRollover()

    public static function getConfirmationDetails($mappingId)
    {
        $query = <<<'SQL'
            SELECT
                det.person_id
                , coalesce(per.first_name || ' ' || per.last_name, per.org_name) as name
                , log.message
                , log.error_message
                , log.date_time
            FROM
                tickets.rollover_log log
            INNER JOIN
                tickets.rollover_tickets det ON det.purchase_id = log.purchase_id
            INNER JOIN
                public.t_person per ON per.person_id = det.person_id
            WHERE
                log.mapping_id = $1
                AND COALESCE(log.error_message, '') != ''
            GROUP BY
                det.person_id
                , per.last_name
                , per.first_name
                , per.org_name
                , log.message
                , log.error_message
                , log.date_time
            ORDER BY 
                log.date_time ASC
SQL;

        $data['errors'] = APDB::QueryAll($query, [$mappingId]);

        $data['detailCount'] = APDB::QueryArray(
            'SELECT * FROM tickets.count_processed($1)',
            [$mappingId]
        );

        return $data;
    }//end getConfirmationDetails

    public static function getNextQueuedRollover()
    {
        $nextInQueue = APDB::QueryOne(<<<'SQL'
            SELECT mapping_id
            FROM tickets.rollover map
            WHERE date_completed IS NULL
                AND subscription_batch_id IS NOT NULL
            ORDER BY subscription_batch_id ASC
            LIMIT 1
SQL
            , []
        );

        return $nextInQueue;
    }//end getNextQueuedMapping()

    /**
     * Returns data sufficient to map subscription data across seasons
     * @param  integer $theatreId A theatre ID
     * @return array Array of rollover data with seasons, personTypes, and saved keys
     */
    public static function getRolloverData($theatreId)
    {
        if (empty((integer) $theatreId)) {
            throw new TheatreException;
        }
        $rollover = [];
        $theatreSeasons = self::getRolloverSeasons($theatreId);
        $mappingId = self::getActiveRollover($theatreId);
        $rolloverMapping = self::getRolloverMapping($mappingId);
        $rollover = array_merge($theatreSeasons, $rolloverMapping);

        return $rollover;
    }//end getRolloverData()

    public static function getRolloverSeasons($theatreId)
    {
        $rollover = [];
        $rollover['theatreId'] = $theatreId;

        //Get list of seasons
        $rollover['seasons'] = APDB::QueryAll(<<<'SQL'
            SELECT
                season_id AS id
                ,season_start AS start
                ,season_name AS name
            FROM l_season
            WHERE theatre_id = $1
            ORDER BY season_start DESC
SQL
            , [$theatreId]
        );

        //Get list of person Types
        $rollover['personTypes'] = APDB::QueryAll(<<<'SQL'
            SELECT
                person_type_id AS id
                ,person_type AS name
            FROM t_person_type
            WHERE theatre_id = $1
            ORDER BY seq ASC
SQL
            , [$theatreId]
        );

        foreach ($rollover['seasons'] as &$season) {
            //Get list of packages for each season
            $season['packages'] = APDB::QueryAll(<<<'SQL'
                SELECT
                    package_id AS id
                    ,package_name AS name
                    ,CASE
                        WHEN 1 = (
                            SELECT MAX(general_admission) 
                            FROM t_show show 
                                INNER JOIN t_package_show pkg ON pkg.show_id = show.show_id 
                            WHERE package_id = t_package.package_id) THEN true
                        ELSE false
                    END AS has_ga
                FROM t_package
                WHERE season_start = $1
                    AND theatre_id = $2
                    AND number_of_shows > 1
                ORDER BY package_name ASC
SQL
                , [
                    $season['start'],
                    $theatreId
                ]
            );
            if (!$season['packages']) {
                $season['packages'] = [];
            }

            //Get list of series for each season
            $season['series'] = APDB::QueryAll(<<<'SQL'
                SELECT
                    series_id AS id
                    ,series_name AS name
                FROM t_series
                WHERE season_start = $1
                    AND theatre_id = $2
                ORDER BY seq ASC
SQL
                , [
                    $season['start']
                    ,$theatreId
                ]
            );
            if (!$season['series']) {
                $season['series'] = [];
            }
            foreach ($season['series'] as &$series) {
                $q = <<<'SQL'
                    SELECT performance_id AS id
                    FROM public.jt_performance_series
                    WHERE series_id = $1
SQL;
                $series['performances'] = APDB::QueryAll($q, [$series['id']]);
            }

            //Get list of used venues for each season
            $season['venues'] = APDB::QueryAll(<<<'SQL'
                SELECT DISTINCT 
                    ven.venue_id AS id
                    ,ven.reference_venue_name AS name
                FROM t_venue AS ven
                    INNER JOIN t_performance AS perf ON perf.venue_id = ven.venue_id
                    INNER JOIN t_show AS show ON show.show_id = perf.show_id
                WHERE show.season_start = $1
                    AND ven.theatre_id = $2
                    AND (ven.inactive = 0 OR ven.inactive IS NULL)
            ORDER BY ven.reference_venue_name ASC
SQL
                , [
                    $season['start']
                    ,$theatreId
                ]
            );
            if (!$season['venues']) {
                $season['venues'] = [];
            }

            // Get list of GA sections for each season
            $season['gaSections'] = APDB::QueryAll(<<<'SQL'
                SELECT
                    NULL AS id
                    , '(none)' AS name
                UNION ALL
                SELECT DISTINCT 
                    t_general_admission_section.pricing_section AS id
                    , t_general_admission_section.section_name AS name
                FROM
                    t_general_admission_section
                INNER JOIN
                    t_performance USING (performance_id)
                INNER JOIN
                    t_show USING (show_id)
                WHERE t_show.season_start = $1
                    AND t_show.theatre_id = $2
                    AND 'f' = t_show.inactive
                ORDER BY
                    name ASC
SQL
                , [
                    $season['start']
                    , $theatreId
                ]
            );

            if (empty($season['gaSections'])) {
                $season['gaSections'] = [];
            }
        }
        return $rollover;
    }//end getRolloverSeasons()

    /**
     * Retrieves data from db for individual patrons
     * @param  integer $theatreId A theatre ID
     * @return array Array of rollover details with name, seat_no, package, series, and person type names
     */
    public static function getRolloverTickets($theatreId)
    {
        if (empty((integer) $theatreId)) {
            throw new TheatreException;
        }

        $mappingId = self::getActiveRollover($theatreId);

        $rolloverDetails = [];
        $rolloverDetails = APDB::QueryAll(<<<'SQL'
            SELECT 
                COALESCE(first_name || ' ' || last_name, org_name) AS name
                ,details.person_id
                ,details.details_id
                ,details.status

                ,details.prior_seat_no
                ,details.prior_package_id
                ,prior_pkg.package_name AS prior_package_name
                ,details.prior_series_id
                ,prior_ser.series_name AS prior_series_name
                ,details.prior_person_type_id
                ,prior_pt.person_type AS prior_person_type
                ,details.prior_venue_id AS prior_venue_id
                ,prior_ven.reference_venue_name AS prior_venue_name
                ,details.prior_ga_section_pricing_name
                
                ,details.new_seat_no
                ,details.new_package_id
                ,new_pkg.package_name AS new_package_name
                ,details.new_series_id
                ,new_ser.series_name AS new_series_name
                ,(SELECT
                    MIN(COALESCE(t_show.general_admission, 0))
                FROM
                    public.jt_performance_series
                INNER JOIN
                    public.t_performance USING (performance_id)
                INNER JOIN
                    public.t_show USING (show_id)
                WHERE
                    jt_performance_series.series_id = details.new_series_id
                ) AS all_ga
                ,(SELECT
                    MAX(COALESCE(t_show.general_admission, 0))
                FROM
                    public.jt_performance_series
                INNER JOIN
                    public.t_performance USING (performance_id)
                INNER JOIN
                    public.t_show USING (show_id)
                WHERE
                    jt_performance_series.series_id = details.new_series_id
                ) AS any_ga
                ,details.new_person_type_id
                ,new_pt.person_type AS new_person_type
                ,details.new_venue_id AS new_venue_id
                ,new_ven.reference_venue_name AS new_venue_name
                ,details.new_ga_section_pricing_name
            FROM tickets.rollover_tickets details
                INNER JOIN public.t_person per ON per.person_id = details.person_id
                LEFT JOIN public.t_package new_pkg ON new_pkg.package_id = details.new_package_id
                LEFT JOIN public.t_series new_ser ON new_ser.series_id = details.new_series_id
                LEFT JOIN public.t_person_type new_pt ON new_pt.person_type_id = details.new_person_type_id
                LEFT JOIN public.t_venue new_ven ON new_ven.venue_id = details.new_venue_id
                LEFT JOIN public.t_package prior_pkg ON prior_pkg.package_id = details.prior_package_id
                LEFT JOIN public.t_series prior_ser ON prior_ser.series_id = details.prior_series_id
                LEFT JOIN public.t_person_type prior_pt ON prior_pt.person_type_id = details.prior_person_type_id
                LEFT JOIN public.t_venue prior_ven ON prior_ven.venue_id = details.prior_venue_id
            WHERE mapping_id = $1
SQL
            , [$mappingId]
        );

        if (!$rolloverDetails) {
            $rolloverDetails = [];
        }

        foreach ($rolloverDetails as &$row) {
            $row['conflicts'] = [];
        }

        return $rolloverDetails;
    }//end getRolloverTickets()

    /**
     * Returns previous & current subscription rollovers
     * @param  integer $theatreId A theatre ID
     * @return array Array of rollover history
     */
    public static function getRolloversHistory($theatreId)
    {
        $history = APDB::QueryAll(<<<'SQL'
            SELECT
                mapping_id
                ,date_completed::date AS date_completed
                ,season_name
                ,(tickets.count_processed(map.mapping_id)).*
                ,subscription_batch_id
            FROM tickets.rollover map
                INNER JOIN public.l_season season ON map.new_season_id = season.season_id
            WHERE map.theatre_id = $1
            ORDER BY date_completed ASC
SQL
            , [$theatreId]
        );

        $detailCount = APDB::QueryArray(
            "SELECT * FROM tickets.count_processed($1)",
            [$history['mapping_id']]
        );

        if (!$history) {
            return [];
        } else {
            return $history;
        }
    }//end getRolloverHistory()

    private function getSeatsDetail($personId, $seriesId, $mappingId)
    {
        $query = <<<'SQL'
SELECT
    ps.performance_id AS performance_id
    ,perf.date_time::DATE
    ,(SELECT person_type FROM t_person_type WHERE person_type_id = details.new_person_type_id) AS person_type
    ,CASE
        WHEN show.general_admission = 1 THEN 'ga'
        WHEN show.general_admission = 0 THEN 'assigned' 
    END AS seating_type
    ,details.new_seat_no AS seat_number
    ,null AS is_wheelchair
    ,details.new_ga_section_pricing_name AS ga_section
    ,(
        SELECT
            gs.general_admission_section_id
        FROM
            t_general_admission_section gs
        WHERE
            gs.performance_id = ps.performance_id
            AND gs.pricing_section = details.new_ga_section_pricing_name
        LIMIT 1
    ) AS ga_section_id
    ,details.details_id
FROM
    tickets.rollover_tickets details
    INNER JOIN jt_performance_series ps ON ps.series_id = details.new_series_id
    INNER JOIN t_performance perf ON perf.performance_id = ps.performance_id AND details.new_venue_id = perf.venue_id
    INNER JOIN t_show show ON show.show_id = perf.show_id
WHERE
    details.person_id = $1
    AND ps.series_id = $2
    AND details.mapping_id = $3
    AND CASE WHEN show.general_admission = 1 THEN details.new_seat_no IS NULL ELSE details.new_seat_no IS NOT NULL END
    AND details.status = 'in_process'
    ORDER BY perf.date_time DESC 
SQL;

        $seatsDetail = APDB::QueryAll($query,
            [
                $personId
                ,$seriesId
                ,$mappingId
            ]
        );
        return $seatsDetail;
    }

    /**
     * Returns an array of sold seats
     * @param  integer $theatreId A theatre ID
     * @return array 2D array of seats in a venue
     */
    public static function getSeatsInVenue($theatreId)
    {
        
        $mappingId = self::getActiveRollover($theatreId);
        $activeMapping = self::getRolloverMapping($mappingId);
        $venueSeats = APDB::QueryAll(<<<'SQL'
            SELECT
                seat.seat_no,
                ven.venue_id,
                perfser.series_id
            FROM public.t_seat seat
                INNER JOIN public.t_venue ven ON ven.venue_id = seat.venue_id
                INNER JOIN public.t_performance perf ON perf.venue_id = seat.venue_id
                INNER JOIN public.jt_performance_series perfser ON perfser.performance_id = perf.performance_id
                INNER JOIN public.t_show show ON show.show_id = perf.show_id
                INNER JOIN public.l_season season ON season.season_start = show.season_start
            WHERE ven.theatre_id = $1
                AND season.season_id = $2
SQL
            , [
                $theatreId,
                $activeMapping['saved']['season']['new']
            ]
        );

        if (!$venueSeats && pg_last_error() == '') {
            $venueSeats = [];
        }

        return $venueSeats;
    }//end getSeatsInVenue()

    /**
     * Returns an array of sold seats
     * @param  integer $theatreId A theatre ID
     * @return array 2D array of sold seats from season corresponding to active rollover
     */
    public static function getSoldSeats($theatreId)
    {
        $mappingId = self::getActiveRollover($theatreId);
        $activeMapping = self::getRolloverMapping($mappingId);
        $soldSeats = APDB::QueryAll(<<<'SQL'
            SELECT
                inv.performance_id,
                inv.seat_no,
                perfser.series_id,
                txn.order_id,
                COALESCE(show.general_admission, 0) AS ga
            FROM arts_people.t_inventory inv
                INNER JOIN arts_people.t1_item item ON item.item_id = inv.item_id
                INNER JOIN arts_people.t_transaction txn ON txn.transaction_id = item.transaction_id
                INNER JOIN public.jt_performance_series perfser ON perfser.performance_id = inv.performance_id
                INNER JOIN public.t_performance perf ON perf.performance_id = inv.performance_id
                INNER JOIN public.t_show show ON show.show_id = perf.show_id
                INNER JOIN public.l_season season ON season.season_start = show.season_start
            WHERE show.theatre_id = $1
                AND season.season_id = $2
SQL
            , [
                $theatreId,
                $activeMapping['saved']['season']['new']
            ]
        );

        return $soldSeats;
    }//end getSoldSeats()

    public static function hasMappingAccess($mappingId, $theatreId)
    {
        $query = <<<'SQL'
            SELECT theatre_id
            FROM tickets.rollover
            WHERE mapping_id = $1
SQL;

        $theatreIdToCheck = APDB::QueryOne($query, [$mappingId]);
        if ($theatreId == $theatreIdToCheck) {
            return true;
        } else {
            return false;
        }
    }

    public static function processRolloverQueue($mappingId)
    {
        //Get rollover info
        $rolloverToProcess = APDB::QueryArray(<<<'SQL'
            SELECT
                mapping_id,
                map.theatre_id,
                subscription_batch_id,
                admin_user_id
            FROM tickets.rollover map
                INNER JOIN public.t_subscription_batch batch ON batch.batch_id = map.subscription_batch_id
            WHERE mapping_id = $1
SQL
            , [$mappingId]
        );

        //Increment attempt counter
        //Method to exit out of rollovers that are failing or taking too long
        $detailsInProcess = APDB::QueryAll(<<<'SQL'
            UPDATE tickets.rollover_tickets details
            SET attempts = attempts + 1
            WHERE details.status = 'in_process'
                AND mapping_id = $1
            RETURNING details.mapping_id, details.details_id, details.attempts
SQL
            , [
                $mappingId
            ]
        );

        $tooManyAttempts = false;
        if ($detailsInProcess) {
            foreach ($detailsInProcess as $detail) {
                if ($detail['attempts'] > 2) {
                    $tooManyAttempts = true;
                    break;
                }
            }
        }

        if ($tooManyAttempts) {
            APDB::Query(<<<'SQL'
                UPDATE tickets.rollover_tickets details
                SET status = 'error'
                WHERE attempts > 2
                    AND mapping_id = $1
SQL
                , [
                    $rolloverToProcess['mapping_id']
                ]
            );
        }

        //Pre-process "removed" rollovers
        $q = <<<'SQL'
            UPDATE tickets.rollover_tickets
            SET status = 'complete'
            WHERE mapping_id = $1
                AND rollover_tickets.status = 'inactive'
            RETURNING details_id, person_id, prior_venue_id, prior_seat_no
SQL;
        $removedRollovers = APDB::QueryAll($q, [$mappingId]);
        if ($removedRollovers) {
            foreach ($removedRollovers as $removed) {
                Log::Create([
                    'messageType' => 'SKIPPED',
                    'message' => 'Person Id ' .$removed['person_id']. ' Prior Venue Id ' .$removed['prior_venue_id']. ' Prior Seat Number ' . $removed['prior_seat_no'],
                    'mappingId' => $mappingId,
                    'detailsId' => $removed['details_id']
                ]);
            }
        }

        //Prepare up to 50 reservations to process
        //Let us know they've been started in case the next 50 start going before this batch is done
        $rolloverDetails = self::enqueueRolloverSet($mappingId);

        //Process Rollovers
        if ($rolloverDetails) {
            //Add seats detail and process
            foreach ($rolloverDetails as &$row) {
                $reminderDate = APDB::QueryOne(<<<'SQL'
                    SELECT t1.reminder_date::DATE 
                    FROM (
                        SELECT tp.date_time AT TIME ZONE tt.time_zone AS reminder_date FROM jt_performance_series jps
                        JOIN t_performance tp ON tp.performance_id = jps.performance_id
                        JOIN t_show ON tp.show_id = t_show.show_id
                        JOIN t_theatre tt ON t_show.theatre_id = tt.theatre_id
                        WHERE jps.series_id = $1
                        ORDER BY tp.date_time ASC LIMIT 1
                    ) t1
SQL
                    , [$row['new_series_id']]
                );
                $row['seatsDetail'] = self::getSeatsDetail($row['person_id'] ,$row['new_series_id'], $mappingId);
                $purchaseId = Purchases\LegacyHelper::createPurchaseId($rolloverToProcess['theatre_id'], $row['person_id']);
                if ($purchaseId) {
                    Log::Create([
                        'purchaseId' => $purchaseId,
                        'messageType' => 'CREATE PURCHASE',
                        'message' => 'Purchase Id ' .$purchaseId. ', Person Id ' .$row['person_id'],
                        'mappingId' => $mappingId
                    ]);
                    APDB::Query('UPDATE t_purchase SET dont_convert = true WHERE purchase_id = $1', [$purchaseId]);

                    try {
                        Purchases\LegacyHelper::addSubscriptionSeriesToPurchase(
                            $rolloverToProcess['theatre_id'],
                            $purchaseId,
                            $row['person_id'],
                            $rolloverToProcess['admin_user_id'],
                            $row['new_package_id'],
                            $row['new_series_id'],
                            $mappingId,
                            $row['seatsDetail']
                        );
                        $logInfo = Purchases\LegacyHelper::putTicketPurchaseOnReserve($purchaseId, $reminderDate, $mappingId, $rolloverToProcess['admin_user_id']);
                        Log::Create($logInfo);
                        //Mark purchase in batch
                        APDB::Query(<<<'SQL'
                            UPDATE t_purchase
                            SET subscription_batch_id = $1
                            WHERE purchase_id = $2
                            RETURNING purchase_id
SQL
                            , [$rolloverToProcess['subscription_batch_id'], $purchaseId]
                        );

                    } catch (Purchases\ExceptionLegacyHelper $e) {
                        //We don't finish reserving the tickets, so it will silently be removed by the often job that cleans up incomplete orders.
                        Log::Create([
                            'purchaseId' => $purchaseId,
                            'messageType' => 'PURCHASE FAILED',
                            'message' => $e->getMessage(),
                            'mappingId' => $mappingId,
                            'errorMsg' => $e->getMessage()
                        ]);
                    }
                } else {
                    Log::Create([
                        'purchaseId' => $purchaseId,
                        'messageType' => 'CREATE PURCHASE',
                        'message' => 'Purchase Id Purchase Failed, Person Id ' .$row['person_id'],
                        'mappingId' => $mappingId
                    ]);
                }
            }
        }
    }//end processRolloverQueue()

    public static function queueRollover($theatreId, $adminUserId)
    {
        $mappingId = self::getActiveRollover($theatreId);

        $detailCount = APDB::QueryArray(
            "SELECT * FROM tickets.count_processed($1)",
            [$mappingId]
        );

        $subscriptionBatchId = APDB::QueryOne(<<<'SQL'
            INSERT INTO t_subscription_batch (
                theatre_id,
                batch_name,
                admin_user_id)
            VALUES ($1, $2, $3)
            RETURNING batch_id
SQL
            , [
                $theatreId,
                'Rollover' . date('Y-m-d-H:i'),
                $adminUserId
            ]
        );
        if (($detailCount['not_started'] + $detailCount['inactive']) == $detailCount['total']) {
            APDB::Query(<<<'SQL'
                UPDATE tickets.rollover
                SET subscription_batch_id = $1
                WHERE mapping_id = $2
SQL
                , [
                    $subscriptionBatchId,
                    $mappingId
                ]
            );
        }
    }//end queueRollover()

    public static function saveRolloverMapping($rolloverMapping)
    {
        $mappingId = self::createRolloverMapping($rolloverMapping);
        
        foreach ($rolloverMapping->packages as $prior => $new) {
            APDB::Query(<<<'SQL'
                INSERT INTO tickets.rollover_packages (prior_package_id, new_package_id, mapping_id)
                VALUES ($1, $2, $3)
SQL
                , [$prior, $new, $mappingId]
            );
        }
        foreach ($rolloverMapping->series as $prior => $new) {
            APDB::Query(<<<'SQL'
                INSERT INTO tickets.rollover_series (prior_series_id, new_series_id, mapping_id)
                VALUES ($1, $2, $3)
SQL
                , [$prior, $new, $mappingId]
            );
        }
        foreach ($rolloverMapping->venues as $prior => $new) {
            foreach ($new as $venue) {
                APDB::Query(<<<'SQL'
                    INSERT INTO tickets.rollover_venues (prior_venue_id, new_venue_id, mapping_id)
                    VALUES ($1, $2, $3)
SQL
                    , [$prior, $venue, $mappingId]
                );
            }
        }
        foreach ($rolloverMapping->personTypes as $prior => $new) {
            APDB::Query(<<<'SQL'
                INSERT INTO tickets.rollover_person_types (prior_person_type_id, new_person_type_id, mapping_id)
                VALUES ($1, $2, $3)
SQL
                , [$prior, $new, $mappingId]
            );
        }
        foreach ($rolloverMapping->gaSections as $prior => $new) {
            APDB::Query(<<<'SQL'
                INSERT INTO tickets.rollover_ga_sections (prior_ga_section_pricing_name, new_ga_section_pricing_name, mapping_id)
                VALUES ($1, $2, $3)
SQL
                , [$prior, $new, $mappingId]
            );
        }

        return (int) $mappingId;
    }//end saveRolloverMapping()

    /**
     * Retrieves data from db for individual patrons
     * @param  string JSON string that represents $rolloverDetail details to be updated
     * @return array Array of rollover details with name, seat_no, package, series, and person type names
     */
    public static function updateRolloverTicket($rolloverDetail)
    {
        $rolloverDetail = APDB::Query(<<<'SQL'
            UPDATE
                tickets.rollover_tickets
            SET
                new_seat_no = $1
                ,new_package_id = $2
                ,new_series_id = $3
                ,new_ga_section_pricing_name = $4
                ,new_person_type_id = $5
                ,new_venue_id = $6
                ,status = $7
            WHERE tickets.rollover_tickets.details_id = $8
SQL
            , [
                $rolloverDetail->newSeatNo,
                $rolloverDetail->packageId,
                $rolloverDetail->seriesId,
                $rolloverDetail->gaSectionPricingName,
                $rolloverDetail->personTypeId,
                $rolloverDetail->venueId,
                $rolloverDetail->status,
                $rolloverDetail->detailId
            ]
        );

        return $rolloverDetail;
    }//end updateRolloverTicket()

    private static function getRolloverMapping($mappingId)
    {
        $rollover['saved'] = [];
        $rollover['saved']['season']['prior'] = null;
        $rollover['saved']['season']['new'] = null;
        $rollover['saved']['mappingId'] = null;

        if ($mappingId) {
            $seasonMapping = APDB::QueryArray(<<<'SQL'
                SELECT prior_season_id, new_season_id
                FROM tickets.rollover
                WHERE mapping_id = $1
SQL
                , [$mappingId]
            );
            $rollover['saved']['season']['prior'] = $seasonMapping['prior_season_id'];
            $rollover['saved']['season']['new'] = $seasonMapping['new_season_id'];
            $rollover['saved']['mappingId'] = $mappingId;

            $packageMapping = APDB::QueryAll(<<<'SQL'
                SELECT prior_package_id, new_package_id
                FROM tickets.rollover_packages
                WHERE mapping_id = $1
SQL
                , [$mappingId]
            );
            foreach ($packageMapping as $package) {
                $rollover['saved']['packages'][$package['prior_package_id']] = $package['new_package_id'];
            }

            $seriesMapping = APDB::QueryAll(<<<'SQL'
                SELECT prior_series_id, new_series_id
                FROM tickets.rollover_series
                WHERE mapping_id = $1
SQL
                , [$mappingId]
            );
            foreach ($seriesMapping as $series) {
                $rollover['saved']['series'][$series['prior_series_id']] = $series['new_series_id'];
            }

            $venueMapping = APDB::QueryAll(<<<'SQL'
                SELECT prior_venue_id, new_venue_id
                FROM tickets.rollover_venues
                WHERE mapping_id = $1
SQL
                , [$mappingId]
            );
            foreach ($venueMapping as $venue) {
                $rollover['saved']['venues'][$venue['prior_venue_id']][] = $venue['new_venue_id'];
            }

            $personTypesMapping = APDB::QueryAll(<<<'SQL'
                SELECT prior_person_type_id, new_person_type_id
                FROM tickets.rollover_person_types
                WHERE mapping_id = $1
SQL
                , [$mappingId]
            );
            foreach ($personTypesMapping as $personTypes) {
                $rollover['saved']['personTypes'][$personTypes['prior_person_type_id']] = $personTypes['new_person_type_id'];
            }

            $rollover['saved']['gaSections'] = [
                null => null
            ];

            $gaSectionsMapping = APDB::QueryAll(<<<'SQL'
                SELECT prior_ga_section_pricing_name, new_ga_section_pricing_name
                FROM tickets.rollover_ga_sections
                WHERE mapping_id = $1
SQL
                , [$mappingId]
            );
            foreach ($gaSectionsMapping as $gaSections) {
                $rollover['saved']['gaSections'][$gaSections['prior_ga_section_pricing_name']] = $gaSections['new_ga_section_pricing_name'];
            }
        }

        return $rollover;
    }//end getRolloverMapping
}//end Helper Class
