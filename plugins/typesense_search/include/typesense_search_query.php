<?php

/**
 * Extensible query-build pipeline for the typesense_search plugin.
 *
 * Flow: Context -> Parse -> Mode -> Restrictions -> Compile -> Execute -> Hydrate.
 *
 * A single "mode" decides the query shape (mirrors core search_special() vs standard) and
 * "restrictions" layer visibility/scope on top of any mode (mirrors core search_filter()).
 * New search types are added by registering a new mode or restriction - the orchestrator is
 * never edited. See the plan for the full design.
 */

/**
 * Normalised input for a single search, built once from the External_search hook arguments
 * plus a snapshot of the current user context. Parsing populates the $command / $keywords /
 * $field_values / $wildcard properties.
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
     * @var int|null "Most-recent N" selection (e.g. !last<num>): the result set is the N
     * highest-ref resources matching the other criteria, but the display order is still the
     * user's chosen sort. Resolved to a `ref:>=<cutoff>` filter at execute time. This mirrors
     * core's !last, whose inner query picks the newest N (ref desc) and whose outer query
     * re-sorts them by the requested order_by.
     */
    public ?int $recent_selection = null;

    /** @var array<string,bool> Restrictions a mode has opted out of. */
    public array $suppressed = array();

    /** @var int */
    public int $page = 1;

    /** @var int */
    public int $per_page = 250;

    /** @var string Target collection short name (without prefix), e.g. "resources". */
    public string $collection = 'resources';

    /** @var bool */
    public bool $supported = true;

    /** @var string|null */
    public ?string $fallback_reason = null;

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
     * Select the N most-recent resources (highest refs) as the result set, while leaving the
     * display sort to the normal sort mapping. See $recent_selection.
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

    public function isSuppressed(string $name): bool
    {
        return !empty($this->suppressed[$name]);
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
            // The configured global filter is expected to begin with its own connector
            // (e.g. " && resource_type:=3"); append as-is when we already have clauses,
            // otherwise strip a leading connector.
            if ($filter_by === '') {
                $filter_by = preg_replace('/^\s*&&\s*/', '', $global);
            } else {
                $filter_by .= (strpos(ltrim($global), '&&') === 0 ? ' ' : ' && ') . ltrim($global);
            }
        }

        return $filter_by;
    }

    /**
     * Compile the plan into the query parameters for the documents/search endpoint.
     *
     * @return array
     */
    public function compileParams(): array
    {
        $params = array(
            'q' => $this->q === '' ? '*' : $this->q,
            'num_typos' => $this->num_typos,
            'page' => $this->page,
            'per_page' => $this->per_page,
            'validate_field_names' => 0,
            // Require every query token to match, mirroring core's AND keyword semantics
            // (Typesense would otherwise drop tokens to find more results).
            'drop_tokens_threshold' => 0,
        );

        // query_by is only required for keyword matching; a match-all (q=*) mode may leave it
        // empty, in which case it must be omitted rather than sent as an empty string.
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
 * Apply keyword matching and node-bucket filtering to the plan.
 *
 * Shared by the standard search and every special mode. This mirrors core, where the keyword
 * match (join + criteria) and node buckets are baked into $sql_join / $sql_filter and applied to
 * *all* searches - so e.g. "!collection123 sunset" or a fixed-list refine within a collection
 * work. Builds q from $keywords (with the field-token split), sets query_by (respecting field
 * visibility) and node filters. May veto (a field-scoped text/date search, or a negative field
 * search) so the whole search falls back to MySQL.
 */
function typesense_apply_keyword_matching(TypesenseSearchContext $ctx, TypesenseQueryPlan $plan): void
{
    global $wildcard_always_applied, $view_title_field;

    $q = typesense_build_q_from_keywords($ctx, $plan);
    if (!$plan->supported) {
        return;
    }
    $plan->q = $q === '' ? '*' : $q;

    if ($ctx->wildcard || !empty($wildcard_always_applied)) {
        $plan->prefix = 'true,true';
    }

    // query_by - title (only when viewable) carries the highest weight; ref_s lets a resource
    // number match as text. typesense_build_query_by() is already limited to viewable fields.
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
 * Build the q string from $ctx->keywords, splitting off field-scoped tokens:
 *   - fixed-list "shortname:value" is already resolved into $node_bucket by core -> dropped from q;
 *   - a named search on a non-fixed-list field, or a negative field search, is not yet
 *     reproducible in Typesense -> veto to MySQL;
 *   - a colon that is not a field short name (e.g. a time "12:30") is treated as free text.
 */
function typesense_build_q_from_keywords(TypesenseSearchContext $ctx, TypesenseQueryPlan $plan): string
{
    global $FIXED_LIST_FIELD_TYPES;

    $terms = array();

    foreach ($ctx->keywords as $keyword) {
        $keyword = trim((string)$keyword);
        if ($keyword === '') {
            continue;
        }

        $is_quoted = substr($keyword, 0, 1) === '"' || substr($keyword, 0, 2) === '-"';

        // Full-text boolean search ("@FULL_TEXT…") relies on MySQL boolean-mode operators that
        // Typesense has no equivalent for -> veto to core.
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
                    continue; // fixed-list already resolved into $node_bucket by core
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
                // Text/date field:value -> a bare-colon (word-contains), date range, or _q clause.
                $clause = typesense_search_fieldvalue_filter($field, $parts[1]);
                if ($clause === null) {
                    $plan->markUnsupported('field-scoped search on field "' . $parts[0] . '" not supported');
                    return '';
                }
                $plan->addFilter($clause);
                continue; // consumed as a filter; not free-text
            }

            $keyword = str_replace(':', ' ', $keyword); // incidental colon
        }

        // OR-groups ("red;green") have no clean Typesense equivalent (no term-level OR across
        // query_by in one query) -> veto to core, which ORs them correctly.
        if (strpos($keyword, ';') !== false) {
            $plan->markUnsupported('OR-group (";") not handled by Typesense');
            return '';
        }

        if (substr($keyword, -1) === '*') {
            $keyword = substr($keyword, 0, -1); // trailing wildcard handled via prefix
        }

        $keyword = trim($keyword);
        if ($keyword !== '') {
            $terms[] = $keyword;
        }
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
 * Apply the default RS-order-by -> Typesense sort mapping, unless a mode already set a sort.
 * Fixed plumbing shared by all searches.
 *
 * $order_by is a resolved SQL fragment (see set_search_order_by()). Only the orders backed by a
 * sortable Typesense field are supported; anything else (rating, popularity, colour, title,
 * random, status, custom-field sorts, ...) vetoes so core sorts it correctly rather than the
 * plugin silently returning relevance order.
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

    // Core's date and modified sorts end in "r.ref <dir>" ("field<$date_field> <dir>, r.ref <dir>" and
    // "modified <dir>, r.ref <dir>"), so break ties by ref.
    if ($field === 'date_field_sort' || $field === 'modified_date') {
        $plan->setSort('ref', $direction);
    }
}


/**
 * Resolve a field short name (as used in "shortname:value" searches) to its ref and type.
 * Uses the same lookup as core do_search_keywords.php. Returns null when the name is not a
 * field (i.e. the colon is incidental, such as a time "12:30").
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
        "SELECT ref, `type` FROM resource_type_field WHERE name = ?",
        array('s', $name),
        'schema'
    );

    $cache[$key] = count($rows) > 0
        ? array('ref' => (int)$rows[0]['ref'], 'type' => (int)$rows[0]['type'])
        : null;

    return $cache[$key];
}


/**
 * Build a Typesense filter_by clause for a `field:value` search on a non-fixed-list field,
 * mirroring RS's word-level, field-scoped keyword match:
 *   - text fields  -> bare-colon word-contains on the field's string (`_s` / `_text`);
 *   - date fields  -> bare-colon on the `_q` representation array (partial-date match);
 *   - date range   -> an interval-overlap filter on `_range_start`/`_range_end`
 *                     (typesense_search_daterange_filter), for every date field type;
 *   - numeric range -> a range / exact filter on `_f` (typesense_search_numrange_filter).
 * Returns null for anything not supported (caller falls back to MySQL). Fixed-list fields never
 * reach here (they are resolved into $node_bucket by core).
 *
 * @param array{ref:int,type:int} $field
 */
function typesense_search_fieldvalue_filter(array $field, string $value): ?string
{
    $prefix = 'field_' . (int)$field['ref'];
    $type = (int)$field['type'];

    // Numeric range (e.g. mynumberfield:numrange1|1234). Emitted as a range/comparison filter on
    // the numeric `_f` representation (populated for numeric-constrained, indexed single-line
    // fields - field_constraint == 1).
    if (strpos($value, 'numrange') === 0) {
        return typesense_search_numrange_filter($prefix, $value);
    }

    $is_date = in_array($type, array(
        FIELD_TYPE_DATE,
        FIELD_TYPE_DATE_AND_OPTIONAL_TIME,
        FIELD_TYPE_EXPIRY_DATE,
        FIELD_TYPE_DATE_RANGE,
    ), true);

    // Date range (e.g. eventdate:rangestart2020-01-01end2020-12-31). Matched as an interval overlap
    // against the resource's indexed [_range_start, _range_end): the date at its own precision (a
    // year/month is an interval, a full date a one-day interval) and, for a DATE_RANGE field, the
    // span between its two endpoints. Works for every date field type.
    if ($is_date && strpos($value, 'range') === 0) {
        return typesense_search_daterange_filter($prefix, $value);
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
            $key = $prefix . '_q';
            break;
        default:
            return null;
    }

    // Bare colon (":") is a tokenised word-contains match honouring the field's tokenizer/stemming.
    return $key . ':' . typesense_search_filter_value($value);
}


/**
 * Build an interval-overlap filter for a date `field:value` range search. RS encodes these as
 * "range" + "start<A>" + "end<B>" (either endpoint optional), where <A>/<B> are (possibly partial)
 * dates like "2020-00-00". We reuse typesense_parse_date() so the query window aligns exactly with
 * the `_range_start`/`_range_end` epochs the indexer stored, then test whether the resource's
 * indexed interval [_range_start, _range_end) overlaps the query window [A.range_start, B.range_end):
 *   start A present -> resource must extend past A:   field_<ref>_range_end   > A.range_start
 *   end   B present -> resource must begin before B:  field_<ref>_range_start < B.range_end
 * A resource whose date has no epoch interval (e.g. a bare "August" with no year) simply has no
 * `_range_*` and is excluded, as it can't be placed on the timeline. Returns null if neither
 * endpoint parses (caller falls back to MySQL).
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
 * Build a numeric filter for a `field:value` numeric-range search on the `_f` representation. RS
 * encodes these as "numrange<min>|<max>" with "neg" standing in for a leading "-", and either
 * bound optional. Mirrors core (do_search_keywords.php): both bounds -> a range; a single bound ->
 * an *exact* match on the value entered (core's `rnn.name = max(min,max)` behaviour), not a
 * one-sided range. Returns null if nothing numeric parses (caller falls back to MySQL).
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
 * Escape a filter_by value: simple word tokens (optionally with a trailing wildcard) are left as
 * is; anything containing spaces or punctuation is backtick-quoted.
 */
function typesense_search_filter_value(string $value): string
{
    if (preg_match('/[^\p{L}\p{N}_*]/u', $value)) {
        return '`' . str_replace('`', '', $value) . '`';
    }
    return $value;
}


/**
 * Ordered list of registered search modes. Exactly one mode claims a given search: the first
 * whose applies() returns true. More specific modes are listed before the catch-alls.
 *
 * Extend via the "typesense_search_modes" hook (return an array of TypesenseSearchComponent to
 * append) so new search types register without editing this function.
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
        // Registered modes take priority over the standard catch-all but after built-in specials.
        array_splice($modes, count($modes) - 1, 0, $extra);
    }

    return $modes;
}


/**
 * Ordered list of registered restrictions. All applicable restrictions run for every mode.
 *
 * Extend via the "typesense_search_restrictions" hook.
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

    // Pagination is fixed plumbing.
    setup_search_chunks($ctx->fetchrows, $chunk_offset, $search_chunk_size);
    $plan->per_page = $search_chunk_size === -1 ? 250 : (int)$search_chunk_size;
    $plan->page = $search_chunk_size > 0
        ? (int)floor($chunk_offset / $search_chunk_size) + 1
        : 1;

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

    $selected_mode->build($ctx, $plan);

    if (!$plan->supported) {
        debug('typesense_search: unsupported (' . $plan->fallback_reason . ')');
        return null;
    }

    // Keyword matching + node buckets are orthogonal to a mode's scope and, as in core, apply to
    // every search (standard and special) - so "!collection123 sunset" and node-bucket refine
    // within a special search work. This may veto (field-scoped text/date or negative search).
    typesense_apply_keyword_matching($ctx, $plan);
    if (!$plan->supported) {
        debug('typesense_search: unsupported (' . $plan->fallback_reason . ')');
        return null;
    }

    // Apply all restrictions.
    foreach (typesense_search_restrictions() as $restriction) {
        if ($restriction->applies($ctx)) {
            $restriction->build($ctx, $plan);
            if (!$plan->supported) {
                debug('typesense_search: unsupported by restriction (' . $plan->fallback_reason . ')');
                return null;
            }
        }
    }

    // Default sort mapping (fixed plumbing) - only if no mode set an explicit sort.
    typesense_apply_default_sort($ctx, $plan);
    if (!$plan->supported) {
        debug('typesense_search: unsupported (' . $plan->fallback_reason . ')');
        return null;
    }

    return $plan;
}


/**
 * Resolve the ref cutoff for a "most-recent N" (!last<num>) selection: the Nth-highest ref among
 * the resources matching the plan's current filters/keywords. Runs one Typesense query sorted by
 * ref desc, reusing the plan's filter_by/q/query_by so the recent set matches the same resources
 * the main query will (e.g. "!last50 sunset" -> newest 50 matching "sunset").
 *
 * Returns the cutoff ref, or null when there are N or fewer matches (no cutoff needed) or on error.
 */
function typesense_search_recent_cutoff(TypesenseQueryPlan $plan, int $n): ?int
{
    global $typesense_search_collection_prefix;

    if ($n < 1) {
        return null;
    }

    $per_page = min($n, 250); // Typesense caps per_page at 250
    $page = (int)ceil($n / $per_page);

    $params = $plan->compileParams();
    // Override sort/paging to walk to the Nth newest; only the ref is needed.
    $params['sort_by'] = 'ref:desc';
    $params['per_page'] = $per_page;
    $params['page'] = $page;
    $params['include_fields'] = 'ref';

    $endpoint =
        '/collections/'
        . rawurlencode($typesense_search_collection_prefix . $plan->collection)
        . '/documents/search?'
        . http_build_query($params);

    $result = typesense_search_request('GET', $endpoint);
    if ($result === false || !isset($result['hits']) || !is_array($result['hits'])) {
        return null;
    }

    // Fewer than (or exactly) N matches: the whole set is the result, no cutoff required.
    if ((int)($result['found'] ?? 0) <= $n) {
        return null;
    }

    // The Nth newest overall sits at this index within the fetched page.
    $index = ($n - 1) - ($page - 1) * $per_page;
    if (!isset($result['hits'][$index]['document']['ref'])) {
        return null;
    }

    return (int)$result['hits'][$index]['document']['ref'];
}


/**
 * Execute a compiled query plan against Typesense and return ordered refs + total.
 *
 * @return array{refs:int[],total:int}|false
 */
function typesense_search_execute(TypesenseQueryPlan $plan)
{
    global $typesense_search_collection_prefix;

    // "Most-recent N" selection (!last<num>): restrict the set to the N highest-ref matches via a
    // ref cutoff, then let the plan's own (user-chosen) sort order them. Mirrors core's !last,
    // where the inner query picks the newest N and the outer query re-sorts them.
    if ($plan->recent_selection !== null) {
        $cutoff = typesense_search_recent_cutoff($plan, $plan->recent_selection);
        if ($cutoff !== null) {
            // ref >= the Nth-highest ref == exactly the newest N matches (refs are unique).
            $plan->addFilter('ref:>=' . $cutoff);
        }
        // else: fewer than N matches - the whole set already qualifies, no cutoff needed.
    }

    $params = $plan->compileParams();

    $endpoint =
        '/collections/'
        . rawurlencode($typesense_search_collection_prefix . $plan->collection)
        . '/documents/search?'
        . http_build_query($params);

    $result = typesense_search_request('GET', $endpoint);

    if ($result === false || !isset($result['hits']) || !is_array($result['hits'])) {
        return false;
    }

    debug('typesense_search_execute(): found=' . ($result['found'] ?? 'unknown'));

    $refs = array();
    foreach ($result['hits'] as $hit) {
        if (isset($hit['document']['ref'])) {
            $refs[] = (int)$hit['document']['ref'];
        }
    }

    $total = (int)($result['found'] ?? count($refs));

    // Apply a result cap (e.g. !last<num>): trim both the reported total and any refs on this
    // page that fall beyond the cap.
    if ($plan->result_limit !== null) {
        $total = min($total, $plan->result_limit);
        $page_start = ($plan->page - 1) * $plan->per_page;
        $allowed_on_page = max(0, $plan->result_limit - $page_start);
        if (count($refs) > $allowed_on_page) {
            $refs = array_slice($refs, 0, $allowed_on_page);
        }
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
        $ctx->select,
        (string)$ctx->order_by
    );
}


/**
 * Result to return when Typesense could not handle a search. In Typesense-only mode this is an
 * empty (but correctly shaped) result set, so core does not fall back to the MySQL search;
 * otherwise false, which lets core continue with its own search.
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
