<?php

include_once __DIR__ . "/../../include/api_functions.php";

$last_truncate_api_log = get_sysvar('last_truncate_api_log', '1970-01-01');

// No need to run if already run in last 24 hours.
if (time() - strtotime($last_truncate_api_log) < 24 * 60 * 60) {
    if ('cli' == PHP_SAPI) {
        echo " - Skipping truncate_api_log job - last run: " . $last_truncate_api_log . $LINE_END;
    }
} else {
    truncate_api_log();
    set_sysvar("last_truncate_api_log", date("Y-m-d H:i:s"));
}
