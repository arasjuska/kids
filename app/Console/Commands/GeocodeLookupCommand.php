<?php

namespace App\Console\Commands;

use App\Contracts\GeocodingServiceInterface;
use Illuminate\Console\Command;

class GeocodeLookupCommand extends Command
{
    protected $signature = 'geo:lookup {query} {--country=}';

    protected $description = 'Perform a cached geocoding lookup';

    public function handle(GeocodingServiceInterface $service): int
    {
        $result = $service->forward($this->argument('query'), $this->option('country'));

        if (! $result) {
            $this->warn('No result');

            return self::FAILURE;
        }

        $payload = $result->toArray();

        $this->table(
            array_keys($payload),
            [array_map(fn ($value) => is_scalar($value) ? (string) $value : json_encode($value, JSON_PRETTY_PRINT), $payload)]
        );

        return self::SUCCESS;
    }
}
