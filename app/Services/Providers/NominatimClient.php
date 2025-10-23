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

    public function forward(array $normalized): ?array
    {
        $response = $this->request()->get('/search', array_filter([
            'q' => $normalized['q'],
            'countrycodes' => $normalized['cc'],
            'format' => 'json',
            'addressdetails' => 1,
            'limit' => 1,
        ]));

        $data = $response->throw()->json();

        return $data[0] ?? null;
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
