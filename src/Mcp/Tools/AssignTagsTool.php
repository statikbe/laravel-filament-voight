<?php

namespace Statikbe\FilamentVoight\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Statikbe\FilamentVoight\FilamentVoightConfig;
use Statikbe\FilamentVoight\Mcp\McpAccess;
use Statikbe\FilamentVoight\Mcp\McpToolError;
use Statikbe\FilamentVoight\Models\Tag;
use Statikbe\FilamentVoight\Services\Mcp\TagReferences;
use Statikbe\FilamentVoight\Services\TagAssigner;

class AssignTagsTool extends VoightTool
{
    public const int MAX_ROWS = 500;

    public const array MODELS = ['project', 'environment', 'customer', 'package', 'vulnerability'];

    protected string $name = 'assign_tags';

    protected string $title = 'Assign tags';

    protected string $description = <<<'MARKDOWN'
        Adds (`action: add`, default) or removes (`action: remove`) existing tags on records of one model, e.g. to sync
        a classification kept in a spreadsheet. Call `list_tags` first for the tag type keys and tag names.

        Each row names one record by its natural key and the tags to add or remove:
        - project: `{project_code}`
        - environment: `{project_code, environment}`
        - customer: `{slug}` or `{name}`
        - package: `{type, name}` (type `composer` or `npm`; may be omitted when the name is unambiguous)
        - vulnerability: `{source_id}`, plus `source` when the id is ambiguous

        On a single-value tag type, adding a tag replaces the record's current tag of that type.

        The whole call is checked first: if any row has an unknown tag type or tag, a type that does not apply to the
        model, two tags of a single-value type, or a record that is not found or that you may not change, nothing
        is written and the error lists every problem by row index. Tags cannot be created here: report missing tag
        types and tags to an admin, who creates them on the Tag types screen.
    MARKDOWN;

