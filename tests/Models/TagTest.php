<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Statikbe\FilamentVoight\Models\Customer;
use Statikbe\FilamentVoight\Models\Environment;
use Statikbe\FilamentVoight\Models\Package;
use Statikbe\FilamentVoight\Models\Project;
use Statikbe\FilamentVoight\Models\Tag;
use Statikbe\FilamentVoight\Models\TagType;
use Statikbe\FilamentVoight\Models\Vulnerability;

it('stores assignments in voight_taggables with the morph alias', function () {
    $tag = Tag::factory()->create();
    $project = Project::factory()->create();

    $project->attachTag($tag);

    $row = DB::table('voight_taggables')->first();

    expect($row->taggable_type)->toBe('voight-project')
        ->and((string) $row->taggable_id)->toBe((string) $project->getKey())
        ->and($project->fresh()->tags->pluck('id')->all())->toBe([$tag->id])
        ->and(Schema::hasTable('taggables'))->toBeFalse();
});

it('tags models with ulid keys', function () {
    $tag = Tag::factory()->create();
    $package = Package::factory()->create();

    $package->attachTag($tag);

    expect($package->fresh()->tags->pluck('id')->all())->toBe([$tag->id]);
});

it('derives an immutable key from the name', function () {
    $type = TagType::factory()->create(['name' => 'Security level']);

    expect($type->key)->toBe('security-level');

    $type->update(['name' => 'Risk level']);

    expect($type->fresh()->key)->toBe('security-level');

    expect(fn () => $type->update(['key' => 'risk-level']))->toThrow(LogicException::class);
});

it('applies to listed models or to all when none are listed', function () {
    $projectsOnly = TagType::factory()->create(['taggable_types' => ['voight-project']]);
    $everywhere = TagType::factory()->create(['taggable_types' => null]);

    expect($projectsOnly->appliesTo(Project::class))->toBeTrue()
        ->and($projectsOnly->appliesTo(new Package))->toBeFalse()
        ->and($projectsOnly->appliesTo('voight-project'))->toBeTrue();

    foreach ([Project::class, Environment::class, Customer::class, Package::class, Vulnerability::class] as $model) {
        expect($everywhere->appliesTo($model))->toBeTrue();
    }
});

it('lists the tag types that apply to a model', function () {
    $projectsOnly = TagType::factory()->create(['taggable_types' => ['voight-project']]);
    $packagesOnly = TagType::factory()->create(['taggable_types' => ['voight-package']]);
    $everywhere = TagType::factory()->create(['taggable_types' => null]);

    expect((new Project)->voightTagTypes()->pluck('id')->all())
        ->toEqualCanonicalizing([$projectsOnly->id, $everywhere->id]);
});

it('cascades a type delete to its tags and assignments', function () {
    $tag = Tag::factory()->create();
    Project::factory()->create()->attachTag($tag);

    $tag->tagType->delete();

    expect(Tag::query()->count())->toBe(0)
        ->and(DB::table('voight_taggables')->count())->toBe(0);
});

it('rejects a tag whose type does not exist', function () {
    expect(fn () => DB::table('voight_tags')->insert([
        'id' => (string) str()->ulid(),
        'name' => json_encode(['en' => 'Orphan']),
        'slug' => json_encode(['en' => 'orphan']),
        'type' => 'does-not-exist',
    ]))->toThrow(QueryException::class);
});
