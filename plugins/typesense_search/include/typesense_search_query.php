<?php

/**
 * Query-build pipeline for the typesense_search plugin.
 *
 * Flow: Context -> Parse -> Mode -> Restrictions -> Compile -> Execute -> Hydrate.
 */

/**
 * Normalised input for a single search, built from the External_search hook arguments and the
 * current user.
 */
class TypesenseSearchContext
{
    /** @var string Raw (already core-preprocessed) search string. */
    public string $search = '';

    /** @var array Parsed keyword structures from core. */
    public array $keywords = array();

    /** @var array Included node search buckets (array of arrays of node ids). */
    public array $node_bucket = array();

    /** @var array Excluded node ids. */
    public array $node_bucket_not = array();

    /** @var mixed Resource type filter (string or array). */
    public $restypes = '';

    /** @var mixed Requested sort field SQL fragment. */
    public $order_by = '';

    /** @var array Archive state filter. */
    public array $archive = array();

    /** @var mixed Result limit or chunk details. */
    public $fetchrows = -1;

    /** @var string Sort direction. */
    public string $sort = 'DESC';

    /** @var bool Whether access checks are overridden. */
    public bool $access_override = false;

    /** @var bool Whether standard filters are ignored. */
    public bool $ignore_filters = false;

    /** @var bool Whether disk usage totals are requested. */
    public bool $return_disk_usage = false;

    /** @var string Recent search day limit. */
    public string $recent_search_daylimit = '';

    /** @var bool Whether only resource refs should be returned. */
    public bool $return_refs_only = false;

    /** @var bool Whether only editable resources should be returned. */
    public bool $editable_only = false;

    /** @var bool Whether SQL should be returned instead of results. */
    public bool $returnsql = false;

    /** @var mixed Access filter override. */
    public $access = null;

    /** @var bool Whether smart search mode is active. */
    public bool $smartsearch = false;

    /** @var PreparedStatementQuery|null Existing SELECT fields, used when hydrating refs. */
    public $select = null;

    // --- User context snapshot ---

    /** @var int Current user ref. */
    public int $userref = 0;

    /** @var int Current user's primary group ref. */
    public int $usergroup = 0;

    /** @var string External share access key ($k) for this request, or '' when not viewing a share. */
    public string $k = '';

    // --- Parsed components (filled by typesense_parse_search()) ---

    /** @var string|null Special command name without the leading "!" (e.g. "collection"), or null. */
    public ?string $command = null;

    /** @var string Argument that immediately followed the command token (e.g. "123" for !collection123). */
    public string $command_arg = '';

    /** @var array<string,string> Parsed field:value pairs (shortname => value). */
    public array $field_values = array();

    /** @var array Free-text keyword terms (command / field:value tokens removed). */
    public array $terms = array();

    /** @var bool Whether a trailing wildcard was requested. */
    public bool $wildcard = false;

    /**
     * Build a context from the raw External_search hook arguments.
     *
     * @param array $args Associative array keyed by hook argument name.
     */
    public static function fromHookArgs(array $args): self
    {
        $ctx = new self();

        foreach ($args as $key => $value) {
            if (property_exists($ctx, $key)) {
                $ctx->$key = $value;
            }
        }

        $ctx->userref = (int)($GLOBALS['userref'] ?? 0);
        $ctx->usergroup = (int)($GLOBALS['usergroup'] ?? 0);
        $ctx->k = (string)($GLOBALS['k'] ?? '');

        typesense_parse_search($ctx);

        return $ctx;
    }
}


/**
 * Contract implemented by every search mode and restriction.
 */
interface TypesenseSearchComponent
{
    /**
     * @param TypesenseSearchContext $ctx
     * @return bool True if this component participates in the current search.
     */
    public function applies(TypesenseSearchContext $ctx): bool;

    /**
     * Contribute to the query plan.
     *
     * @param TypesenseSearchContext $ctx
     * @param TypesenseQueryPlan     $plan
     * @return void
     */
    public function build(TypesenseSearchContext $ctx, TypesenseQueryPlan $plan): void;
}


/**
 * Accumulator that modes and restrictions write into. Compiled into the parameters sent to the
 * Typesense documents/search endpoint.
 */
class TypesenseQueryPlan
{
    /** @var string The q string. */
    public string $q = '*';

    /** @var array<string,int> query_by field => weight. */
    public array $query_by = array();

    /** @var int */
    public int $num_typos = 0;

    /** @var string|null "true,true" style prefix flag, or null to omit. */
    public ?string $prefix = null;

    /**
     * @var array<int,array{connector:string,clauses:array<int,string>}>
     * Each filter group's clauses are joined by its connector; groups are AND-ed together.
     */
    public array $filter_groups = array();

    /** @var array<int,string> Sort expressions like "ref:desc". */
    public array $sort_by = array();

    /** @var int|null Hard cap on the total number of results (e.g. !last<num>). */
    public ?int $result_limit = null;

    /**
     * @var int|null Most-recent N selection (e.g. !last<num>): the N highest-ref matches, shown
     * in the requested sort order.
     */
    public ?int $recent_selection = null;

    /** @var array<string,bool> Restrictions a mode has opted out of. */
    public array $suppressed = array();

    /** @var int Offset of the first result to return, from fetchrows. */
    public int $offset = 0;

    /** @var int Number of results to return, from fetchrows, or -1 for every result from the offset. */
    public int $limit = -1;

    /** @var bool Whether core answers this search in search_special() (affects result padding). */
    public bool $special_search = false;

