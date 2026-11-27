<?php

use Illuminate\Support\Facades\DB;
use Statikbe\FilamentVoight\Enums\TagRuleStatus;
use Statikbe\FilamentVoight\Models\Project;
use Statikbe\FilamentVoight\Models\Tag;
use Statikbe\FilamentVoight\Models\TagRule;

it('casts its status to the enum', function () {
    expect(TagRule::factory()->create()->status)->toBe(TagRuleStatus::Active);
});

it('is deleted with its tag, matches included', function () {
    $rule = TagRule::factory()->create();
    DB::table('voight_tag_rule_matches')->insert(['tag_rule_id' => $rule->id, 'taggable_type' => 'voight-project', 'taggable_id' => '1']);

    $rule->tag->delete();

    expect(TagRule::query()->count())->toBe(0)
        ->and(DB::table('voight_tag_rule_matches')->count())->toBe(0);
});

it('marks tags attached by hand as manual', function () {
    $project = Project::factory()->create();
    $project->attachTag(Tag::factory()->create());

    expect((bool) DB::table('voight_taggables')->value('is_manual'))->toBeTrue();
});
