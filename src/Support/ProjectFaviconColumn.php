<?php

namespace Statikbe\FilamentVoight\Support;

use Closure;
use Filament\Tables\Columns\ImageColumn;
use Illuminate\Database\Eloquent\Model;
use Statikbe\FilamentVoight\Facades\FilamentVoight;
use Statikbe\FilamentVoight\Models\Project;

final class ProjectFaviconColumn
{
    /**
     * @param  Closure(Model): Project  $resolveProject  Project whose favicon (or placeholder) the row shows.
     */
    public static function make(Closure $resolveProject): ImageColumn
    {
        return ImageColumn::make('favicon')
            ->label('')
            ->state(fn (Model $record): ?string => $resolveProject($record)->favicon_path)
            ->disk(FilamentVoight::config()->getFaviconsDisk())
            ->imageSize(24)
            ->extraImgAttributes(['class' => 'rounded object-contain', 'loading' => 'lazy'])
            ->defaultImageUrl(fn (Model $record): string => $resolveProject($record)->getFaviconPlaceholderUrl())
            ->grow(false);
    }
}