    /** @var string Target collection short name (without prefix), e.g. "resources". */
    public string $collection = 'resources';

    /** @var bool */
    public bool $supported = true;

    /** @var string|null */
    public ?string $fallback_reason = null;

    /** @var bool False when a mode owns the whole scope and keyword matching must not run. */
    public bool $keyword_matching = true;

    /** @var bool True when the results are presented as open access - see setOpenAccess(). */
    public bool $open_access = false;

    /**
     * Add a single AND filter clause.
     */
    public function addFilter(string $clause): void
    {
        $clause = trim($clause);
        if ($clause === '') {
            return;
        }
        $this->filter_groups[] = array('connector' => '&&', 'clauses' => array($clause));
    }

    /**
     * Add a group of clauses OR-ed together (wrapped in parentheses), then AND-ed with the rest.
     *
     * @param array<int,string> $clauses
     */
    public function addFilterOr(array $clauses): void
    {
        $clauses = array_values(array_filter(array_map('trim', $clauses), 'strlen'));
        if (count($clauses) === 0) {
            return;
        }
        $this->filter_groups[] = array('connector' => '||', 'clauses' => $clauses);
    }

    /**
     * Constrain via a referenced collection join, e.g.
     * addJoinFilter('resource_collection_memberships', 'collection_ref:=5').
     */
    public function addJoinFilter(string $collection, string $expr): void
    {
        global $typesense_search_collection_prefix;
        $expr = trim($expr);
        if ($expr === '') {
            return;
        }
        $this->addFilter('$' . $typesense_search_collection_prefix . $collection . '(' . $expr . ')');
    }

    /**
     * Add a field to query_by with an optional relevance weight.
     */
    public function addQueryBy(string $field, int $weight = 1): void
    {
        if ($field === '') {
            return;
        }
        // Keep the highest weight if the same field is added twice.
        $this->query_by[$field] = max($weight, $this->query_by[$field] ?? 0);
    }

    /**
     * Append a sort expression, e.g. setSort('ref', 'desc').
     */
    public function setSort(string $field, string $direction): void
    {
        $direction = strtolower($direction) === 'asc' ? 'asc' : 'desc';
        $this->sort_by[] = $field . ':' . $direction;
    }

    /**
     * Append a raw sort expression (e.g. a referenced-collection sort).
     */
    public function addRawSort(string $expr): void
    {
        $expr = trim($expr);
        if ($expr !== '') {
            $this->sort_by[] = $expr;
        }
    }

    public function setResultLimit(int $limit): void
    {
        $this->result_limit = $limit;
    }

    /**
     * Select the N most-recent resources (highest refs) as the result set.
     */
    public function setRecentSelection(int $limit): void
    {
        $this->recent_selection = $limit;
        $this->result_limit = $limit;
    }

    public function suppressRestriction(string $name): void
    {
        $this->suppressed[$name] = true;
    }

    /**
     * Suppress every restriction - for a mode that defines the whole scope of the search itself.
     */
    public function suppressAllRestrictions(): void
    {
        $this->suppressed['*'] = true;
    }

    public function isSuppressed(string $name): bool
    {
        return !empty($this->suppressed[$name]) || !empty($this->suppressed['*']);
    }

    /**
     * Skip the shared keyword and node-bucket step - for a mode that defines the whole scope itself.
     */
    public function suppressKeywordMatching(): void
    {
        $this->keyword_matching = false;
    }

    /**
     * Present the results as open access, as core does for a user's own contributions with
     * $open_access_for_contributor.
     */
    public function setOpenAccess(): void
    {
        $this->open_access = true;
    }

    /**
     * Mark the whole search as unsupported so the plugin falls back to core MySQL search.
     */
    public function markUnsupported(string $reason): void
    {
        $this->supported = false;
        $this->fallback_reason = $reason;
    }

    /**
     * Compile the filter groups (plus the configured global filter) into a Typesense filter_by
     * string.
     */
    public function compileFilterBy(): string
    {
        global $typesense_search_global_filter;

        $parts = array();

        foreach ($this->filter_groups as $group) {
            $clauses = array_values(array_filter(array_map('trim', $group['clauses']), 'strlen'));
            if (count($clauses) === 0) {
                continue;
            }
            if (count($clauses) === 1) {
                $parts[] = $clauses[0];
            } else {
                $parts[] = '(' . implode(' ' . $group['connector'] . ' ', $clauses) . ')';
            }
        }

        $filter_by = implode(' && ', $parts);

        $global = trim((string)$typesense_search_global_filter);
        if ($global !== '') {
            // The global filter may begin with its own connector (e.g. " && resource_type:=3").
            if ($filter_by === '') {
                $filter_by = preg_replace('/^\s*&&\s*/', '', $global);
            } else {
                $filter_by .= (strpos(ltrim($global), '&&') === 0 ? ' ' : ' && ') . ltrim($global);
            }
        }

        return $filter_by;
    }

