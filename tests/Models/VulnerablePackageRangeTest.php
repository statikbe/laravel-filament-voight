<?php

use Statikbe\FilamentVoight\Models\VulnerablePackageRange;
use Statikbe\FilamentVoight\Support\VersionRange;

it('exposes the stored range as a version constraint', function () {
    $range = VulnerablePackageRange::factory()->make(['affected_range' => '>=0 <v10.48.29 || >=11.0.0']);

    expect($range->versionConstraint())->toBe('>=0 <v10.48.29 || >=11.0.0');
});

it('reads legacy comma separated ranges as alternatives', function () {
    $range = VulnerablePackageRange::factory()->make(['affected_range' => '>=0 <v10.48.29, >=11.0.0 <11.3.1']);

    expect($range->versionConstraint())->toBe('>=0 <v10.48.29 || >=11.0.0 <11.3.1')
        ->and(VersionRange::filterSatisfying(['v10.1.0', 'v10.48.29', 'v11.1.0'], $range->versionConstraint()))
        ->toBe(['v10.1.0', 'v11.1.0']);
});
