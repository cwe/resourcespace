<?php
// do_search() arguments: resource types, archive states, sort orders, fetchrows shapes and the rest.
require __DIR__ . '/fixture.php';
fx_index();

echo "\n################ A/B: PARAMETERS ################\n";
ab('E1 restypes list', 'launch', array('restypes' => '2'));
ab('E2 restypes Global', 'launch', array('restypes' => 'Global'));
ab('E3 restypes + FeaturedCollections', 'launch', array('restypes' => '2,FeaturedCollections'));
ab('E4 restypes FeaturedCollections ONLY', 'launch', array('restypes' => 'FeaturedCollections'));
ab('E10 archive 0,2', 'sunset', array('archive' => '0,2'));
ab('E11 archive empty', 'sunset', array('archive' => ''));
ab('E12 archive deleted (3)', 'sunset', array('archive' => '3'));
ab('E13 archive pending (-1), not own', 'sunset', array('archive' => '-1'));
$search_all_workflow_states = true;
ab('E14 $search_all_workflow_states', 'sunset');
$search_all_workflow_states = false;

echo "\n---- sort orders (first page order shown when it differs)\n";
foreach (array('relevance', 'date', 'resourceid', 'modified', 'popularity', 'title', 'status', 'resourcetype', 'field12') as $ob) {
    ab('E20 order_by=' . $ob, '', array('order_by' => $ob));
}
ab('E21 date ASC', '', array('order_by' => 'date', 'sort' => 'ASC'));
ab('E22 relevance, keyword', 'sunset', array('order_by' => 'relevance'));
ab('E23 resourceid ASC', 'sunset', array('order_by' => 'resourceid', 'sort' => 'ASC'));

echo "\n---- other arguments\n";
ab('E30 recent day limit 30', '', array('daylimit' => '30'));
ab('E31 access_override', 'sunset', array('access_override' => true));
ab('E32 ignore_filters', 'sunset', array('ignore_filters' => true));
ab('E33 ignore_filters + access_override, all states (openai_gpt job)', '!hasdata8', array('ignore_filters' => true, 'access_override' => true, 'archive' => '-2,-1,0,1,2,3', 'fetchrows' => -1, 'return_refs_only' => true));
ab('E34 editable_only', 'sunset', array('editable_only' => true));
ab('E35 fetchrows int 2 (padded)', 'sunset', array('fetchrows' => 2));
ab('E36 fetchrows -1', 'sunset', array('fetchrows' => -1));
ab('E37 fetchrows [1,1]', 'sunset', array('fetchrows' => array(1, 1), 'order_by' => 'resourceid'));
ab('E38 refs only, int fetchrows', 'sunset', array('fetchrows' => 2, 'return_refs_only' => true));
ab('E39 count only', 'sunset', array('fetchrows' => array(0, 0)));
ab('E40 offset beyond the end', 'sunset', array('fetchrows' => array(100, 48)));