    /**
     * Compile the plan into Typesense search parameters, without the result window.
     *
     * @return array
     */
    public function compileParams(): array
    {
        $params = array(
            'q' => $this->q === '' ? '*' : $this->q,
            'num_typos' => $this->num_typos,
            'validate_field_names' => 0,
            // Require every query token to match, as core's AND keyword search does.
            'drop_tokens_threshold' => 0,
        );

        // Omit query_by when empty (a match-all search).
        if (count($this->query_by) > 0) {
            $params['query_by'] = implode(',', array_keys($this->query_by));

            $weights = array_values($this->query_by);
            if (count(array_unique($weights)) > 1) {
                $params['query_by_weights'] = implode(',', $weights);
            }
        }

        if ($this->prefix !== null) {
            $params['prefix'] = $this->prefix;
        }

        if (count($this->sort_by) > 0) {
            $params['sort_by'] = implode(',', $this->sort_by);
        }

        $filter_by = $this->compileFilterBy();
        if ($filter_by !== '') {
            $params['filter_by'] = $filter_by;
        }

        return $params;
    }
}


/**
 * Decompose the raw search string once onto the context: trailing wildcard, leading special
 * command (+ its immediate argument), field:value pairs and the remaining free-text terms.
 */
function typesense_parse_search(TypesenseSearchContext $ctx): void
{
    $search = trim($ctx->search);

    if ($search !== '' && substr($search, -1) === '*') {
        $ctx->wildcard = true;
    }

    // Special command: a leading "!" followed by letters, e.g. !collection123 / !last50.
    if ($search !== '' && $search[0] === '!') {
        if (preg_match('/^!([a-z]+)/i', $search, $m)) {
            $ctx->command = strtolower($m[1]);
            $after = substr($search, strlen($m[0]));
            $ctx->command_arg = explode(' ', $after)[0];
        }
        return;
    }

    // field:value pairs (shortname:value or shortname:"quoted value").
    $remaining = $search;
    if (preg_match_all('/([a-zA-Z0-9_]+):("[^"]*"|\S+)/', $search, $pairs, PREG_SET_ORDER)) {
        foreach ($pairs as $pair) {
            $ctx->field_values[strtolower($pair[1])] = trim($pair[2], '"');
            $remaining = str_replace($pair[0], '', $remaining);
        }
    }

    $ctx->terms = array_values(array_filter(preg_split('/\s+/', trim($remaining)), 'strlen'));
}


/**
 * The collection ref of a !collection search, parsed as core does ("!collection123,456" is 123).
 */
function typesense_search_collection_ref(TypesenseSearchContext $ctx): int
{
    return (int)explode(',', $ctx->command_arg)[0];
}


/**
 * Whether a collection's memberships are indexed. Selection and upload collections, and any
 * collection with a negative ref, are not.
 *
 * @param int $collection Collection ref.
 * @param int $type       collection.type.
 */
function typesense_search_membership_indexed(int $collection, int $type): bool
{
    return $collection > 0 && !in_array($type, array(COLLECTION_TYPE_UPLOAD, COLLECTION_TYPE_SELECTION), true);
}


/**
 * SQL twin of typesense_search_membership_indexed(), for a query joining collection_resource $cr to
 * collection $c.
 */
function typesense_search_membership_indexed_sql(string $cr = 'cr', string $c = 'c'): string
{
    return $cr . '.collection > 0 AND ' . $c . '.type NOT IN ('
        . (int)COLLECTION_TYPE_UPLOAD . ', ' . (int)COLLECTION_TYPE_SELECTION . ')';
}


/**
 * Whether a search of this collection can be served from the index. An unknown collection is left
 * to the collection mode's readability check.
 */
function typesense_search_collection_indexed(int $collection): bool
{
    if ($collection <= 0) {
        return false;
    }
    $type = ps_value('SELECT type value FROM collection WHERE ref = ?', array('i', $collection), null);
    if ($type === null || $type === '') {
        return true;
    }
    return typesense_search_membership_indexed($collection, (int)$type);
}


/**
 * Apply keyword matching and node-bucket filtering to the plan. Shared by every mode, as in core.
 * May mark the search unsupported.
 */
function typesense_apply_keyword_matching(TypesenseSearchContext $ctx, TypesenseQueryPlan $plan): void
{
    global $wildcard_always_applied, $view_title_field;

    $q = typesense_build_q_from_keywords($ctx, $plan);
    if (!$plan->supported) {
        return;
    }
    $plan->q = $q === '' ? '*' : $q;

    // One value for every query_by field. Typesense prefixes the last token only.
    if ($ctx->wildcard || !empty($wildcard_always_applied)) {
        $plan->prefix = 'true';
    }

    // Title (when viewable) has the highest weight; ref_s lets a resource number match as text.
    if (metadata_field_view_access((int)$view_title_field)) {
        $plan->addQueryBy('title', 10);
    }
    $plan->addQueryBy('ref_s', 1);
    foreach (typesense_build_query_by() as $field) {
        if ($field === 'title' || $field === '') {
            continue;
        }
        $plan->addQueryBy($field, 2);
    }

    typesense_apply_node_buckets($ctx, $plan);
}


/**
 * Build the q string from $ctx->keywords. Field-scoped tokens become filters or mark the search
 * unsupported; fixed-list ones are dropped, as core has already put them in $node_bucket.
 */
