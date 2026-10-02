<?php
require __DIR__ . '/boot.php';

echo "\n################ E. PARAMETERS ################\n";
echo "\n---- restypes\n";
trace('E1 restypes list', 'sunset', array('restypes' => '1,2'));
trace('E2 restypes Global', 'sunset', array('restypes' => 'Global'));
trace('E3 restypes + FeaturedCollections', 'sunset', array('restypes' => '1,2,FeaturedCollections'));
trace('E4 restypes FeaturedCollections ONLY', 'sunset', array('restypes' => 'FeaturedCollections'));
trace('E5 restypes Collections ONLY', 'sunset', array('restypes' => 'Collections'));

echo "\n---- archive\n";
trace('E10 archive multiple', 'sunset', array('archive' => '0,1,2'));
trace('E11 archive empty string', 'sunset', array('archive' => ''));
trace('E12 archive deleted', 'sunset', array('archive' => '3'));
$archive_standard = true;
trace('E13 $archive_standard (search page, no archive param)', 'sunset', array('archive' => ''));
$archive_standard = false;
$search_all_workflow_states = true;
trace('E14 $search_all_workflow_states', 'sunset', array('archive' => '0'));
$search_all_workflow_states = false;
trace('E15 search containing the text integrityfail', 'integrityfail report', array('archive' => '0'));

echo "\n---- order_by / sort\n";
foreach (array('relevance', 'popularity', 'rating', 'date', 'colour', 'title', 'file_path', 'resourceid', 'resourcetype', 'extension', 'status', 'modified', 'random', 'country', 'titleandcountry', 'field8', 'field12', 'field3', 'collection', '', 'nonsense') as $ob) {
    $h = trace('E20 order_by=' . json_encode($ob), 'sunset', array('order_by' => $ob));
}
trace('E21 relevance ASC', 'sunset', array('sort' => 'ASC'));
trace('E22 date ASC', 'sunset', array('order_by' => 'date', 'sort' => 'ASC'));
trace('E23 empty search, relevance', '', array());
trace('E24 empty search, date', '', array('order_by' => 'date'));
trace('E25 invalid sort value', 'sunset', array('sort' => 'sideways'));

echo "\n---- other do_search() arguments\n";
trace('E30 recent day limit', '', array('daylimit' => '60'));
trace('E31 access (non-v user)', 'sunset', array('access' => 1));
trace('E32 access_override', 'sunset', array('access_override' => true));
trace('E33 ignore_filters', 'sunset', array('ignore_filters' => true));
trace('E34 ignore_filters + date field', 'date:2024', array('ignore_filters' => true));
trace('E35 editable_only', 'sunset', array('editable_only' => true));
trace('E36 return_disk_usage', 'sunset', array('return_disk_usage' => true));
trace('E37 returnsql', 'sunset', array('returnsql' => true));
trace('E38 smartsearch', 'sunset', array('smartsearch' => true));
trace('E39 return_refs_only, all rows', 'sunset', array('return_refs_only' => true, 'fetchrows' => -1));
trace('E40 fetchrows count only [0,0]', 'sunset', array('fetchrows' => array(0, 0)));
trace('E41 fetchrows int 10', 'sunset', array('fetchrows' => 10));
trace('E42 fetchrows -1', 'sunset', array('fetchrows' => -1));
trace('E43 fetchrows page 3', 'sunset', array('fetchrows' => array(96, 48)));
trace('E44 fetchrows unaligned offset', 'sunset', array('fetchrows' => array(45, 48)));

echo "\n---- config: \$resource_created_by_filter\n";
$resource_created_by_filter = array(-1, 9);
trace('E50 resource_created_by_filter', 'sunset');
$resource_created_by_filter = array();
