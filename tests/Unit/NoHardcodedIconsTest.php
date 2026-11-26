<?php

use Symfony\Component\Finder\Finder;

it('names icons only through VoightIcon', function () {
    $root = dirname(__DIR__, 2);

    $files = Finder::create()
        ->files()
        ->in(["{$root}/src", "{$root}/resources/views"])
        ->name(['*.php'])
        ->notPath('Enums/VoightIcon.php')
        ->contains('/Heroicon::|heroicon-/');

    $offenders = array_map(
        fn (SplFileInfo $file): string => str_replace("{$root}/", '', $file->getPathname()),
        iterator_to_array($files, false),
    );

    expect($offenders)->toBe([], 'Add a VoightIcon case instead of hardcoding an icon.');
});
