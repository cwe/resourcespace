<?php

/**
 * Group search filter restriction.
 *
 * Reproduces core's group `search_filter` ([get_filter_sql()](include/search_functions.php:1957),
 * applied via do_search_filtering.php before the hook) as node filters. Filter rules are pure
 * resource_node membership, so this works against the existing `nodes[]` index with no schema
 * change.
 *
 * Rule semantics (per get_filter_sql): each rule ORs its nodes_on / nodes_off clauses; rules are
 * combined with AND for ALL/NONE conditions and OR for ANY; NONE inverts each clause. The whole
 * filter is then OR'd with a grant-exists clause (when $custom_access_overrides_search_filter) and
 * the user's own resources (when $open_access_for_contributor).
 */
class TypesenseGroupFilterRestriction implements TypesenseSearchComponent
{
    public function applies(TypesenseSearchContext $ctx): bool
    {
        global $usersearchfilter;
        return !$ctx->access_override
            && isset($usersearchfilter)
            && is_int_loose($usersearchfilter)
            && (int)$usersearchfilter > 0;
    }

    public function build(TypesenseSearchContext $ctx, TypesenseQueryPlan $plan): void
    {
        global $usersearchfilter, $open_access_for_contributor, $custom_access_overrides_search_filter;
        global $typesense_search_collection_prefix;

        $filterid = (int)$usersearchfilter;
        $filter = get_filter($filterid);

        if ($filter === false) {
            // Invalid filter - core would error; fall back so it is handled consistently.
            $plan->markUnsupported('invalid group search_filter ' . $filterid);
            return;
        }

        $rules = get_filter_rules($filterid);
        if (empty($rules)) {
            return;
        }

        $condition = (int)$filter['filter_condition'];
        $none = ($condition === RS_FILTER_NONE);

        $rule_exprs = array();
        foreach ($rules as $rule) {
            $on = array_values(array_filter(array_map('intval', $rule['nodes_on'] ?? array())));
            $off = array_values(array_filter(array_map('intval', $rule['nodes_off'] ?? array())));

            $clauses = array();
            if (count($on) > 0) {
                $clauses[] = 'nodes:' . ($none ? '!=' : '=') . '[' . implode(',', $on) . ']';
            }
            if (count($off) > 0) {
                $clauses[] = 'nodes:' . ($none ? '=' : '!=') . '[' . implode(',', $off) . ']';
            }
            if (count($clauses) > 0) {
                $rule_exprs[] = count($clauses) > 1 ? '(' . implode(' || ', $clauses) . ')' : $clauses[0];
            }
        }

        if (count($rule_exprs) === 0) {
            return;
        }

        $glue = ($condition === RS_FILTER_ANY) ? ' || ' : ' && ';
        $expr = count($rule_exprs) > 1 ? '(' . implode($glue, $rule_exprs) . ')' : $rule_exprs[0];

        // The whole filter can be overridden by a custom access grant or ownership.
        $ors = array($expr);

        if (!empty($custom_access_overrides_search_filter)) {
            $grants = $typesense_search_collection_prefix . 'resource_access_grants';
            $ors[] = '$' . $grants . '(user:=' . (int)$ctx->userref . ' || usergroup:=' . (int)$ctx->usergroup . ')';
        }
        if (!empty($open_access_for_contributor)) {
            $ors[] = 'created_by:=' . (int)$ctx->userref;
        }

        $plan->addFilter(count($ors) > 1 ? '(' . implode(' || ', $ors) . ')' : $ors[0]);
    }
}
