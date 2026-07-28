<?php

namespace Statikbe\FilamentVoight\Resources\AuditRunResource\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\IconPosition;
use Filament\Support\Icons\Heroicon;
use Statikbe\FilamentVoight\Models\AuditRun;
use Statikbe\FilamentVoight\Resources\ProjectResource;

class AuditRunInfolistSchema
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()
                ->columns(3)
                ->schema([
                    TextEntry::make('environment.project.name')
                        ->label(voightTrans('models.audit_run.fields.project'))
                        ->url(fn (AuditRun $record): string => ProjectResource::getUrl(
                            'view',
                            ['record' => $record->environment->project],
                        ))
                        ->color('primary')
                        ->icon(Heroicon::OutlinedArrowRight)
                        ->iconPosition(IconPosition::After)
                        ->extraAttributes(['class' => 'underline']),
                    // Environments have no resource of their own, so this links to the
                    // project page that lists them rather than to the environment itself.
                    TextEntry::make('environment.name')
                        ->label(voightTrans('models.audit_run.fields.environment'))
                        ->url(fn (AuditRun $record): string => ProjectResource::getUrl(
                            'view',
                            ['record' => $record->environment->project],
                        ))
                        ->color('primary')
                        ->icon(Heroicon::OutlinedArrowRight)
                        ->iconPosition(IconPosition::After)
                        ->extraAttributes(['class' => 'underline']),
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
