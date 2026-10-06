<?php
// Read-only survey of the values a ResourceSpace database offers for the search catalogue examples
// (docs/core-search-catalogue.md): resource types, fields, common keywords, fixed-list options, dates,
// numbers, collections, users and the counts behind the special searches. Only SELECT statements, each
// capped at 30 seconds; nothing here is as heavy as a real search.
//
// Usage: RS_DB_HOST=… RS_DB_PORT=… RS_DB_USER=… RS_DB_PASS=… RS_DB_NAME=… RS_USER_TS=… RS_USER_CORE=… php live/catalogue_values.php
// Optional: RS_DATE_FIELD (the system's $date_field, default 12).

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$env = array();
foreach (array('HOST', 'PORT', 'USER', 'PASS', 'NAME') as $part) {
    $env[$part] = getenv('RS_DB_' . $part);
    if ($env[$part] === false || $env[$part] === '') {
        fwrite(STDERR, "Set RS_DB_HOST, RS_DB_PORT, RS_DB_USER, RS_DB_PASS and RS_DB_NAME in the environment.\n");
        exit(1);
    }
}
$api_users = array_values(array_filter(array((string)getenv('RS_USER_TS'), (string)getenv('RS_USER_CORE')), 'strlen'));
$date_field = (int)(getenv('RS_DATE_FIELD') ?: 12);

mysqli_report(MYSQLI_REPORT_OFF);
$db = mysqli_init();
$db->options(MYSQLI_OPT_CONNECT_TIMEOUT, 10);
if (!@$db->real_connect($env['HOST'], $env['USER'], $env['PASS'], $env['NAME'], (int)$env['PORT'])) {
    fwrite(STDERR, 'Could not connect: ' . mysqli_connect_error() . "\n");
    exit(1);
}
$db->set_charset('utf8mb4');
@$db->query('SET SESSION MAX_EXECUTION_TIME = 30000');
@$db->query('SET SESSION max_statement_time = 30');

/** Run a SELECT with ? placeholders; returns rows (and prints the error instead of stopping). */
function q(string $sql, array $params = array()): array
{
    global $db, $ms;
    $started = microtime(true);
    $stmt = $db->prepare($sql);
    if ($stmt === false) {
        echo '    (query failed: ' . $db->error . ")\n";
        return array();
    }
    if (count($params) > 0) {
        $stmt->bind_param(str_repeat('s', count($params)), ...$params);
    }
    if (!$stmt->execute()) {
        echo '    (query failed: ' . $stmt->error . ")\n";
        return array();
    }
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $ms = (int)round((microtime(true) - $started) * 1000);
    return $rows;
}

function table(array $rows, array $cols = array()): void
{
    if (count($rows) === 0) {
        echo "    none\n";
        return;
    }
    $cols = count($cols) ? $cols : array_keys($rows[0]);
    $widths = array();
    foreach ($cols as $c) {
        $widths[$c] = strlen($c);
        foreach ($rows as $r) {
            $widths[$c] = max($widths[$c], mb_strlen(mb_substr((string)($r[$c] ?? ''), 0, 60)));
        }
    }
    $line = '    ';
    foreach ($cols as $c) {
        $line .= str_pad($c, $widths[$c] + 2);
    }
    echo rtrim($line) . "\n";
    foreach ($rows as $r) {
        $line = '    ';
        foreach ($cols as $c) {
            $v = mb_substr((string)($r[$c] ?? ''), 0, 60);
            $line .= $v . str_repeat(' ', max(0, $widths[$c] + 2 - mb_strlen($v)));
        }
        echo rtrim($line) . "\n";
    }
}

$TYPE = array(0 => 'text', 1 => 'text multi', 2 => 'checkbox', 3 => 'dropdown', 4 => 'datetime', 5 => 'text large', 6 => 'expiry',
    7 => 'tree', 8 => 'formatted', 9 => 'dynamic kw', 10 => 'date', 12 => 'radio', 13 => 'warning', 14 => 'date range');
$FIXED = array(2, 3, 7, 9, 12);
$DATES = array(4, 6, 10, 14);

echo 'server: ' . $db->server_info . ', date field: ' . $date_field . "\n";

