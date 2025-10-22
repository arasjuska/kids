@php
    $fieldWrapperView = $getFieldWrapperView();
    $statePath = $getStatePath();
@endphp

<x-dynamic-component
    :component="$fieldWrapperView"
    :field="$field"
>
    <div
        wire:key="{{ $getLivewireKey() }}"
        class="fi-fo-address-field space-y-4"
    >
        {{ $getChildSchema() }}
    </div>
</x-dynamic-component>
