<?php

use App\Support\QualityCalculator;

it('produces excellent tier', function () {
    $result = (new QualityCalculator)->compute('ROOFTOP', 1.0, false, []);

    expect($result['confidence'])->toEqualWithDelta(0.965, 1e-6);
    expect($result['tier'])->toBe('EXCELLENT');
    expect($result['requires_verification'])->toBeFalse();
});

it('produces good tier', function () {
    $result = (new QualityCalculator)->compute('RANGE_INTERPOLATED', 0.90, false, []);

    expect($result['confidence'])->toEqualWithDelta(0.83, 1e-6);
    expect($result['tier'])->toBe('GOOD');
    expect($result['requires_verification'])->toBeFalse();
});

it('produces acceptable tier', function () {
    $result = (new QualityCalculator)->compute('GEOMETRIC_CENTER', 0.60, false, []);

    expect($result['confidence'])->toEqualWithDelta(0.6, 1e-6);
    expect($result['tier'])->toBe('ACCEPTABLE');
    expect($result['requires_verification'])->toBeFalse();
});

it('produces requires review tier', function () {
    $result = (new QualityCalculator)->compute('APPROXIMATE', 0.10, false, []);

    expect($result['confidence'])->toEqualWithDelta(0.31, 1e-6);
    expect($result['tier'])->toBe('REQUIRES_REVIEW');
    expect($result['requires_verification'])->toBeTrue();
});

it('applies penalty on manual override', function () {
    $result = (new QualityCalculator)->compute('ROOFTOP', 0.90, true, ['city']);

    expect($result['confidence'])->toEqualWithDelta(0.79475, 1e-6);
    expect($result['tier'])->toBe('GOOD');
    expect($result['requires_verification'])->toBeFalse();
});