echo "\n== resource types (resources in state 0)\n";
$types = q('SELECT rt.ref, rt.name, (SELECT COUNT(*) FROM resource r WHERE r.resource_type = rt.ref AND r.archive = 0 AND r.ref > 0) c FROM resource_type rt ORDER BY rt.ref');
table($types);
$type_names = array_map(fn($t) => mb_strtolower($t['name']), $types);
echo "    type names that also exist as keywords:\n";
table(q('SELECT keyword, hit_count FROM keyword WHERE keyword IN (' . implode(',', array_fill(0, count($type_names), '?')) . ')', $type_names));

echo "\n== workflow states\n";
table(q('SELECT archive, COUNT(*) c FROM resource WHERE ref > 0 GROUP BY archive ORDER BY archive'));

echo "\n== active fields\n";
$fields = q('SELECT f.ref, f.name, f.title, f.type, f.keywords_index idx, f.partial_index partial, f.advanced_search adv, f.simple_search simple, f.field_constraint num, f.global, f.display_as_dropdown dd, GROUP_CONCAT(rtfrt.resource_type) rts FROM resource_type_field f LEFT JOIN resource_type_field_resource_type rtfrt ON rtfrt.resource_type_field = f.ref WHERE f.active = 1 GROUP BY f.ref ORDER BY f.ref');
foreach ($fields as &$f) {
    $f['type'] = ($TYPE[$f['type']] ?? $f['type']) . ' (' . $f['type'] . ')';
}
unset($f);
table($fields, array('ref', 'name', 'type', 'idx', 'partial', 'adv', 'simple', 'num', 'global', 'dd', 'rts', 'title'));
$fields = q('SELECT ref, name, type, keywords_index, field_constraint, global FROM resource_type_field WHERE active = 1 ORDER BY ref');
$by_type = array();
foreach ($fields as $f) {
    $by_type[(int)$f['type']][] = $f;
}

echo "\n== keywords: most common (4+ letters)\n";
$top = q("SELECT keyword, hit_count FROM keyword WHERE hit_count > 0 AND CHAR_LENGTH(keyword) >= 4 AND keyword NOT REGEXP '^[0-9]' ORDER BY hit_count DESC LIMIT 40");
table($top);
echo "\n== keywords: numbers\n";
table(q("SELECT keyword, hit_count FROM keyword WHERE keyword REGEXP '^[0-9]+$' AND hit_count > 0 ORDER BY hit_count DESC LIMIT 8"));
echo "\n== keywords: with a hyphen or other punctuation inside\n";
table(q("SELECT keyword, hit_count FROM keyword WHERE hit_count > 0 AND (keyword LIKE '%-%' OR keyword LIKE '%.%' OR keyword LIKE '%&%' OR keyword LIKE '%''%') ORDER BY hit_count DESC LIMIT 12"));
echo "\n== keywords: stop words present in the keyword table (core never indexes these)\n";
table(q("SELECT keyword, hit_count FROM keyword WHERE keyword IN ('a','the','this','then','another','is','with','in','and','where','how','on','of','to','from','at','for','by','be')"));
echo "\n== keywords: related pairs\n";
table(q('SELECT k1.keyword a, k2.keyword b FROM keyword_related kr JOIN keyword k1 ON k1.ref = kr.keyword JOIN keyword k2 ON k2.ref = kr.related LIMIT 10'));

foreach (array_slice($top, 0, 4) as $t) {
    $prefix = mb_substr($t['keyword'], 0, 4);
    echo "\n== keywords starting with '" . $prefix . "' (wildcard candidates)\n";
    $r = q('SELECT COUNT(*) n, SUM(hit_count) hits FROM keyword WHERE keyword LIKE ?', array($prefix . '%'));
    echo '    ' . $r[0]['n'] . ' keywords, ' . $r[0]['hits'] . " mappings in total\n";
    table(q('SELECT keyword, hit_count FROM keyword WHERE keyword LIKE ? ORDER BY hit_count DESC LIMIT 12', array($prefix . '%')));
    echo "    node names (any field) matching the full-text prefix:\n";
    table(q("SELECT n.resource_type_field f, n.name FROM node n WHERE MATCH(n.name) AGAINST (? IN BOOLEAN MODE) AND n.name LIKE '% %' LIMIT 8", array('+' . $prefix . '*')));
}
if (count($top) > 0) {
    $word = $top[0]['keyword'];
    $typo = mb_substr($word, 0, -1) . 'x';
    echo "\n== sound-alike suggestion for the misspelling '" . $typo . "'\n";
    table(q('SELECT keyword, hit_count FROM keyword WHERE soundex = LEFT(SOUNDEX(?), 10) ORDER BY hit_count DESC LIMIT 5', array($typo)));
}

