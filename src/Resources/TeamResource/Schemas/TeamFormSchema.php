<?php

namespace Statikbe\FilamentVoight\Resources\TeamResource\Schemas;

use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Statikbe\FilamentVoight\Facades\FilamentVoight;

class TeamFormSchema
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make([
                    FileUpload::make('avatar_path')
                        ->label(voightTrans('models.team.fields.avatar'))
                        ->avatar()
                        ->automaticallyResizeImagesToWidth('256')
                        ->automaticallyResizeImagesToHeight('256')
                        ->maxSize(2048)
                        ->disk(FilamentVoight::config()->getTeamAvatarDisk())
                        ->directory('voight/team-avatars')
                        ->visibility('public'),
                    TextInput::make('name')
                        ->label(voightTrans('models.team.fields.name'))
                        ->required()
                        ->maxLength(255),
                ]),
            ]);
    }
}
