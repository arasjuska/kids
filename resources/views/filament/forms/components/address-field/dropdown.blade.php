@php
    $items = $suggestions ?? [];
@endphp

@if (is_array($items) && count($items) > 0)
    <div class="relative">
        <div class="fi-address-suggestions absolute left-0 right-0 top-full mt-1 z-50">
            <div class="w-full overflow-hidden rounded-xl border border-slate-200 bg-white shadow-xl ring-1 ring-black/5 dark:border-slate-700 dark:bg-gray-900/95 max-h-64 overflow-y-auto">
                @foreach ($items as $s)
                    @php $pid = (string)($s['place_id'] ?? ''); @endphp
                    <button
                        type="button"
                        wire:click.prevent="$set('{{ $statePath }}.selected_place_id', '{{ $pid }}')"
                        x-on:click="
                            const suggestion = @js($s);
                            const lat = Number(suggestion.latitude ?? suggestion.lat ?? 0);
                            const lng = Number(suggestion.longitude ?? suggestion.lon ?? 0);
                            window.dispatchEvent(new CustomEvent('address-field::select', { detail: { suggestion, place_id: '{{ $pid }}', lat, lng } }));
                        "
                        class="flex w-full flex-col items-start border-b border-slate-200 px-3 py-2 text-left text-sm transition last:border-b-0 hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800"
                    >
                        <span class="font-medium text-gray-900 dark:text-white">
                            {{ $s['short_address_line'] ?? $s['formatted_address'] ?? 'Nežinomas adresas' }}
                        </span>
                        @if (($s['city'] ?? null) || ($s['postal_code'] ?? null))
                            <span class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                {{ $s['city'] ?? '' }} {{ $s['postal_code'] ?? '' }}
                            </span>
                        @endif
                        @if (isset($s['confidence']))
                            @php
                                $v = (float) ($s['confidence'] ?? 0);
                                $base = 'inline-flex items-center rounded-full border px-2 py-0.5 text-[11px] font-medium';
                                $cls = $v >= 0.95
                                    ? $base . ' bg-green-50 text-green-700 border-green-200 dark:bg-green-900/30 dark:text-green-200 dark:border-green-500/30'
                                    : ($v >= 0.6
                                        ? $base . ' bg-amber-50 text-amber-700 border-amber-200 dark:bg-amber-900/30 dark:text-amber-200 dark:border-amber-500/30'
                                        : $base . ' bg-red-50 text-red-700 border-red-200 dark:bg-red-900/30 dark:text-red-200 dark:border-red-500/30');
                            @endphp
                            <span class="mt-0.5 text-[11px] text-gray-500 dark:text-gray-400">
                                Patikimumas: <span class="{{ $cls }}">{{ round($v * 100) }}%</span>
                            </span>
                        @endif
                    </button>
                @endforeach
            </div>
        </div>
    </div>
@endif
