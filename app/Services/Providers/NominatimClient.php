<?php

namespace App\Services\Providers;

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;

final class NominatimClient
{
    public function __construct(private readonly HttpFactory $http)
    {
    }

    public function name(): string
    {
        return 'nominatim';
    }

    public function forward(array $payload): ?array
    {
        $response = $this->request()->get('/search', array_filter([
            'q' => $payload['raw'] ?? $payload['q'] ?? null,
            'countrycodes' => $payload['cc'] ?? null,
            'format' => 'json',
            'addressdetails' => 1,
            'limit' => 1,
        ]));

        $data = $response->throw()->json();

        return $data[0] ?? null;
    }

    public function search(array $payload, array $options = []): array
    {
        $limit = max(1, min((int) ($options['limit'] ?? 8), 15));

        $response = $this->request()->get('/search', array_filter([
            'q' => $payload['raw'] ?? $payload['q'] ?? null,
            'countrycodes' => $payload['cc'] ?? null,
            'format' => 'json',
            'addressdetails' => 1,
            'limit' => $limit,
        ]));

        $data = $response->throw()->json();

        return is_array($data) ? $data : [];
    }

    public function reverse(float $lat, float $lon): ?array
    {
        $response = $this->request()->get('/reverse', [
            'lat' => $lat,
            'lon' => $lon,
            'format' => 'json',
            'addressdetails' => 1,
        ]);

        return $response->throw()->json();
    }

    private function request(): PendingRequest
    {
        $config = config('geocoding');
        $headers = $config['providers']['nominatim']['headers'];
        $timeouts = $config['http'];

        return $this->http->baseUrl($config['providers']['nominatim']['base_url'])
            ->withHeaders($headers)
            ->timeout($timeouts['read_timeout'])
            ->connectTimeout($timeouts['connect_timeout']);
    }
}
