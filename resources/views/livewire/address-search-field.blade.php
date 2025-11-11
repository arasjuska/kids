@php
    use Illuminate\Support\Str;
    $listboxId = 'addr-suggestions-'.Str::uuid();
@endphp

<div
    class="fi-input-wrp relative space-y-2"
    x-data="addressSearchDropdown({
        listboxId: '{{ $listboxId }}',
        suggestions: @entangle('suggestions').live,
        selectCallback(suggestion) {
            const payload = window.structuredClone ? structuredClone(suggestion) : JSON.parse(JSON.stringify(suggestion));
            const placeId = String(payload.place_id ?? '');

            window.dispatchEvent(
                new CustomEvent('address-field::select', {
                    detail: {
                        suggestion: payload,
                        place_id: placeId,
                        lat: Number(payload.latitude ?? payload.lat ?? 0),
                        lng: Number(payload.longitude ?? payload.lon ?? 0),
                    },
                })
            );

            this.$wire.selectSuggestion(payload);
        },
        initialQuery: @js($query ?? ''),
    })"
    x-init="init()"
>
    <div class="fi-input-wrp-field">
        <input
            x-ref="searchInput"
            type="text"
            wire:model.live.debounce.250ms="query"
            x-on:input="rawQuery = $event.target.value"
            x-on:focus="isFocused = true; open = suggestionsAvailable();"
            x-on:blur="isFocused = false"
            x-on:keydown.arrow-down.prevent="nextItem()"
            x-on:keydown.arrow-up.prevent="previousItem()"
            x-on:keydown.enter.prevent="selectActive()"
            x-on:keydown.escape.stop="closeDropdown()"
            :aria-expanded="open.toString()"
            aria-autocomplete="list"
            aria-controls="{{ $listboxId }}"
            role="combobox"
            autocomplete="off"
            placeholder="Įveskite adresą..."
            class="fi-input block w-full rounded-xl border border-gray-300 bg-white py-2 pe-12 ps-3 text-base text-gray-900 transition placeholder:text-gray-400 focus:border-primary-500 focus:ring-1 focus:ring-primary-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 dark:placeholder:text-gray-500"
        />
        <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center pe-3 text-gray-400">
            <svg class="h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-4.35-4.35M10 18a8 8 0 100-16 8 8 0 000 16z" />
            </svg>
        </div>
    </div>

    <div class="text-xs text-gray-500 dark:text-gray-400">
        <p x-show="isFocused && !rawQuery" x-cloak>Pradėkite vesti adresą arba pasirinkite „Nustatyti PIN žemėlapyje“.</p>
        <p x-show="isFocused && open && limitedItems.length" x-cloak>Enter – pasirinkti. ↑/↓ – judėti sąraše.</p>
    </div>

    <div
        x-cloak
        x-show="open"
        class="fi-address-suggestions absolute left-0 right-0 top-full z-50 mt-1 pointer-events-auto"
    >
        <div class="w-full overflow-hidden rounded-xl border border-slate-200 bg-white shadow-xl ring-1 ring-black/5 dark:border-slate-700 dark:bg-gray-900/95">
            <ul
                id="{{ $listboxId }}"
                role="listbox"
                class="max-h-60 divide-y divide-slate-200 overflow-y-auto text-sm dark:divide-slate-700"
            >
                <template x-for="(item, index) in limitedItems" :key="item.place_id ?? index">
                    <li>
                        <button
                            type="button"
                            role="option"
                            class="flex w-full flex-col items-start px-3 py-2 text-left transition"
                            :class="{ 'bg-primary-50 dark:bg-primary-500/20': isActive(index), 'hover:bg-gray-50 dark:hover:bg-gray-800': true }"
                            @mouseenter="highlight(index)"
                            @click.prevent.stop="choose(index)"
                            :aria-selected="isActive(index).toString()"
                        >
                            <span class="font-medium text-gray-900 dark:text-white" x-text="item.short_address_line || item.formatted_address || 'Adresų rezultatas'"></span>
                            <span class="mt-1 text-xs text-gray-500 dark:text-gray-300" x-show="item.context_line" x-text="item.context_line"></span>
                            <span class="mt-0.5 text-[11px] text-gray-500 dark:text-gray-400" x-show="typeof item.confidence !== 'undefined'">
                                Patikimumas:
                                <span
                                    :class="confidenceClass(item.confidence)"
                                    x-text="Math.round((item.confidence || 0) * 100) + '%'"
                                ></span>
                            </span>
                        </button>
                    </li>
                </template>
                <li
                    wire:loading.flex
                    wire:target="query"
                    class="items-center gap-2 px-3 py-2 text-xs text-gray-500 dark:text-gray-400"
                >
                    <svg class="h-4 w-4 animate-spin" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
                    </svg>
                    <span>Ieškoma…</span>
                </li>
                <li
                    x-show="!limitedItems.length"
                    class="px-3 py-2 text-xs text-gray-500 dark:text-gray-400"
                >
                    Nieko nerasta
                </li>
            </ul>
        </div>
    </div>
