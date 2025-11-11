<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Contracts\GeocodingServiceInterface;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Livewire\Component;

/**
 * Livewire input + dropdown, bound to Filament field state via LivewireField (wire:model="model").
 * Expects $model to be an array with keys: search_query, suggestions, selected_place_id.
 */
class AddressSearchField extends Component
{
    /**
     * The entire Filament field state is bound here via LivewireField (wire:model="model").
     *
     * @var array<string, mixed>
     */
    public array $model = [];

    public string $countryCode = 'lt';

    public int $minSearchLength = 3;

    public string $query = '';

    protected int $searchToken = 0;

    protected string $lastExecutedQuery = '';

    /**
     * Local suggestions used only for rendering the dropdown.
     * Parent model is updated only on selection to avoid input re-renders.
     *
     * @var array<int, array<string, mixed>>
     */
    public array $suggestions = [];

    public function mount(): void
    {
        // Ensure expected keys exist to avoid undefined index notices in Blade
        $this->model = array_replace([
            'search_query' => '',
            'suggestions' => [],
            'selected_place_id' => null,
        ], $this->model ?? []);

        $this->query = (string) ($this->model['search_query'] ?? '');
        $this->suggestions = [];
    }

    public function updatedQuery(): void
    {
        $trimmed = trim($this->query);

        if (is_array($this->model)) {
            $this->model['search_query'] = $trimmed;
        }

        if (app()->environment(['local', 'testing'])) {
            Log::info('addr:search:input', [
                'query' => $trimmed,
                'token' => $this->searchToken,
            ]);
        }

        if (mb_strlen($trimmed) < $this->minSearchLength) {
            $this->suggestions = [];
            $this->lastExecutedQuery = '';

            return;
        }

        $normalized = Str::of($trimmed)->lower()->squish()->value();

        if ($normalized === $this->lastExecutedQuery && ! empty($this->suggestions)) {
            return;
        }

        $this->performSearch($trimmed, $normalized);
    }

    public function performSearch(?string $query = null, ?string $normalized = null): void
    {
        $raw = $query ?? trim((string) $this->query);
        $q = trim($raw);
        $normalized ??= Str::of($q)->lower()->squish()->value();

        if ($normalized === '' || mb_strlen($q) < $this->minSearchLength) {
            $this->suggestions = [];
            // keep parent model in sync so manager can resolve selections
            if (is_array($this->model)) {
                $this->model['suggestions'] = [];
            }
            $this->lastExecutedQuery = '';

            return;
        }

        if ($normalized === $this->lastExecutedQuery && ! empty($this->suggestions)) {
            return;
        }

        $token = ++$this->searchToken;

        if (app()->environment(['local', 'testing'])) {
            Log::info('addr:search:perform', [
                'query' => $q,
                'normalized' => $normalized,
                'token' => $token,
            ]);
        }

        try {
            /** @var GeocodingServiceInterface $svc */
            $svc = app(GeocodingServiceInterface::class);
            $results = $svc->search($q, [
                'country_codes' => $this->countryCode,
                'limit' => 8,
            ])->take(8)->values();

            if ($token !== $this->searchToken) {
                return;
            }

            $this->lastExecutedQuery = $normalized;
            $this->suggestions = $results->toArray();
            // propagate to parent field state under `live.suggestions`
            if (is_array($this->model)) {
                $this->model['suggestions'] = $this->suggestions;
            }
        } catch (\Throwable $e) {
            Log::error('AddressSearchField performSearch failed', [
                'query' => $q,
                'error' => $e->getMessage(),
            ]);
            $this->suggestions = [];
            if (is_array($this->model)) {
                $this->model['suggestions'] = [];
            }
            $this->lastExecutedQuery = '';
        }
    }

    public function select(string $placeId): void
    {
        if (app()->environment(['local', 'testing'])) {
            Log::info('addr:search:select', [
                'place_id' => $placeId,
                'token' => $this->searchToken,
            ]);
        }

        $this->model['selected_place_id'] = (string) $placeId;

        if (is_array($this->model)) {
            $this->model['search_query'] = '';
        }

        // Find full suggestion payload and pass it to parent model for reliability
        $chosen = null;
        foreach (($this->suggestions ?? []) as $s) {
            if ((string) ($s['place_id'] ?? '') === (string) $placeId) {
                $chosen = $s;
                break;
            }
        }
        if ($chosen !== null && is_array($this->model)) {
            $this->model['selected_suggestion'] = $chosen;
            $this->model['suggestions'] = [$chosen];
        }

        // Clear local dropdown but keep parent suggestions for one update cycle
        $this->suggestions = [];
        $this->query = '';
        $this->lastExecutedQuery = '';
    }

    /**
     * Direct payload selection (bypasses lookup).
     *
     * @param  array<string, mixed>  $payload
     */
    public function selectSuggestion(array $payload): void
    {
        $placeId = (string) ($payload['place_id'] ?? ($payload['id'] ?? ''));

        if ($placeId === '') {
            $placeId = (string) Str::uuid();
            $payload['place_id'] = $placeId;
        }

        if (app()->environment(['local', 'testing'])) {
            Log::info('addr:search:select:payload', [
                'place_id' => $placeId,
                'token' => $this->searchToken,
            ]);
        }

        $this->model['selected_place_id'] = $placeId;
        $this->model['selected_suggestion'] = $payload;
        $this->model['suggestions'] = [$payload];
        $this->model['search_query'] = '';

        $this->suggestions = [];
        $this->query = '';
        $this->lastExecutedQuery = '';
    }

    public function render()
    {
        return view('livewire.address-search-field');
    }
}
