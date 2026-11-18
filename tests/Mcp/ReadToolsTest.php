<?php

use Statikbe\FilamentVoight\Enums\Framework;
use Statikbe\FilamentVoight\Mcp\Tools\FindPackageUsageTool;
use Statikbe\FilamentVoight\Mcp\Tools\GetProjectVulnerabilitiesTool;
use Statikbe\FilamentVoight\Mcp\Tools\GetStatisticsTool;
use Statikbe\FilamentVoight\Mcp\Tools\GetVulnerabilityTool;
use Statikbe\FilamentVoight\Mcp\Tools\ListProjectsTool;
use Statikbe\FilamentVoight\Mcp\VoightLocalServer;
use Statikbe\FilamentVoight\Models\AuditRun;
use Statikbe\FilamentVoight\Models\Customer;
use Statikbe\FilamentVoight\Models\Environment;
use Statikbe\FilamentVoight\Models\EnvironmentPackage;
use Statikbe\FilamentVoight\Models\EnvironmentSystemDetail;
use Statikbe\FilamentVoight\Models\Package;
use Statikbe\FilamentVoight\Models\Project;
use Statikbe\FilamentVoight\Models\Team;
use Statikbe\FilamentVoight\Models\Vulnerability;
use Statikbe\FilamentVoight\Models\VulnerablePackageRange;

function mcpEnvironment(string $projectCode, string $name = 'production', array $projectAttributes = []): Environment
{
    $project = Project::query()->where('project_code', $projectCode)->first()
        ?? Project::factory()->create(['project_code' => $projectCode, ...$projectAttributes]);

    return Environment::factory()->for($project)->create(['name' => $name]);
}

function installFramework(Environment $environment, Framework $framework, string $version): void
{
    EnvironmentPackage::factory()->for($environment)->for(
        Package::query()->firstOrCreate(['name' => $framework->value, 'type' => 'composer']),
    )->create(['version' => $version]);
}

/**
 * Walks a list tool page by page with the given page size and checks the paging fields on each page:
 * the same total everywhere, next_offset pointing at the next page, and truncated only before the last.
 * Returns the rows of all pages, in order.
 *
 * @param  class-string  $tool
 * @return array<int, array<string, mixed>>
 */
function walkPages(string $tool, array $arguments, string $rowsKey, int $pageSize): array
{
    $rows = [];
    $offset = 0;
    $total = null;

    do {
        $page = structuredContent(VoightLocalServer::tool($tool, [...$arguments, 'limit' => $pageSize, 'offset' => $offset]));
        $total ??= $page['total'];
        $rows = [...$rows, ...$page[$rowsKey]];

        expect($page['total'])->toBe($total)
            ->and($page['truncated'])->toBe($page['next_offset'] !== null)
            ->and($page['next_offset'])->toBe(count($rows) < $total ? count($rows) : null);

        $offset = $page['next_offset'];
    } while ($offset !== null);

    expect($rows)->toHaveCount($total);

    return $rows;
}

