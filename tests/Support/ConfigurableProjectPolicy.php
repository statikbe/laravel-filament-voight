<?php

namespace Statikbe\FilamentVoight\Tests\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Statikbe\FilamentVoight\Models\Project;

/**
 * A host policy for the project model whose answers tests set per ability.
 */
class ConfigurableProjectPolicy
{
    /** @var array<string, bool> ability => allowed; abilities not listed are allowed */
    public static array $abilities = [];

    /** @var array<int, string> project codes `view` and `update` deny */
    public static array $deniedProjects = [];

    public function viewAny(Authenticatable $user): bool
    {
        return static::$abilities['viewAny'] ?? true;
    }

    public function view(Authenticatable $user, Project $project): bool
    {
        return (static::$abilities['view'] ?? true) && ! in_array($project->project_code, static::$deniedProjects, true);
    }

    public function create(Authenticatable $user): bool
    {
        return static::$abilities['create'] ?? true;
    }

    public function update(Authenticatable $user, Project $project): bool
    {
        return (static::$abilities['update'] ?? true) && ! in_array($project->project_code, static::$deniedProjects, true);
    }
}