function typesense_build_q_from_keywords(TypesenseSearchContext $ctx, TypesenseQueryPlan $plan): string
{
    global $FIXED_LIST_FIELD_TYPES;

    $terms = array();
    $wildcard_terms = array();

    foreach ($ctx->keywords as $keyword) {
        $keyword = trim((string)$keyword);
        if ($keyword === '') {
            continue;
        }

        $is_quoted = substr($keyword, 0, 1) === '"' || substr($keyword, 0, 2) === '-"';

        // Full-text boolean search has no Typesense equivalent.
        if ($is_quoted) {
            $ft_prefix = defined('FULLTEXT_SEARCH_PREFIX') ? FULLTEXT_SEARCH_PREFIX : '@FULL_TEXT';
            $inner = substr($keyword, 0, 1) === '-' ? substr($keyword, 2) : substr($keyword, 1);
            if ($ft_prefix !== '' && strpos($inner, $ft_prefix) === 0) {
                $plan->markUnsupported('full-text boolean search not handled by Typesense');
                return '';
            }
        }

        if (!$is_quoted && strpos($keyword, ':') !== false) {
            if (substr($keyword, 0, 1) === '-') {
                $plan->markUnsupported('negative field-scoped search not handled by Typesense');
                return '';
            }

            $parts = explode(':', $keyword, 2);
            $field = typesense_search_field_by_shortname($parts[0]);

            if ($field !== null) {
                if (in_array($field['type'], (array)$FIXED_LIST_FIELD_TYPES, true)) {
                    continue; // already in $node_bucket
                }
                // Never scope a search to a field the user can't view (would probe hidden data).
                if (!metadata_field_view_access((int)$field['ref'])) {
                    $plan->markUnsupported('field-scoped search on a non-viewable field');
                    return '';
                }
                // OR-groups (";") can't be reproduced in a single Typesense field filter -> veto.
                if (strpos($parts[1], ';') !== false) {
                    $plan->markUnsupported('OR-group (";") in a field-scoped search not handled by Typesense');
                    return '';
                }
                // A text or date field:value becomes a filter clause.
                $clause = typesense_search_fieldvalue_filter($field, $parts[1]);
                if ($clause === null) {
                    $plan->markUnsupported('field-scoped search on field "' . $parts[0] . '" not supported');
                    return '';
                }
                $plan->addFilter($clause);
                continue; // now a filter, not free text
            }

            $keyword = str_replace(':', ' ', $keyword); // incidental colon
        }

        // OR-groups ("red;green") have no Typesense equivalent.
        if (strpos($keyword, ';') !== false) {
            $plan->markUnsupported('OR-group (";") not handled by Typesense');
            return '';
        }

        $is_wildcard = substr($keyword, -1) === '*';
        if ($is_wildcard) {
            $keyword = substr($keyword, 0, -1); // the wildcard becomes Typesense's prefix flag
        }

        $keyword = trim($keyword);
        if ($keyword !== '') {
            if ($is_wildcard) {
                $wildcard_terms[] = $keyword;
            } else {
                $terms[] = $keyword;
            }
        }
    }

    // Typesense only prefixes the last token, so a wildcarded word goes last; two can't be expressed.
    if (count($wildcard_terms) > 1) {
        $plan->markUnsupported('more than one wildcard keyword not handled by Typesense');
        return '';
    }
    if (count($wildcard_terms) === 1) {
        $terms[] = $wildcard_terms[0];
        $plan->prefix = 'true';
    }

    return trim(implode(' ', $terms));
}


/**
 * Translate node buckets into Typesense node filters: AND across buckets, OR within a bucket
 * (unless $category_tree_search_use_and_logic requires every node), plus exclusions.
 */
function typesense_apply_node_buckets(TypesenseSearchContext $ctx, TypesenseQueryPlan $plan): void
{
    global $category_tree_search_use_and_logic;

    foreach ($ctx->node_bucket as $bucket) {
        $bucket = array_values(array_filter(array_map('intval', (array)$bucket)));
        if (count($bucket) === 0) {
            continue;
        }

        if (!empty($category_tree_search_use_and_logic)) {
            foreach ($bucket as $node) {
                $plan->addFilter('nodes:=[' . $node . ']');
            }
        } else {
            $plan->addFilter('nodes:=[' . implode(',', $bucket) . ']');
        }
    }

    $not = array_values(array_filter(array_map('intval', $ctx->node_bucket_not)));
    if (count($not) > 0) {
        $plan->addFilter('nodes:!=[' . implode(',', $not) . ']');
    }
}


/**
 * Map the ResourceSpace order_by to a Typesense sort, unless a mode already set one. An order
 * with no sortable Typesense field marks the search unsupported.
 */
function typesense_apply_default_sort(TypesenseSearchContext $ctx, TypesenseQueryPlan $plan): void
{
    global $date_field;

    if (count($plan->sort_by) > 0) {
        return; // a mode set an explicit sort
    }

    $order_by = trim((string)$ctx->order_by);
    $direction = strtolower($ctx->sort) === 'asc' ? 'asc' : 'desc';

    if ($order_by === '' || strpos($order_by, 'score') === 0) {
        $field = '_text_match'; // relevance
    } elseif (preg_match('/^field' . (int)$date_field . '\b/', $order_by) === 1) {
        // Word boundary, so that e.g. field120 isn't taken for field12.
        $field = 'date_field_sort';
    } elseif (strpos($order_by, 'r.ref') === 0 || strpos($order_by, 'ref ') === 0) {
        $field = 'ref';
    } elseif (strpos($order_by, 'modified') === 0) {
        $field = 'modified_date';
    } else {
        $plan->markUnsupported('unsupported sort order: ' . $order_by);
        return;
    }

    // _text_match is meaningless for a match-all query; fall back to ref order.
    if ($field === '_text_match' && $plan->q === '*') {
        $field = 'ref';
    }

    $plan->setSort($field, $direction);

    // Break ties by ref, as core's date and modified sorts do.
    if ($field === 'date_field_sort' || $field === 'modified_date') {
        $plan->setSort('ref', $direction);
    }
}


