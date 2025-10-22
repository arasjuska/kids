<div
    class="fi-input-wrp relative"
    x-data="{ items: $wire.entangle('{{ $statePath }}.suggestions').live }"
>
    <div class="fi-input-wrp-field">
        <input
            type="text"
            wire:model.live.debounce.500ms="{{ $statePath }}.search_query"
            autocomplete="off"
            placeholder="Įveskite adresą..."
            class="fi-input block w-full border-none bg-transparent py-1.5 pe-12 ps-3 text-base text-gray-950 transition duration-75 placeholder:text-gray-400 focus:ring-0 disabled:text-gray-500 disabled:[-webkit-text-fill-color:theme(colors.gray.500)] dark:text-white dark:placeholder:text-gray-500 dark:disabled:text-gray-400 dark:disabled:[-webkit-text-fill-color:theme(colors.gray.400)] sm:text-sm sm:leading-6 rounded-lg border border-gray-950/10 dark:border-white/20 dark:bg-white/5 shadow-sm focus:border-blue-500 focus:ring-1 focus:ring-blue-500 dark:focus:border-blue-500 dark:focus:ring-blue-500"
        />

        <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center pe-3">
            <svg class="h-5 w-5 text-gray-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-4.35-4.35M10 18a8 8 0 100-16 8 8 0 000 16z" />
            </svg>
        </div>
    </div>

    <div
        x-show="Array.isArray(items) && items.length > 0"
        x-cloak
        class="absolute left-0 right-0 top-full mt-1 z-50"
    >
        <div class="w-full overflow-hidden rounded-lg border border-gray-200 bg-white shadow-lg dark:border-gray-700 dark:bg-gray-800">
            <ul class="max-h-60 divide-y divide-gray-100 overflow-y-auto text-sm dark:divide-gray-700">
                <template x-for="(s, idx) in items" :key="s.place_id ?? idx">
                    <li class="group">
                        <button
                            type="button"
                        @click.prevent="
                            const suggestion = window.structuredClone ? structuredClone(s) : JSON.parse(JSON.stringify(s));
                            const lat = Number(suggestion.latitude ?? suggestion.lat ?? suggestion.latLng?.[0] ?? 0);
                            const lng = Number(suggestion.longitude ?? suggestion.lon ?? suggestion.latLng?.[1] ?? 0);
                            const placeId = String(suggestion.place_id ?? '');
                            $wire.set('{{ $statePath }}.selected_place_id', placeId);
                            window.dispatchEvent(new CustomEvent('address-field::select', { detail: { suggestion, place_id: placeId, lat, lng } }));
                        "
                            class="flex w-full flex-col items-start px-3 py-2 text-left transition hover:bg-gray-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 dark:hover:bg-gray-700"
                        >
                            <span class="text-sm font-medium text-gray-900 dark:text-white" x-text="s.short_address_line || s.formatted_address || 'Adresų rezultatas'"></span>
                            <span class="mt-1 text-xs text-gray-500 dark:text-gray-300" x-show="s.context_line" x-text="s.context_line"></span>
                            <span class="mt-0.5 text-[11px]" x-show="typeof s.confidence !== 'undefined'">
                                Patikimumas:
                                <span
                                    x-text="Math.round((s.confidence || 0) * 100) + '%'"
                                    :class="(() => { const v = Number(s.confidence || 0); const base = 'inline-flex items-center rounded-full border px-2 py-0.5 font-medium'; if (v >= 0.95) return base + ' bg-green-50 text-green-700 border-green-200 dark:bg-green-900/30 dark:text-green-200 dark:border-green-500/30'; if (v >= 0.6) return base + ' bg-amber-50 text-amber-700 border-amber-200 dark:bg-amber-900/30 dark:text-amber-200 dark:border-amber-500/30'; return base + ' bg-red-50 text-red-700 border-red-200 dark:bg-red-900/30 dark:text-red-200 dark:border-red-500/30'; })()"
                                ></span>
                            </span>
                        </button>
                    </li>
                </template>
            </ul>
        </div>
    </div>
</div>