describe('list_projects', function () {
    it('counts current findings per severity and sorts by findings', function () {
        $a = mcpEnvironment('A');
        mcpFinding($a, Vulnerability::factory()->critical()->create(['vulnerability_score' => 9.8]));
        $b = mcpEnvironment('B');
        mcpFinding($b, Vulnerability::factory()->high()->create(['vulnerability_score' => 7.5]), 'axios');
        mcpFinding($b, Vulnerability::factory()->low()->create(['vulnerability_score' => 2.0]), 'ms');
        mcpEnvironment('C');

        $projects = structuredContent(VoightLocalServer::tool(ListProjectsTool::class))['projects'];

        expect(array_column($projects, 'code'))->toBe(['B', 'A', 'C'])
            ->and($projects[0]['findings'])->toBe(['critical' => 0, 'high' => 1, 'medium' => 0, 'low' => 1, 'none' => 0, 'total' => 2])
            ->and($projects[0]['max_score'])->toBe(7.5)
            ->and($projects[2]['findings']['total'])->toBe(0)
            ->and($projects[2]['max_score'])->toBeNull();
    });

    it('sorts by max score, name and least recently scanned', function () {
        $a = mcpEnvironment('A', projectAttributes: ['name' => 'Zulu']);
        $a->update(['scanned_at' => now()->subDay()]);
        mcpFinding($a, Vulnerability::factory()->critical()->create());
        $b = mcpEnvironment('B', projectAttributes: ['name' => 'Alpha']);
        $b->update(['scanned_at' => now()->subDays(10)]);
        mcpFinding($b, Vulnerability::factory()->low()->create(), 'one');
        mcpFinding($b, Vulnerability::factory()->low()->create(), 'two');

        $codes = fn (string $sort): array => array_column(structuredContent(VoightLocalServer::tool(ListProjectsTool::class, ['sort' => $sort]))['projects'], 'code');

        expect($codes('max_score'))->toBe(['A', 'B'])
            ->and($codes('name'))->toBe(['B', 'A'])
            ->and($codes('last_scanned'))->toBe(['B', 'A'])
            ->and($codes('findings'))->toBe(['B', 'A']);
    });

    it('filters on search, team, customer, severity, findings and stale scans', function () {
        $team = Team::factory()->create(['name' => 'Web']);
        $customer = Customer::factory()->create(['name' => 'Acme']);
        $shop = mcpEnvironment('SHOP', projectAttributes: ['name' => 'Webshop', 'team_id' => $team->id, 'customer_id' => $customer->id]);
        $shop->update(['scanned_at' => now()]);
        mcpFinding($shop, Vulnerability::factory()->medium()->create());
        $blog = mcpEnvironment('BLOG', projectAttributes: ['name' => 'Blog']);
        $blog->update(['scanned_at' => now()->subMonth()]);

        $codes = fn (array $arguments): array => array_column(structuredContent(VoightLocalServer::tool(ListProjectsTool::class, $arguments))['projects'], 'code');

        expect($codes(['search' => 'shop']))->toBe(['SHOP'])
            ->and($codes(['team' => 'Web']))->toBe(['SHOP'])
            ->and($codes(['customer' => 'Acme']))->toBe(['SHOP'])
            ->and($codes(['min_severity' => 'medium']))->toBe(['SHOP'])
            ->and($codes(['min_severity' => 'high']))->toBe([])
            ->and($codes(['has_findings' => true]))->toBe(['SHOP'])
            ->and($codes(['has_findings' => false]))->toBe(['BLOG'])
            ->and($codes(['scanned_before' => now()->subWeek()->toDateString()]))->toBe(['BLOG']);
    });

    it('limits and reports truncation', function () {
        foreach (['A', 'B', 'C'] as $code) {
            mcpEnvironment($code);
        }

        $result = structuredContent(VoightLocalServer::tool(ListProjectsTool::class, ['limit' => 2]));

        expect($result['projects'])->toHaveCount(2)->and($result['truncated'])->toBeTrue();
    });

    it('rejects a limit above the maximum', function () {
        VoightLocalServer::tool(ListProjectsTool::class, ['limit' => 101])->assertHasErrors();
    });

    it('pages through every project in order, with the total', function () {
        foreach (['A', 'B', 'C', 'D', 'E'] as $code) {
            mcpEnvironment($code);
        }

        $paged = walkPages(ListProjectsTool::class, ['sort' => 'name'], 'projects', 2);
        $unpaged = structuredContent(VoightLocalServer::tool(ListProjectsTool::class, ['sort' => 'name']))['projects'];

        expect(array_column($paged, 'code'))->toBe(array_column($unpaged, 'code'))
            ->and($paged)->toHaveCount(5);
    });

    it('counts only the filtered projects in the total', function () {
        mcpFinding(mcpEnvironment('VULN'), Vulnerability::factory()->high()->create());
        mcpEnvironment('CLEAN');

        $result = structuredContent(VoightLocalServer::tool(ListProjectsTool::class, ['has_findings' => true]));

        expect($result['total'])->toBe(1)
            ->and($result['next_offset'])->toBeNull()
            ->and($result['truncated'])->toBeFalse();
    });

    it('returns an empty last page for an offset past the end', function () {
        mcpEnvironment('A');

        $result = structuredContent(VoightLocalServer::tool(ListProjectsTool::class, ['offset' => 10]));

        expect($result['projects'])->toBe([])
            ->and($result['total'])->toBe(1)
            ->and($result['next_offset'])->toBeNull();
    });

    it('rejects a negative offset', function () {
        VoightLocalServer::tool(ListProjectsTool::class, ['offset' => -1])->assertHasErrors();
    });

    it('reports every missing code on each page', function () {
        foreach (['A', 'B', 'C'] as $code) {
            mcpEnvironment($code);
        }

        $arguments = ['project_codes' => ['A', 'B', 'C', 'NOPE'], 'limit' => 2];

        expect(structuredContent(VoightLocalServer::tool(ListProjectsTool::class, $arguments))['missing_codes'])->toBe(['NOPE'])
            ->and(structuredContent(VoightLocalServer::tool(ListProjectsTool::class, [...$arguments, 'offset' => 2]))['missing_codes'])->toBe(['NOPE']);
    });

    it('describes the stack of the highest-stage environment', function () {
        $production = mcpEnvironment('SHOP');
        installFramework($production, Framework::Laravel, 'v11.31.0');
        installFramework($production, Framework::Filament, '5.0.2');
        EnvironmentSystemDetail::factory()->for($production)->create(['php_version' => '8.3.12']);
        installFramework(mcpEnvironment('SHOP', 'staging'), Framework::Laravel, '13.0.0');
        mcpEnvironment('BLOG');

        $projects = collect(structuredContent(VoightLocalServer::tool(ListProjectsTool::class))['projects'])->keyBy('code');

        expect($projects['SHOP']['stack'])->toBe([
            'environment' => 'production',
            'php_version' => '8.3.12',
            'frameworks' => [
                ['package' => 'laravel/framework', 'version' => 'v11.31.0', 'latest_major' => Framework::Laravel->latestMajor()],
                ['package' => 'filament/filament', 'version' => '5.0.2', 'latest_major' => Framework::Filament->latestMajor()],
            ],
            'upgrade_status' => 'needs_upgrade',
        ])->and($projects['BLOG']['stack'])->toBe([
            'environment' => 'production',
            'php_version' => null,
            'frameworks' => [],
            'upgrade_status' => 'unknown',
        ]);
    });

    it('filters on upgrade status', function () {
        installFramework(mcpEnvironment('OLD'), Framework::Laravel, '11.0.0');
        installFramework(mcpEnvironment('NEW'), Framework::Laravel, Framework::Laravel->latestMajor() . '.1.0');
        mcpEnvironment('NODE');

        $codes = fn (string $status): array => array_column(structuredContent(VoightLocalServer::tool(ListProjectsTool::class, ['upgrade_status' => $status]))['projects'], 'code');

        expect($codes('needs_upgrade'))->toBe(['OLD'])
            ->and($codes('current'))->toBe(['NEW'])
            ->and($codes('unknown'))->toBe(['NODE']);
    });

    it('rejects an unknown upgrade status', function () {
        VoightLocalServer::tool(ListProjectsTool::class, ['upgrade_status' => 'ancient'])->assertHasErrors();
    });

    it('lists the requested project codes and reports the missing ones', function () {
        foreach (['KULCOB', 'STIPLA', 'OTHER'] as $code) {
            mcpEnvironment($code);
        }

        $result = structuredContent(VoightLocalServer::tool(ListProjectsTool::class, ['project_codes' => ['STIPLA', 'KULXMS', 'KULCOB', 'COLTRA']]));

        expect(array_column($result['projects'], 'code'))->toEqualCanonicalizing(['KULCOB', 'STIPLA'])
            ->and($result['missing_codes'])->toBe(['KULXMS', 'COLTRA']);
    });

    it('does not report a requested code as missing when another filter excludes it', function () {
        mcpFinding(mcpEnvironment('VULN'), Vulnerability::factory()->high()->create());
        mcpEnvironment('CLEAN');

        $result = structuredContent(VoightLocalServer::tool(ListProjectsTool::class, ['project_codes' => ['VULN', 'CLEAN'], 'has_findings' => true]));

        expect(array_column($result['projects'], 'code'))->toBe(['VULN'])
            ->and($result['missing_codes'])->toBe([]);
    });

    it('omits missing codes when no project codes are requested', function () {
        mcpEnvironment('A');

        expect(structuredContent(VoightLocalServer::tool(ListProjectsTool::class)))->not->toHaveKey('missing_codes');
    });

    it('returns every requested project without a limit', function () {
        $codes = array_map(fn (int $number): string => "P{$number}", range(1, 30));

        foreach ($codes as $code) {
            mcpEnvironment($code);
        }

        $result = structuredContent(VoightLocalServer::tool(ListProjectsTool::class, ['project_codes' => $codes]));

        expect($result['projects'])->toHaveCount(30)
            ->and($result['truncated'])->toBeFalse();
    });

    it('rejects more than the maximum number of project codes', function () {
        $codes = array_map(fn (int $number): string => "P{$number}", range(1, 101));

        VoightLocalServer::tool(ListProjectsTool::class, ['project_codes' => $codes])->assertHasErrors();
    });
});