    public function shouldRegister(FilamentVoightConfig $config): bool
    {
        return $config->mcpWriteToolsEnabled();
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'model' => $schema->string()->enum(self::MODELS)->required(),
            'action' => $schema->string()->enum(['add', 'remove'])->default('add'),
            'rows' => $schema->array()->max(self::MAX_ROWS)->required()->items($schema->object([
                'record' => $schema->object()->required()->description('The record\'s natural key, see the tool description.'),
                'tags' => TagReferences::schema($schema)->required()->description(TagReferences::DESCRIPTION),
            ])),
        ];
    }

    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'rows' => $schema->array()->items($schema->object([
                'row' => $schema->integer(),
                'record' => $schema->string(),
                'added' => $schema->integer(),
                'replaced' => $schema->integer()->description('Single-value tags swapped for another tag of the same type.'),
                'removed' => $schema->integer(),
                'unchanged' => $schema->integer()->description('Tags the record already had (add) or did not have (remove).'),
            ]))->required(),
        ];
    }

    protected function run(Request $request, McpAccess $access): Response | ResponseFactory
    {
        $request->validate([
            'model' => ['required', Rule::in(self::MODELS)],
            'action' => ['nullable', Rule::in(['add', 'remove'])],
            'rows' => ['required', 'array', 'min:1', 'max:' . self::MAX_ROWS],
            'rows.*.record' => ['required', 'array'],
            'rows.*.tags' => ['required', 'array', 'min:1'],
            'rows.*.tags.*.type' => ['required', 'string'],
            'rows.*.tags.*.tag' => ['required', 'string'],
        ]);

        $model = $request->get('model');
        $adding = $request->get('action', 'add') === 'add';
        $assigner = app(TagAssigner::class);

        $resolved = [];
        $problems = [];

        foreach ($request->get('rows') as $index => $row) {
            ['tags' => $tags, 'problems' => $tagProblems] = TagReferences::resolve($row['tags']);
            $record = $this->findRecord($access, $model, $row['record']);
            $rowProblems = $tagProblems;

            if ($record === null) {
                $rowProblems[] = "{$model} \"" . $this->describeKey($model, $row['record']) . '" not found.';
            } elseif (! $access->mayTag($record)) {
                $rowProblems[] = "not allowed to tag {$model} \"" . $this->describeKey($model, $row['record']) . '".';
            } elseif ($adding && $tagProblems === []) {
                $rowProblems = $assigner->problems($record, $tags);
            }

            foreach ($rowProblems as $problem) {
                $problems[] = "Row {$index}: {$problem}";
            }

            $resolved[$index] = [$record, $tags];
        }

        if ($problems !== []) {
            throw new McpToolError(implode("\n", $problems) . "\nNothing was changed. Ask an admin to create missing tag types or tags.");
        }

        $results = DB::transaction(fn (): array => collect($resolved)
            ->map(fn (array $pair, int $index): array => [
                'row' => $index,
                'record' => $this->describeKey($model, $request->get('rows')[$index]['record']),
                ...$this->apply($assigner, $pair[0], $pair[1], $adding),
            ])
            ->values()
            ->all());

        return Response::structured(['rows' => $results]);
    }

    /**
     * @param  Collection<int, Tag>  $tags
     * @return array{added: int, replaced: int, removed: int, unchanged: int}
     */
    private function apply(TagAssigner $assigner, Model $record, Collection $tags, bool $adding): array
    {
        $before = $this->tagIdsByType($record);

        if ($adding) {
            $assigner->assign([$record], $tags);
        } else {
            $assigner->remove([$record], $tags);
        }

        $after = $this->tagIdsByType($record);
        $counts = ['added' => 0, 'replaced' => 0, 'removed' => 0, 'unchanged' => 0];

        foreach ($tags as $tag) {
            $had = in_array($tag->getKey(), $before[$tag->type] ?? [], true);
            $has = in_array($tag->getKey(), $after[$tag->type] ?? [], true);

            if ($had === $has) {
                $counts['unchanged']++;
            } elseif ($has) {
                $replaced = array_diff($before[$tag->type] ?? [], $after[$tag->type]) !== [];
                $counts[$replaced ? 'replaced' : 'added']++;
            } else {
                $counts['removed']++;
            }
        }

        return $counts;
    }

    /**
     * @return array<string, list<string>>
     */
    private function tagIdsByType(Model $record): array
    {
        /** @var Collection<int, Tag> $tags */
        $tags = $record->tags()->get(); // @phpstan-ignore method.notFound

        return $tags->groupBy('type')
            ->map(fn (Collection $typeTags): array => $typeTags->map(fn (Tag $tag): string => (string) $tag->getKey())->values()->all())
            ->all();
    }

    /**
     * @param  array<string, mixed>  $key
     */
    private function findRecord(McpAccess $access, string $model, array $key): ?Model
    {
        $string = fn (string $field): ?string => is_string($key[$field] ?? null) ? trim($key[$field]) : null;

        $query = match ($model) {
            'project' => $string('project_code') === null ? null : $access->visibleProjects()->where('project_code', $string('project_code')),
            'environment' => $string('project_code') === null || $string('environment') === null ? null : $access->visibleEnvironments()
                ->where('name', $string('environment'))
                ->whereHas('project', fn (Builder $project) => $project->where('project_code', $string('project_code'))),
            'customer' => match (true) {
                $string('slug') !== null => $access->visibleCustomers()->where('slug', $string('slug')),
                $string('name') !== null => $access->visibleCustomers()->where('name', $string('name')),
                default => null,
            },
            'package' => $string('name') === null ? null : $access->visiblePackages()
                ->where('name', $string('name'))
                ->when($string('type'), fn (Builder $query, string $type) => $query->where('type', $type)),
            'vulnerability' => $string('source_id') === null ? null : $access->visibleVulnerabilities()
                ->where('source_id', $string('source_id'))
                ->when($string('source'), fn (Builder $query, string $source) => $query->where('source', $source)),
            default => null,
        };

        $matches = $query?->limit(2)->get();

        // An ambiguous key (e.g. a package name used by both composer and npm) matches nothing.
        return $matches !== null && $matches->count() === 1 ? $matches->first() : null;
    }

    /**
     * @param  array<string, mixed>  $key
     */
    private function describeKey(string $model, array $key): string
    {
        $fields = match ($model) {
            'project' => ['project_code'],
            'environment' => ['project_code', 'environment'],
            'customer' => ['slug', 'name'],
            'package' => ['type', 'name'],
            'vulnerability' => ['source', 'source_id'],
            default => [],
        };

        return collect($fields)
            ->map(fn (string $field): mixed => $key[$field] ?? null)
            ->filter(fn (mixed $value): bool => is_string($value) && $value !== '')
            ->implode(' ');
    }
}
