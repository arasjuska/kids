<div x-data="{
    show: @entangle('showSuggestions').live,
    loading: @entangle('isLoading').live,
    selectedIndex: @entangle('selectedIndex').live,
    suggestions: @entangle('suggestions').live,
    error: @entangle('error').live,
}" @click.away="show = false" @keydown.escape.window="show = false" class="relative">

    {{-- Read-Only pasirinkimo kortelė --}}
    @if(!empty($selectedAddress))
    <div
        class="fi-input group/input relative rounded-lg border border-gray-950/10 bg-white dark:border-white/20 dark:bg-white/5 shadow-sm transition duration-75 hover:border-gray-950/20 dark:hover:border-white/30 p-3">

        <div class="flex items-start justify-between space-x-4">
            {{-- Adreso informacija --}}
            <div class="flex-1 min-w-0">
                {{-- Adreso eilutė --}}
                <p class="text-sm font-semibold text-gray-900 dark:text-white truncate">
                    {{ $selectedAddress['short_address_line'] ?? $selectedAddress['formatted_address'] ?? 'Nežinomas
                    adresas' }}
                </p>

                {{-- Konteksto eilutė --}}
                @if(!empty($selectedAddress['context_line']))
                <p class="text-xs text-gray-500 dark:text-gray-400 truncate mt-0.5">
                    {{ $selectedAddress['context_line'] }}
                </p>
                @endif

                {{-- Patikimumo ženkliukas --}}
                @php
                $badgeColor = $selectedAddress['badge_color'] ?? 'info';
                $typeLabel = $selectedAddress['address_type_label'] ?? 'Nežinomas';

                $badgeClasses = match($badgeColor) {
                'success' => 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-300',
                'warning' => 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-300',
                'danger' => 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-300',
                default => 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-300',
                };
                @endphp

                <span
                    class="inline-flex items-center mt-2 px-2.5 py-0.5 rounded-full text-xs font-medium {{ $badgeClasses }}">
                    <svg class="w-3 h-3 mr-1.5" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd"
                            d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.857-9.857a.75.75 0 00-1.214-.858L9.5 11.238l-1.643-1.643a.75.75 0 00-1.06 1.06l2.25 2.25a.75.75 0 001.06 0l4-4z"
                            clip-rule="evenodd" />
                    </svg>
                    {{ $typeLabel }}
                </span>
            </div>

            {{-- Išvalyti mygtukas --}}
            <button type="button" wire:click="clearSelection"
                class="flex-shrink-0 text-gray-400 hover:text-red-600 dark:text-gray-500 dark:hover:text-red-400 transition duration-150 ease-in-out focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-offset-2 rounded p-1"
                title="Išvalyti adresą">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
        </div>
    </div>
    @else
    {{-- Paieškos įvesties laukas --}}
    <div class="relative">
        <input type="text" placeholder="{{ $placeholder }}" wire:model.live.debounce.300ms="query"
            wire:keydown.down.prevent="handleKeydown('ArrowDown')" wire:keydown.up.prevent="handleKeydown('ArrowUp')"
            wire:keydown.enter.prevent="handleKeydown('Enter')" wire:keydown.escape.prevent="handleKeydown('Escape')"
            @focus="$wire.openDropdownOnFocus()"
            class="fi-input block w-full border-none bg-transparent py-1.5 pe-12 ps-3 text-base text-gray-950 transition duration-75 placeholder:text-gray-400 focus:ring-0 disabled:text-gray-500 disabled:[-webkit-text-fill-color:theme(colors.gray.500)] dark:text-white dark:placeholder:text-gray-500 dark:disabled:text-gray-400 dark:disabled:[-webkit-text-fill-color:theme(colors.gray.400)] sm:text-sm sm:leading-6 rounded-lg border border-gray-950/10 dark:border-white/20 dark:bg-white/5 shadow-sm focus:border-blue-500 focus:ring-1 focus:ring-blue-500 dark:focus:border-blue-500 dark:focus:ring-blue-500" />

        {{-- Įkrovimo indikatorius --}}
        <div x-show="loading" x-cloak class="absolute inset-y-0 right-0 flex items-center pr-3 pointer-events-none">
            <svg class="animate-spin h-5 w-5 text-gray-500" xmlns="http://www.w3.org/2000/svg" fill="none"
                viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor"
                    d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z">
                </path>
            </svg>
        </div>
    </div>

    {{-- Klaidos pranešimas --}}
    @if($error)
    <div class="mt-2 flex items-start space-x-2 text-sm text-red-600 dark:text-red-400">
        <svg class="w-5 h-5 flex-shrink-0 mt-0.5" fill="currentColor" viewBox="0 0 20 20">
            <path fill-rule="evenodd"
                d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.28 7.22a.75.75 0 00-1.06 1.06L8.94 10l-1.72 1.72a.75.75 0 101.06 1.06L10 11.06l1.72 1.72a.75.75 0 101.06-1.06L11.06 10l1.72-1.72a.75.75 0 00-1.06-1.06L10 8.94 8.28 7.22z"
                clip-rule="evenodd" />
        </svg>
        <span>{{ $error }}</span>
    </div>
    @endif

    {{-- Pasiūlymų iškrentantis sąrašas --}}
    <div x-show="show && suggestions.length > 0" x-cloak x-transition:enter="transition ease-out duration-100"
        x-transition:enter-start="opacity-0 scale-95" x-transition:enter-end="opacity-100 scale-100"
        x-transition:leave="transition ease-in duration-75" x-transition:leave-start="opacity-100 scale-100"
        x-transition:leave-end="opacity-0 scale-95"
        class="absolute z-50 w-full mt-1 bg-white border border-gray-300 rounded-lg shadow-xl dark:bg-gray-800 dark:border-gray-700 max-h-60 overflow-y-auto">

        <ul class="divide-y divide-gray-200 dark:divide-gray-700">
            @forelse($suggestions as $index => $suggestion)
            <li wire:key="suggestion-{{ $index }}" wire:click="selectSuggestion({{ $index }})"
                @mouseenter="selectedIndex = {{ $index }}" :class="{
                    'bg-blue-50 dark:bg-blue-900/50': selectedIndex === {{ $index }},
                    'hover:bg-gray-50 dark:hover:bg-gray-700/50': selectedIndex !== {{ $index }}
                }" class="p-3 cursor-pointer transition duration-150 ease-in-out">

                {{-- Adreso eilutė --}}
                <p class="text-sm font-medium text-gray-900 dark:text-white">
                    {{ $suggestion['short_address_line'] ?? $suggestion['formatted_address'] ?? 'Nežinomas adresas' }}
                </p>

                {{-- Konteksto eilutė --}}
                @if(!empty($suggestion['context_line']))
                <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
                    {{ $suggestion['context_line'] }}
                </p>
                @endif

                {{-- Debug: Confidence indikatorius --}}
                @if(config('app.debug') && isset($suggestion['confidence']))
                <span class="inline-block mt-1 text-xs text-gray-400 dark:text-gray-500">
                    Tikslumas: {{ number_format($suggestion['confidence'] * 100, 0) }}%
                </span>
                @endif
            </li>
            @empty
            <li class="p-3 text-sm text-gray-500 dark:text-gray-400 text-center">
                Pasiūlymų nerasta
            </li>
            @endforelse
        </ul>
    </div>
    @endif
</div>