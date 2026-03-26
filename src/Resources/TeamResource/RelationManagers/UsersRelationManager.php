<?php

namespace Statikbe\FilamentVoight\Resources\TeamResource\RelationManagers;

use Filament\Actions\AttachAction;
use Filament\Actions\DetachAction;
use Filament\Actions\DetachBulkAction;
use Filament\Forms\Components\Select;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class UsersRelationManager extends RelationManager
{
    protected static string $relationship = 'users';

    protected static bool $hasInverseRelationship = false;

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return voightTrans('models.team.fields.users');
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(voightTrans('models.customer.fields.name'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('email')
                    ->label(voightTrans('models.team.fields.email'))
                    ->searchable()
                    ->sortable(),
            ])
            ->headerActions([
                AttachAction::make()
                    ->recordSelect(fn (Select $select) => $select
                        ->placeholder(voightTrans('models.team.fields.select_user'))
                        ->searchable()
                        ->getSearchResultsUsing(function (string $search): array {
                            $userModel = $this->getRelationship()->getRelated();

                            return $userModel::query()
                                ->where('name', 'like', "%{$search}%")
                                ->orWhere('email', 'like', "%{$search}%")
                                ->limit(50)
                                ->pluck('name', 'id')
                                ->toArray();
                        })
                        ->getOptionLabelUsing(fn (mixed $value): ?string => $this->getRelationship()->getRelated()::find($value)?->name)),
            ])
            ->recordActions([
                DetachAction::make(),
            ])
            ->toolbarActions([
                DetachBulkAction::make(),
            ]);
    }
}