echo "\n== fixed-list fields: options with the most resources (checkbox, dropdown, radio, tree)\n";
$small = array();
foreach (array(2, 3, 7, 12) as $t) {
    foreach ($by_type[$t] ?? array() as $f) {
        $small[] = $f['ref'];
    }
}
if (count($small) > 0) {
    table(q('SELECT n.ref node, n.resource_type_field f, n.name, COUNT(rn.resource) c FROM node n JOIN resource_node rn ON rn.node = n.ref WHERE n.resource_type_field IN (' . implode(',', array_fill(0, count($small), '?')) . ') GROUP BY n.ref ORDER BY c DESC LIMIT 40', $small));
    echo '    (' . $ms . " ms)\n";
    echo "    options per field:\n";
    table(q('SELECT resource_type_field f, COUNT(*) options FROM node WHERE resource_type_field IN (' . implode(',', array_fill(0, count($small), '?')) . ') GROUP BY resource_type_field ORDER BY f', $small));
}
echo "\n== dynamic keyword fields: options per field, then the busiest two\n";
$dyn = array_column($by_type[9] ?? array(), 'ref');
if (count($dyn) > 0) {
    $per = q('SELECT resource_type_field f, COUNT(*) options FROM node WHERE resource_type_field IN (' . implode(',', array_fill(0, count($dyn), '?')) . ') GROUP BY resource_type_field ORDER BY options DESC', $dyn);
    table($per);
    foreach (array_slice($per, 0, 2) as $p) {
        echo '    field ' . $p['f'] . ":\n";
        table(q('SELECT n.ref node, n.name, COUNT(rn.resource) c FROM node n JOIN resource_node rn ON rn.node = n.ref WHERE n.resource_type_field = ? GROUP BY n.ref ORDER BY c DESC LIMIT 8', array($p['f'])));
    }
}

echo "\n== dates: field " . $date_field . " by year\n";
$years = q('SELECT LEFT(n.name, 4) y, COUNT(*) c FROM node n JOIN resource_node rn ON rn.node = n.ref WHERE n.resource_type_field = ? GROUP BY y ORDER BY y', array($date_field));
table($years);
usort($years, fn($a, $b) => $b['c'] <=> $a['c']);
if (count($years) > 0) {
    $y = $years[0]['y'];
    echo "    months of " . $y . ":\n";
    $months = q('SELECT LEFT(n.name, 7) ym, COUNT(*) c FROM node n JOIN resource_node rn ON rn.node = n.ref WHERE n.resource_type_field = ? AND n.name LIKE ? GROUP BY ym ORDER BY ym', array($date_field, $y . '-%'));
    table($months);
    usort($months, fn($a, $b) => $b['c'] <=> $a['c']);
    if (count($months) > 0) {
        echo "    busiest days of " . $months[0]['ym'] . ":\n";
        table(q('SELECT n.name, COUNT(*) c FROM node n JOIN resource_node rn ON rn.node = n.ref WHERE n.resource_type_field = ? AND n.name LIKE ? GROUP BY n.name ORDER BY c DESC LIMIT 5', array($date_field, $months[0]['ym'] . '-%')));
    }
}
echo "    values shorter than a full date (partial dates):\n";
table(q('SELECT n.name, COUNT(rn.resource) c FROM node n JOIN resource_node rn ON rn.node = n.ref WHERE n.resource_type_field = ? AND CHAR_LENGTH(n.name) < 10 GROUP BY n.name ORDER BY c DESC LIMIT 6', array($date_field)));

