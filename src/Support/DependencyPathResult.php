<?php

namespace Statikbe\FilamentVoight\Support;

use Illuminate\Support\Collection;

final class DependencyPathResult
{
    /**
     * @param  Collection<int, DependencyPath>  $paths  shortest first
     * @param  bool  $truncated  a path, depth or work limit stopped the search with work remaining
     */
    public function __construct(
        public Collection $paths,
        public bool $truncated,
    ) {}
}
