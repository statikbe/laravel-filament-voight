<?php

use Filament\Support\Enums\FontFamily;
use Filament\Support\Icons\Heroicon;
use Statikbe\FilamentVoight\Enums\Severity;
use Statikbe\FilamentVoight\Resources\Components\FindingColumns;

it('colours the cvss score by its severity and aligns it to the end', function () {
    $column = FindingColumns::cvss();

    expect($column->getName())->toBe('vulnerability.vulnerability_score')
        ->and($column->getAlignment()->value)->toBe('end')
        ->and($column->getColor(9.8))->toBe('critical')
        ->and($column->getColor(2.0))->toBe('success');
});

it('shows the severity as a badge with an icon', function () {
    $column = FindingColumns::severity();

    expect($column->isBadge())->toBeTrue()
        ->and($column->getIcon(Severity::Critical))->toBe(Heroicon::OutlinedFire);
});

it('renders versions in monospace and a missing fixed version as a dash', function () {
    expect(FindingColumns::installedVersion()->getFontFamily())->toBe(FontFamily::Mono)
        ->and(FindingColumns::fixedVersion()->getPlaceholder())->toBe('—');
});

it('gives the vulnerability id a copy button', function () {
    expect(FindingColumns::vulnerabilityId()->isCopyable('GHSA-x'))->toBeTrue();
});

it('truncates the summary to keep the findings tables narrow', function () {
    expect(FindingColumns::summary()->getCharacterLimit())->toBe(50);
});