/**
 * Resolve a field short name to its ref and type, or null if it is not a field.
 *
 * @return array{ref:int,type:int}|null
 */
function typesense_search_field_by_shortname(string $name): ?array
{
    static $cache = array();

    $key = mb_strtolower($name);
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    $rows = ps_query(
        "SELECT ref, `type`, partial_index FROM resource_type_field WHERE name = ?",
        array('s', $name),
        'schema'
    );

    $cache[$key] = count($rows) > 0
        ? array('ref' => (int)$rows[0]['ref'], 'type' => (int)$rows[0]['type'])
        : null;

    return $cache[$key];
}


/**
 * Build a filter_by clause for a field:value search on a text or date field. Returns null if the
 * search is not supported.
 *
 * @param array{ref:int,type:int} $field
 */
function typesense_search_fieldvalue_filter(array $field, string $value): ?string
{
    $prefix = 'field_' . (int)$field['ref'];
    $type = (int)$field['type'];

    // Numeric range (e.g. mynumberfield:numrange1|1234).
    if (strpos($value, 'numrange') === 0) {
        return typesense_search_numrange_filter($prefix, $value);
    }

    $is_date = in_array($type, array(
        FIELD_TYPE_DATE,
        FIELD_TYPE_DATE_AND_OPTIONAL_TIME,
        FIELD_TYPE_EXPIRY_DATE,
        FIELD_TYPE_DATE_RANGE,
    ), true);

    // Date range (e.g. eventdate:rangestart2020-01-01end2020-12-31).
    if ($is_date && strpos($value, 'range') === 0) {
        return typesense_search_daterange_filter($prefix, $value);
    }

    $value = typesense_search_clean_text($value);

    // A partially indexed field matches on word prefixes, as core's partial index does.
    global $partial_index_min_word_length;
    $min = isset($partial_index_min_word_length) ? (int)$partial_index_min_word_length : 3;
    if (
        !empty($field['partial_index'])
        && in_array($type, array(FIELD_TYPE_TEXT_BOX_SINGLE_LINE, FIELD_TYPE_WARNING_MESSAGE, FIELD_TYPE_TEXT_BOX_MULTI_LINE, FIELD_TYPE_TEXT_BOX_LARGE_MULTI_LINE, FIELD_TYPE_TEXT_BOX_FORMATTED_AND_TINYMCE), true)
        && preg_match('/^[\p{L}\p{N}_]+$/u', $value) === 1
        && mb_strlen($value) >= $min
    ) {
        $value .= '*';
    }

    switch ($type) {
        case FIELD_TYPE_TEXT_BOX_SINGLE_LINE:
        case FIELD_TYPE_WARNING_MESSAGE:
            $key = $prefix . '_s';
            break;
        case FIELD_TYPE_TEXT_BOX_MULTI_LINE:
        case FIELD_TYPE_TEXT_BOX_LARGE_MULTI_LINE:
        case FIELD_TYPE_TEXT_BOX_FORMATTED_AND_TINYMCE:
            $key = $prefix . '_text';
            break;
        case FIELD_TYPE_DATE:
        case FIELD_TYPE_DATE_AND_OPTIONAL_TIME:
        case FIELD_TYPE_EXPIRY_DATE:
        case FIELD_TYPE_DATE_RANGE:
            // Exact match on a date representation, as core's LIKE '<value>%' does.
            return $prefix . '_q:=' . typesense_search_filter_value($value);
        default:
            return null;
    }

    // Bare colon is a word-contains match.
    return $key . ':' . typesense_search_filter_value($value);
}


/**
 * Build a filter for a date range field:value search ("rangestart<A>end<B>", either end optional),
 * matching resources whose date overlaps the range. Returns null if neither end parses.
 */
function typesense_search_daterange_filter(string $prefix, string $value): ?string
{
    $body = substr($value, strlen('range')); // strip leading "range"
    $start_pos = strpos($body, 'start');
    $end_pos = strpos($body, 'end');

    $start_date = null;
    $end_date = null;

    if ($start_pos !== false) {
        $from = $start_pos + strlen('start');
        $length = ($end_pos !== false && $end_pos > $from) ? $end_pos - $from : null;
        $start_date = $length === null ? substr($body, $from) : substr($body, $from, $length);
    }
    if ($end_pos !== false) {
        $end_date = substr($body, $end_pos + strlen('end'));
    }

    $clauses = array();

    if (is_string($start_date) && trim($start_date) !== '') {
        $parsed = typesense_parse_date(str_replace(' ', '-', trim($start_date)));
        if ($parsed !== null && $parsed['range_start'] !== null) {
            $clauses[] = $prefix . '_range_end:>' . (int)$parsed['range_start'];
        }
    }
    if (is_string($end_date) && trim($end_date) !== '') {
        $parsed = typesense_parse_date(str_replace(' ', '-', trim($end_date)));
        if ($parsed !== null && $parsed['range_end'] !== null) {
            $clauses[] = $prefix . '_range_start:<' . (int)$parsed['range_end'];
        }
    }

    if (count($clauses) === 0) {
        return null;
    }

    return implode(' && ', $clauses);
}


/**
 * Build a filter for a numeric range field:value search ("numrange<min>|<max>", "neg" for a minus
 * sign). As in core, a single bound is an exact match. Returns null if nothing numeric parses.
 */