echo "\n== every date field: values and resources with a value\n";
foreach ($DATES as $t) {
    foreach ($by_type[$t] ?? array() as $f) {
        $r = q('SELECT COUNT(*) nodes, MIN(name) mn, MAX(name) mx FROM node WHERE resource_type_field = ?', array($f['ref']));
        $n = q('SELECT COUNT(DISTINCT rn.resource) c FROM resource_node rn JOIN node n ON n.ref = rn.node WHERE n.resource_type_field = ?', array($f['ref']));
        echo sprintf("    %-5s %-22s %-11s indexed=%s values=%s resources=%s range=%s .. %s\n", $f['ref'], $f['name'], $TYPE[$t], $f['keywords_index'], $r[0]['nodes'], $n[0]['c'], $r[0]['mn'], $r[0]['mx']);
        if ($t === 14) {
            echo "        sample values: " . implode(' | ', array_column(q('SELECT name FROM node WHERE resource_type_field = ? ORDER BY name LIMIT 8', array($f['ref'])), 'name')) . "\n";
        }
    }
}

echo "\n== creation dates (for !last and the recent-days limit)\n";
table(q('SELECT MIN(creation_date) mn, MAX(creation_date) mx, SUM(creation_date > NOW() - INTERVAL 7 DAY) d7, SUM(creation_date > NOW() - INTERVAL 30 DAY) d30, SUM(creation_date > NOW() - INTERVAL 365 DAY) d365 FROM resource WHERE archive = 0 AND ref > 0'));
echo "    newest refs:\n";
table(q('SELECT ref, resource_type, archive, creation_date FROM resource WHERE ref > 0 ORDER BY ref DESC LIMIT 5'));
echo "    one ref per workflow state:\n";
table(q('SELECT archive, MIN(ref) ref, COUNT(*) c FROM resource WHERE ref > 0 GROUP BY archive ORDER BY archive'));

echo "\n== numeric fields (field_constraint = 1)\n";
foreach ($fields as $f) {
    if ((int)$f['field_constraint'] !== 1) {
        continue;
    }
    $r = q('SELECT COUNT(DISTINCT n.name) distinct_values, COUNT(rn.resource) resources, MIN(CAST(n.name AS DECIMAL(20,4))) mn, MAX(CAST(n.name AS DECIMAL(20,4))) mx FROM node n JOIN resource_node rn ON rn.node = n.ref WHERE n.resource_type_field = ?', array($f['ref']));
    echo sprintf("    %-5s %-22s type=%s indexed=%s distinct=%s resources=%s min=%s max=%s\n", $f['ref'], $f['name'], $f['type'], $f['keywords_index'], $r[0]['distinct_values'], $r[0]['resources'], $r[0]['mn'], $r[0]['mx']);
    table(q('SELECT n.name, COUNT(rn.resource) c FROM node n JOIN resource_node rn ON rn.node = n.ref WHERE n.resource_type_field = ? GROUP BY n.name ORDER BY c DESC LIMIT 10', array($f['ref'])));
}

echo "\n== collections: public, with the most resources\n";
table(q('SELECT c.ref, c.name, c.type, c.public, c.user, COUNT(cr.resource) n FROM collection c JOIN collection_resource cr ON cr.collection = c.ref WHERE c.public = 1 GROUP BY c.ref ORDER BY n DESC LIMIT 8'));
echo "    featured (type 3) and featured categories (type 4):\n";
table(q('SELECT c.ref, c.name, c.type, c.public, c.parent, COUNT(cr.resource) n FROM collection c LEFT JOIN collection_resource cr ON cr.collection = c.ref WHERE c.type IN (3, 4) GROUP BY c.ref ORDER BY n DESC LIMIT 10'));
echo "    smart collections (saved searches):\n";
table(q('SELECT c.ref, c.name, c.type, c.public, cs.search, cs.restypes, cs.archive FROM collection c JOIN collection_savedsearch cs ON cs.ref = c.savedsearch LIMIT 8'));

