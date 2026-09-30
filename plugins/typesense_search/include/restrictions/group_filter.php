<?php

/**
 * Group search filter restriction - core's group search_filter rules as node filters.
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
            // Invalid filter - leave it to core.
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
