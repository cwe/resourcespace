<?php
require __DIR__ . '/boot.php';

echo "\n################ C. NODE SEARCHES ################\n";
trace('C1 single node', '@@201');
trace('C2 OR within a field', '@@201@@203');
trace('C3 AND across words', '@@201 @@301');
trace('C4 NOT node', '@@!201');
trace('C5 node + NOT node', '@@301 @@!201');
trace('C6 node + keyword', '@@201 sunset');
trace('C7 node, comma + keyword (form output)', 'sunset, @@201, @@301');
trace('C8 node on a field the user cannot view', '@@931');
trace('C9 hidden-field node + visible node', '@@931 @@201');
trace('C10 unknown node id', '@@99999999');
trace('C11 mixed NOT inside a word', '@@201@@!203');
$category_tree_search_use_and_logic = true;
trace('C12 OR word with $category_tree_search_use_and_logic', '@@201@@203');
$category_tree_search_use_and_logic = false;

echo "\n################ D. SPECIAL SEARCHES ################\n";
echo "\n---- served or vetoed by a plugin mode\n";
trace('D1 own collection', '!collection5', array('order_by' => 'collection', 'sort' => 'ASC'));
trace('D2 own collection, relevance order', '!collection5');
trace('D3 collection + keyword', '!collection5 sunset', array('order_by' => 'collection', 'sort' => 'ASC'));
trace('D4 collection + restypes', '!collection5', array('restypes' => '1,2', 'order_by' => 'collection'));
trace('D5 another user\'s private collection (non-admin)', '!collection6', array('order_by' => 'collection'));
trace('D6 selection collection', '!collection7', array('order_by' => 'collection'));
trace('D7 featured collection', '!collection30', array('order_by' => 'collection'));
trace('D8 collection that does not exist', '!collection424242', array('order_by' => 'collection'));
trace('D9 collection, date order', '!collection5', array('order_by' => 'date'));
trace('D10 upload collection (negative ref)', '!collection-5', array('order_by' => 'collection'));

trace('D11 !last', '!last1000');
trace('D12 !last + keyword (space)', '!last50 sunset');
trace('D13 !last + keyword (comma)', '!last50, sunset');
trace('D14 !last, lower-case sort', '!last1000', array('sort' => 'desc'));
trace('D15 !last, date order', '!last1000', array('order_by' => 'date'));
trace('D16 !last + restypes', '!last1000', array('restypes' => '1,2'));

trace('D17 !list', '!list1:2:3');
trace('D18 !listall', '!listall1:2:3');
trace('D19 !list + node (core test)', '!list1,@@201');
trace('D20 !list empty', '!list');
trace('D21 !resource', '!resource123');
trace('D22 !contributions other user', '!contributions9', array('archive' => '-2,-1,0'));
trace('D23 !contributions self', '!contributions5', array('archive' => '-2'));
trace('D24 !hasdata', '!hasdata18');
trace('D25 !hasdata + keyword', '!hasdata18 sunset');
trace('D26 !archivepending', '!archivepending');
trace('D27 !userpending', '!userpending');
trace('D28 !userpending, rating order', '!userpending', array('order_by' => 'rating'));

echo "\n---- no plugin mode (core only)\n";
foreach (array(
    '!related123', '!relatedpushed123', '!duplicates', '!duplicates123', '!nodownloads', '!unused',
    '!geo51p5b-1p2t52p5b0p2', '!colour3', '!colourkey1234', '!rgb:255,0,0', '!nopreview', '!images',
    '!properties' . 'hmin:100;wmin:200', '!integrityfail', '!locked', '!noningested', '!report18p7',
    '!license5', '!consent5', '!face12', '!clipsearch dogs on a beach', '!mplus_invalid_assoc',
) as $special) {
    trace('D-core ' . explode(' ', preg_replace('/[0-9:].*$/', '', $special))[0], $special);
}

echo "\n---- config: \$open_access_for_contributor = true\n";
$open_access_for_contributor = true;
trace('D40 own contributions, open access', '!contributions5', array('archive' => '-2,0'));
trace('D41 own contributions + keyword, open access', '!contributions5 sunset', array('archive' => '0'));
trace('D42 other user contributions, open access', '!contributions9', array('archive' => '0'));
$open_access_for_contributor = false;

echo "\n---- config: \$special_search_honors_restypes = true\n";
$special_search_honors_restypes = true;
trace('D50 !last + restypes', '!last1000', array('restypes' => '1,2'));
trace('D51 !collection + restypes', '!collection5', array('restypes' => '1,2', 'order_by' => 'collection'));
$special_search_honors_restypes = false;

echo "\n---- config: \$collections_omit_archived = true\n";
$collections_omit_archived = true;
trace('D60 collection, omit archived', '!collection5', array('order_by' => 'collection'));
$collections_omit_archived = false;
