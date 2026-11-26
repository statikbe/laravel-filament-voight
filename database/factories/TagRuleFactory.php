<?php

namespace Statikbe\FilamentVoight\Database\Factories;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Statikbe\FilamentVoight\Enums\TagRuleStatus;
use Statikbe\FilamentVoight\Models\Project;
use Statikbe\FilamentVoight\Models\Tag;
use Statikbe\FilamentVoight\Models\TagRule;
use Statikbe\FilamentVoight\Resources\ProjectResource\Pages\ListProjects;

/**
 * @extends Factory<TagRule>
 */
class TagRuleFactory extends Factory
{
    protected $model = TagRule::class;

    public function definition(): array
    {
        $query = Project::query()->select('voight_projects.id');

        return [
            'tag_id' => Tag::factory(),
            'taggable_type' => 'voight-project',
            'source_page' => ListProjects::class,
            'tab' => null,
            'table_state' => ['filters' => [], 'search' => null],
            'description' => ['All projects'],
            'query_sql' => $query->toSql(),
            'query_bindings' => $query->getBindings(),
            'status' => TagRuleStatus::Active,
        ];
    }

    /**
     * A rule whose stored query is the given key query.
     *
     * @param  Builder<Model>  $query  Selects the keys of matching records.
     */
    public function matching(Builder $query, string $taggableType = 'voight-project'): static
    {
        return $this->state([
            'taggable_type' => $taggableType,
            'query_sql' => $query->toSql(),
            'query_bindings' => $query->getBindings(),
        ]);
    }
}
