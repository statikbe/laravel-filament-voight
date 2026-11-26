<?php

namespace Statikbe\FilamentVoight\Resources\Components;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables\Contracts\HasTable;
use Illuminate\Contracts\Support\Htmlable;
use Statikbe\FilamentVoight\Enums\TagRuleStatus;
use Statikbe\FilamentVoight\Enums\VoightIcon;
use Statikbe\FilamentVoight\Facades\FilamentVoight;
use Statikbe\FilamentVoight\Models\Tag;
use Statikbe\FilamentVoight\Models\TagRule;
use Statikbe\FilamentVoight\Services\TagAssigner;
use Statikbe\FilamentVoight\Services\TagRuleRecorder;

/**
 * Saves the current filtered table as an auto-tag rule on a tag.
 */
class SaveTagRuleAction
{
    public static function make(): Action
    {
        return Action::make('saveTagRule')
            ->label(voightTrans('tags.rules.save_action'))
            ->icon(fn (): string | BackedEnum | Htmlable => VoightIcon::ActionSaveTagRule->resolve())
            ->color('gray')
            ->modalHeading(voightTrans('tags.rules.save_heading'))
            ->modalDescription(voightTrans('tags.rules.save_description'))
            ->visible(fn (HasTable & Page $livewire): bool => TagRuleRecorder::isFiltered($livewire))
            ->schema(fn (HasTable & Page $livewire): array => [
                Select::make('tag')
                    ->label(voightTrans('models.tag.label'))
                    ->options(TagOptions::grouped($livewire->getTable()->getModel()))
                    ->searchable()
                    ->required(),
                Toggle::make('replace')
                    ->label(fn (): string => voightTrans('tags.rules.replace', ['tag' => self::replaceableRule($livewire)?->tag->name]))
                    ->default(true)
                    ->visible(fn (): bool => self::replaceableRule($livewire) !== null),
            ])
            ->action(function (array $data, HasTable & Page $livewire, TagRuleRecorder $recorder): void {
                /** @var Tag $tag */
                $tag = FilamentVoight::config()->getTagModel()::query()->findOrFail($data['tag']);

                if (! TagAssigner::userMayTag(auth()->user(), $tag->tagType)) {
                    Notification::make()->danger()->title(voightTrans('tags.rules.not_allowed'))->send();

                    return;
                }

                $rule = $recorder->record($livewire, $tag, ($data['replace'] ?? false) ? self::replaceableRule($livewire) : null);

                $rule->status === TagRuleStatus::Failed
                    ? Notification::make()->danger()->title(voightTrans('tags.rules.failed'))->body($rule->last_error)->send()
                    : Notification::make()->success()->title(voightTrans('tags.rules.saved', ['count' => $rule->last_match_count ?? 0]))->send();
            });
    }

    /**
     * The rule this table was opened from ("Open in table"), when it was saved from this page and the user may manage it.
     */
    private static function replaceableRule(HasTable & Page $page): ?TagRule
    {
        $ruleId = property_exists($page, 'tagRule') ? $page->tagRule : null;

        if (blank($ruleId)) {
            return null;
        }

        $rule = FilamentVoight::config()->getTagRuleModel()::query()
            ->whereKey($ruleId)
            ->where('source_page', $page::class)
            ->first();

        // The id comes from the URL: only a rule on a tag type the user may manage can be replaced.
        return $rule !== null && TagAssigner::userMayTag(auth()->user(), $rule->tag->tagType) ? $rule : null;
    }
}
