<?php
require __DIR__ . '/boot.php';

trace('plain keyword', 'sculpture', array('show_core' => true, 'show_unknown' => true));
trace('two keywords', 'sculpture landscape', array('show_unknown' => true));
trace('empty search', '', array('show_unknown' => true));
