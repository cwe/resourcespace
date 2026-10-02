<?php
/**
 * The standard fixture for the A/B harness: a small catalogue chosen to separate behaviours.
 */

require_once __DIR__ . '/fixture_lib.php';

// ----------------------------------------------------------------------------------------- schema
foreach (array(1 => 'Photo', 2 => 'Document', 3 => 'Video', 4 => 'Audio') as $ref => $name) {
    fx_exec('INSERT INTO resource_type (ref, name, order_by) VALUES (?, ?, ?)', array($ref, $name, $ref));
}
fx_field(8, 'title', FIELD_TYPE_TEXT_BOX_SINGLE_LINE);
fx_field(18, 'caption', FIELD_TYPE_TEXT_BOX_MULTI_LINE);
fx_field(12, 'date', FIELD_TYPE_DATE_AND_OPTIONAL_TIME);
fx_field(3, 'country', FIELD_TYPE_DROP_DOWN_LIST);
fx_field(1, 'keywords', FIELD_TYPE_DYNAMIC_KEYWORDS_LIST);
fx_field(73, 'subject', FIELD_TYPE_CATEGORY_TREE);
fx_field(51, 'originalfilename', FIELD_TYPE_TEXT_BOX_SINGLE_LINE, array('partial_index' => 1));
fx_field(90, 'price', FIELD_TYPE_TEXT_BOX_SINGLE_LINE, array('field_constraint' => 1));
fx_field(91, 'eventdates', FIELD_TYPE_DATE_RANGE);
fx_field(92, 'notes', FIELD_TYPE_TEXT_BOX_FORMATTED_AND_TINYMCE);
fx_field(93, 'secret', FIELD_TYPE_TEXT_BOX_SINGLE_LINE);                          // user has f-93
fx_field(94, 'oldfield', FIELD_TYPE_TEXT_BOX_SINGLE_LINE, array('active' => 0));  // inactive
fx_field(95, 'expiry', FIELD_TYPE_EXPIRY_DATE);
fx_field(96, 'altnotes', FIELD_TYPE_TEXT_BOX_MULTI_LINE, array('keywords_index' => 0)); // not indexed

foreach (array(1 => array('admin', 1), 5 => array('tester', 3), 9 => array('other', 3)) as $ref => $u) {
    fx_exec('INSERT INTO user (ref, username, fullname, usergroup) VALUES (?, ?, ?, ?)', array($ref, $u[0], ucfirst($u[0]), $u[1]));
}
fx_exec("INSERT INTO usergroup (ref, name) VALUES (1, 'Admins'), (3, 'General users')");

// ----------------------------------------------------------------------------------------- fixed-list options
$N = array();
$N['france'] = fx_node(3, 'France', null, 201);
$N['uk'] = fx_node(3, 'United Kingdom', null, 202);
$N['spain'] = fx_node(3, 'Spain', null, 203);
$N['germany'] = fx_node(3, '~en:Germany~fr:Allemagne', null, 204);
$N['sculpture'] = fx_node(1, 'sculpture', null, 301);
$N['modernart'] = fx_node(1, 'modern art', null, 302);
$N['landscape'] = fx_node(1, 'landscape', null, 303);
$N['animals'] = fx_node(73, 'Animals', null, 401);
$N['birds'] = fx_node(73, 'Birds', 401, 402);
$N['mammals'] = fx_node(73, 'Mammals', 401, 403);
// Make sure free-text nodes get refs above the fixed-list ones.
fx_exec("INSERT INTO node (ref, resource_type_field, name, order_by) VALUES (1000, 96, 'placeholder', 10)");

// ----------------------------------------------------------------------------------------- resources
$recent = date('Y-m-d H:i:s', strtotime('-10 days'));
$filler = implode(' ', array_fill(0, 75, 'filler text')); // > 500 characters

fx_resource(1, 1, array('hit_count' => 5, 'creation_date' => $recent));
fx_set(1, 8, 'Sunset over the harbour');
fx_set(1, 18, 'A red car parked by the harbour wall at sunset');
fx_set(1, 12, '2024-05-17');
fx_tag(1, $N['france']);
fx_tag(1, $N['sculpture']);
fx_set(1, 51, 'IMG_1234.jpg');
fx_set(1, 90, '100');

