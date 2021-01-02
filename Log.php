<?php
namespace ArtsPeople\Rollovers;

use Artspeople\Core\DB;

class Log
{
    public static function create($logArray)
    {
        $logMsg = [
            'purchaseId' => null,
            'messageType' => '',
            'message' => '',
            'mappingId' => null,
            'detailsId' => null,
            'errorMsg' => ''
        ];
        $logMsg = array_merge($logMsg, $logArray);
        $sql = <<<'SQL'
INSERT INTO tickets.rollover_log
    (purchase_id, message_type, message, mapping_id, details_id, error_message)
VALUES
    ($1, $2, $3, $4, $5, $6)
SQL;
        $args = [$logMsg['purchaseId'], $logMsg['messageType'], $logMsg['message'], $logMsg['mappingId'], $logMsg['detailsId'], $logMsg['errorMsg']];
        DB::Query($sql, $args);
    } // end create()
} // end class Log
