<div class="fi-alert fi-alert-info">
    <div class="fi-alert-content">
        {{ __('Automatiškai pasirinktas adresas') }}
    </div>

    <div class="fi-alert-actions">
        <button
            type="button"
            class="fi-btn fi-btn-info fi-size-xs"
            wire:click="$set('{{ $statePath }}.control.undo_autoselect_token', Date.now())"
        >
            <svg class="fi-btn-icon fi-btn-icon-start h-3.5 w-3.5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.992 0l3.181 3.183A8.25 8.25 0 0019.5 9.75M4.5 14.25A8.25 8.25 0 0119.5 4.5l-3.181 3.183" />
            </svg>
            <span>{{ __('Pasirinkti kitą') }}</span>
        </button>
    </div>
</div>
