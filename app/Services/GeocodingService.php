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
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Throwable;

final class GeocodingService implements GeocodingServiceInterface
{
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
        $normalized = $this->normalizer->normalizeForwardQuery($query, $countryCode);
        $cacheKey = $this->forwardKey($normalized['q'], $normalized['cc']);
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

        $result = $this->throttle(fn () => $this->callProvider(fn () => $this->provider->forward($normalized)));

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

    private function throttle(callable $callback): ?GeocodeResult
    {
        $key = 'geo:throttle:' . $this->provider->name();
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

    private function forwardKey(string $query, ?string $country): string
    {
        return sprintf(
            'geo:f:%s:%s',
            $country ? Str::upper($country) : 'XX',
            hash('sha1', $query)
        );
    }

    private function reverseKey(float $lat, float $lon): string
    {
        return sprintf('geo:r:%s:%s', $lat, $lon);
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
        return 'geo:breaker:' . $this->provider->name() . ':state';
    }

    private function breakerOpenedKey(): string
    {
        return 'geo:breaker:' . $this->provider->name() . ':opened_at';
    }

    private function failureKey(): string
    {
        return 'geo:breaker:' . $this->provider->name() . ':failures';
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
