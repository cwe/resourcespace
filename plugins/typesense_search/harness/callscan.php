<?php
// Scan every do_search() call in a checkout for arguments that land on a scalar-typed parameter of the plugin's
// external_search hook. A null there is a TypeError when the hook is called; a variable may or may not be.
// Usage: php callscan.php [path to a ResourceSpace checkout, default this one]

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = rtrim($argv[1] ?? dirname(__DIR__, 3), '/');

// do_search() argument position => the hook parameter it becomes.
$typed = array(
    1 => 'string $search', 6 => 'string $sort', 7 => 'bool $access_override', 9 => 'bool $ignore_filters',
    10 => 'bool $return_disk_usage', 11 => 'string $recent_search_daylimit', 14 => 'bool $return_refs_only',
    15 => 'bool $editable_only', 16 => 'bool $returnsql', 18 => 'bool $smartsearch',
);

$calls = 0;
$nulls = array();
$variables = array();
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($it as $file) {
    $path = (string)$file;
    $relative = substr($path, strlen($root) + 1);
    if (substr($path, -4) !== '.php' || preg_match('#^(lib|\.claude|filestore|tests|plugins/typesense_search/harness)/#', $relative)) {
        continue;
    }
    $src = file_get_contents($path);
    if (strpos($src, 'do_search(') === false) {
        continue;
    }

    $tokens = token_get_all($src);
    $count = count($tokens);
    for ($i = 0; $i < $count; $i++) {
        $t = $tokens[$i];
        if (!is_array($t) || $t[0] !== T_STRING || $t[1] !== 'do_search') {
            continue;
        }
        // Not the function definition.
        $p = $i - 1;
        while ($p >= 0 && is_array($tokens[$p]) && $tokens[$p][0] === T_WHITESPACE) {
            $p--;
        }
        if ($p >= 0 && is_array($tokens[$p]) && $tokens[$p][0] === T_FUNCTION) {
            continue;
        }
        $j = $i + 1;
        while ($j < $count && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
            $j++;
        }
        if ($j >= $count || $tokens[$j] !== '(') {
            continue;
        }

        // Split the top-level arguments.
        $depth = 0;
        $args = array('');
        for ($k = $j; $k < $count; $k++) {
            $tk = $tokens[$k];
            if (is_array($tk) && in_array($tk[0], array(T_COMMENT, T_DOC_COMMENT), true)) {
                continue;
            }
            $text = is_array($tk) ? $tk[1] : $tk;
            if ($text === '(' || $text === '[') {
                $depth++;
                if ($depth === 1) {
                    continue;
                }
            }
            if ($text === ')' || $text === ']') {
                $depth--;
                if ($depth === 0) {
                    break;
                }
            }
            if ($text === ',' && $depth === 1) {
                $args[] = '';
                continue;
            }
            $args[count($args) - 1] .= $text;
        }
        $args = array_map(fn($a) => trim(preg_replace('/\s+/', ' ', $a)), $args);
        $calls++;

        foreach ($typed as $position => $parameter) {
            if (!isset($args[$position - 1])) {
                continue;
            }
            $arg = $args[$position - 1];
            $where = $relative . ':' . $t[2] . '  argument ' . $position . ' -> ' . $parameter;
            if (strtolower($arg) === 'null') {
                $nulls[] = $where;
            } elseif ($position !== 1 && $position !== 6 && !in_array(strtolower($arg), array('true', 'false'), true) && !preg_match('/^(["\']).*\1$/', $arg)) {
                // $search and $sort are always strings by the time the hook is called (core trims / validates them).
                $variables[] = $where . ' = ' . $arg;
            }
        }
    }
}

echo "do_search() calls scanned: $calls\n";
echo "\nnull passed to a typed hook parameter (TypeError when the plugin is active): " . count($nulls) . "\n";
foreach ($nulls as $line) {
    echo '    ' . $line . "\n";
}
echo "\nvariables passed to a typed bool / string hook parameter (fine unless they can be null): " . count($variables) . "\n";
foreach ($variables as $line) {
    echo '    ' . $line . "\n";
}