function typesense_search_numrange_filter(string $prefix, string $value): ?string
{
    $body = substr($value, strlen('numrange')); // "<min>|<max>"
    $parts = explode('|', $body, 2);

    $min = str_replace('neg', '-', trim($parts[0]));
    $max = isset($parts[1]) ? str_replace('neg', '-', trim($parts[1])) : '';

    $has_min = $min !== '' && is_numeric($min);
    $has_max = $max !== '' && is_numeric($max);

    if ($has_min && $has_max) {
        return $prefix . '_f:[' . $min . '..' . $max . ']';
    }
    // A single bound is an exact match in core, not a one-sided range.
    if ($has_min) {
        return $prefix . '_f:=' . $min;
    }
    if ($has_max) {
        return $prefix . '_f:=' . $max;
    }

    return null;
}


/**
 * Normalise text as RS's keyword indexing does (cleanse_string()): remove invisible format
 * characters and turn every Unicode space into a plain space.
 */
function typesense_search_clean_text(string $value): string
{
    $clean = preg_replace('/\p{Cf}/u', '', $value);
    $clean = preg_replace('/\p{Zs}/u', ' ', $clean ?? $value);
    return $clean ?? $value;
}


/**
 * Escape a filter_by value: anything other than a simple word (optionally with a trailing
 * wildcard) is backtick-quoted.
 */
function typesense_search_filter_value(string $value): string
{
    if (preg_match('/[^\p{L}\p{N}_*]/u', $value)) {
        return '`' . str_replace('`', '', $value) . '`';
    }
    return $value;
}


/**
 * Ordered list of search modes; the first whose applies() returns true claims the search.
 * Extend via the "typesense_search_modes" hook.
 *
 * @return TypesenseSearchComponent[]
 */
function typesense_search_modes(): array
{
    $modes = array(
        new TypesenseCollectionMode(),
        new TypesenseLastMode(),
        new TypesenseListMode(),
        new TypesenseContributionsMode(),
        new TypesenseHasDataMode(),
        new TypesenseArchivePendingMode(),
        new TypesenseUserPendingMode(),
        new TypesenseResourceRefMode(),
        new TypesenseUnsupportedSpecialMode(),
        new TypesenseStandardSearchMode(),
    );

    $extra = hook('typesense_search_modes');
    if (is_array($extra)) {
        // Hook modes go before the standard catch-all.
        array_splice($modes, count($modes) - 1, 0, $extra);
    }

    return $modes;
}


/**
 * Ordered list of restrictions; all that apply run for every mode. Extend via the
 * "typesense_search_restrictions" hook.
 *
 * @return TypesenseSearchComponent[]
 */
function typesense_search_restrictions(): array
{
    $restrictions = array(
        new TypesenseStandardRestrictions(),
        new TypesenseFeaturedCollectionsRestriction(),
        new TypesenseGroupFilterRestriction(),
        new TypesenseAccessRestriction(),
    );

    $extra = hook('typesense_search_restrictions');
    if (is_array($extra)) {
        $restrictions = array_merge($restrictions, $extra);
    }

    return $restrictions;
}


/**
 * Build the query plan for a search: pick the one applicable mode, then apply every restriction.
 *
 * @return TypesenseQueryPlan|null Null if the search is unsupported (fall back to MySQL).
 */
function typesense_search_build_query(TypesenseSearchContext $ctx): ?TypesenseQueryPlan
{
    $plan = new TypesenseQueryPlan();

    // Result window from fetchrows; -1 means every row from the offset.
    setup_search_chunks($ctx->fetchrows, $chunk_offset, $search_chunk_size);
    $plan->offset = (int)$chunk_offset;
    $plan->limit = $search_chunk_size < 0 ? -1 : (int)$search_chunk_size;

    // Select and run the single applicable mode.
    $selected_mode = null;
    foreach (typesense_search_modes() as $mode) {
        if ($mode->applies($ctx)) {
            $selected_mode = $mode;
            break;
        }
    }

    if ($selected_mode === null) {
        return null;
    }

    $plan->special_search = !($selected_mode instanceof TypesenseStandardSearchMode);
    $selected_mode->build($ctx, $plan);

    if (!$plan->supported) {
        debug('typesense_search: unsupported (' . $plan->fallback_reason . ')');
        return null;
    }

    // Keyword matching and node buckets apply to every search, as in core, unless the mode opts out.
    if ($plan->keyword_matching) {
        typesense_apply_keyword_matching($ctx, $plan);
        if (!$plan->supported) {
            debug('typesense_search: unsupported (' . $plan->fallback_reason . ')');
            return null;
        }
    }

    // Apply the restrictions, unless the mode suppressed them all.
    $restrictions = $plan->isSuppressed('*') ? array() : typesense_search_restrictions();
    foreach ($restrictions as $restriction) {
        if ($restriction->applies($ctx)) {
            $restriction->build($ctx, $plan);
            if (!$plan->supported) {
                debug('typesense_search: unsupported by restriction (' . $plan->fallback_reason . ')');
                return null;
            }
        }
    }

    // Default sort, if no mode set one.
    typesense_apply_default_sort($ctx, $plan);
    if (!$plan->supported) {
        debug('typesense_search: unsupported (' . $plan->fallback_reason . ')');
        return null;
    }

    return $plan;
}


/**
 * The ref cutoff for a most-recent N selection (!last<num>): the Nth-highest ref among the plan's
 * matches, or null when there are N or fewer.
 */
