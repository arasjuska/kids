<?php

return [
    'hot_file' => public_path('hot'),
    'build_path' => 'build',
    // When running tests, point to an empty manifest so @vite never throws.
    'manifest' => (env('APP_ENV') === 'testing')
        ? base_path('tests/fixtures/empty-manifest.json')
        : public_path('build/manifest.json'),
];
