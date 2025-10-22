<div class="fi-input-wrp relative">
    <div class="fi-input-wrp-field">
        <input
            type="text"
            wire:model.live.debounce.500ms="query"
            autocomplete="off"
            placeholder="Įveskite adresą..."
            class="fi-input block w-full border-none bg-transparent py-1.5 pe-12 ps-3 text-base text-gray-950 transition duration-75 placeholder:text-gray-400 focus:ring-0 dark:text-white dark:placeholder:text-gray-500 sm:text-sm sm:leading-6"
        />

        <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center pe-3">
            <svg class="h-5 w-5 text-gray-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-4.35-4.35M10 18a8 8 0 100-16 8 8 0 000 16z" />
            </svg>
        </div>
    </div>

    @php $list = $suggestions ?? []; @endphp
    @if (is_array($list) && count($list) > 0)
        <div class="fi-address-suggestions absolute left-0 right-0 top-full mt-1 z-50">
            <div class="w-full overflow-hidden rounded-xl border border-slate-200 bg-white shadow-xl ring-1 ring-black/5 dark:border-slate-700 dark:bg-gray-900/95">
                <ul class="max-h-60 divide-y divide-slate-200 overflow-y-auto text-sm dark:divide-slate-700">
                    @foreach ($list as $s)
                        @php $pid = (string)($s['place_id'] ?? ''); @endphp
                        <li>
                            <button
                                type="button"
                                wire:click.prevent="select('{{ $pid }}')"
                                x-on:click="
                                    const suggestion = @js($s);
                                    const lat = Number(suggestion.latitude ?? suggestion.lat ?? 0);
                                    const lng = Number(suggestion.longitude ?? suggestion.lon ?? 0);
                                    const placeId = String(suggestion.place_id ?? '');
                                    window.dispatchEvent(new CustomEvent('address-field::select', { detail: { suggestion, place_id: placeId, lat, lng } }));
                                "
                                class="flex w-full flex-col items-start px-3 py-2 text-left text-sm transition hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 dark:text-slate-200 dark:hover:bg-slate-800"
                            >
                                <span class="font-medium text-gray-900 dark:text-white">
                                    {{ $s['short_address_line'] ?? $s['formatted_address'] ?? 'Adresų rezultatas' }}
                                </span>
                                @if (($s['context_line'] ?? null))
                                    <span class="mt-1 text-xs text-gray-500 dark:text-gray-300">{{ $s['context_line'] }}</span>
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
                        </li>
                    @endforeach
                </ul>
            </div>
        </div>
    @endif
</div>
