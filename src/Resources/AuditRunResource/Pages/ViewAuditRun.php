<?php

namespace Statikbe\FilamentVoight\Resources\AuditRunResource\Pages;

use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use Statikbe\FilamentVoight\FilamentVoightPlugin;
use Statikbe\FilamentVoight\Models\AuditRun;
use Statikbe\FilamentVoight\Resources\AuditRunResource;
use Statikbe\FilamentVoight\Resources\AuditRunResource\Schemas\AuditRunInfolistSchema;

class ViewAuditRun extends ViewRecord
{
    protected static string $resource = AuditRunResource::class;

    public function infolist(Schema $schema): Schema
    {
        return AuditRunInfolistSchema::configure($schema);
    }

    public function getTitle(): string | Htmlable
    {
        $run = $this->getRun();

        return voightTrans('models.audit_run.view.title', [
            'environment' => $run->environment->name,
            'started' => $run->started_at?->format(FilamentVoightPlugin::DATE_TIME_FORMAT) ?? '—',
        ]);
    }

    public function getSubheading(): ?string
    {
        $environment = $this->getRun()->environment;

        return ($environment->project->name ?: $environment->project->project_code) . ' · ' . $environment->name;
    }

    private function getRun(): AuditRun
    {
        /** @var AuditRun $run */
        $run = $this->getRecord();

        return $run->loadMissing('environment.project');
    }
}
