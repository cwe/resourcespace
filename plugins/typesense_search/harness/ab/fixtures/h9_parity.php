<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/**
 * H9 additions to the base fixture: accented values, which MySQL's collation folds and the SQLite driver does not.
 * Resource 40 has an accented title and an accented fixed-list option; nothing else in the fixture holds a character
 * outside ASCII, so every other case is unaffected.
 */
fx_resource(40, 1);
fx_set(40, 8, "Caf\u{e9} Zo\u{eb}");
fx_tag(40, fx_node(3, "Z\u{fc}rich", null, 205));

fx_report_errors('loading the H9 fixture');
