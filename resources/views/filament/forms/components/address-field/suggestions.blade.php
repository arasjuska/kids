<div
    x-data="{ items: $wire.entangle('{{ $statePath }}.suggestions').live }"
    x-show="Array.isArray(items) && items.length > 0"
    x-cloak
    class="fi-address-suggestions absolute left-0 right-0 top-full mt-1 z-50"
>
    <div class="w-full overflow-hidden rounded-xl border border-slate-200 bg-white shadow-xl ring-1 ring-black/5 dark:border-slate-700 dark:bg-gray-900/95">
        <ul class="max-h-60 divide-y divide-slate-200 overflow-y-auto text-sm dark:divide-slate-700">
            <template x-for="(s, idx) in items" :key="s.place_id ?? idx">
                <li>
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
                        class="flex w-full flex-col items-start px-3 py-2 text-left text-sm transition hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 dark:text-slate-200 dark:hover:bg-slate-800"
                    >
                        <span class="font-medium text-gray-900 dark:text-white" x-text="s.short_address_line || s.formatted_address || 'Adresų rezultatas'"></span>
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
