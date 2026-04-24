<?php

namespace Statikbe\FilamentVoight\Resources\PackageResource\Pages;

use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Schema;
use Statikbe\FilamentVoight\Resources\PackageResource;
use Statikbe\FilamentVoight\Resources\PackageResource\Schemas\PackageInfolistSchema;

class ViewPackage extends ViewRecord
{
    protected static string $resource = PackageResource::class;

    public function infolist(Schema $schema): Schema
    {
        return PackageInfolistSchema::configure($schema);
    }
}
