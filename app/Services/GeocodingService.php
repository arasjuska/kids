<?php

namespace App\Services;

use App\Contracts\GeocodingServiceInterface;
use App\Data\GeocodeResult;
use App\Services\Providers\NominatimClient;
use App\Support\GeoNormalizer;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Throwable;

final class GeocodingService implements GeocodingServiceInterface
{
    private const CACHE_VERSION = 'v2';

    private CacheRepository $cache;

    public function __construct(
        private readonly GeoNormalizer $normalizer,
        private readonly NominatimClient $provider,
        CacheFactory $cacheFactory,
    ) {
        $this->cache = $cacheFactory->store();
    }

    public function forward(string $query, ?string $countryCode = null): ?GeocodeResult
    {
        $normalized = $this->normalizer->normalizeForwardQuery($query, $countryCode, true);
        $cacheKey = $this->forwardKey($normalized['key'], $normalized['cc']);
        $startedAt = microtime(true);

        if ($cached = $this->cache->get($cacheKey)) {
            $this->logCache('forward', 'hit', $cacheKey, $startedAt);

            return $cached;
        }

        $this->logCache('forward', 'miss', $cacheKey, $startedAt);

        if ($this->breakerOpen()) {
            $this->logBreaker('forward', 'open');

            return null;
        }

        $result = $this->throttle(fn () => $this->callProvider(fn () => $this->provider->forward([
            'raw' => $normalized['raw'],
            'q' => $normalized['q'],
            'cc' => $normalized['cc'],
        ])));

        if (! $result instanceof GeocodeResult) {
            return null;
        }

        $this->cache->put($cacheKey, $result, $this->ttl('forward'));
        $this->logLatency('forward', $cacheKey, $startedAt);

        return $result;
    }

    public function reverse(float $lat, float $lon): ?GeocodeResult
    {
        $rounded = $this->normalizer->roundForReverse($lat, $lon, 'DEFAULT');
        $cacheKey = $this->reverseKey($rounded['lat'], $rounded['lon']);
        $startedAt = microtime(true);

        if ($cached = $this->cache->get($cacheKey)) {
            $this->logCache('reverse', 'hit', $cacheKey, $startedAt);

            return $cached;
        }

        $this->logCache('reverse', 'miss', $cacheKey, $startedAt);

        if ($this->breakerOpen()) {
            $this->logBreaker('reverse', 'open');

            return null;
        }

        $result = $this->throttle(fn () => $this->callProvider(fn () => $this->provider->reverse($lat, $lon)));

        if (! $result instanceof GeocodeResult) {
            return null;
        }

        $precise = $this->normalizer->roundForReverse($result->latitude, $result->longitude, $result->accuracyLevel);
        $cacheKey = $this->reverseKey($precise['lat'], $precise['lon']);
        $this->cache->put($cacheKey, $result, $this->ttl('reverse'));
        $this->logLatency('reverse', $cacheKey, $startedAt);

        return $result;
    }

    public function search(string $query, array $options = []): Collection
    {
        $country = $options['country_codes'] ?? null;
        $limit = max(1, (int) ($options['limit'] ?? 8));

        $normalized = $this->normalizer->normalizeForwardQuery($query, $country, true);
        $cacheKey = $this->searchKey($normalized['key'], $normalized['cc'], $limit);
        $startedAt = microtime(true);

        if ($cached = $this->cache->get($cacheKey)) {
            $this->logCache('search', 'hit', $cacheKey, $startedAt);

            return collect($cached);
        }

        $this->logCache('search', 'miss', $cacheKey, $startedAt);

        if ($this->breakerOpen()) {
            $this->logBreaker('search', 'open');

            return collect();
        }

        $raw = $this->throttle(function () use ($normalized, $limit, $options) {
            return $this->callProviderRaw(fn () => $this->provider->search([
                'raw' => $normalized['raw'],
                'q' => $normalized['q'],
                'cc' => $normalized['cc'],
                'limit' => $limit,
            ] + $options));
        });

        $rawResults = is_array($raw) ? array_slice($raw, 0, $limit) : [];

        if (empty($rawResults)) {
            return collect();
        }

        $mapped = collect($rawResults)
            ->map(fn ($item) => $this->normalizer->mapProviderSuggestion((array) $item))
            ->filter(fn ($item) => ! empty($item['place_id']))
            ->values();

        $this->cache->put($cacheKey, $mapped->all(), config('geocoding.cache.search_ttl'));
        $this->logLatency('search', $cacheKey, $startedAt);

        return $mapped;
    }

    private function callProvider(callable $callback): ?GeocodeResult
    {
        $attempts = config('geocoding.http.retry.max_attempts');
        $delay = config('geocoding.http.retry.initial_delay_ms');

        for ($i = 0; $i < $attempts; $i++) {
            try {
                $raw = $callback();

                if ($raw === null) {
                    return null;
                }

                $this->resetFailures();

                return $this->normalizer->mapProviderPayload($raw);
            } catch (RequestException $e) {
                if (! $this->shouldRetry($e)) {
                    $this->recordFailure();
                    throw $e;
                }

                $this->recordFailure();
                usleep(($delay + random_int(0, 150)) * 1000);
                $delay = min($delay * 2, config('geocoding.http.retry.max_delay_ms'));
            } catch (Throwable $e) {
                $this->recordFailure();
                throw $e;
            }
        }

        return null;
    }

