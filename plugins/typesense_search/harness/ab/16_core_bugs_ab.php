<?php
// The two core bugs of 15_core_bugs_sql.php, run through both engines.
require __DIR__ . '/fixture.php';
fx_index();
echo "\n-- bug 1\n";
ab('node only', '@@201');
ab('!last + node', '!last10, @@201');
ab('!last + fixed-list field:value', '!last10, country:france');
ab('!last + nodes that match nothing', '!last10, @@203 @@301');
ab('!last + keyword (no node)', '!last10, sunset');
echo "\n-- bug 2\n";
ab('day inside the stored range', 'eventdates:2024-03-05');
ab('the stored start date', 'eventdates:2024-03-01');
ab('year of the stored range', 'eventdates:2024');
ab('day outside the stored range', 'eventdates:2024-03-20');
