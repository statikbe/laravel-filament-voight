<?php

namespace Statikbe\FilamentVoight\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Statikbe\FilamentVoight\Enums\TagRuleStatus;
use Statikbe\FilamentVoight\Facades\FilamentVoight;

/**
 * A filtered list table saved on a tag: every record its stored query matches carries the tag.
 *
 * @property string $id
 * @property string $tag_id
 * @property string $taggable_type Morph alias of the model the query returns.
 * @property class-string $source_page The list page it was saved from.
 * @property string|null $tab
 * @property array{filters?: array<string, mixed>|null, search?: string|null} $table_state
 * @property list<string> $description Filter indicator labels at save time.
 * @property string $query_sql Returns the keys of matching records.
 * @property list<mixed> $query_bindings
 * @property TagRuleStatus $status
 * @property string|null $last_error
 * @property Carbon|null $failed_at
 * @property Carbon|null $last_run_at
 * @property int|null $last_match_count
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Tag $tag
 */
class TagRule extends Model
{
    use HasFactory;
    use HasUlids;

    protected $table = 'voight_tag_rules';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'table_state' => 'array',
            'description' => 'array',
            'query_bindings' => 'array',
            'status' => TagRuleStatus::class,
            'failed_at' => 'datetime',
            'last_run_at' => 'datetime',
            'last_match_count' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Tag, $this>
     */
    public function tag(): BelongsTo
    {
        return $this->belongsTo(FilamentVoight::config()->getTagModel());
    }

    /**
     * @return BelongsTo<Model, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(FilamentVoight::config()->getUserModel(), 'created_by');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', TagRuleStatus::Active);
    }

    /**
     * Taggable rows of this rule's tag that a rule added (not set by hand) and that this rule matches.
     */
    public function automaticTaggablesQuery(): QueryBuilder
    {
        return DB::table('voight_taggables')
            ->where('tag_id', $this->tag_id)
            ->where('is_manual', false)
            ->whereExists(fn (QueryBuilder $match) => $match->from('voight_tag_rule_matches')
                ->where('tag_rule_id', $this->getKey())
                ->whereColumn('voight_tag_rule_matches.taggable_type', 'voight_taggables.taggable_type')
                ->whereColumn('voight_tag_rule_matches.taggable_id', 'voight_taggables.taggable_id'));
    }
}
