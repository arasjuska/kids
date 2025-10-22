@php
    $errors = collect($errors ?? [])->filter();
    $warnings = collect($warnings ?? [])->filter();
@endphp

@if ($errors->isNotEmpty())
    <div class="rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-900 dark:border-red-500/30 dark:bg-red-950/40 dark:text-red-200">
        <p class="font-semibold mb-1">Reikia pataisyti:</p>
        <ul class="list-disc ps-5 space-y-1">
            @foreach ($errors as $message)
                <li>{{ $message }}</li>
            @endforeach
        </ul>
    </div>
@endif

@if ($warnings->isNotEmpty())
    <div class="rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900 dark:border-amber-500/30 dark:bg-amber-950/40 dark:text-amber-200">
        <p class="font-semibold mb-1">Įspėjimai:</p>
        <ul class="list-disc ps-5 space-y-1">
            @foreach ($warnings as $message)
                <li>{{ $message }}</li>
            @endforeach
        </ul>
    </div>
@endif
