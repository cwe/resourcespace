<?php
// Permissions: v, T, z, ert, J / j, group search filters, the collection gate, own contributions.
require __DIR__ . '/fixture.php';
fx_index();
$STD = array('s', 'f*', 'f-93', 'g', 'j*');

echo "\n################ A/B: PERMISSIONS ################\n";
$userpermissions = array('s', 'f*', 'g', 'v', 'j*');
ab('F1 "v" user', 'sunset');
ab('F2 "v" user, access=2', 'sunset', array('access' => 2));
ab('F3 "v" user, pending', 'sunset', array('archive' => '-1'));
$userpermissions = array_merge($STD, array('T2', 'T3'));
ab('F4 T permissions (types 2,3 hidden)', 'launch');
$userpermissions = array_merge($STD, array('z2'));
ab('F5 z2 permission', 'sunset', array('archive' => '0,2'));
$userpermissions = array_merge($STD, array('ert1'));
ab('F6 ert1: pending resources of type 1 visible', 'sunset', array('archive' => '-1,0'));
$userpermissions = $STD;
$userref = 9;
ab('F7 own pending resource (as user 9)', 'sunset', array('archive' => '-1,0'));
$userref = 5;

echo "\n---- J permission\n";
$userpermissions = array('s', 'f*', 'f-93', 'g', 'J', 'j*');
ab('F10 J + j*: only resources in some collection', 'sunset');
ab('F11 J + j*: empty search', '');
$userpermissions = array('s', 'f*', 'f-93', 'g', 'J', 'j30');
ab('F12 J + j30 only', '');
ab('F13 J + j30, inside collection 30', '!collection30', array('order_by' => 'collection', 'sort' => 'ASC'));
ab('F14 J + j30, inside collection 31', '!collection31', array('order_by' => 'collection', 'sort' => 'ASC'));
ab('F15 J + j30, inside own collection 5', '!collection5', array('order_by' => 'collection', 'sort' => 'ASC'));
$userpermissions = array('s', 'f*', 'f-93', 'g', 'J');
ab('F16 J with no j permission', 'sunset');
$userpermissions = $STD;

echo "\n---- group search filter\n";
$usersearchfilter = 7;
ab('F20 filter ALL (must be France)', '');
$usersearchfilter = 8;
ab('F21 filter NONE (must not be France)', 'sunset');
$usersearchfilter = 9;
ab('F22 filter ANY (France or not sculpture)', '@@301');
$usersearchfilter = 7;
$custom_access_overrides_search_filter = true;
ab('F23 filter ALL + $custom_access_overrides_search_filter (36 = expired grant, 37 = valid grant)', 'sunset');
$custom_access_overrides_search_filter = false;
$open_access_for_contributor = true;
$userref = 1;
ab('F24 filter ALL + $open_access_for_contributor (as user 1, the contributor)', 'sunset');
$userref = 5;
$open_access_for_contributor = false;
ab('F25 filter ALL + access_override', 'sunset', array('access_override' => true));
ab('F26 filter ALL inside a collection', '!collection5', array('order_by' => 'collection', 'sort' => 'ASC'));
$usersearchfilter = '';

echo "\n---- collection gate\n";
$userpermissions = array_merge($STD, array('R'));
ab('F30 "R" user, another user\'s private collection', '!collection6', array('order_by' => 'collection', 'sort' => 'ASC'));
$userpermissions = array_merge($STD, array('h'));
ab('F31 "h" user, another user\'s private collection', '!collection6', array('order_by' => 'collection', 'sort' => 'ASC'));
$userpermissions = $STD;
ab('F32 standard user, another user\'s collection + access_override', '!collection6', array('order_by' => 'collection', 'sort' => 'ASC', 'access_override' => true));
$userpermissions = array('s', 'f*', 'g', 'v', 'a', 'j*');
ab('F33 admin, another user\'s private collection', '!collection6', array('order_by' => 'collection', 'sort' => 'ASC'));
$userpermissions = $STD;

echo "\n---- own contributions with \$open_access_for_contributor\n";
$open_access_for_contributor = true;
$userref = 9;
ab('F40 own contributions (user 9), pending state', '!contributions9', array('archive' => '-1'));
ab('F41 own contributions + keyword', '!contributions9 nosuchword', array('archive' => '-1'));
$userref = 5;
$open_access_for_contributor = false;