function typesense_search_recent_cutoff(TypesenseQueryPlan $plan, int $n): ?int
{
    if ($n < 1) {
        return null;
    }

    $params = $plan->compileParams();
    // Sort newest first and fetch just the Nth row; only the ref is needed.
    $params['sort_by'] = 'ref:desc';
    $params['include_fields'] = 'ref';

    $window = typesense_search_fetch_window($plan, $params, $n - 1, 1);
    if ($window === false) {
        return null;
    }

    // N or fewer matches - no cutoff needed.
    if ($window['found'] <= $n) {
        return null;
    }

    return $window['refs'][0] ?? null;
}


/**
 * Append the resource refs of a page of Typesense hits to $refs.
 */
function typesense_search_collect_refs(array $hits, array &$refs): void
{
    foreach ($hits as $hit) {
        if (isset($hit['document']['ref'])) {
            $refs[] = (int)$hit['document']['ref'];
        }
    }
}


/**
 * Count the operations in a filter_by expression as Typesense does for --filter-by-max-ops: each
 * clause and each && or || counts one. A $collection(...) join counts as one clause and its inner
 * expression is counted separately; the larger figure is returned.
 */
function typesense_search_filter_ops(string $filter_by): int
{
    $filter_by = trim($filter_by);
    if ($filter_by === '') {
        return 0;
    }

    $connectors = 0;
    $largest_inner = 0;
    $quoted = false;
    $length = strlen($filter_by);
    for ($i = 0; $i < $length; $i++) {
        $char = $filter_by[$i];
        if ($char === '`') {
            $quoted = !$quoted;
            continue;
        }
        if ($quoted) {
            continue;
        }

        // Join clause: count its inner expression separately.
        if ($char === '$' && preg_match('/\G\$[A-Za-z0-9_.-]+\(/', $filter_by, $join, 0, $i) === 1) {
            $start = $i + strlen($join[0]);
            $depth = 1;
            $inner_quoted = false;
            for ($j = $start; $j < $length && $depth > 0; $j++) {
                if ($filter_by[$j] === '`') {
                    $inner_quoted = !$inner_quoted;
                } elseif (!$inner_quoted && $filter_by[$j] === '(') {
                    $depth++;
                } elseif (!$inner_quoted && $filter_by[$j] === ')') {
                    $depth--;
                }
            }
            // $j is one past the closing parenthesis.
            $inner_end = $depth === 0 ? $j - 1 : $length;
            $largest_inner = max(
                $largest_inner,
                typesense_search_filter_ops(substr($filter_by, $start, $inner_end - $start))
            );
            $i = $j - 1;
            continue;
        }

        if (($char === '&' || $char === '|') && $i + 1 < $length && $filter_by[$i + 1] === $char) {
            $connectors++;
            $i++;
        }
    }

    return max(2 * $connectors + 1, $largest_inner);
}


/**
 * Run one or more windows of a compiled query through multi_search, up to 50 searches per
 * request. POST is used for every search because GET caps the query string at 4,000 bytes.
 * Returns false without sending anything if the filter exceeds $typesense_search_filter_max_ops.
 *
 * @param array $windows [offset, limit] pairs, one per search.
 * @return array[]|false One search result per window, in order.
 */
function typesense_search_multi_search(TypesenseQueryPlan $plan, array $params, array $windows)
{
    global $typesense_search_collection_prefix, $typesense_search_filter_max_ops;

    if (isset($params['filter_by'])) {
        $max_ops = isset($typesense_search_filter_max_ops) ? (int)$typesense_search_filter_max_ops : 100;
        $ops = typesense_search_filter_ops((string)$params['filter_by']);
        if ($max_ops > 0 && $ops > $max_ops) {
            debug(
                'typesense_search_multi_search(): filter_by costs ' . $ops . ' operations, more than '
                . '$typesense_search_filter_max_ops (' . $max_ops . '), so the MySQL search will handle it'
            );
            return false;
        }
    }

    $searches = array();
    foreach ($windows as $window) {
        $searches[] = array_merge($params, array(
            'collection' => $typesense_search_collection_prefix . $plan->collection,
            'offset' => (int)$window[0],
            'limit' => (int)$window[1],
        ));
    }

    $results = array();
    foreach (array_chunk($searches, 50) as $batch) {
        $response = typesense_search_request('POST', '/multi_search', false, array('searches' => $batch));
        if (
            $response === false
            || !isset($response['results'])
            || !is_array($response['results'])
            || count($response['results']) !== count($batch)
        ) {
            return false;
        }

        foreach ($response['results'] as $result) {
            // A search that fails reports its error in place of hits; the response is still HTTP 200.
            if (!isset($result['hits']) || !is_array($result['hits'])) {
                debug('typesense_search_multi_search(): search failed: ' . ($result['error'] ?? 'no hits returned'));
                return false;
            }
            $results[] = $result;
        }
    }

    return $results;
}


/**
 * Fetch rows $offset to $offset + $limit - 1 of a compiled query, with the total. $limit can be at
 * most 250, the most hits Typesense returns per request; 0 fetches just the total.
 *
 * @return array{found:int,refs:int[]}|false
 */
function typesense_search_fetch_window(TypesenseQueryPlan $plan, array $params, int $offset, int $limit)
{
    $results = typesense_search_multi_search($plan, $params, array(array($offset, $limit)));
    if ($results === false) {
        return false;
    }

    $refs = array();
    typesense_search_collect_refs($results[0]['hits'], $refs);

    return array('found' => (int)($results[0]['found'] ?? count($refs)), 'refs' => $refs);
}


/**
 * Fetch $count rows of a compiled query from $offset on, 250 per page.
 *
 * @return int[]|false Refs in result order.
 */
