<?php

namespace Statikbe\FilamentVoight\Resources\TagTypeResource\Schemas;

use Closure;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;
use Statikbe\FilamentVoight\Models\TagType;

class TagTypeFormSchema
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make([
                    TextInput::make('name')
                        ->label(voightTrans('models.tag_type.fields.name'))
                        ->required()
                        ->maxLength(255)
                        ->rule(fn (?TagType $record): Closure => self::keyRule($record)),
                    TextInput::make('key')
                        ->label(voightTrans('models.tag_type.fields.key'))
                        ->helperText(voightTrans('models.tag_type.fields.key_help'))
                        ->disabled()
                        ->dehydrated(false)
                        ->visibleOn('edit'),
                    Textarea::make('description')
                        ->label(voightTrans('models.tag_type.fields.description'))
                        ->helperText(voightTrans('models.tag_type.fields.description_help'))
                        ->rows(3),
                    Toggle::make('is_single')
                        ->label(voightTrans('models.tag_type.fields.is_single'))
                        ->helperText(voightTrans('models.tag_type.fields.is_single_help'))
                        ->rule(fn (?TagType $record): Closure => self::singleValueRule($record)),
                    CheckboxList::make('taggable_types')
                        ->label(voightTrans('models.tag_type.fields.taggable_types'))
                        ->helperText(voightTrans('models.tag_type.fields.taggable_types_help'))
                        ->options(fn (): array => self::modelOptions())
                        ->columns(3)
                        ->dehydrateStateUsing(fn (?array $state): ?array => filled($state) ? array_values($state) : null)
                        ->rule(fn (?TagType $record): Closure => self::taggableTypesRule($record)),
                ]),
            ]);
    }

    /**
     * @return array<string, string>
     */
    public static function modelOptions(): array
    {
        return collect(TagType::taggableModels())
            ->map(fn (string $model, string $alias): string => TagType::modelLabel($alias))
            ->all();
    }

    /**
     * On create, the name must give a usable key that no other type has; the key never changes afterwards.
     */
    private static function keyRule(?TagType $record): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($record): void {
            if ($record !== null) {
                return;
            }

            $key = Str::slug((string) $value);

            if ($key === '') {
                $fail(voightTrans('tags.validation.key_empty'));
            } elseif (TagType::query()->where('key', $key)->exists()) {
                $fail(voightTrans('tags.validation.key_taken', ['key' => $key]));
            }
        };
    }

    /**
     * Refuses to stop applying to a model while records of it still carry tags of the type.
     */
    private static function taggableTypesRule(?TagType $record): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($record): void {
            if ($record === null || blank($value)) {
                return;
            }

            $stillAssigned = collect($record->assignmentCountsByModel())
                ->except($value)
                ->map(fn (int $count, string $alias): string => TagType::modelLabel($alias) . ': ' . $count);

            if ($stillAssigned->isNotEmpty()) {
                $fail(voightTrans('tags.validation.still_assigned', ['models' => $stillAssigned->implode(', ')]));
            }
        };
    }

    /**
     * Refuses single-value while a record holds several tags of the type.
     */
    private static function singleValueRule(?TagType $record): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($record): void {
            if ($record === null || ! $value || $record->is_single) {
                return;
            }

            $count = $record->recordsWithSeveralTagsCount();

            if ($count > 0) {
                $fail(voightTrans('tags.validation.several_tags', ['count' => $count]));
            }
        };
    }
}