echo "\n== users\n";
if (count($api_users) > 0) {
    $users = q('SELECT u.ref, u.username, u.usergroup, g.name gname, g.permissions, g.search_filter_id, g.resource_defaults FROM user u JOIN usergroup g ON g.ref = u.usergroup WHERE u.username IN (' . implode(',', array_fill(0, count($api_users), '?')) . ')', $api_users);
    table($users);
    $refs = array_column($users, 'ref');
    if (count($refs) > 0) {
        $ph = implode(',', array_fill(0, count($refs), '?'));
        echo "    their collections:\n";
        table(q('SELECT c.ref, c.name, c.type, c.public, c.user, COUNT(cr.resource) n FROM collection c LEFT JOIN collection_resource cr ON cr.collection = c.ref WHERE c.user IN (' . $ph . ') GROUP BY c.ref ORDER BY c.user, c.ref', $refs));
        echo "    their contributions:\n";
        table(q('SELECT created_by, archive, COUNT(*) c FROM resource WHERE created_by IN (' . $ph . ') AND ref > 0 GROUP BY created_by, archive', $refs));
        echo "    a private collection of someone else, with resources:\n";
        table(q('SELECT c.ref, c.user, c.type, COUNT(cr.resource) n FROM collection c JOIN collection_resource cr ON cr.collection = c.ref WHERE c.public = 0 AND c.type = 0 AND c.user NOT IN (' . $ph . ') GROUP BY c.ref ORDER BY n DESC LIMIT 3', $refs));
    }
}
echo "    top contributors (state 0):\n";
table(q('SELECT r.created_by, u.username, COUNT(*) c FROM resource r LEFT JOIN user u ON u.ref = r.created_by WHERE r.archive = 0 AND r.ref > 0 GROUP BY r.created_by ORDER BY c DESC LIMIT 5'));

echo "\n== related resources\n";
table(q('SELECT rr.resource, COUNT(*) c FROM resource_related rr JOIN resource r ON r.ref = rr.related AND r.archive = 0 GROUP BY rr.resource ORDER BY c DESC LIMIT 3'));
echo "\n== duplicate files (same checksum)\n";
table(q("SELECT file_checksum, COUNT(*) c, MIN(ref) first_ref FROM resource WHERE file_checksum IS NOT NULL AND file_checksum <> '' AND ref > 0 GROUP BY file_checksum HAVING c > 1 ORDER BY c DESC LIMIT 3"));

echo "\n== file properties (state 0)\n";
table(q('SELECT file_extension, COUNT(*) c FROM resource WHERE archive = 0 AND ref > 0 GROUP BY file_extension ORDER BY c DESC LIMIT 10'));
table(q('SELECT COUNT(*) with_dimensions, MIN(width) wmin, MAX(width) wmax, MIN(height) hmin, MAX(height) hmax, SUM(height > width) portrait, SUM(height < width) landscape, SUM(height = width) square FROM resource_dimensions'));
table(q('SELECT MIN(file_size) smallest, MAX(file_size) largest, ROUND(AVG(file_size)) mean, SUM(file_size >= 5 * 1024 * 1024) over_5mb, SUM(file_size <= 1024 * 1024) under_1mb, SUM(file_size IS NULL OR file_size = 0) no_size FROM resource WHERE archive = 0 AND ref > 0'));
table(q('SELECT has_image, COUNT(*) c FROM resource WHERE archive = 0 AND ref > 0 GROUP BY has_image'));

echo "\n== counts behind the other special searches (state 0)\n";
table(q("SELECT SUM(integrity_fail = 1 AND no_file = 0) integrityfail, SUM(lock_user <> 0) locked, SUM(geo_lat IS NOT NULL) geo, SUM(colour_key <> '' AND colour_key IS NOT NULL) colourkey FROM resource WHERE archive = 0 AND ref > 0"));
table(q('SELECT MIN(geo_lat) lat_min, MAX(geo_lat) lat_max, MIN(geo_long) long_min, MAX(geo_long) long_max FROM resource WHERE geo_lat IS NOT NULL AND archive = 0'));
table(q("SELECT LEFT(colour_key, 4) ck, COUNT(*) c FROM resource WHERE colour_key <> '' AND archive = 0 AND has_image > 0 GROUP BY ck ORDER BY c DESC LIMIT 5"));
table(q('SELECT ref, image_red, image_green, image_blue FROM resource WHERE has_image > 0 AND archive = 0 AND image_red IS NOT NULL ORDER BY ref DESC LIMIT 1'));
$r = q('SELECT COUNT(*) c FROM resource r WHERE r.archive = 0 AND r.ref > 0 AND NOT EXISTS (SELECT 1 FROM collection_resource cr WHERE cr.resource = r.ref)');
echo '    not in any collection (!unused): ' . ($r[0]['c'] ?? '?') . "\n";
$r = q("SELECT COUNT(DISTINCT object_ref) c FROM daily_stat WHERE activity_type = 'Resource download'");
echo '    resources ever downloaded (!nodownloads excludes these): ' . ($r[0]['c'] ?? '?') . "\n";
echo "    reports (!report needs the reports permission):\n";
table(q('SELECT ref, name FROM report ORDER BY ref LIMIT 12'));

