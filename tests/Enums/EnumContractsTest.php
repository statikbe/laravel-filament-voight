<?php

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Statikbe\FilamentVoight\Enums\AlertChannel;
use Statikbe\FilamentVoight\Enums\AlertFrequency;
use Statikbe\FilamentVoight\Enums\AuditRunStatus;
use Statikbe\FilamentVoight\Enums\AuditRunTrigger;
use Statikbe\FilamentVoight\Enums\DependencySyncStatus;
use Statikbe\FilamentVoight\Enums\PackageType;
use Statikbe\FilamentVoight\Enums\Severity;
use Statikbe\FilamentVoight\Enums\VulnerabilitySource;

$enums = [
    AuditRunStatus::class,
    AuditRunTrigger::class,
    DependencySyncStatus::class,
    Severity::class,
    PackageType::class,
    VulnerabilitySource::class,
    AlertChannel::class,
    AlertFrequency::class,
];

it('implements the Filament label, color and icon contracts on every enum', function (string $enum) use ($enums) {
    expect($enums)->toContain($enum); // guard: keep list in sync

    foreach ($enum::cases() as $case) {
        expect($case)->toBeInstanceOf(HasLabel::class)
            ->toBeInstanceOf(HasColor::class)
            ->toBeInstanceOf(HasIcon::class);

        expect($case->getLabel())->toBeString()->not->toBe('');
        expect($case->getColor())->toBeString()->not->toBe('');
        expect($case->getIcon())->toBeString()->toStartWith('heroicon-');

        // Contract getters delegate to the internal helpers.
        expect($case->getColor())->toBe($case->color())
            ->and($case->getIcon())->toBe($case->icon());
    }
})->with($enums);
