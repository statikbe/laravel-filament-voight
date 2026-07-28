<?php

namespace Statikbe\FilamentVoight\Resources\AuditRunResource\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class AuditRunInfolistSchema
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()
                ->columns(3)
                ->schema([
                    TextEntry::make('environment.project.name')
                        ->label(voightTrans('models.audit_run.fields.project')),
                    TextEntry::make('environment.name')
                        ->label(voightTrans('models.audit_run.fields.environment')),
                    TextEntry::make('trigger')
                        ->label(voightTrans('models.audit_run.fields.trigger'))
                        ->badge(),
                    TextEntry::make('status')
                        ->label(voightTrans('models.audit_run.fields.status'))
                        ->badge(),
                    TextEntry::make('started_at')
                        ->label(voightTrans('models.audit_run.fields.started_at'))
                        ->dateTime(),
                    TextEntry::make('completed_at')
                        ->label(voightTrans('models.audit_run.fields.completed_at'))
                        ->dateTime()
                        ->placeholder('—'),
                    TextEntry::make('formatted_duration')
                        ->label(voightTrans('models.audit_run.fields.duration'))
                        ->placeholder('—'),
                ]),
        ]);
    }
}
