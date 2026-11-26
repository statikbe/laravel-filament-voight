<?php

namespace Statikbe\FilamentVoight\Support;

use Illuminate\Contracts\Database\Query\Builder;

/**
 * Encodes a version into a key whose byte order is version order, so versions can be compared and
 * sorted in SQL. The key is four zero-padded numeric parts (`0000000001.0000000002.0000000003.0000000000`)
 * followed by `~` for a release, or `-` and the pre-release identifiers joined with `!`. A numeric
 * identifier is `0` plus its padded number, any other is `1` plus the identifier, so numbers sort
 * before words. Byte order: `!` < `-` < `.` < digits < letters < `~`.
 *
 * Versions that cannot be compared (`dev-main`, git locators, other URLs) have no key.
 */
final class VersionKey
{
    private const int PART_DIGITS = 10;

    private const int MAX_LENGTH = 191;

    private const string BRANCH_PART = '9999999999';

    /** Composer suffixes that sort after the release instead of before it. */
    private const array PATCH_IDENTIFIERS = ['p', 'pl', 'patch'];

    public static function for(string $version): ?string
    {
        $version = self::normalise($version);

        if ($version === null) {
            return null;
        }

        if (preg_match('/^(\d+)(?:\.(\d+))?(?:\.(\d+))?\.x-dev$/', $version, $matches) === 1) {
            $parts = array_values(array_filter(array_slice($matches, 1), fn (string $part): bool => $part !== ''));

            return self::key(array_pad($parts, 4, self::BRANCH_PART), ['dev']);
        }

        if (preg_match('/^(\d+(?:\.\d+){0,3})(?:-([0-9a-z][0-9a-z.-]*)|([a-z][0-9a-z.-]*))?$/', $version, $matches) !== 1) {
            return null;
        }

        $suffix = ($matches[2] ?? '') . ($matches[3] ?? '');

        return self::key(explode('.', $matches[1]), $suffix === '' ? [] : explode('.', $suffix));
    }

    /**
     * Order by a subquery selecting one key, with versions without a key (or no row) last in both directions.
     *
     * @template TBuilder of Builder
     *
     * @param  TBuilder  $query
     * @return TBuilder
     */
    public static function orderBy(Builder $query, Builder $key, string $direction): Builder
    {
        $sql = $key->toSql();
        $bindings = $key->getBindings();
        $direction = strtolower($direction) === 'desc' ? 'desc' : 'asc';

        return $query
            ->orderByRaw("({$sql}) is null", $bindings)
            ->orderByRaw("({$sql}) {$direction}", $bindings);
    }

    /**
     * The 43-character numeric part of a key: every key of that version, its pre-releases included,
     * sorts at or after it, and before the core of any higher version.
     */
    public static function core(string $version): ?string
    {
        $key = self::for($version);

        return $key === null ? null : substr($key, 0, 4 * self::PART_DIGITS + 3);
    }

    /**
     * A PHP version without its distro suffix (`8.3.12-1+ubuntu22.04.1` is `8.3.12`).
     */
    public static function forPhp(string $version): ?string
    {
        return self::for(explode('-', $version, 2)[0]);
    }

    /**
     * Lowercased, without a leading `v` or build metadata. A registry tarball URL becomes the version in
     * its file name; anything else with a colon (git locators, other URLs) is not a version.
     */
    private static function normalise(string $version): ?string
    {
        $version = strtolower(trim($version));

        if (str_contains($version, ':')) {
            if (preg_match('#^https?://.+/-/[^/]+?-(\d+\.\d+\.\d+(?:-[0-9a-z.-]+)?)\.tgz$#', $version, $matches) !== 1) {
                return null;
            }

            $version = $matches[1];
        }

        $version = explode('+', $version, 2)[0];

        if (str_starts_with($version, 'v')) {
            $version = substr($version, 1);
        }

        return $version === '' ? null : $version;
    }

    /**
     * @param  array<int, string>  $parts  1 to 4 numeric parts
     * @param  array<int, string>  $identifiers  pre-release or patch identifiers, empty for a release
     */
    private static function key(array $parts, array $identifiers): ?string
    {
        $core = [];

        foreach (array_pad($parts, 4, '0') as $part) {
            if (strlen($part) > self::PART_DIGITS) {
                return null;
            }

            $core[] = str_pad($part, self::PART_DIGITS, '0', STR_PAD_LEFT);
        }

        $identifiers = self::splitIdentifiers($identifiers);

        if ($identifiers === null) {
            return null;
        }

        $marker = $identifiers === [] || in_array($identifiers[0], self::PATCH_IDENTIFIERS, true) ? '~' : '-';
        $encoded = [];

        foreach ($identifiers as $identifier) {
            if (ctype_digit($identifier) && strlen($identifier) > self::PART_DIGITS) {
                return null;
            }

            $encoded[] = ctype_digit($identifier)
                ? '0' . str_pad($identifier, self::PART_DIGITS, '0', STR_PAD_LEFT)
                : '1' . $identifier;
        }

        $key = implode('.', $core) . $marker . implode('!', $encoded);

        return strlen($key) > self::MAX_LENGTH ? null : $key;
    }

    /**
     * Splits Composer's `rc10` into `rc` and `10`, so it orders like `rc.10`.
     *
     * @param  array<int, string>  $identifiers
     * @return array<int, string>|null null when an identifier is empty
     */
    private static function splitIdentifiers(array $identifiers): ?array
    {
        $split = [];

        foreach ($identifiers as $identifier) {
            if ($identifier === '') {
                return null;
            }

            if (preg_match('/^([a-z]+)(\d+)$/', $identifier, $matches) === 1) {
                array_push($split, $matches[1], $matches[2]);
            } else {
                $split[] = $identifier;
            }
        }

        return $split;
    }
}