    private function throttle(callable $callback)
    {
        $key = 'geo:throttle:'.$this->provider->name();
        $max = max(1, config('geocoding.throttle.rps'));
        $window = max(1, config('geocoding.throttle.window_seconds'));

        $result = null;

        $allowed = RateLimiter::attempt($key, $max, function () use (&$result, $callback) {
            $result = $callback();
        }, $window);

        if ($allowed !== false) {
            return $result;
        }

        $this->logBreaker('throttle', 'blocked');

        return null;
    }

    private function shouldRetry(RequestException $exception): bool
    {
        $status = $exception->response?->status();

        if ($status === null) {
            return true;
        }

        if ($status >= 500 || $status === 429) {
            return true;
        }

        return false;
    }

    private function forwardKey(string $canonicalQuery, ?string $country): string
    {
        return sprintf(
            'geo:%s:f:%s:%s',
            self::CACHE_VERSION,
            $country ? Str::upper($country) : 'XX',
            hash('sha1', $canonicalQuery)
        );
    }

    private function reverseKey(float $lat, float $lon): string
    {
        return sprintf('geo:%s:r:%s:%s', self::CACHE_VERSION, $lat, $lon);
    }

    private function searchKey(string $canonicalQuery, ?string $country, int $limit): string
    {
        return sprintf(
            'geo:%s:s:%s:%s:%d',
            self::CACHE_VERSION,
            $country ? Str::upper($country) : 'XX',
            hash('sha1', $canonicalQuery),
            $limit
        );
    }

    private function ttl(string $type): int
    {
        return $type === 'forward'
            ? config('geocoding.cache.forward_ttl')
            : config('geocoding.cache.reverse_ttl');
    }

    private function logCache(string $type, string $event, string $key, float $startedAt): void
    {
        Log::channel(config('geocoding.log_channel'))->info("geocoding.cache.{$event}", [
            'type' => $type,
            'key' => $key,
            'elapsed_ms' => (microtime(true) - $startedAt) * 1000,
        ]);
    }

    private function logLatency(string $type, string $key, float $startedAt): void
    {
        Log::channel(config('geocoding.log_channel'))->info('geocoding.request.latency', [
            'type' => $type,
            'key' => $key,
            'elapsed_ms' => (microtime(true) - $startedAt) * 1000,
        ]);
    }

    private function logBreaker(string $type, string $state): void
    {
        Log::channel(config('geocoding.log_channel'))->warning("geocoding.breaker.{$state}", [
            'type' => $type,
            'state' => $this->breakerState(),
            'failures' => $this->failureCount(),
        ]);
    }

    private function callProviderRaw(callable $callback): array
    {
        $attempts = config('geocoding.http.retry.max_attempts');
        $delay = config('geocoding.http.retry.initial_delay_ms');

        for ($i = 0; $i < $attempts; $i++) {
            try {
                $raw = $callback();

                if ($raw === null) {
                    return [];
                }

                $this->resetFailures();

                return is_array($raw) ? $raw : [];
            } catch (RequestException $e) {
                if (! $this->shouldRetry($e)) {
                    $this->recordFailure();
                    throw $e;
                }

                $this->recordFailure();
                usleep(($delay + random_int(0, 150)) * 1000);
                $delay = min($delay * 2, config('geocoding.http.retry.max_delay_ms'));
            } catch (Throwable $e) {
                $this->recordFailure();
                throw $e;
            }
        }

        return [];
    }

    private function breakerOpen(): bool
    {
        $state = $this->breakerState();

        if ($state === 'closed') {
            return false;
        }

        if ($state === 'half-open') {
            return false;
        }

        $openedAt = $this->cache->get($this->breakerOpenedKey());
        if ($openedAt && Carbon::parse($openedAt)->addSeconds(config('geocoding.breaker.open_seconds'))->isPast()) {
            $this->cache->put($this->breakerKey(), 'half-open', config('geocoding.breaker.open_seconds'));

            return false;
        }

        return true;
    }

    private function breakerState(): string
    {
        return (string) $this->cache->get($this->breakerKey(), 'closed');
    }

    private function breakerKey(): string
    {
        return 'geo:breaker:'.$this->provider->name().':state';
    }

    private function breakerOpenedKey(): string
    {
        return 'geo:breaker:'.$this->provider->name().':opened_at';
    }

    private function failureKey(): string
    {
        return 'geo:breaker:'.$this->provider->name().':failures';
    }

    private function failureCount(): int
    {
        return (int) $this->cache->get($this->failureKey(), 0);
    }

    private function recordFailure(): void
    {
        $count = $this->cache->increment($this->failureKey());
        $this->cache->put($this->failureKey(), $count, config('geocoding.breaker.interval_seconds'));

        if ($count >= config('geocoding.breaker.failure_threshold')) {
            $this->cache->put($this->breakerKey(), 'open', config('geocoding.breaker.open_seconds'));
            $this->cache->put($this->breakerOpenedKey(), now()->toIso8601String(), config('geocoding.breaker.open_seconds'));
        }
    }

    private function resetFailures(): void
    {
        $this->cache->forget($this->failureKey());
        $this->cache->put($this->breakerKey(), 'closed', config('geocoding.breaker.open_seconds'));
    }
}
