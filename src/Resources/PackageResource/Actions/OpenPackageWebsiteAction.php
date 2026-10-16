<?php

namespace Statikbe\FilamentVoight\Resources\PackageResource\Actions;

use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use Statikbe\FilamentVoight\Enums\PackageType;
use Statikbe\FilamentVoight\Models\Package;

class OpenPackageWebsiteAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'open_package_website';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label(voightTrans('models.package.actions.open_website'));
        $this->icon(Heroicon::OutlinedArrowTopRightOnSquare);
        $this->url(fn (Package $record): string => self::urlFor($record));
        $this->openUrlInNewTab();
    }

    public static function urlFor(Package $package): string
    {
        return match ($package->type) {
            PackageType::Composer => 'https://packagist.org/packages/' . $package->name,
            PackageType::Npm => 'https://www.npmjs.com/package/' . $package->name,
        };
    }
}
