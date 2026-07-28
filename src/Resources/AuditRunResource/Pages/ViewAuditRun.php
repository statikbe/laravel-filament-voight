<?php

namespace Statikbe\FilamentVoight\Resources\AuditRunResource\Pages;

use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Schema;
use Statikbe\FilamentVoight\Resources\AuditRunResource;
use Statikbe\FilamentVoight\Resources\AuditRunResource\Schemas\AuditRunInfolistSchema;

class ViewAuditRun extends ViewRecord
{
    protected static string $resource = AuditRunResource::class;

    public function infolist(Schema $schema): Schema
    {
        return AuditRunInfolistSchema::configure($schema);
    }
}
