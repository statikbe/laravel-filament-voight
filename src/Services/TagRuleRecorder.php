<?php

namespace Statikbe\FilamentVoight\Services;

use Filament\Pages\Page;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Indicator;
use Illuminate\Contracts\Support\Htmlable;
use Statikbe\FilamentVoight\Enums\TagRuleStatus;
use Statikbe\FilamentVoight\Facades\FilamentVoight;
use Statikbe\FilamentVoight\Jobs\RunTagRulesJob;
use Statikbe\FilamentVoight\Models\Tag;
use Statikbe\FilamentVoight\Models\TagRule;

/**
 * Turns a filtered list table into a stored auto-tag rule: the table's query as SQL, plus what is needed to show
 * and reopen it.
 */
class TagRuleRecorder
{
    public function __construct(private readonly TagRuleRunner $runner) {}

    /**
     * Saves the rule (replacing $replacing when given) and runs it once.
     */
    public function record(HasTable & Page $page, Tag $tag, ?TagRule $replacing = null): TagRule
    {
        $query = $page->getFilteredTableQuery();
        $model = $query->getModel();
        $keyQuery = (clone $query)->reorder()->select($model->getQualifiedKeyName());

        $attributes = [
            'tag_id' => $tag->getKey(),
            'taggable_type' => $model->getMorphClass(),
            'source_page' => $page::class,
            'tab' => self::activeTab($page),
            'table_state' => [
                'filters' => $page->tableFilters ?? [],
                'search' => self::search($page),
            ],
            'description' => self::describe($page),
            'query_sql' => $keyQuery->toSql(),
            'query_bindings' => $keyQuery->getBindings(),
            'status' => TagRuleStatus::Active,
            'last_error' => null,
            'failed_at' => null,
        ];

        if ($replacing !== null) {
            $replacing->update($attributes);
            $rule = $replacing->refresh();
        } else {
            $rule = FilamentVoight::config()->getTagRuleModel()::query()->create([...$attributes, 'created_by' => auth()->id()]);
        }

        $this->runner->run($rule);

        // Settles any tag the replaced rule used to provide and rules that depend on this one.
        RunTagRulesJob::dispatchIfRulesExist();

        return $rule->refresh();
    }

    /**
     * Whether the table is narrowed at all: saving an unfiltered table would tag every record.
     */
    public static function isFiltered(HasTable & Page $page): bool
    {
        return $page->getTable()->getFilterIndicators() !== []
            || self::search($page) !== null
            || self::activeTab($page) !== null;
    }

    /**
     * The active tab when it differs from the page's default.
     */
    private static function activeTab(HasTable & Page $page): ?string
    {
        if (! property_exists($page, 'activeTab') || ! method_exists($page, 'getDefaultActiveTab')) {
            return null;
        }

        $tab = $page->activeTab;

        return filled($tab) && (string) $tab !== (string) $page->getDefaultActiveTab() ? (string) $tab : null;
    }

    /**
     * The filter indicators shown above the table, as plain text.
     *
     * @return list<string>
     */
    private static function describe(HasTable & Page $page): array
    {
        $labels = collect($page->getTable()->getFilterIndicators())
            ->map(fn (Indicator $indicator): string => self::plain($indicator->getLabel()));

        $tab = self::activeTab($page);

        if ($tab !== null && method_exists($page, 'getTabs') && isset($page->getTabs()[$tab])) {
            $labels->prepend(voightTrans('tags.rules.tab', ['tab' => self::plain($page->getTabs()[$tab]->getLabel())]));
        }

        if (($search = self::search($page)) !== null) {
            $labels->push(voightTrans('tags.rules.search', ['search' => $search]));
        }

        return $labels->values()->all();
    }

    private static function search(HasTable & Page $page): ?string
    {
        $search = method_exists($page, 'getTableSearch') ? $page->getTableSearch() : null;

        return filled($search) ? (string) $search : null;
    }

    private static function plain(string | Htmlable | null $label): string
    {
        return trim(strip_tags($label instanceof Htmlable ? $label->toHtml() : (string) $label));
    }
}