echo "\n== resources with a value, for !hasdata / !empty (state 0)\n";
$probe = array($date_field);
foreach ($fields as $f) {
    if ((int)$f['field_constraint'] === 1 || ((int)$f['keywords_index'] === 0 && in_array((int)$f['type'], $DATES, true)) || (int)$f['ref'] === 8) {
        $probe[] = (int)$f['ref'];
    }
}
foreach (array_unique($probe) as $ref) {
    $r = q('SELECT COUNT(DISTINCT rn.resource) c FROM resource_node rn JOIN node n ON n.ref = rn.node JOIN resource r ON r.ref = rn.resource AND r.archive = 0 WHERE n.resource_type_field = ?', array($ref));
    echo '    field ' . $ref . ': ' . ($r[0]['c'] ?? '?') . ' (' . $ms . " ms)\n";
}

echo "\n== fixed-list options with resource counts (fields with up to 25 options)\n";
foreach (array(2, 3, 7, 12) as $t) {
    foreach ($by_type[$t] ?? array() as $f) {
        $n = q('SELECT COUNT(*) c FROM node WHERE resource_type_field = ?', array($f['ref']));
        if ((int)$n[0]['c'] > 25) {
            continue;
        }
        echo '    ' . $f['ref'] . ' ' . $f['name'] . ' (' . $TYPE[$t] . "):\n";
        table(q('SELECT n.ref node, n.name, COUNT(rn.resource) c FROM node n LEFT JOIN resource_node rn ON rn.node = n.ref WHERE n.resource_type_field = ? GROUP BY n.ref ORDER BY c DESC', array($f['ref'])));
    }
}

echo "\n== dynamic keyword fields: top values (the busiest two were listed above)\n";
foreach (array_slice($by_type[9] ?? array(), 0, 20) as $f) {
    if (in_array($f['ref'], array_column(array_slice($per ?? array(), 0, 2), 'f'))) {
        continue;
    }
    echo '    ' . $f['ref'] . ' ' . $f['name'] . ":\n";
    table(q('SELECT n.ref node, n.name, COUNT(rn.resource) c FROM node n JOIN resource_node rn ON rn.node = n.ref WHERE n.resource_type_field = ? GROUP BY n.ref ORDER BY c DESC LIMIT 6', array($f['ref'])));
}

echo "\n== date fields: most common values\n";
foreach ($DATES as $t) {
    foreach ($by_type[$t] ?? array() as $f) {
        echo '    ' . $f['ref'] . ' ' . $f['name'] . ":\n";
        table(q('SELECT n.name, COUNT(rn.resource) c FROM node n JOIN resource_node rn ON rn.node = n.ref WHERE n.resource_type_field = ? GROUP BY n.ref ORDER BY c DESC LIMIT 6', array($f['ref'])));
    }
}

echo "\n== text fields with few values: most common (one scan of the node table)\n";
$nodes_per_field = array_column(q('SELECT resource_type_field f, COUNT(*) nodes FROM node GROUP BY resource_type_field'), 'nodes', 'f');
echo '    (' . $ms . " ms)\n";
foreach (array(0, 1, 5, 8) as $t) {
    foreach ($by_type[$t] ?? array() as $f) {
        $nn = (int)($nodes_per_field[$f['ref']] ?? 0);
        if ($nn === 0 || $nn > 3000 || (int)$f['field_constraint'] === 1) {
            continue;
        }
        echo '    ' . $f['ref'] . ' ' . $f['name'] . ' (' . $nn . " values):\n";
        table(q('SELECT n.name, COUNT(rn.resource) c FROM node n JOIN resource_node rn ON rn.node = n.ref WHERE n.resource_type_field = ? GROUP BY n.ref ORDER BY c DESC LIMIT 5', array($f['ref'])));
    }
}

