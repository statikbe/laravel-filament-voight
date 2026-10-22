<?php

namespace Statikbe\FilamentVoight\Support;

use Composer\Semver\Constraint\Constraint;
use Composer\Semver\Constraint\ConstraintInterface;
use Composer\Semver\VersionParser;
use UnexpectedValueException;

/**
 * Matches installed package versions against a semver range as published in
 * advisories, e.g. ">=10.0.0,<10.48.29" (Composer) or "<1.6.4 || >=2.0.0 <2.1.1" (npm).
 *
 * Ranges are interpreted with Composer semantics. Advisories almost always use
 * explicit bounds, where Composer and npm agree; only "~" differs (Composer
 * reads "~1.2" as "<2.0", npm as "<1.3.0").
 */
class VersionRange
{
    public static function isValid(string $range): bool
    {
        return self::parse($range) !== null;
    }

    /**
     * @param  iterable<string>  $versions
     * @return list<string> the versions inside the range, in their original notation
     */
    public static function filterSatisfying(iterable $versions, string $range): array
    {
        $constraint = self::parse($range);

        if ($constraint === null) {
            return [];
        }

        $parser = new VersionParser;
        $satisfying = [];

        foreach ($versions as $version) {
            try {
                $normalized = $parser->normalize($version);
            } catch (UnexpectedValueException) {
                // Branch aliases, git hashes and other non-semver locks can't be in a range.
                continue;
            }

            if ($constraint->matches(new Constraint('==', $normalized))) {
                $satisfying[] = $version;
            }
        }

        return $satisfying;
    }

    private static function parse(string $range): ?ConstraintInterface
    {
        if (trim($range) === '') {
            return null;
        }

        try {
            return (new VersionParser)->parseConstraints($range);
        } catch (UnexpectedValueException) {
            return null;
        }
    }
}
