<?php

namespace Statikbe\FilamentVoight\Resources\ProjectResource\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ProjectFormSchema
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(voightTrans('models.project.sections.general'))
                    ->components([
                        TextInput::make('project_code')
                            ->label(voightTrans('models.project.fields.project_code'))
                            ->required()
                            ->maxLength(255)
                            ->unique(ignoreRecord: true),
                        TextInput::make('name')
                            ->label(voightTrans('models.project.fields.name'))
                            ->required()
                            ->maxLength(255),
                        Textarea::make('description')
                            ->label(voightTrans('models.project.fields.description'))
                            ->rows(3),
                        TextInput::make('repo_url')
                            ->label(voightTrans('models.project.fields.repo_url'))
                            ->required()
                            ->url()
                            ->maxLength(255),
                    ]),
                Section::make(voightTrans('models.project.sections.assignment'))
                    ->components([
                        Select::make('customer_id')
                            ->label(voightTrans('models.project.fields.customer'))
                            ->relationship('customer', 'name')
                            ->searchable()
                            ->preload()
                            ->required(),
                        Select::make('team_id')
                            ->label(voightTrans('models.project.fields.team'))
                            ->relationship('team', 'name')
                            ->searchable()
                            ->preload()
                            ->required(),
                    ]),
                Section::make(voightTrans('models.project.sections.settings'))
                    ->components([
                        Toggle::make('is_muted')
                            ->label(voightTrans('models.project.fields.is_muted'))
                            ->helperText(voightTrans('models.project.fields.is_muted_help')),
                    ]),
            ]);
    }
}
