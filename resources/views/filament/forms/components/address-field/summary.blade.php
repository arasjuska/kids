@php
    use App\Enums\AddressTypeEnum;

    $manual = $manual ?? [];
    $coords = $coordinates ?? [];
    $street = trim((string) ($manual['street_name'] ?? ''));
    $number = trim((string) ($manual['street_number'] ?? ''));
    $city = trim((string) ($manual['city'] ?? ''));
    $postal = trim((string) ($manual['postal_code'] ?? ''));
    $formatted = trim((string) ($manual['formatted_address'] ?? ''));
    $lat = $coords['latitude'] ?? null;
    $lng = $coords['longitude'] ?? null;

    $summary = null;
    if ($street && $city) {
        $summary = $street.($number ? ' '.$number : '').', '.$city;
        if ($postal) {
            $summary .= " ({$postal})";
        }
    } elseif ($formatted !== '') {
        $summary = $formatted;
    } elseif (is_numeric($lat) && is_numeric($lng)) {
        $summary = sprintf('Koordinatės: %.6f, %.6f', (float) $lat, (float) $lng);
    } else {
        $summary = 'Adresas dar nepasirinktas';
    }

    $type = AddressTypeEnum::tryFrom($addressType ?? '') ?? AddressTypeEnum::UNVERIFIED;
    $badgeClasses = [
        AddressTypeEnum::VERIFIED->value => 'bg-emerald-50 text-emerald-700 border-emerald-200',
        AddressTypeEnum::LOW_CONFIDENCE->value => 'bg-amber-50 text-amber-800 border-amber-200',
        AddressTypeEnum::VIRTUAL->value => 'bg-gray-100 text-gray-700 border-gray-200',
        AddressTypeEnum::UNVERIFIED->value => 'bg-blue-50 text-blue-700 border-blue-200',
    ];
    $badgeClass = $badgeClasses[$type->value] ?? $badgeClasses[AddressTypeEnum::UNVERIFIED->value];
@endphp

<div class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-gray-200 bg-white px-4 py-3 text-sm shadow-sm dark:border-gray-800 dark:bg-gray-900">
    <p class="min-w-0 flex-1 truncate font-semibold text-gray-900 dark:text-gray-100" title="{{ $summary }}">
        {{ $summary }}
    </p>
    <span class="inline-flex shrink-0 items-center rounded-full border px-3 py-1 text-xs font-semibold uppercase tracking-wide {{ $badgeClass }}">
        {{ $type->label() }}
    </span>
</div>
