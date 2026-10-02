<?php
// Second pass: the collection gate inside an external upload-share session.
require __DIR__ . '/fixture.php';
fx_index();
echo "\n################ T: collection gate inside an external upload-share session ################\n";
// An upload-share key for collection 40; the visitor asks for collection 6 (another user's private collection).
fx_exec("INSERT INTO external_access_keys (resource, access_key, user, usergroup, collection, upload, access) VALUES (NULL, 'abc', 9, 3, 40, 1, 0)");
$k = 'abc';
$_GET['k'] = 'abc';
$upload_share_active = 40;
ab('T1 upload-share session asking for a collection that is not its own', '!collection6', array('order_by' => 'collection', 'sort' => 'ASC'));
ab('T2 upload-share session asking for another public collection', '!collection5', array('order_by' => 'collection', 'sort' => 'ASC'));
unset($upload_share_active);
$upload_share_active = null;
unset($GLOBALS['upload_share_active']);
echo "\n---- ordinary external share key (not an upload share)\n";
fx_exec("UPDATE external_access_keys SET upload = 0 WHERE access_key = 'abc'");
ab('T3 valid key for collection 40, asking for collection 6', '!collection6', array('order_by' => 'collection', 'sort' => 'ASC'));
