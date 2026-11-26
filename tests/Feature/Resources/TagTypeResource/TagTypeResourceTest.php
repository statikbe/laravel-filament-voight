<?php

use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Statikbe\FilamentVoight\Models\Package;
use Statikbe\FilamentVoight\Models\Project;
use Statikbe\FilamentVoight\Models\Tag;
use Statikbe\FilamentVoight\Models\TagType;
use Statikbe\FilamentVoight\Resources\TagTypeResource;
use Statikbe\FilamentVoight\Resources\TagTypeResource\Pages\CreateTagType;
use Statikbe\FilamentVoight\Resources\TagTypeResource\Pages\EditTagType;
use Statikbe\FilamentVoight\Resources\TagTypeResource\RelationManagers\TagsRelationManager;

beforeEach(function () {
    $this->actingAs(new User);
});

it('lives in the settings navigation group as tags and tag types', function () {
    expect(TagTypeResource::getNavigationGroup())->toBe(voightTrans('navigation.settings'))
        ->and(TagTypeResource::getNavigationLabel())->toBe('Tags & tag types');
});

it('creates a tag type with a key derived from its name', function () {
    Livewire::test(CreateTagType::class)
        ->fillForm([
            'name' => 'Security level',
            'description' => 'How sensitive the project is.',
            'is_single' => true,
            'taggable_types' => ['voight-project'],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $type = TagType::query()->sole();

    expect($type->key)->toBe('security-level')
        ->and($type->is_single)->toBeTrue()
        ->and($type->taggable_types)->toBe(['voight-project']);
});

it('stores no models as applying to all', function () {
    Livewire::test(CreateTagType::class)
        ->fillForm(['name' => 'Labels', 'taggable_types' => []])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(TagType::query()->sole()->taggable_types)->toBeNull();
});

it('creates tags inside a tag type', function () {
    $type = TagType::factory()->create();

    Livewire::test(TagsRelationManager::class, ['ownerRecord' => $type, 'pageClass' => EditTagType::class])
        ->callAction(TestAction::make('create')->table(), ['name' => 'High'])
        ->assertHasNoFormErrors();

    expect($type->tags()->sole()->name)->toBe('High');
});

it('rejects a tag name that already exists in the type, ignoring case', function () {
    $type = TagType::factory()->create();
    Tag::factory()->ofType($type)->create(['name' => 'High']);

    Livewire::test(TagsRelationManager::class, ['ownerRecord' => $type, 'pageClass' => EditTagType::class])
        ->callAction(TestAction::make('create')->table(), ['name' => ' high '])
        ->assertHasFormErrors(['name']);

    expect($type->tags()->count())->toBe(1);
});

it('refuses to stop applying to a model that still carries tags of the type', function () {
    $type = TagType::factory()->appliesTo(['voight-project', 'voight-package'])->create();
    $tag = Tag::factory()->ofType($type)->create();
    Package::factory()->create()->attachTag($tag);

    Livewire::test(EditTagType::class, ['record' => $type->getRouteKey()])
        ->fillForm(['taggable_types' => ['voight-project']])
        ->call('save')
        ->assertHasFormErrors(['taggable_types']);

    expect($type->fresh()->taggable_types)->toBe(['voight-project', 'voight-package']);
});

it('refuses to make a type single-value while a record holds several of its tags', function () {
    $type = TagType::factory()->create();
    $project = Project::factory()->create();
    $project->attachTags(Tag::factory()->ofType($type)->count(2)->create());

    Livewire::test(EditTagType::class, ['record' => $type->getRouteKey()])
        ->fillForm(['is_single' => true])
        ->call('save')
        ->assertHasFormErrors(['is_single']);

    expect($type->fresh()->is_single)->toBeFalse();
});

it('removes tags and assignments when a type is deleted', function () {
    $type = TagType::factory()->create();
    Project::factory()->create()->attachTag(Tag::factory()->ofType($type)->create());

    Livewire::test(EditTagType::class, ['record' => $type->getRouteKey()])
        ->callAction('delete');

    expect(TagType::query()->count())->toBe(0)
        ->and(DB::table('voight_taggables')->count())->toBe(0);
});

it('fills the edit form with the translated name and the fixed key', function () {
    $type = TagType::factory()->create(['name' => 'Security level', 'description' => 'How sensitive.']);

    Livewire::test(EditTagType::class, ['record' => $type->getRouteKey()])
        ->assertSchemaStateSet([
            'name' => 'Security level',
            'description' => 'How sensitive.',
            'key' => 'security-level',
        ]);
});

it('fills and saves a tag name in the edit action as plain text', function () {
    $type = TagType::factory()->create();
    $tag = Tag::factory()->ofType($type)->create(['name' => 'High']);

    Livewire::test(TagsRelationManager::class, ['ownerRecord' => $type, 'pageClass' => EditTagType::class])
        ->mountAction(TestAction::make('edit')->table($tag))
        ->assertSchemaStateSet(['name' => 'High'])
        ->setActionData(['name' => 'Very high'])
        ->callMountedAction()
        ->assertHasNoFormErrors();

    expect($tag->fresh()->name)->toBe('Very high');
});

it('refuses a tag type name whose key is already taken', function () {
    TagType::factory()->create(['name' => 'Security level']);

    Livewire::test(CreateTagType::class)
        ->fillForm(['name' => 'Security Level'])
        ->call('create')
        ->assertHasFormErrors(['name']);

    expect(TagType::query()->count())->toBe(1);
});

it('refuses a tag type name that yields no key', function () {
    Livewire::test(CreateTagType::class)
        ->fillForm(['name' => '安全'])
        ->call('create')
        ->assertHasFormErrors(['name']);

    expect(TagType::query()->count())->toBe(0);
});
