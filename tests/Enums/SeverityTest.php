<?php

use Statikbe\FilamentVoight\Enums\Severity;

it('returns none for score 0.0', function () {
    expect(Severity::fromScore(0.0))->toBe(Severity::None);
});

it('returns low for scores 0.1 to 3.9', function () {
    expect(Severity::fromScore(0.1))->toBe(Severity::Low);
    expect(Severity::fromScore(2.0))->toBe(Severity::Low);
    expect(Severity::fromScore(3.9))->toBe(Severity::Low);
});

it('returns medium for scores 4.0 to 6.9', function () {
    expect(Severity::fromScore(4.0))->toBe(Severity::Medium);
    expect(Severity::fromScore(5.5))->toBe(Severity::Medium);
    expect(Severity::fromScore(6.9))->toBe(Severity::Medium);
});

it('returns high for scores 7.0 to 8.9', function () {
    expect(Severity::fromScore(7.0))->toBe(Severity::High);
    expect(Severity::fromScore(8.0))->toBe(Severity::High);
    expect(Severity::fromScore(8.9))->toBe(Severity::High);
});

it('returns critical for scores 9.0 to 10.0', function () {
    expect(Severity::fromScore(9.0))->toBe(Severity::Critical);
    expect(Severity::fromScore(9.5))->toBe(Severity::Critical);
    expect(Severity::fromScore(10.0))->toBe(Severity::Critical);
});

it('keeps scoreRange boundaries consistent with fromScore bucketing', function (Severity $case) {
    [$min, $max] = $case->scoreRange();

    expect(Severity::fromScore($min))->toBe($case)
        ->and(Severity::fromScore($max))->toBe($case);
})->with(Severity::cases());
