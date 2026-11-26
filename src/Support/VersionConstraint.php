<?php

namespace Statikbe\FilamentVoight\Support;

use Illuminate\Contracts\Database\Query\Builder;
use InvalidArgumentException;

/**
 * A version filter of one or more comparisons that must all hold, such as `<4.17.21` or `>=7.0 <7.4`.
 * apply() compares a VersionKey column in SQL, so a bound must be a version VersionKey can encode.
 * A leading `v` is ignored.
 */
final readonly class VersionConstraint
{
    private const string OPERATOR = '<=|>=|==|<|>|=';

    /**
     * @param  array<int, array{0: string, 1: string, 2: string}>  $comparisons  [operator, normalized version, version key]
     */
    private function __construct(private array $comparisons) {}

    /**
     * Comparisons separated by spaces or commas; a bare version means equality.
     *
     * @throws InvalidArgumentException
     */
    public static function parse(string $constraint): self
    {
        $comparison = '(?:' . self::OPERATOR . ')?\s*v?\d[^\s,]*';

        if (preg_match("/^\\s*{$comparison}(?:[\\s,]+{$comparison})*\\s*$/i", $constraint) !== 1) {
            throw new InvalidArgumentException("Invalid version filter `{$constraint}`.");
        }

        preg_match_all('/(' . self::OPERATOR . ')?\s*(v?\d[^\s,]*)/i', $constraint, $matches, PREG_SET_ORDER);

        return new self(array_map(
            fn (array $match): array => self::comparison($match[1] === '' ? '=' : $match[1], $match[2]),
            $matches,
        ));
    }

    /**
     * A range from an optional lower and upper bound, each an [operator, version] pair.
     *
     * @param  array{0: string, 1: string}|null  $lower
     * @param  array{0: string, 1: string}|null  $upper
     *
     * @throws InvalidArgumentException when a bound cannot be compared
     */
    public static function between(?array $lower, ?array $upper): self
    {
        return new self(array_map(
            fn (array $bound): array => self::comparison($bound[0], $bound[1]),
            array_filter([$lower, $upper]),
        ));
    }

    /**
     * Restrict the query to rows whose version key column satisfies every comparison. Rows without a
     * key (versions that cannot be compared) never match.
     *
     * @template TBuilder of Builder
     *
     * @param  TBuilder  $query
     * @return TBuilder
     */
    public function apply(Builder $query, string $column): Builder
    {
        foreach ($this->comparisons as [$operator, , $key]) {
            $query->where($column, $operator === '==' ? '=' : $operator, $key);
        }

        return $query;
    }

    public function matches(string $version): bool
    {
        $version = self::normalize($version);

        foreach ($this->comparisons as [$operator, $bound]) {
            if (! version_compare($version, $bound, $operator)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array{0: string, 1: string, 2: string}
     *
     * @throws InvalidArgumentException when the version cannot be compared
     */
    private static function comparison(string $operator, string $version): array
    {
        $key = VersionKey::for($version) ?? throw new InvalidArgumentException("Invalid version `{$version}`.");

        return [$operator === '=' ? '==' : $operator, self::normalize($version), $key];
    }

    private static function normalize(string $version): string
    {
        return ltrim($version, 'vV');
    }
}
