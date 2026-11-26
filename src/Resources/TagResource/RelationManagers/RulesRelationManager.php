<?php

namespace Statikbe\FilamentVoight\Resources\TagResource\RelationManagers;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Statikbe\FilamentVoight\Enums\TagRuleStatus;
use Statikbe\FilamentVoight\Enums\VoightIcon;
use Statikbe\FilamentVoight\Models\TagRule;
use Statikbe\FilamentVoight\Models\TagType;
use Statikbe\FilamentVoight\Services\TagRuleRunner;

class RulesRelationManager extends RelationManager
{
    protected static string $relationship = 'rules';

    public static function getIcon(Model $ownerRecord, string $pageClass): string | BackedEnum | Htmlable | null
    {
        return VoightIcon::TabTagRules->resolve();
    }

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return voightTrans('tags.rules.plural');
    }

    /**
     * The list page the rule came from, with its filter, search and tab, and the rule so saving there replaces it.
     */
    public static function openInTableUrl(TagRule $rule): string
    {
        return $rule->source_page::getUrl(array_filter([
            'filters' => $rule->table_state['filters'] ?? null,
            'search' => $rule->table_state['search'] ?? null,
            'tab' => $rule->tab,
            'tag_rule' => $rule->getKey(),
        ], filled(...)));
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->defaultSort('created_at')
            ->columns([
                TextColumn::make('description')
                    ->label(voightTrans('tags.rules.fields.conditions'))
                    ->badge()
                    ->color('gray')
                    ->wrap(),
                TextColumn::make('taggable_type')
                    ->label(voightTrans('tags.rules.fields.table'))
                    ->formatStateUsing(fn (string $state): string => TagType::modelLabel($state)),
                TextColumn::make('status')
                    ->label(voightTrans('tags.rules.fields.status'))
                    ->badge()
                    ->description(fn (TagRule $record): ?string => $record->status === TagRuleStatus::Failed ? str($record->last_error)->limit(120)->toString() : null),
                TextColumn::make('last_match_count')
                    ->label(voightTrans('tags.rules.fields.matches'))
                    ->placeholder('—'),
                TextColumn::make('last_run_at')
                    ->label(voightTrans('tags.rules.fields.last_run'))
                    ->since()
                    ->placeholder('—'),
                TextColumn::make('creator.name')
                    ->label(voightTrans('tags.rules.fields.created_by'))
                    ->placeholder('—'),
            ])
            ->recordActions([
                Action::make('openInTable')
                    ->label(voightTrans('tags.rules.actions.open_in_table'))
                    ->icon(fn (): string | BackedEnum | Htmlable => VoightIcon::ActionOpenTagRuleInTable->resolve())
                    ->url(fn (TagRule $record): string => self::openInTableUrl($record)),
                Action::make('pause')
                    ->label(voightTrans('tags.rules.actions.pause'))
                    ->icon(fn (): string | BackedEnum | Htmlable => VoightIcon::ActionPauseTagRule->resolve())
                    ->color('gray')
                    ->visible(fn (TagRule $record): bool => $record->status === TagRuleStatus::Active)
                    ->action(fn (TagRule $record) => $record->update(['status' => TagRuleStatus::Paused])),
                Action::make('resume')
                    ->label(voightTrans('tags.rules.actions.resume'))
                    ->icon(fn (): string | BackedEnum | Htmlable => VoightIcon::ActionResumeTagRule->resolve())
                    ->visible(fn (TagRule $record): bool => $record->status === TagRuleStatus::Paused)
                    ->action(fn (TagRule $record, TagRuleRunner $runner) => $runner->run($record)),
                Action::make('retry')
                    ->label(voightTrans('tags.rules.actions.retry'))
                    ->icon(fn (): string | BackedEnum | Htmlable => VoightIcon::ActionRetryTagRule->resolve())
                    ->visible(fn (TagRule $record): bool => $record->status === TagRuleStatus::Failed)
                    ->action(fn (TagRule $record, TagRuleRunner $runner) => $runner->run($record)),
                Action::make('deleteRule')
                    ->label(voightTrans('tags.rules.actions.delete'))
                    ->icon(fn (): string | BackedEnum | Htmlable => VoightIcon::ActionDeleteTagRule->resolve())
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription(fn (TagRule $record): string => voightTrans('tags.rules.delete_description', ['count' => self::automaticCount($record)]))
                    ->schema([
                        Checkbox::make('remove_tags')
                            ->label(voightTrans('tags.rules.remove_tags'))
                            ->default(true),
                    ])
                    ->action(fn (TagRule $record, array $data, TagRuleRunner $runner) => $runner->removeRule($record, (bool) ($data['remove_tags'] ?? true))),
            ]);
    }

    /**
     * Records that carry the tag only because a rule added it and that this rule matches.
     */
    private static function automaticCount(TagRule $rule): int
    {
        return DB::table('voight_taggables')
            ->where('tag_id', $rule->tag_id)
            ->where('is_manual', false)
            ->whereExists(fn ($match) => $match->from('voight_tag_rule_matches')
                ->where('tag_rule_id', $rule->getKey())
                ->whereColumn('voight_tag_rule_matches.taggable_type', 'voight_taggables.taggable_type')
                ->whereColumn('voight_tag_rule_matches.taggable_id', 'voight_taggables.taggable_id'))
            ->count();
    }
}
