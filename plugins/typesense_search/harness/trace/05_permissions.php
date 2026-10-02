<?php
require __DIR__ . '/boot.php';

echo "\n################ F. PERMISSIONS AND FILTERS ################\n";
$userpermissions = array('s', 'f*', 'g', 'v', 'j*');
trace('F1 "v" user', 'sunset');
trace('F2 "v" user, access=2', 'sunset', array('access' => 2));
trace('F3 "v" user, pending states', 'sunset', array('archive' => '-2,-1'));

$userpermissions = array('s', 'f*', 'g', 'j*', 'T3', 'T4');
trace('F4 T permissions', 'sunset');
trace('F5 T permissions + access_override', 'sunset', array('access_override' => true));

$userpermissions = array('s', 'f*', 'g', 'j*', 'z2', 'z3');
trace('F6 z permissions ($uploader_view_override on)', 'sunset', array('archive' => '0,2'));
$uploader_view_override = false;
trace('F7 z permissions ($uploader_view_override off)', 'sunset', array('archive' => '0,2'));
$uploader_view_override = true;

$userpermissions = array('s', 'f*', 'g', 'j*', 'ert2', 'ert3');
trace('F8 ert permissions', 'sunset', array('archive' => '-1,0'));

echo "\n---- J permission (featured collections only)\n";
$userpermissions = array('s', 'f*', 'g', 'J', 'j*');
trace('F10 J with j* (all featured collections)', 'sunset', array('sql' => true));
trace('F11 J with j*, inside a private collection', '!collection5', array('order_by' => 'collection'));
$userpermissions = array('s', 'f*', 'g', 'J', 'j30');
trace('F12 J with j30 only', 'sunset');
trace('F13 J with j30, inside collection 30', '!collection30', array('order_by' => 'collection'));
trace('F14 J with j30, inside collection 31', '!collection31', array('order_by' => 'collection'));
trace('F15 J with j30, own upload collection', '!collection-5', array('order_by' => 'collection'));
trace('F16 J with j30 + access_override', 'sunset', array('access_override' => true));
$userpermissions = array('s', 'f*', 'g', 'J');
trace('F17 J with no j permissions at all', 'sunset', array('sql' => true));
trace('F18 J with no j permissions, inside a collection', '!collection5', array('order_by' => 'collection'));

echo "\n---- group search filter\n";
$userpermissions = array('s', 'f*', 'g', 'j*');
$FAKE['filters'][7] = array('ref' => 7, 'name' => 'ALL', 'filter_condition' => RS_FILTER_ALL);
$FAKE['filter_rules'][7] = array(
    array('rule' => 1, 'node_condition' => 1, 'node' => 201),
    array('rule' => 1, 'node_condition' => 1, 'node' => 203),
    array('rule' => 2, 'node_condition' => 0, 'node' => 301),
);
$FAKE['filters'][8] = array('ref' => 8, 'name' => 'NONE', 'filter_condition' => RS_FILTER_NONE);
$FAKE['filter_rules'][8] = $FAKE['filter_rules'][7];
$FAKE['filters'][9] = array('ref' => 9, 'name' => 'ANY', 'filter_condition' => RS_FILTER_ANY);
$FAKE['filter_rules'][9] = $FAKE['filter_rules'][7];

$usersearchfilter = 7;
trace('F20 search filter, ALL', 'sunset', array('sql' => true));
$usersearchfilter = 8;
trace('F21 search filter, NONE', 'sunset');
$usersearchfilter = 9;
trace('F22 search filter, ANY', 'sunset');
$usersearchfilter = 7;
$custom_access_overrides_search_filter = true;
trace('F23 search filter + $custom_access_overrides_search_filter', 'sunset');
$open_access_for_contributor = true;
trace('F24 search filter + custom access + $open_access_for_contributor', 'sunset');
$custom_access_overrides_search_filter = false;
$open_access_for_contributor = false;
trace('F25 search filter + access_override', 'sunset', array('access_override' => true));
trace('F26 search filter inside a collection', '!collection5', array('order_by' => 'collection'));
$usersearchfilter = 99;
trace('F27 search filter id that does not exist', 'sunset');
$usersearchfilter = '';

echo "\n---- collection gate\n";
$userpermissions = array('s', 'f*', 'g', 'j*', 'R');
trace('F30 "R" user, another user\'s private collection', '!collection6', array('order_by' => 'collection'));
$userpermissions = array('s', 'f*', 'g', 'j*', 'h');
trace('F31 "h" user, another user\'s private collection', '!collection6', array('order_by' => 'collection'));
$userpermissions = array('s', 'f*', 'g', 'j*');
trace('F32 standard user, another user\'s collection + access_override', '!collection6', array('order_by' => 'collection', 'access_override' => true));
$userpermissions = array('s', 'f*', 'g', 'v', 'a', 'j*');
trace('F33 admin, another user\'s private collection', '!collection6', array('order_by' => 'collection'));
$userpermissions = array('s', 'f*', 'g', 'j*');

echo "\n---- external share\n";
$k = 'abc123';
$_GET['k'] = 'abc123';
$collection_allow_not_approved_share = true;
trace('F40 share key, $collection_allow_not_approved_share', '!collection5', array('order_by' => 'collection'));
$collection_allow_not_approved_share = false;
trace('F41 share key, default', '!collection5', array('order_by' => 'collection'));
$k = '';
unset($_GET['k']);
