<?php

namespace Statikbe\FilamentVoight\Parsers;

use Statikbe\FilamentVoight\Enums\DependencyKind;
use Statikbe\FilamentVoight\Enums\PackageType;

/**
 * Turns one lockfile into installed packages plus the edges between them.
 *
 * `key` is unique within one parsed lockfile, and every dependency key points to
 * a package in the same result: requirements that cannot be resolved are dropped.
 *
 * @phpstan-type ParsedDependency array{key: string, constraint: string|null, kind: DependencyKind}
 * @phpstan-type ParsedPackage array{key: string, name: string, version: string, type: PackageType, is_direct: bool, is_dev: bool, dependencies: array<int, ParsedDependency>}
 */
interface LockfileParser
{
    /**
     * @param  string  $content  raw lockfile contents
     * @param  string|null  $manifestContent  raw composer.json / package.json next to the lockfile, when uploaded
     * @return array<int, ParsedPackage>
     *
     * @throws UnsupportedLockfileException for a format the parser recognises but cannot read
     */
    public function parse(string $content, ?string $manifestContent = null): array;
}
