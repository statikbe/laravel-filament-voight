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

it('implements the Filament contracts with exhaustive, well-formed color and icon maps', function (string $enum) {
    foreach ($enum::cases() as $case) {
        expect($case)
            ->toBeInstanceOf(HasLabel::class)
            ->toBeInstanceOf(HasColor::class)
            ->toBeInstanceOf(HasIcon::class);

        // Exercising every case guards against a non-exhaustive color()/icon()
        // match() (a missing arm throws UnhandledMatchError) and checks the
        // returned values are well-formed. Label copy comes from translations,
        // so it is not asserted here.
        expect($case->getColor())->toBeString()->not->toBe('');
        expect($case->getIcon())->toStartWith('heroicon-');
    }
})->with($enums);
