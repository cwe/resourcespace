<?php

/**
 * The ORDER BY of a search.
 *
 * search_query_order() is the pipeline step. It runs early in search_query_build() because the select step needs
 * to know whether the ordering reads metadata field columns. resolve_search_order_by() turns the ordering the
 * caller asked for ("relevance", "date", "field8", ...) into the body of the ORDER BY clause.
 *
 * A special search that needs a different ordering changes SpecialSearch::$order_by in its builder, see
 * search_special.php: "!last" and the related searches drop the table alias, "!rgb" orders by colour distance and
 * "!relatedpushed" may resolve its own configured ordering.
 */

/**
 * Pipeline step: resolve the requested ORDER BY for this search and note whether it refers to metadata field
 * columns.
 *
 * @param ParsedSearch  $parsed Parsed search
 * @param SearchContext $ctx    Search context; requested_order_by and sort are read
 * @param SearchQuery   $query  Query under construction; order_by and order_needs_fields are set
 */
function search_query_order(ParsedSearch $parsed, SearchContext $ctx, SearchQuery $query): void
{
    $resolved = resolve_search_order_by($parsed->search, $ctx->requested_order_by, $ctx->sort);
    $query->order_by = $resolved['order_by'];
    $query->order_needs_fields = $resolved['needs_fields'];
}

/**
 * Resolve the ORDER BY clause for a search from the order and sort requested by the do_search() caller.
 *
 * The named orderings are listed below; "fieldN" orders by that metadata field (search_order_by_field()). An
 * unknown value falls back to relevance, or to the collection order for a "!collection" search. Every ordering
 * ends in the resource reference so that results keep a stable sequence. The modifyorderarray hook is called
 * before the ordering is chosen.
 *
 * Reads the $date_field and $view_title_field configuration, and the current user's access to the date field.
 *
 * @param string $search   Search string
 * @param string $order_by Requested ordering: a name from the list, or "fieldN"
 * @param string $sort     Sort direction; an invalid value becomes "asc"
 *
 * @return array{order_by: string, needs_fields: bool} The ORDER BY clause body, and whether it refers to metadata
 *                                                      field columns that must then be selected
 */
function resolve_search_order_by(string $search, string $order_by, string $sort): array
{
    if (!validate_sort_value($sort)) {
        $sort = 'asc';
    }
    # An empty order means relevance
    if ($order_by === "") {
        $order_by = "relevance";
    }

    // Several orderings fall back to the date field before the reference, when the user may see that field
    $date_sql = null;
    $then_date = ",";
    $by_date = "r.ref $sort";
    if (metadata_field_view_access($GLOBALS["date_field"])) {
        $date_sql = "field" . (int) $GLOBALS["date_field"] . " " . $sort;
        $then_date = ", {$date_sql}, ";
        $by_date = "{$date_sql}, r.ref {$sort}";
    }

    $order = [
        "relevance"       => "score $sort, user_rating $sort, total_hit_count $sort {$then_date} r.ref $sort",
        "popularity"      => "user_rating $sort, total_hit_count $sort {$then_date} r.ref $sort",
        "rating"          => "r.rating $sort, user_rating $sort, score $sort, r.ref $sort",
        "date"            => "$by_date, r.ref $sort",
        "colour"          => "has_image $sort, image_blue $sort, image_green $sort, image_red $sort {$then_date} r.ref $sort",
        "title"           => "field" . (int) $GLOBALS["view_title_field"] . " " . $sort . ", r.ref $sort",
        "file_path"       => "file_path $sort, r.ref $sort",
        "resourceid"      => "r.ref $sort",
        "resourcetype"    => "order_by $sort, resource_type $sort, r.ref $sort",
        "extension"       => "file_extension $sort, r.ref $sort",
        "status"          => "archive $sort, r.ref $sort",
        "modified"        => "modified $sort, r.ref $sort",
        "random"          => "RAND()"
    ];

    // These options are only supported if the default field 3 is still present
    if (in_array(3, get_resource_table_joins())) {
        $order["country"] = "field3 $sort, r.ref $sort";
        $order["titleandcountry"] = "field" . $GLOBALS["view_title_field"] . " $sort, field3 $sort, r.ref $sort";
    }

    // The collection order is only available when searching a collection. Its sort order is ascending where the
    // date added is descending, so the date takes the opposite direction.
    if (substr($search, 0, 11) == '!collection') {
        $revsort = (strtoupper($sort) == 'DESC') ? "ASC" : " DESC";
        $order["collection"] = "c.sortorder $sort,c.date_added $revsort,r.ref $sort";
    }

    # A named ordering that uses the date field needs that column in the inner select
    $needs_fields = $date_sql !== null && isset($order[$order_by]) && strpos($order[$order_by], $date_sql) !== false;

    if (substr($order_by, 0, 5) == "field") {
        $order_by = check_order_by_in_table_joins($order_by);
        # The field column is needed in the inner select to be used in ordering
        $needs_fields = true;
        $order[$order_by] = search_order_by_field($order_by, $sort);
    }

    hook("modifyorderarray");

    // Fail safe by falling back to the default when the ordering is not known
    $default = substr($search, 0, 11) == '!collection' ? $order['collection'] : $order['relevance'];
    return ["order_by" => $order[$order_by] ?? $default, "needs_fields" => $needs_fields];
}

/**
 * The ORDER BY clause body for ordering by a metadata field column.
 *
 * The field's settings decide how its values compare: dot notation ("1.2.10", optionally with a leading word)
 * orders each part numerically, a numeric constraint orders by number, anything else orders as text. The
 * reference is the final key, for a stable result sequence when moving between resources on the view page.
 *
 * @param string $column Column to order by, "fieldN"
 * @param string $sort   Sort direction
 *
 * @return string
 */
function search_order_by_field(string $column, string $sort): string
{
    $field = ps_query(
        "SELECT field_constraint, sort_method
            FROM resource_type_field
            WHERE ref = ?
            LIMIT 1",
        ["i",str_replace("field", "", $column)],
        "",
        "schema"
    )[0];

    if ($field["sort_method"] == FIELD_SORT_METHODS['dot-notation']) {
        $order =
            "CASE WHEN TRIM($column) = '' THEN 0 ELSE 1 END $sort,
                ISNULL(REGEXP_SUBSTR(SUBSTRING_INDEX($column, '.', 1), '[^0-9]+')) $sort,
                IFNULL(REGEXP_SUBSTR(SUBSTRING_INDEX($column, '.', 1), '[^0-9]+'), '') $sort,
                CAST(REGEXP_SUBSTR(SUBSTRING_INDEX($column, '.', 1), '[0-9]+') AS UNSIGNED) $sort,";
        for ($n = 2; $n <= 10; $n++) {
            $order .= "\nCAST(SUBSTRING_INDEX(SUBSTRING_INDEX($column, '.', $n), '.', -1) AS UNSIGNED) $sort,";
        }
        return $order . "\nref $sort";
    }
    if ($field["field_constraint"] == 1) {
        return "$column +0 $sort,r.ref $sort";
    }
    return "$column $sort,r.ref $sort";
}
