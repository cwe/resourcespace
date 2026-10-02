<?php
require __DIR__ . '/boot.php';

echo "\n---- collection gate\n";
$userpermissions = array('s', 'f*', 'g', 'j*', 'R');
trace('F30 "R" user, another user\'s private collection', '!collection6', array('order_by' => 'collection'));
$userpermissions = array('s', 'f*', 'g', 'j*', 'h');
trace('F31 "h" user, another user\'s private collection', '!collection6', array('order_by' => 'collection'));
$userpermissions = array('s', 'f*', 'g', 'j*');
trace('F32 standard user, another user\'s collection + access_override', '!collection6', array('order_by' => 'collection', 'access_override' => true));
$userpermissions = array('s', 'f*', 'g', 'v', 'a', 'j*');
trace('F33 admin, another user\'s private collection', '!collection6', array('order_by' => 'collection'));
trace('F34 admin, selection collection', '!collection7', array('order_by' => 'collection'));
$userpermissions = array('s', 'f*', 'g', 'j*');
$ignore_collection_access = true;
trace('F35 $ignore_collection_access, another user\'s collection', '!collection6', array('order_by' => 'collection'));
$ignore_collection_access = false;

echo "\n---- external share\n";
$k = 'abc123';
$_GET['k'] = 'abc123';
$collection_allow_not_approved_share = true;
trace('F40 share key, $collection_allow_not_approved_share', '!collection5', array('order_by' => 'collection'));
$collection_allow_not_approved_share = false;
trace('F41 share key, default', '!collection5', array('order_by' => 'collection'));
$k = '';
unset($_GET['k']);

echo "\n---- derestrict filter / restricted-by-default group (no g permission)\n";
$userpermissions = array('s', 'f*', 'j*');
trace('F50 user without g', 'sunset');