describe('get_project_vulnerabilities', function () {
    it('groups current findings by vulnerability, worst first, and ignores older audit runs', function () {
        $production = mcpEnvironment('P');
        $old = AuditRun::factory()->for($production)->create(['started_at' => now()->subWeek()]);
        $stale = Vulnerability::factory()->critical()->create(['source_id' => 'GHSA-old']);
        mcpFinding($production, $stale)->update(['audit_run_id' => $old->id]);
        AuditRun::factory()->for($production)->create(['started_at' => now()]);

        $high = Vulnerability::factory()->high()->create(['source_id' => 'GHSA-high']);
        $critical = Vulnerability::factory()->critical()->create(['source_id' => 'GHSA-crit', 'aliases' => ['CVE-1']]);
        mcpFinding($production, $high, 'axios', '0.21.0', '0.21.2');
        mcpFinding($production, $critical, 'lodash', '4.17.15', '4.17.21', ['is_direct' => false, 'is_dev' => true]);
        $staging = mcpEnvironment('P', 'staging');
        mcpFinding($staging, $critical, 'lodash', '4.17.15', '4.17.21', ['is_direct' => false, 'is_dev' => true]);

        $vulnerabilities = structuredContent(VoightLocalServer::tool(GetProjectVulnerabilitiesTool::class, ['project_code' => 'P']))['vulnerabilities'];

        expect(array_column($vulnerabilities, 'id'))->toBe(['GHSA-crit', 'GHSA-high'])
            ->and($vulnerabilities[0]['aliases'])->toBe(['CVE-1'])
            ->and($vulnerabilities[0]['severity'])->toBe('critical')
            ->and($vulnerabilities[0]['packages'])->toBe([[
                'name' => 'lodash',
                'type' => 'npm',
                'installed_version' => '4.17.15',
                'fixed_version' => '4.17.21',
                'is_direct' => false,
                'is_dev' => true,
                'environments' => ['production', 'staging'],
            ]]);
    });

    it('filters by environment, severity and dev dependencies', function () {
        $production = mcpEnvironment('P');
        mcpFinding($production, Vulnerability::factory()->high()->create(['source_id' => 'GHSA-runtime']), 'axios');
        mcpFinding($production, Vulnerability::factory()->low()->create(['source_id' => 'GHSA-dev']), 'jest', node: ['is_direct' => true, 'is_dev' => true]);
        mcpFinding(mcpEnvironment('P', 'staging'), Vulnerability::factory()->critical()->create(['source_id' => 'GHSA-staging']));

        $ids = fn (array $arguments): array => array_column(structuredContent(VoightLocalServer::tool(GetProjectVulnerabilitiesTool::class, ['project_code' => 'P', ...$arguments]))['vulnerabilities'], 'id');

        expect($ids(['environment' => 'production']))->toBe(['GHSA-runtime', 'GHSA-dev'])
            ->and($ids(['min_severity' => 'high']))->toBe(['GHSA-staging', 'GHSA-runtime'])
            ->and($ids(['include_dev' => false]))->toBe(['GHSA-staging', 'GHSA-runtime']);
    });

    it('lists the environments when an unknown one is passed', function () {
        mcpEnvironment('P');
        mcpEnvironment('P', 'staging');

        VoightLocalServer::tool(GetProjectVulnerabilitiesTool::class, ['project_code' => 'P', 'environment' => 'prod'])
            ->assertHasErrors(['Environment `prod` not found. Project `P` has environments production, staging.']);
    });

    it('limits the number of vulnerabilities', function () {
        $production = mcpEnvironment('P');
        foreach (range(1, 3) as $i) {
            mcpFinding($production, Vulnerability::factory()->create(), "package-{$i}");
        }

        $result = structuredContent(VoightLocalServer::tool(GetProjectVulnerabilitiesTool::class, ['project_code' => 'P', 'limit' => 2]));

        expect($result['vulnerabilities'])->toHaveCount(2)->and($result['truncated'])->toBeTrue();
    });

    it('pages through the vulnerabilities, worst first', function () {
        $production = mcpEnvironment('P');
        foreach ([9.8, 7.5, 5.0, 2.0, 1.0] as $i => $score) {
            mcpFinding($production, Vulnerability::factory()->create(['vulnerability_score' => $score]), "package-{$i}");
        }

        $paged = walkPages(GetProjectVulnerabilitiesTool::class, ['project_code' => 'P'], 'vulnerabilities', 2);

        expect(array_column($paged, 'score'))->toEqual([9.8, 7.5, 5, 2, 1]);
    });
});

