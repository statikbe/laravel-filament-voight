<?php

namespace Statikbe\FilamentVoight\Resources\TagTypeResource\RelationManagers;

use BackedEnum;
use Closure;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Statikbe\FilamentVoight\Enums\VoightIcon;
use Statikbe\FilamentVoight\Models\Tag;
use Statikbe\FilamentVoight\Models\TagType;

class TagsRelationManager extends RelationManager
{
    protected static string $relationship = 'tags';

    public static function getIcon(Model $ownerRecord, string $pageClass): string | BackedEnum | Htmlable | null
    {
        return VoightIcon::TabTagTypeTags->resolve();
    }

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return voightTrans('models.tag.plural');
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->label(voightTrans('models.tag.fields.name'))
                ->required()
                ->maxLength(255)
                ->dehydrateStateUsing(fn (string $state): string => trim($state))
                ->rule(fn (?Tag $record): Closure => $this->uniqueNameRule($record)),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->addSelect([
                'usage_count' => DB::table('voight_taggables')
                    ->selectRaw('count(*)')
                    ->whereColumn('voight_taggables.tag_id', 'voight_tags.id'),
            ]))
            ->reorderable('order_column')
            ->defaultSort('order_column')
            ->columns([
                TextColumn::make('name')
                    ->label(voightTrans('models.tag.fields.name')),
                TextColumn::make('usage_count')
                    ->label(voightTrans('models.tag.fields.usage_count')),
            ])
            ->headerActions([
                CreateAction::make(),
            ])
            ->recordActions([
                EditAction::make()
                    ->mutateRecordDataUsing(fn (array $data, Tag $record): array => [...$data, 'name' => $record->name]),
                DeleteAction::make()
                    ->requiresConfirmation()
                    ->modalDescription(fn (Tag $record): string => voightTrans('models.tag.delete_description', [
                        'count' => DB::table('voight_taggables')->where('tag_id', $record->getKey())->count(),
                    ])),
            ]);
    }

    /**
     * A type cannot hold the same tag name twice, ignoring case and surrounding spaces.
     */
    private function uniqueNameRule(?Tag $record): Closure
    {
        /** @var TagType $type */
        $type = $this->getOwnerRecord();

        return function (string $attribute, mixed $value, Closure $fail) use ($type, $record): void {
            $name = mb_strtolower(trim((string) $value));

            $taken = $type->tags()->get()
                ->reject(fn (Tag $tag): bool => $record !== null && $tag->is($record))
                ->contains(fn (Tag $tag): bool => mb_strtolower(trim($tag->name)) === $name);

            if ($taken) {
                $fail(voightTrans('tags.validation.duplicate_name'));
            }
        };
    }
}