</div>

@once
    @push('scripts')
        <script>
            window.addressSearchDropdown = function ({ listboxId, suggestions, selectCallback, initialQuery }) {
                return {
                    listboxId,
                    rawItems: suggestions,
                    rawQuery: initialQuery || '',
                    isFocused: false,
                    open: false,
                    active: -1,
                    init() {
                        this.$watch('rawItems', () => {
                            this.open = this.suggestionsAvailable();
                            if (! this.open) {
                                this.active = -1;
                            } else if (this.active === -1) {
                                this.active = 0;
                            }
                        });
                    },
                    get limitedItems() {
                        return Array.isArray(this.rawItems) ? this.rawItems.slice(0, 5) : [];
                    },
                    suggestionsAvailable() {
                        return Array.isArray(this.rawItems) && this.rawItems.length > 0;
                    },
                    isActive(index) {
                        return this.active === index;
                    },
                    highlight(index) {
                        this.active = index;
                    },
                    nextItem() {
                        if (! this.suggestionsAvailable()) {
                            return;
                        }
                        this.open = true;
                        this.active = (this.active + 1) % this.limitedItems.length;
                    },
                    previousItem() {
                        if (! this.suggestionsAvailable()) {
                            return;
                        }
                        this.open = true;
                        this.active = (this.active - 1 + this.limitedItems.length) % this.limitedItems.length;
                    },
                    selectActive() {
                        if (this.active < 0) {
                            return;
                        }
                        this.choose(this.active);
                    },
                    choose(index) {
                        const choice = this.limitedItems[index] ?? null;
                        if (! choice) {
                            return;
                        }
                        const placeId = String(choice.place_id ?? '');
                        if (! placeId) {
                            return;
                        }
                        selectCallback(choice, placeId);
                        this.closeDropdown();
                    },
                    closeDropdown() {
                        this.open = false;
                        this.active = -1;
                    },
                    confidenceClass(value) {
                        const v = Number(value || 0);
                        const base = 'inline-flex items-center rounded-full border px-2 py-0.5 text-[11px] font-medium';
                        if (v >= 0.95) {
                            return base + ' bg-green-50 text-green-700 border-green-200 dark:bg-green-900/30 dark:text-green-200 dark:border-green-500/30';
                        }
                        if (v >= 0.6) {
                            return base + ' bg-amber-50 text-amber-700 border-amber-200 dark:bg-amber-900/30 dark:text-amber-200 dark:border-amber-500/30';
                        }
                        return base + ' bg-rose-50 text-rose-700 border-rose-200 dark:bg-rose-900/30 dark:text-rose-200 dark:border-rose-500/30';
                    },
                };
            };
        </script>
    @endpush
@endonce
