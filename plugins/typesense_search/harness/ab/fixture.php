<?php

// The A/B harness plus the base fixture. A battery that must change configuration or add fields before the
// fixture is loaded requires boot.php and fixture_lib.php itself and calls fx_load('base') when ready.
require __DIR__ . '/boot.php';
require __DIR__ . '/fixture_lib.php';
fx_load('base');
