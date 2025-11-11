<?php

$precisionByZoom = [
    1 => 1.00,
    2 => 1.00,
    3 => 0.75,
    4 => 0.50,
    5 => 0.25,
    6 => 0.20,
    7 => 0.10,
    8 => 0.08,
    9 => 0.05,
    10 => 0.025,
    11 => 0.015,
    12 => 0.010,
];

return [
    /*
    |--------------------------------------------------------------------------
    | Zoom → Precision table
    |--------------------------------------------------------------------------
    |
    | Precision is expressed in decimal degrees and controls the grid size
    | used when aggregating low-zoom clusters.
    |
    */
    'precision_by_zoom' => $precisionByZoom,
    'zoom_precision' => $precisionByZoom,

    'zoom_min' => 1,

    'zoom_max' => 18,

    'precision_default' => 0.50,

    'precision_min' => 0.005,

    'precision_max' => 2.000,

    'markers_zoom_threshold' => 12,

    'markers_per_page' => 1000,

    'max_cluster_items' => 3000,

    'cache_ttl_seconds' => 60,

    'marker_bounds_decimals' => 4,
];
