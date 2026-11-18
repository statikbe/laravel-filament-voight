<?php

namespace Statikbe\FilamentVoight\Support;

use InvalidArgumentException;

/**
 * A version filter of one or more comparisons that must all hold, such as `<4.17.21` or `>=7.0 <7.4`.
 * Versions cannot be ordered in SQL, so callers fetch the candidate versions and match them here with
 * PHP's version_compare (numeric parts, pre-releases before the release). A leading `v` is ignored.
 */
final readonly class VersionConstraint
{
    private const string OPERATOR = '<=|>=|==|<|>|=';

    /**
     * @param  array<int, array{0: string, 1: string}>  $comparisons  [operator, normalized version] pairs
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
     */
    public static function between(?array $lower, ?array $upper): self
    {
        return new self(array_map(
            fn (array $bound): array => self::comparison($bound[0], $bound[1]),
            array_filter([$lower, $upper]),
        ));
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
     * @return array{0: string, 1: string}
     */
    private static function comparison(string $operator, string $version): array
    {
        return [$operator === '=' ? '==' : $operator, self::normalize($version)];
    }

    private static function normalize(string $version): string
    {
        return ltrim($version, 'vV');
    }
}