function typesense_search_fetch_pages(TypesenseQueryPlan $plan, array $params, int $offset, int $count)
{
    $page_size = 250;
    $windows = array();
    for ($start = $offset; $start < $offset + $count; $start += $page_size) {
        $windows[] = array($start, min($page_size, $offset + $count - $start));
    }

    $results = typesense_search_multi_search($plan, $params, $windows);
    if ($results === false) {
        return false;
    }

    $refs = array();
    foreach ($results as $result) {
        typesense_search_collect_refs($result['hits'], $refs);
    }

    return $refs;
}


/**
 * Execute a compiled query plan against Typesense. Returns the refs in the plan's result window,
 * in order, and the total number of matches.
 *
 * @return array{refs:int[],total:int}|false False to fall back to the MySQL search.
 */
function typesense_search_execute(TypesenseQueryPlan $plan)
{
    global $typesense_search_only, $typesense_search_max_rows;

    // Most-recent N selection: restrict the set to the N highest refs with a ref cutoff.
    if ($plan->recent_selection !== null) {
        $cutoff = typesense_search_recent_cutoff($plan, $plan->recent_selection);
        if ($cutoff !== null) {
            $plan->addFilter('ref:>=' . $cutoff);
        }
    }

    $params = $plan->compileParams();
    // Only the ref of each hit is used.
    $params['include_fields'] = 'ref';

    // The first request returns the total and the start of the window.
    $first_limit = $plan->limit < 0 ? 250 : min($plan->limit, 250);
    $first = typesense_search_fetch_window($plan, $params, $plan->offset, $first_limit);
    if ($first === false) {
        return false;
    }

    debug('typesense_search_execute(): found=' . $first['found']);

    // A result cap (e.g. !last<num>) limits the total.
    $total = $plan->result_limit !== null ? min($first['found'], $plan->result_limit) : $first['found'];

    // Rows in the window, from the offset.
    $window = max(0, $total - $plan->offset);
    if ($plan->limit >= 0) {
        $window = min($window, $plan->limit);
    }

    $refs = array_slice($first['refs'], 0, $window);

    if ($window > $first_limit) {
        $max_rows = (int)$typesense_search_max_rows;
        if (empty($typesense_search_only) && $max_rows > 0 && $window > $max_rows) {
            debug(
                'typesense_search_execute(): ' . $window . ' rows requested, more than $typesense_search_max_rows ('
                . $max_rows . '), so the MySQL search will handle it'
            );
            return false;
        }

        $more = typesense_search_fetch_pages($plan, $params, $plan->offset + $first_limit, $window - $first_limit);
        if ($more === false) {
            return false;
        }

        // Drop a ref repeated on a later page if the index changed between requests.
        $refs = array_values(array_unique(array_merge($refs, $more)));
    }

    return array('refs' => $refs, 'total' => $total);
}


/**
 * Top-level driver: build the plan for a context, execute it and hydrate the refs. Returns a
 * ResourceSpace-compatible result set, or false to fall back to core MySQL search.
 *
 * @return array|false
 */
function typesense_search_run(TypesenseSearchContext $ctx)
{
    $plan = typesense_search_build_query($ctx);
    if ($plan === null) {
        return false;
    }

    $result = typesense_search_execute($plan);
    if ($result === false) {
        return false;
    }

    return typesense_search_hydrate_refs(
        $result['refs'],
        $result['total'],
        $ctx->fetchrows,
        $ctx->return_refs_only,
        $plan->open_access ? typesense_search_open_access_select($ctx->select) : $ctx->select,
        (string)$ctx->order_by,
        $plan->special_search
    );
}


/**
 * The SELECT for results presented as open access: the custom-access columns read as 0, as core
 * does for a user's own contributions.
 *
 * @param PreparedStatementQuery|null $select
 * @return PreparedStatementQuery|null
 */
function typesense_search_open_access_select($select)
{
    if (!is_object($select)) {
        return $select;
    }
    $copy = clone $select;
    $copy->sql = str_replace(array('rca.access', 'rca2.access'), '0', (string)$copy->sql);
    return $copy;
}


/**
 * Result when Typesense could not handle a search: false to let core search, or an empty result
 * set in Typesense-only mode.
 *
 * @return array|false
 */
function typesense_search_fallback_result(TypesenseSearchContext $ctx)
{
    global $typesense_search_only;

    if (!empty($typesense_search_only)) {
        return typesense_search_hydrate_refs(
            array(),
            0,
            $ctx->fetchrows,
            $ctx->return_refs_only,
            $ctx->select,
            (string)$ctx->order_by
        );
    }

    return false;
}


// Load the registered modes and restrictions.
require_once __DIR__ . '/modes/standard.php';
require_once __DIR__ . '/modes/collection.php';
require_once __DIR__ . '/modes/last.php';
require_once __DIR__ . '/modes/list.php';
require_once __DIR__ . '/modes/resource_ref.php';
require_once __DIR__ . '/modes/contributions.php';
require_once __DIR__ . '/modes/hasdata.php';
require_once __DIR__ . '/modes/archive_pending.php';
require_once __DIR__ . '/modes/user_pending.php';
require_once __DIR__ . '/modes/unsupported_special.php';
require_once __DIR__ . '/restrictions/standard.php';
require_once __DIR__ . '/restrictions/featured_collections.php';
require_once __DIR__ . '/restrictions/group_filter.php';
require_once __DIR__ . '/restrictions/access.php';
