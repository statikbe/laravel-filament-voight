<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Statikbe\FilamentVoight\Models\TagRule;
use Statikbe\FilamentVoight\Models\TagType;
use Statikbe\FilamentVoight\Services\TagRuleFilterConverter;

function storedFilters(string $name, array $settings, string $operator = 'is'): array
{
    return ['queryBuilder' => ['rules' => queryBuilderRule($name, $operator, $settings)]];
}

function onlyRule(?array $filters): array
{
    return array_values($filters['queryBuilder']['rules'])[0];
}

it('rewrites every older shape to the single tag filter', function (string $name, array $settings, string $expected) {
    $types = ['security-level', 'level', 'owner'];

    $rule = onlyRule(TagRuleFilterConverter::convert(storedFilters($name, $settings, 'is.inverse'), 'project', $types));

    expect($rule['type'])->toBe('tag')
        ->and($rule['data']['operator'])->toBe('is.inverse')
        ->and($rule['data']['settings'])->toBe([...$settings, 'type' => $expected]);
})->with([
    'own type' => ['tag_security_level', ['values' => ['1']], 'project:security-level'],
    'related type' => ['tag_customer_owner', ['values' => ['1']], 'customer:owner'],
    'first step own' => ['tag', ['type' => 'owner', 'values' => ['1']], 'project:owner'],
    'first step related' => ['tag_customer', ['type' => 'owner', 'values' => ['1']], 'customer:owner'],
]);

it('converts rules inside or blocks and leaves converted and other rules alone', function () {
    $filters = ['queryBuilder' => ['rules' => [
        'a' => ['type' => 'or', 'data' => ['groups' => ['g' => ['rules' => queryBuilderRule('tag_level', 'is', ['values' => ['1']])]]]],
        'b' => ['type' => 'tag', 'data' => ['operator' => 'is', 'settings' => ['type' => 'project:level', 'values' => ['1']]]],
        'c' => ['type' => 'name', 'data' => ['operator' => 'contains', 'settings' => ['text' => 'x']]],
    ]]];

    $converted = TagRuleFilterConverter::convert($filters, 'project', ['level']);

    $inner = $converted['queryBuilder']['rules']['a']['data']['groups']['g']['rules'];

    expect(array_values($inner)[0]['type'])->toBe('tag')
        ->and(array_values($inner)[0]['data']['settings']['type'])->toBe('project:level')
        ->and($converted['queryBuilder']['rules']['b'])->toBe($filters['queryBuilder']['rules']['b'])
        ->and($converted['queryBuilder']['rules']['c'])->toBe($filters['queryBuilder']['rules']['c']);
});

it('leaves ambiguous and unknown names untouched and reports them', function () {
    // `tag_project_level` is the own type "project-level" or the project scope's type "level".
    $filters = storedFilters('tag_project_level', ['values' => ['1']]);
    $unmapped = [];

    $gone = storedFilters('tag_gone', ['values' => []]);

    expect(TagRuleFilterConverter::convert($filters, 'customer', ['project-level', 'level'], $unmapped))->toBe($filters)
        ->and(TagRuleFilterConverter::convert($gone, 'customer', ['level'], $unmapped))->toBe($gone)
        ->and($unmapped)->toBe(['tag_project_level', 'tag_gone']);
});

it('rewrites the stored rules and warns about the ones it cannot map', function () {
    TagType::factory()->create(['name' => 'Level']);
    Log::spy();

    $store = fn (array $filters): string => TagRule::factory()->create(['table_state' => ['filters' => $filters, 'search' => 'x']])->id;
    $old = $store(storedFilters('tag_level', ['values' => ['1']]));
    $broken = $store(storedFilters('tag_gone', ['values' => ['1']]));

    expect(app(TagRuleFilterConverter::class)->convertAll())->toBe(1);

    $state = fn (string $id): array => json_decode(DB::table('voight_tag_rules')->where('id', $id)->value('table_state'), true);

    expect(onlyRule($state($old)['filters'])['data']['settings']['type'])->toBe('project:level')
        ->and($state($old)['search'])->toBe('x')
        ->and(onlyRule($state($broken)['filters'])['type'])->toBe('tag_gone');

    Log::shouldHaveReceived('warning')->once();
});
