<?php

/**
 * typesense_search: the loader and the shared helpers.
 *
 * One file per concept under include/, in dependency order: the options accessor first, as every later file reads
 * configuration through it. The index and sync modules are required here; the search/ modules are required only
 * inside the search hook. The harness directory is not part of the release and nothing here depends on it.
 */

require_once __DIR__ . '/options.php';
require_once __DIR__ . '/tenancy.php';
require_once __DIR__ . '/schema.php';
require_once __DIR__ . '/dates.php';
