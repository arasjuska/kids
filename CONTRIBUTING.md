# Contributing

Thanks for helping improve the project! Please review the existing coding standards before opening a PR and keep changes focused and well tested.

## String hygiene (UTF-8 NFC)

- Normalize every user-facing string through `App\Support\TextNormalizer::toNfc()` before persisting; FormRequests already do this in `prepareForValidation`, and the `Address`, `Place`, and related models re-run the guard via mutators.
- Avoid storing decomposed UTF-8 sequences or mixed encodings—tests in `tests/Feature/Utf8NormalizationTest.php` cover the required behaviour, so add similar coverage when touching input flows.
- Reject or strip invalid byte sequences instead of silently passing them downstream. If you need custom sanitisation, reuse the helper rather than reimplementing iconv/regex filters.

## Map warm-up

- Production boots (and any environment with `MAP_WARMUP=true`) preheat `PrecisionFromZoom` for common zooms; set the env var locally if you want the same behaviour without enabling production mode.
- Use `php artisan map:warmup --zooms=3,6,9,12,15 --bbox=25.20,54.60,25.40,54.80` to manually prime caches; add `--force` to hit the `MapClusterService` query outside production.