describe('find_package_usage', function () {
    beforeEach(function () {
        $lodash = Package::factory()->create(['name' => 'lodash', 'type' => 'npm']);
        $a = mcpEnvironment('A');
        $b = mcpEnvironment('B');
        EnvironmentPackage::factory()->for($a)->for($lodash)->create(['version' => '4.17.15', 'is_direct' => false, 'is_dev' => false]);
        // The same version installed twice (nested): one usage, direct because one copy is.
        EnvironmentPackage::factory()->for($a)->for($lodash)->create(['version' => '4.17.15', 'is_direct' => true, 'is_dev' => false]);
        EnvironmentPackage::factory()->for($b)->for($lodash)->create(['version' => 'v4.17.21', 'is_direct' => true, 'is_dev' => true]);
    });

    it('compares versions with each operator, ignoring a leading v', function (string $version, array $expected) {
        $usages = structuredContent(VoightLocalServer::tool(FindPackageUsageTool::class, ['package' => 'lodash', 'version' => $version]))['usages'];

        expect(array_column($usages, 'project_code'))->toBe($expected);
    })->with([
        ['<4.17.21', ['A']],
        ['<=4.17.21', ['A', 'B']],
        ['>4.17.15', ['B']],
        ['>=v4.17.15', ['A', 'B']],
        ['=4.17.21', ['B']],
        ['4.17.15', ['A']],
    ]);

    it('merges nested copies and can leave out dev dependencies', function () {
        $usages = structuredContent(VoightLocalServer::tool(FindPackageUsageTool::class, ['package' => 'lodash', 'include_dev' => false]))['usages'];

        expect($usages)->toBe([['project_code' => 'A', 'environment' => 'production', 'type' => 'npm', 'version' => '4.17.15', 'is_direct' => true, 'is_dev' => false]]);
    });

    it('pages through the usages after filtering on version', function () {
        $lodash = Package::query()->where('name', 'lodash')->firstOrFail();
        foreach (['C', 'D', 'E'] as $code) {
            EnvironmentPackage::factory()->for(mcpEnvironment($code))->for($lodash)->create(['version' => '4.0.0']);
        }

        $paged = walkPages(FindPackageUsageTool::class, ['package' => 'lodash', 'version' => '<4.17.21'], 'usages', 2);

        expect(array_column($paged, 'project_code'))->toBe(['A', 'C', 'D', 'E']);
    });

    it('rejects a malformed version filter', function () {
        VoightLocalServer::tool(FindPackageUsageTool::class, ['package' => 'lodash', 'version' => '~>4'])
            ->assertHasErrors(['Invalid version filter']);
    });
});

