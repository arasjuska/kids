<div class="flex flex-wrap items-center gap-2 text-xs">
    <span class="inline-flex items-center rounded bg-gray-100 px-2 py-0.5 text-gray-800 dark:bg-gray-800 dark:text-gray-200">
        State: <span class="ml-1 font-semibold">{{ $currentState }}</span>
    </span>
    <span class="inline-flex items-center rounded bg-gray-100 px-2 py-0.5 text-gray-800 dark:bg-gray-800 dark:text-gray-200">
        Mode: <span class="ml-1 font-semibold">{{ $inputMode }}</span>
    </span>
    <span class="inline-flex items-center rounded bg-gray-100 px-2 py-0.5 text-gray-800 dark:bg-gray-800 dark:text-gray-200">
        Suggestions: <span class="ml-1 font-semibold">{{ $suggestionsCount }}</span>
    </span>
    @if(!empty($query))
        <span class="inline-flex items-center truncate rounded bg-blue-50 px-2 py-0.5 text-blue-700 dark:bg-blue-900/40 dark:text-blue-200" title="{{ $query }}">
            Query: <span class="ml-1 max-w-[280px] truncate font-medium">{{ $query }}</span>
        </span>
    @endif
</div>
