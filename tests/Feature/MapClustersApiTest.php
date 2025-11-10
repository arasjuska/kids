<?php

use App\Models\Address;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    Cache::flush();
});

it('returns aggregated clusters for low zoom levels', function (): void {
    seedRegionalAddresses();

    $response = $this->getJson('/api/map/clusters?north=56&south=53&east=26&west=20&zoom=6');

    $response->assertOk()
        ->assertJson([
            'mode' => 'cluster',
        ])
        ->assertJsonStructure([
            'items',
            'meta' => ['precision', 'count'],
        ]);

    $payload = $response->json();

    expect($payload['meta']['precision'])->toBe(0.20)
        ->and($payload['items'])->not->toBeEmpty()
        ->and(array_sum(array_column($payload['items'], 'count')))->toBeGreaterThanOrEqual(180);
});

it('returns paginated markers when zoomed in', function (): void {
    seedDenseArea(1200);

    $baseUrl = '/api/map/clusters?north=55&south=54&east=26&west=24&zoom=14';

    $first = $this->getJson($baseUrl);
    $first->assertOk()
        ->assertJsonPath('mode', 'markers')
        ->assertJsonPath('meta.per_page', 1000)
        ->assertJsonPath('meta.page', 1)
        ->assertJsonPath('meta.has_more', true);

    expect(count($first->json('items')))->toBe(1000);

    $second = $this->getJson($baseUrl.'&page=2');
    $second->assertOk()
        ->assertJsonPath('mode', 'markers')
        ->assertJsonPath('meta.page', 2)
        ->assertJsonPath('meta.has_more', false);

    expect(count($second->json('items')))->toBe(200);
});

it('serves cached payloads without hitting the database repeatedly', function (): void {
    seedRegionalAddresses();

    $url = '/api/map/clusters?north=56&south=53&east=26&west=20&zoom=6';

    DB::enableQueryLog();
    $this->getJson($url)->assertOk();
    $firstQueryCount = countAddressQueries(DB::getQueryLog());

    DB::flushQueryLog();
    $this->getJson($url)->assertOk();
    $secondQueryCount = countAddressQueries(DB::getQueryLog());
    DB::disableQueryLog();

    expect($firstQueryCount)->toBeGreaterThan(0)
        ->and($secondQueryCount)->toBe(0);
});

it('returns empty markers when no addresses within bounds', function (): void {
    $response = $this->getJson('/api/map/clusters?north=10&south=9&east=11&west=9&zoom=14');

    $response->assertOk()
        ->assertExactJson([
            'mode' => 'markers',
            'items' => [],
            'meta' => [
                'page' => 1,
                'per_page' => 1000,
                'has_more' => false,
            ],
        ]);
});

it('coarsens cluster precision when exceeding guardrail', function (): void {
    config()->set('map_clusters.max_cluster_items', 2);
    seedSparsePoints(10);

    $response = $this->getJson('/api/map/clusters?north=60&south=40&east=30&west=10&zoom=10');

    $response->assertOk()
        ->assertJsonPath('mode', 'cluster');

    expect($response->json('meta.precision'))->toBeGreaterThan(0.05);
});

it('validates bounds and zoom inputs', function (): void {
    $invalid = $this->getJson('/api/map/clusters?north=10&south=10&east=11&west=9&zoom=6');
    $invalid->assertStatus(422);

    $antimeridian = $this->getJson('/api/map/clusters?north=55&south=54&east=10&west=20&zoom=6');
    $antimeridian->assertStatus(400);
});

/**
 * Seed helpers
 */
function seedRegionalAddresses(int $perCity = 60): void
{
    $cities = [
        ['lat' => 54.6872, 'lon' => 25.2797],
        ['lat' => 54.8985, 'lon' => 23.9036],
        ['lat' => 55.7033, 'lon' => 21.1443],
    ];

    foreach ($cities as $center) {
        Address::factory()
            ->count($perCity)
            ->state(function () use ($center) {
                return [
                    'latitude' => fake()->randomFloat(6, $center['lat'] - 0.05, $center['lat'] + 0.05),
                    'longitude' => fake()->randomFloat(6, $center['lon'] - 0.05, $center['lon'] + 0.05),
                ];
            })
            ->create();
    }
}

function seedDenseArea(int $count): void
{
    Address::factory()
        ->count($count)
        ->state(function () {
            return [
                'latitude' => fake()->randomFloat(6, 54.60, 54.90),
                'longitude' => fake()->randomFloat(6, 25.00, 25.40),
            ];
        })
        ->create();
}

function seedSparsePoints(int $count): void
{
    Address::factory()
        ->count($count)
        ->state(function () {
            return [
                'latitude' => fake()->randomFloat(6, 40.0, 60.0),
                'longitude' => fake()->randomFloat(6, 10.0, 30.0),
            ];
        })
        ->create();
}

function countAddressQueries(array $queries): int
{
    return collect($queries)
        ->filter(function (array $query): bool {
            $sql = strtolower(str_replace(['`', '"'], '', $query['query'] ?? ''));

            return str_contains($sql, 'from addresses');
        })
        ->count();
}
