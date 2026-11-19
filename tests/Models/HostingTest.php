<?php

use Statikbe\FilamentVoight\Models\Environment;
use Statikbe\FilamentVoight\Models\Hosting;

it('reuses a hosting whose name differs only in case and surrounding whitespace', function () {
    $combell = Hosting::factory()->create(['name' => 'Combell']);

    expect(Hosting::findOrCreateByName(' combell ')->is($combell))->toBeTrue()
        ->and(Hosting::query()->count())->toBe(1);
});

it('creates a hosting with a trimmed name when none matches', function () {
    $hosting = Hosting::findOrCreateByName(' Level27 ');

    expect($hosting->wasRecentlyCreated)->toBeTrue()
        ->and($hosting->name)->toBe('Level27');
});

it('keeps its environments without a hosting when it is deleted', function () {
    $hosting = Hosting::factory()->create();
    $environment = Environment::factory()->for($hosting)->create();

    expect($hosting->environments->modelKeys())->toBe([$environment->getKey()]);

    $hosting->delete();

    expect($environment->fresh()->hosting_id)->toBeNull();
});
