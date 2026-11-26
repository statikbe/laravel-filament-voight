<?php

use Illuminate\Database\Eloquent\Model;
use Statikbe\FilamentVoight\Models\Package;
use Statikbe\FilamentVoight\Models\Project;
use Statikbe\FilamentVoight\Models\Tag;
use Statikbe\FilamentVoight\Models\TagType;
use Statikbe\FilamentVoight\Services\TagAssigner;
use Statikbe\FilamentVoight\Services\TagAssignmentException;

beforeEach(function () {
    $this->assigner = app(TagAssigner::class);

    $this->level = TagType::factory()->single()->appliesTo(['voight-project'])->create(['name' => 'Security level']);
    $this->low = Tag::factory()->ofType($this->level)->create(['name' => 'Low']);
    $this->high = Tag::factory()->ofType($this->level)->create(['name' => 'High']);

    $this->labels = TagType::factory()->create(['name' => 'Labels']);
    $this->legacy = Tag::factory()->ofType($this->labels)->create(['name' => 'Legacy']);
    $this->flagship = Tag::factory()->ofType($this->labels)->create(['name' => 'Flagship']);
});

function tagIdsOf(Model $record): array
{
    return $record->fresh()->tags->pluck('id')->sort()->values()->all();
}

it('refuses a tag whose type does not apply to the model and writes nothing', function () {
    $package = Package::factory()->create();

    expect(fn () => $this->assigner->assign([$package], collect([$this->high])))
        ->toThrow(TagAssignmentException::class);

    expect(tagIdsOf($package))->toBe([]);
});

it('refuses two tags of a single-value type in one call', function () {
    $project = Project::factory()->create();

    try {
        $this->assigner->assign([$project], collect([$this->low, $this->high]));
        $this->fail('Expected a TagAssignmentException');
    } catch (TagAssignmentException $exception) {
        expect($exception->problems)->toHaveCount(1)
            ->and($exception->problems[0])->toContain('Security level');
    }

    expect(tagIdsOf($project))->toBe([]);
});

it('replaces the existing tag of a single-value type and keeps other types', function () {
    $project = Project::factory()->create();
    $project->attachTags([$this->low, $this->legacy]);

    $this->assigner->assign([$project], collect([$this->high]));

    expect(tagIdsOf($project))->toEqualCanonicalizing([$this->high->id, $this->legacy->id]);
});

it('adds tags of a multi-value type', function () {
    $project = Project::factory()->create();
    $project->attachTag($this->legacy);

    $this->assigner->assign([$project], collect([$this->flagship]));

    expect(tagIdsOf($project))->toEqualCanonicalizing([$this->legacy->id, $this->flagship->id]);
});

it('removes only the given tags', function () {
    $project = Project::factory()->create();
    $project->attachTags([$this->legacy, $this->flagship, $this->high]);

    $this->assigner->remove([$project], collect([$this->legacy]));

    expect(tagIdsOf($project))->toEqualCanonicalizing([$this->flagship->id, $this->high->id]);
});

it('writes nothing for a batch when one record is invalid', function () {
    $projects = Project::factory()->count(2)->create();
    $package = Package::factory()->create();

    expect(fn () => $this->assigner->assign([...$projects, $package], collect([$this->high])))
        ->toThrow(TagAssignmentException::class);

    foreach ($projects as $project) {
        expect(tagIdsOf($project))->toBe([]);
    }
});

it('syncs one type and leaves the other types alone', function () {
    $project = Project::factory()->create();
    $project->attachTags([$this->legacy, $this->low]);

    $this->assigner->sync($project, $this->labels, collect([$this->flagship]));

    expect(tagIdsOf($project))->toEqualCanonicalizing([$this->flagship->id, $this->low->id]);

    $this->assigner->sync($project, $this->level, collect());

    expect(tagIdsOf($project))->toBe([$this->flagship->id]);
});

it('refuses to sync a tag of another type', function () {
    $project = Project::factory()->create();

    expect(fn () => $this->assigner->sync($project, $this->labels, collect([$this->high])))
        ->toThrow(TagAssignmentException::class);
});

it('reports problems without writing', function () {
    $package = Package::factory()->create();

    expect($this->assigner->problems($package, collect([$this->high])))->toHaveCount(1)
        ->and($this->assigner->problems($package, collect([$this->legacy])))->toBe([]);
});