fx_resource(2, 1, array('hit_count' => 9));
fx_set(2, 8, 'The red sports car');
fx_set(2, 18, 'Car show with red paint');
fx_set(2, 12, '2024-02-10');
fx_tag(2, $N['uk']);
fx_tag(2, $N['modernart']);
fx_set(2, 51, 'dog_photo-1.jpg');
fx_set(2, 90, '50');

fx_resource(3, 2, array('hit_count' => 1));
fx_set(3, 8, 'Launch party');
fx_set(3, 18, 'Book launch party for foo-bar industries');
fx_set(3, 12, '2023-11-30');
fx_tag(3, $N['spain']);
fx_set(3, 51, 'yorkshireSculpturePark.pdf');
fx_set(3, 90, '12.5');

fx_resource(4, 3, array('hit_count' => 2));
fx_set(4, 8, 'Ship launch');
fx_set(4, 18, 'Foo and bar are separate here');
fx_set(4, 12, '2024');                                   // year-only date
fx_tag(4, $N['france']);
fx_tag(4, $N['sculpture']);
fx_tag(4, $N['modernart']);
fx_set(4, 92, "<p>Hello <strong>world</strong> of <a href='https://example.com/gallery'>art galleries</a></p>");

fx_resource(5, 2);
fx_set(5, 8, 'Production notes');
fx_set(5, 18, 'video production schedule');
fx_set(5, 12, '2022-06-01');

fx_resource(6, 1);
fx_set(6, 8, 'Long caption');
fx_set(6, 18, 'albatross ' . $filler . ' zeppelin');       // "zeppelin" is past the first 500 characters
fx_set(6, 12, '2021-01-01');

fx_resource(7, 1);
fx_set(7, 8, 'Brandenburg gate');
fx_tag(7, $N['germany']);                                 // translated option ~en:Germany~fr:Allemagne
fx_set(7, 12, '2020-03-03');

fx_resource(8, 1, array('hit_count' => 50));
fx_set(8, 8, 'Sunset');
fx_set(8, 12, '2019-09-09');

fx_resource(9, 1, array('archive' => 2));
fx_set(9, 8, 'Archived sunset');

fx_resource(10, 1, array('archive' => -1, 'created_by' => 9));
fx_set(10, 8, 'Pending sunset');

fx_resource(11, 1, array('access' => 2));
fx_set(11, 8, 'Confidential sunset');

fx_resource(12, 1, array('access' => 3));
fx_set(12, 8, 'Custom sunset');
fx_exec('INSERT INTO resource_custom_access (resource, usergroup, access) VALUES (12, 3, 0)');

fx_resource(13, 1, array('access' => 2));
fx_set(13, 8, 'Expired grant sunset');
fx_exec('INSERT INTO resource_custom_access (resource, user, access, user_expires) VALUES (13, 5, 1, ?)', array(date('Y-m-d', strtotime('-1 day'))));

fx_resource(14, 2);
fx_set(14, 8, 'Festival');
fx_set(14, 12, '2024-03-01');
fx_tag(14, fx_node(91, '2024-03-01'));
fx_tag(14, fx_node(91, '2024-03-10'));

fx_resource(15, 1);
fx_set(15, 8, 'Pricey');
fx_set(15, 90, '250');

fx_resource(16, 1);
fx_set(16, 8, 'Unpriced');
fx_set(16, 90, 'abc');

fx_resource(18, 1);
fx_set(18, 8, 'Reversed');
fx_set(18, 18, 'bar foo');

fx_resource(19, 1);
fx_set(19, 8, 'Ordinary');
fx_set(19, 93, 'classified plans');                       // field the user cannot view

fx_resource(21, 1);
fx_set(21, 8, 'Film reel');
fx_set(21, 18, '8 mm film reel');

