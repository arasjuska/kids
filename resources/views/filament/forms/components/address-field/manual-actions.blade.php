@php
    $modeValue = $mode ?? null;
    $canSwitchToManual = $modeValue === \App\Enums\InputModeEnum::SEARCH->value;
@endphp

@if ($canSwitchToManual)
    <div class="flex items-center justify-start">
        <button
            type="button"
            class="fi-btn fi-btn-secondary fi-size-sm"
            wire:click="$set('{{ $statePath }}.control.switch_manual_token', Date.now())"
        >
            <svg class="fi-btn-icon fi-btn-icon-start h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13l-3.262 1.087 1.087-3.262a4.5 4.5 0 011.13-1.897l10.222-10.222z" />
                <path stroke-linecap="round" stroke-linejoin="round" d="M18 14v4.75A2.25 2.25 0 0115.75 21H5.25A2.25 2.25 0 013 18.75V8.25A2.25 2.25 0 015.25 6H10" />
            </svg>
            <span>{{ __('Rankinis įvedimas') }}</span>
        </button>
    </div>
@endif
