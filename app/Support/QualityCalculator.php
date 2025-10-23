<?php

namespace App\Support;

final class QualityCalculator
{
    private const BASE_SCORES = [
        'ROOFTOP' => 0.95,
        'RANGE_INTERPOLATED' => 0.80,
        'GEOMETRIC_CENTER' => 0.60,
        'APPROXIMATE' => 0.40,
        'UNKNOWN' => 0.50,
    ];

    public function compute(string $accuracy, float $providerConf, bool $manuallyOverridden, array $overriddenFields): array
    {
        $base = self::BASE_SCORES[strtoupper($accuracy)] ?? self::BASE_SCORES['UNKNOWN'];
        $provider = min(1.0, max(0.0, $providerConf));

        $score = ($base * 0.7) + ($provider * 0.3);

        if ($manuallyOverridden && $this->hasPenaltyFields($overriddenFields)) {
            $score *= 0.85;
        }

        $score = min(1.0, max(0.0, $score));

        $tier = match (true) {
            $score >= 0.90 => 'EXCELLENT',
            $score >= 0.75 => 'GOOD',
            $score >= 0.60 => 'ACCEPTABLE',
            default => 'REQUIRES_REVIEW',
        };

        return [
            'confidence' => $score,
            'tier' => $tier,
            'requires_verification' => $tier === 'REQUIRES_REVIEW',
        ];
    }

    private function hasPenaltyFields(array $overriddenFields): bool
    {
        foreach ($overriddenFields as $field) {
            if (in_array($field, ['city', 'postal_code', 'country_code'], true)) {
                return true;
            }
        }

        return false;
    }
}