fx_resource(22, 4);
fx_set(22, 8, 'Interview');
fx_set(22, 94, 'legacy landscape notes');                 // inactive field (was indexed while active)

fx_resource(23, 1);
fx_set(23, 8, 'Mountain landscape');
fx_tag(23, $N['landscape']);

fx_resource(24, 1);
fx_set(24, 8, 'Birdwatching');
fx_tag(24, $N['birds']);
fx_tag(24, $N['animals']);

fx_resource(25, 2);
fx_set(25, 8, "O'Brien family archive");
fx_set(25, 18, 'john.smith@example.com sent the up-at-em report');

fx_resource(26, 3);
fx_set(26, 8, 'Untitled clip');

fx_resource(28, 1);
fx_set(28, 8, 'Booking form');

fx_resource(29, 1);
fx_set(29, 8, 'Street scene');
fx_set(29, 96, 'zebra crossing');                         // field not flagged for indexing

fx_resource(30, 1, array('archive' => 3));
fx_set(30, 8, 'Deleted sunset');

fx_resource(31, 1);
fx_set(31, 8, 'Expiring');
fx_set(31, 95, '2025-06-30');

fx_resource(32, 1);
fx_set(32, 8, 'Zeppelin poster');                          // "zeppelin" within the first 500 characters

fx_resource(33, 1);
fx_set(33, 8, 'Carpet warehouse');                        // "car" is a prefix of "carpet"

fx_resource(34, 1);
fx_set(34, 8, 'Strong winds');                            // "strong" is also an HTML tag name in resource 4

fx_resource(35, 2);
fx_set(35, 8, 'Germany travel guide');
fx_set(35, 18, 'Hello from Berlin, a world city');

fx_resource(36, 1);
fx_set(36, 8, 'Filtered sunset');                         // open access, EXPIRED user grant
fx_exec('INSERT INTO resource_custom_access (resource, user, access, user_expires) VALUES (36, 5, 0, ?)', array(date('Y-m-d', strtotime('-1 day'))));

fx_resource(37, 1);
fx_set(37, 8, 'Granted sunset');                          // open access, valid user grant
fx_exec('INSERT INTO resource_custom_access (resource, user, access, user_expires) VALUES (37, 5, 0, ?)', array(date('Y-m-d', strtotime('+30 days'))));

// Search filters: 7 = must be France (ALL); 8 = must not be France (NONE); 9 = France or not "sculpture" (ANY).
foreach (array(7 => RS_FILTER_ALL, 8 => RS_FILTER_NONE, 9 => RS_FILTER_ANY) as $fref => $cond) {
    fx_exec('INSERT INTO filter (ref, name, filter_condition) VALUES (?, ?, ?)', array($fref, 'filter ' . $fref, $cond));
}
fx_exec('INSERT INTO filter_rule (ref, filter) VALUES (71, 7), (81, 8), (91, 9), (92, 9)');
fx_exec('INSERT INTO filter_rule_node (filter_rule, node_condition, node) VALUES (71, 1, 201), (81, 1, 201), (91, 1, 201), (92, 0, 301)');

// Related keywords: "automobile" -> "car".
save_related_keywords('automobile', 'car');

// ----------------------------------------------------------------------------------------- collections
fx_collection(5, 'My collection', 5, COLLECTION_TYPE_STANDARD, array(3, 1, 2));
fx_collection(6, 'Private to another user', 9, COLLECTION_TYPE_STANDARD, array(4, 5));
fx_collection(7, 'Selection', 5, COLLECTION_TYPE_SELECTION, array(2));
fx_collection(30, 'Featured A', 1, COLLECTION_TYPE_FEATURED, array(1, 7));
fx_collection(31, 'Featured B', 1, COLLECTION_TYPE_FEATURED, array(8));
fx_collection(40, 'Public', 9, COLLECTION_TYPE_PUBLIC, array(2, 8));

if (!empty($HARNESS['sql_errors'])) {
    echo "SQL errors while loading the fixture:\n  " . implode("\n  ", array_unique($HARNESS['sql_errors'])) . "\n";
    $HARNESS['sql_errors'] = array();
}
