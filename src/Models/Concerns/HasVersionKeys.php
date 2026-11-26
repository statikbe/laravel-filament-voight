<?php

namespace Statikbe\FilamentVoight\Models\Concerns;

use Illuminate\Database\Eloquent\Model;
use Statikbe\FilamentVoight\Support\VersionKey;

/**
 * Keeps the sortable key of each version column current, so version filters and sorts can run in SQL.
 * Custom model classes configured in place of the default ones must use this trait too.
 *
 * @mixin Model
 */
trait HasVersionKeys
{
    /**
     * @return array<string, string> version column => key column
     */
    abstract protected function versionKeyColumns(): array;

    public static function bootHasVersionKeys(): void
    {
        static::saving(fn (self $model) => $model->syncVersionKeys());
    }

    protected function syncVersionKeys(): void
    {
        foreach ($this->versionKeyColumns() as $source => $key) {
            if ($this->isDirty($source) || $this->getAttribute($key) === null) {
                $value = $this->getAttribute($source);
                $this->setAttribute($key, is_string($value) ? $this->versionKey($value) : null);
            }
        }
    }

    protected function versionKey(string $version): ?string
    {
        return VersionKey::for($version);
    }
}
