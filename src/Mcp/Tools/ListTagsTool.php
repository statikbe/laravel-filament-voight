<?php

namespace Statikbe\FilamentVoight\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Statikbe\FilamentVoight\Facades\FilamentVoight;
use Statikbe\FilamentVoight\Mcp\McpAccess;
use Statikbe\FilamentVoight\Models\Tag;
use Statikbe\FilamentVoight\Models\TagType;

#[IsReadOnly]
#[IsIdempotent]
class ListTagsTool extends VoightTool
{
    protected string $name = 'list_tags';

    protected string $title = 'List tags';

    protected string $description = <<<'MARKDOWN'
        Lists the tag types admins defined (e.g. "Security level") with their tags and how many records you can see
        carry each. Use it before filtering with `tags` or assigning with `assign_tags`: refer to a tag as
        `{type: <type key>, tag: <tag name or slug>}`.

        `applies_to` names the models a type can be assigned to (project, environment, customer, package,
        vulnerability); `applies_to_all` is true when it is not restricted. A `single` type holds one tag per record: assigning another replaces it.
        `description` explains what the classification means; use it to map values from other sources.
        Tags cannot be created over MCP: when a value has no tag yet, ask an admin to add it.
    MARKDOWN;

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'tag_types' => $schema->array()->items($schema->object([
                'key' => $schema->string(),
                'name' => $schema->string(),
                'description' => $schema->string()->nullable(),
                'single' => $schema->boolean(),
                'applies_to' => $schema->array()->items($schema->string())->description('Model names the type can be assigned to.'),
                'applies_to_all' => $schema->boolean()->description('true when the type is not restricted to some models.'),
                'tags' => $schema->array()->items($schema->object([
                    'name' => $schema->string(),
                    'slug' => $schema->string(),
                    'usage_count' => $schema->integer()->description('Records you can see that carry the tag.'),
                    'auto_usage_count' => $schema->integer()->description('Of those, records that carry it only because an auto-tag rule matches them.'),
                    'rule_count' => $schema->integer()->description('Auto-tag rules that keep this tag on matching records (saved from panel tables).'),
                ])),
            ]))->required(),
        ];
    }

    protected function run(Request $request, McpAccess $access): Response | ResponseFactory
    {
        $access->authorize('viewAny', FilamentVoight::config()->getProjectModel());

        $types = FilamentVoight::config()->getTagTypeModel()::query()->ordered()->with(['tags' => fn ($tags) => $tags->withCount('rules')])->get();
        $usage = $this->usageCounts($access);
        $automaticUsage = $this->usageCounts($access, automaticOnly: true);

        return Response::structured([
            'tag_types' => $types->map(fn (TagType $type): array => [
                'key' => $type->key,
                'name' => $type->name,
                'description' => $type->description,
                'single' => $type->is_single,
                'applies_to' => array_map(self::modelName(...), blank($type->taggable_types) ? array_keys(TagType::taggableModels()) : $type->taggable_types),
                'applies_to_all' => blank($type->taggable_types),
                'tags' => $type->tags->map(fn (Tag $tag): array => [
                    'name' => $tag->name,
                    'slug' => $tag->slug,
                    'usage_count' => $usage[$tag->getKey()] ?? 0,
                    'auto_usage_count' => $automaticUsage[$tag->getKey()] ?? 0,
                    'rule_count' => (int) $tag->getAttribute('rules_count'),
                ])->values()->all(),
            ])->values()->all(),
        ]);
    }

    public static function modelName(string $morphAlias): string
    {
        return str($morphAlias)->after('voight-')->toString();
    }

    /**
     * Assignments per tag, counting only records the caller can see.
     *
     * @return array<string, int>
     */
    private function usageCounts(McpAccess $access, bool $automaticOnly = false): array
    {
        $query = DB::table('voight_taggables')
            ->when($automaticOnly, fn (QueryBuilder $query) => $query->where('is_manual', false))
            ->groupBy('tag_id')
            ->selectRaw('tag_id, count(*) as aggregate');

        if (! $access->seesEverything) {
            $query->where(function (QueryBuilder $query) use ($access): void {
                foreach (array_keys(TagType::taggableModels()) as $alias) {
                    $visible = $access->visibleTaggables($alias);

                    $query->orWhere(fn (QueryBuilder $query) => $query
                        ->where('taggable_type', $alias)
                        ->whereIn('taggable_id', $visible->select($visible->getModel()->getQualifiedKeyName())));
                }
            });
        }

        return $query->pluck('aggregate', 'tag_id')->map(fn (mixed $count): int => (int) $count)->all();
    }
}
