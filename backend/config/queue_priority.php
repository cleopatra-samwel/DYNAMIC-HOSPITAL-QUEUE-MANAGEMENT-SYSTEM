<?php

/**
 * Phase 4 dynamic priority engine constants. Kept in config (not hardcoded
 * inline) specifically so an Administrator-configurable settings screen —
 * out of scope for this phase — can later overwrite this at runtime
 * (e.g. via a settings-backed config repository) without touching
 * PriorityEngine itself.
 */
return [
    'aging_points_per_minute' => env('QUEUE_AGING_POINTS_PER_MINUTE', 2),
];
