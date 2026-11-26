<?php

use Filament\Support\Facades\FilamentIcon;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\HtmlString;
use Statikbe\FilamentVoight\Enums\VoightIcon;

/**
 * @return array<string, array<string, mixed>>
 */
function iconRowsByAlias(): array
{
    Artisan::call('voight:icons', ['--json' => true]);

    return collect(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR))->keyBy('alias')->all();
}

it('lists icons in a table', function () {
    expect(Artisan::call('voight:icons'))->toBe(0)
        ->and(Artisan::output())
        ->toContain('voight::severity.critical')
        ->toContain('heroicon-o-fire');
});

it('outputs json with every case and marks overrides', function () {
    FilamentIcon::register(['voight::severity.critical' => Heroicon::OutlinedBolt]);

    $rows = iconRowsByAlias();

    expect($rows)->toHaveCount(count(VoightIcon::cases()))
        ->and($rows['voight::severity.critical'])->toMatchArray([
            'case' => 'SeverityCritical',
            'alias' => 'voight::severity.critical',
            'default' => 'heroicon-o-fire',
            'current' => 'Heroicon::OutlinedBolt',
            'overridden' => true,
        ])
        ->and($rows['voight::severity.critical']['description'])->not->toBe('')
        ->and($rows['voight::severity.low']['overridden'])->toBeFalse();
});

it('renders Htmlable overrides as strings in json', function () {
    FilamentIcon::register(['voight::severity.critical' => new HtmlString('<svg/>')]);

    expect(iconRowsByAlias()['voight::severity.critical']['current'])->toBe('<svg/>');
});

it('shows enum defaults as pasteable enum references', function () {
    expect(iconRowsByAlias()['voight::navigation.projects']['default'])->toBe('Heroicon::OutlinedCodeBracket');
});
