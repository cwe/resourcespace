<?php
// Extra !last and collection cases.
require __DIR__ . '/fixture.php';
fx_index();
ab('D8 public collection', '!collection40', array('order_by' => 'collection', 'sort' => 'ASC'));
ab('D5 another user\'s private collection', '!collection6', array('order_by' => 'collection'));
ab('D11 !last', '!last5');
ab('D12 !last + keyword (space) -> 1000', '!last2 sunset');
ab('D13 !last + keyword (comma)', '!last2, sunset');
ab('D14 !last, lower-case sort', '!last5', array('sort' => 'desc'));
ab('D15 !last, date order', '!last5', array('order_by' => 'date'));
ab('D16 !last + restypes (documents only)', '!last5', array('restypes' => '2'));
ab('D16b !last + node OR bucket', '!last3, @@201@@301');
ab('D16c !last, modified order', '!last5', array('order_by' => 'modified'));
ab('D16d !last, resourceid ASC', '!last5', array('order_by' => 'resourceid', 'sort' => 'ASC'));
