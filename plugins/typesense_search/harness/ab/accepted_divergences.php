<?php

// The accepted-divergence list the A/B runner reads (Q10.3). One entry per case label, which is a catalogue row
// id or a battery case id:
//   'A7' => array('kind' => 'order', 'reason' => '...', 'decision' => 'Q7.1 2026-10-20'),
// kind: order     the plugin serves the same set in a different order (relevance to text is the one listed kind)
//       declined  the plugin declines with exactly this reason (the reason= part of its log line)
//       core-bug  the row reproduces a core bug: expected to differ, flips when core is fixed (H7)
// Security cases are never listed; everything unlisted that differs fails. A check in the planning repository
// confirms each entry against coverage.md.
return array();