describe('get_vulnerability', function () {
    it('finds a vulnerability by source id, CVE alias or GHSA alias', function (string $id) {
        $vulnerability = Vulnerability::factory()->high()->create(['source_id' => 'PYSEC-2026-1', 'aliases' => ['CVE-2026-1234', 'GHSA-xxxx-yyyy-zzzz']]);
        $package = Package::factory()->create(['name' => 'lodash', 'type' => 'npm']);
        VulnerablePackageRange::factory()->for($vulnerability)->for($package)->create(['affected_range' => '<4.17.21', 'fixed_version' => '4.17.21']);
        mcpFinding(mcpEnvironment('A'), $vulnerability, 'lodash', '4.17.15', '4.17.21');

        $result = structuredContent(VoightLocalServer::tool(GetVulnerabilityTool::class, ['id' => $id]));

        expect($result['id'])->toBe('PYSEC-2026-1')
            ->and($result['severity'])->toBe('high')
            ->and($result['affected'])->toBe([['package' => 'lodash', 'type' => 'npm', 'affected_range' => '<4.17.21', 'fixed_version' => '4.17.21']])
            ->and($result['affected_projects'])->toBe([['project_code' => 'A', 'environment' => 'production', 'package' => 'lodash', 'installed_version' => '4.17.15', 'fixed_version' => '4.17.21']]);
    })->with(['PYSEC-2026-1', 'CVE-2026-1234', 'ghsa-xxxx-yyyy-zzzz']);

    it('does not match part of an alias', function () {
        Vulnerability::factory()->create(['aliases' => ['CVE-2026-12345']]);

        VoightLocalServer::tool(GetVulnerabilityTool::class, ['id' => 'CVE-2026-1234'])->assertHasErrors(['No vulnerability']);
    });
});