$title_field = (int)(getenv('RS_TITLE_FIELD') ?: 8);
$phrase = (string)(getenv('RS_PHRASE') ?: 'sculpture park');
echo "\n== title field " . $title_field . ": values containing the phrase '" . $phrase . "'\n";
table(q('SELECT n.name FROM node n WHERE n.resource_type_field = ? AND MATCH(n.name) AGAINST (? IN BOOLEAN MODE) LIMIT 6', array($title_field, '+' . str_replace(' ', ' +', $phrase))));
echo "    with punctuation inside:\n";
table(q("SELECT n.name FROM node n WHERE n.resource_type_field = ? AND (n.name LIKE '%-%' OR n.name LIKE '%''%' OR n.name LIKE '%&%') LIMIT 8", array($title_field)));
echo "\n== partially indexed text fields: newest values\n";
foreach (q('SELECT ref, name FROM resource_type_field WHERE partial_index = 1 AND active = 1') as $f) {
    echo '    ' . $f['ref'] . ' ' . $f['name'] . ":\n";
    table(q('SELECT n.name FROM node n WHERE n.resource_type_field = ? ORDER BY n.ref DESC LIMIT 5', array($f['ref'])));
}

echo "\n== more counts (state 0)\n";
table(q('SELECT SUM(creation_date > NOW() - INTERVAL 90 DAY) d90, SUM(creation_date > NOW() - INTERVAL 180 DAY) d180, SUM(creation_date > NOW() - INTERVAL 400 DAY) d400 FROM resource WHERE archive = 0 AND ref > 0'));
table(q('SELECT SUM(rd.width >= 4000) w4000, SUM(rd.height >= 3000) h3000, SUM(rd.width <= 1000) w1000, SUM(rd.height <= 1000) h1000, SUM(rd.height > rd.width) portrait, SUM(rd.height < rd.width) landscape FROM resource_dimensions rd JOIN resource r ON r.ref = rd.resource AND r.archive = 0'));
$r = q("SELECT COUNT(*) c FROM resource r WHERE r.archive = 0 AND r.ref > 0 AND r.ref NOT IN (SELECT DISTINCT object_ref FROM daily_stat WHERE activity_type = 'Resource download')");
echo '    never downloaded (!nodownloads): ' . ($r[0]['c'] ?? '?') . "\n";
echo "    densest 0.1-degree squares of geotagged resources:\n";
table(q('SELECT ROUND(geo_lat, 1) lat, ROUND(geo_long, 1) lng, COUNT(*) c FROM resource WHERE geo_lat IS NOT NULL AND archive = 0 GROUP BY lat, lng ORDER BY c DESC LIMIT 3'));
$top_related = q('SELECT rr.resource FROM resource_related rr GROUP BY rr.resource ORDER BY COUNT(*) DESC LIMIT 1');
if (count($top_related) > 0) {
    echo '    related resources of ' . $top_related[0]['resource'] . " by state:\n";
    table(q('SELECT r.archive, COUNT(*) c FROM resource_related rr JOIN resource r ON r.ref = rr.related WHERE rr.resource = ? GROUP BY r.archive', array($top_related[0]['resource'])));
}
$check = array_filter(array_map('intval', explode(',', (string)getenv('RS_CHECK_COLLECTIONS'))));
if (count($check) > 0) {
    echo "    collections named in RS_CHECK_COLLECTIONS:\n";
    table(q('SELECT c.ref, c.name, c.type, c.public, c.user, (SELECT COUNT(*) FROM collection_resource cr WHERE cr.collection = c.ref) n FROM collection c WHERE c.ref IN (' . implode(',', array_fill(0, count($check), '?')) . ')', $check));
}
