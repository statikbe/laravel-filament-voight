<?php

use Spatie\Tags\Tag;
use Statikbe\FilamentVoight\Models\Environment;
use Statikbe\FilamentVoight\Models\HostingTag;

it('stores hostings as spatie tags of type hosting and only sees those', function () {
    Tag::findOrCreate('Unrelated', 'other');
    $combell = HostingTag::factory()->create(['name' => ['en' => 'Combell']]);

    expect($combell->type)->toBe(HostingTag::TYPE)
        ->and(HostingTag::query()->pluck('id')->all())->toBe([$combell->id]);
});

it('replaces an environment\'s hostings, matching names case-insensitively', function () {
    $combell = HostingTag::factory()->create(['name' => ['en' => 'Combell']]);
    $environment = Environment::factory()->create();
    $environment->syncHostings(['Own server']);

    $created = $environment->syncHostings([' combell ', 'Shared server', 'COMBELL', '']);

    expect($created)->toBe(['Shared server'])
        ->and($environment->hostings->pluck('name')->sort()->values()->all())->toBe(['Combell', 'Shared server'])
        ->and($environment->tagsWithType(HostingTag::TYPE)->pluck('id')->contains($combell->id))->toBeTrue()
        ->and(HostingTag::query()->count())->toBe(3);
});

it('keeps its environments when a hosting is deleted', function () {
    $environment = Environment::factory()->create();
    $environment->syncHostings(['Combell']);

    expect(HostingTag::query()->sole()->environments->modelKeys())->toBe([$environment->getKey()]);

    HostingTag::query()->sole()->delete();

    expect($environment->fresh()->hostings)->toBeEmpty();
});