describe('get_statistics', function () {
    beforeEach(function () {
        $web = Team::factory()->create(['name' => 'Web']);
        $critical = Vulnerability::factory()->critical()->create(['source_id' => 'GHSA-crit', 'vulnerability_score' => 9.5]);
        $medium = Vulnerability::factory()->medium()->create(['source_id' => 'GHSA-med', 'vulnerability_score' => 5.0]);
        mcpFinding(mcpEnvironment('A', projectAttributes: ['team_id' => $web->id]), $critical, 'lodash');
        mcpFinding(mcpEnvironment('B', projectAttributes: ['team_id' => $web->id]), $medium, 'lodash');
        mcpFinding(Environment::query()->whereHas('project', fn ($q) => $q->where('project_code', 'B'))->first(), $medium, 'axios');
    });

    it('groups by project, worst first', function () {
        $groups = structuredContent(VoightLocalServer::tool(GetStatisticsTool::class, ['group_by' => 'project']))['groups'];

        expect(array_column($groups, 'key'))->toBe(['A', 'B'])
            ->and($groups[1]['findings']['medium'])->toBe(2)
            ->and($groups[0]['max_score'])->toBe(9.5);
    });

    it('groups by package, vulnerability and team, counting affected projects', function () {
        $byPackage = structuredContent(VoightLocalServer::tool(GetStatisticsTool::class, ['group_by' => 'package']))['groups'];
        $byVulnerability = structuredContent(VoightLocalServer::tool(GetStatisticsTool::class, ['group_by' => 'vulnerability']))['groups'];
        $byTeam = structuredContent(VoightLocalServer::tool(GetStatisticsTool::class, ['group_by' => 'team']))['groups'];

        expect($byPackage[0])->toMatchArray(['key' => 'npm:lodash', 'label' => 'lodash', 'affected_projects' => 2])
            ->and(array_column($byVulnerability, 'key'))->toBe(['GHSA-crit', 'GHSA-med'])
            ->and($byVulnerability[1]['affected_projects'])->toBe(1)
            ->and($byTeam)->toHaveCount(1)
            ->and($byTeam[0]['label'])->toBe('Web')
            ->and($byTeam[0]['findings']['total'])->toBe(3);
    });

    it('applies the severity threshold and limit', function () {
        $result = structuredContent(VoightLocalServer::tool(GetStatisticsTool::class, ['group_by' => 'project', 'min_severity' => 'critical', 'limit' => 1]));

        expect(array_column($result['groups'], 'key'))->toBe(['A'])->and($result['truncated'])->toBeFalse();
    });

    it('pages through the groups', function () {
        $paged = walkPages(GetStatisticsTool::class, ['group_by' => 'project'], 'groups', 1);
        $unpaged = structuredContent(VoightLocalServer::tool(GetStatisticsTool::class, ['group_by' => 'project']))['groups'];

        expect($paged)->toBe($unpaged)
            ->and($paged)->toHaveCount(2);
    });
});
