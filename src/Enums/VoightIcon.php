<?php

namespace Statikbe\FilamentVoight\Enums;

use BackedEnum;
use Filament\Support\Facades\FilamentIcon;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use ReflectionEnumBackedCase;
use Statikbe\FilamentVoight\Facades\FilamentVoight;
use Statikbe\FilamentVoight\Support\IconDefinition;

/**
 * Every icon the package renders. Each case is a Filament icon alias (`voight::<value>`) with a default and a description.
 *
 * Override an icon in `config('filament-voight.icons')` (keyed by the case value) or with
 * `FilamentIcon::register(['voight::<value>' => ...])`; the latter wins. `php artisan voight:icons --json` lists them all.
 */
enum VoightIcon: string
{
    public const string ALIAS_PREFIX = 'voight::';

    // -- Navigation --

    #[IconDefinition(Heroicon::OutlinedCube, 'Sidebar navigation item for the packages list: all Composer and npm packages installed across projects.')]
    case NavigationPackages = 'navigation.packages';

    #[IconDefinition(Heroicon::OutlinedUserGroup, 'Sidebar navigation item for the teams list: groups of users responsible for projects.')]
    case NavigationTeams = 'navigation.teams';

    #[IconDefinition(Heroicon::OutlinedCodeBracket, 'Sidebar navigation item for the projects list: the monitored software projects.')]
    case NavigationProjects = 'navigation.projects';

    #[IconDefinition(Heroicon::OutlinedShieldExclamation, 'Sidebar navigation item for the vulnerabilities list: known security advisories.')]
    case NavigationVulnerabilities = 'navigation.vulnerabilities';

    #[IconDefinition(Heroicon::OutlinedBuildingOffice, 'Sidebar navigation item for the customers list: the clients projects belong to.')]
    case NavigationCustomers = 'navigation.customers';

    #[IconDefinition(Heroicon::OutlinedTag, 'Sidebar navigation item for the tag types screen, where admins define classifications and their tags.')]
    case NavigationTagTypes = 'navigation.tag-types';

    #[IconDefinition(Heroicon::OutlinedCommandLine, 'User menu item opening the MCP access page, where users connect AI assistants to Voight.')]
    case UserMenuMcpAccess = 'user-menu.mcp-access';

    // -- Relation manager / page tabs --

    #[IconDefinition(Heroicon::OutlinedShieldExclamation, 'Tab on an audit run page listing the findings (vulnerable packages) that run detected.')]
    case TabAuditRunFindings = 'tab.audit-run.findings';

    #[IconDefinition(Heroicon::OutlinedEye, 'Tab on a project page with its general information and environments overview.')]
    case TabProjectInfo = 'tab.project.info';

    #[IconDefinition(Heroicon::OutlinedClipboardDocumentCheck, 'Tab on a project page listing its audit runs (vulnerability scan history).')]
    case TabProjectAuditRuns = 'tab.project.audit-runs';

    #[IconDefinition(Heroicon::OutlinedServerStack, 'Tab on a project page listing its environments (servers/stages such as production and staging).')]
    case TabProjectEnvironments = 'tab.project.environments';

    #[IconDefinition(Heroicon::OutlinedShieldExclamation, 'Tab on a project page listing the vulnerabilities affecting it.')]
    case TabProjectVulnerabilities = 'tab.project.vulnerabilities';

    #[IconDefinition(Heroicon::OutlinedBellAlert, 'Tab on a project page with its alert settings: who gets notified about new vulnerabilities, and how.')]
    case TabProjectAlertSettings = 'tab.project.alert-settings';

    #[IconDefinition(Heroicon::OutlinedUsers, 'Tab on a team page listing its member users.')]
    case TabTeamUsers = 'tab.team.users';

    #[IconDefinition(Heroicon::OutlinedFolder, 'Tab on a team page listing the projects the team is responsible for.')]
    case TabTeamProjects = 'tab.team.projects';

    #[IconDefinition(Heroicon::OutlinedCube, 'Tab on a vulnerability page listing the packages (and version ranges) it affects.')]
    case TabVulnerabilityAffectedPackages = 'tab.vulnerability.affected-packages';

    #[IconDefinition(Heroicon::OutlinedCodeBracket, 'Tab on a vulnerability page listing the projects that install an affected package.')]
    case TabVulnerabilityAffectedProjects = 'tab.vulnerability.affected-projects';

    #[IconDefinition(Heroicon::OutlinedDocumentText, 'Tab on a vulnerability page showing the advisory description text.')]
    case TabVulnerabilityDescription = 'tab.vulnerability.description';

    #[IconDefinition(Heroicon::OutlinedShieldExclamation, 'Tab on a package page listing the open audit findings for that package.')]
    case TabPackageActiveFindings = 'tab.package.active-findings';

    #[IconDefinition(Heroicon::OutlinedServerStack, 'Tab on a package page listing the environments where the package is installed.')]
    case TabPackageInstallations = 'tab.package.installations';

    #[IconDefinition(Heroicon::OutlinedBookOpen, 'Tab on a package page listing the known vulnerabilities (advisories) published for the package.')]
    case TabPackageKnownVulnerabilities = 'tab.package.known-vulnerabilities';

    #[IconDefinition(Heroicon::Cube, 'Tab on an environment page with framework details (e.g. Laravel `about` output).')]
    case TabEnvironmentFramework = 'tab.environment.framework';

    #[IconDefinition(Heroicon::ServerStack, 'Tab on an environment page with server details (PHP, OS, limits, php.ini).')]
    case TabEnvironmentServer = 'tab.environment.server';

    #[IconDefinition(Heroicon::PuzzlePiece, 'Tab on an environment page listing the loaded PHP extensions.')]
    case TabEnvironmentExtensions = 'tab.environment.extensions';

    #[IconDefinition(Heroicon::OutlinedTag, 'Tab on a tag type page listing the tags of that type.')]
    case TabTagTypeTags = 'tab.tag-type.tags';

    // -- Empty states --

    #[IconDefinition(Heroicon::OutlinedShieldCheck, 'Empty state of vulnerability/finding lists meaning "all clear": nothing vulnerable was found.')]
    case EmptyStateAllClear = 'empty-state.all-clear';

    #[IconDefinition(Heroicon::OutlinedCube, 'Empty state of the affected packages tab on a vulnerability: no affected packages are recorded.')]
    case EmptyStateNoAffectedPackages = 'empty-state.no-affected-packages';

    // -- Project page --

    #[IconDefinition(Heroicon::OutlinedServerStack, 'Section header on a project page holding the table of its environments.')]
    case ProjectSectionEnvironments = 'project.section.environments';

    #[IconDefinition(Heroicon::OutlinedInformationCircle, 'Section header on a project page with general information (code, customer, team, description).')]
    case ProjectSectionGeneral = 'project.section.general';

    #[IconDefinition(Heroicon::OutlinedBell, 'Section header on a project page with alert status (muted or active, last alert sent).')]
    case ProjectSectionAlerts = 'project.section.alerts';

    #[IconDefinition(Heroicon::OutlinedKey, 'Section header on a project page for its API token, used to push lockfiles to Voight.')]
    case ProjectSectionApiToken = 'project.section.api-token';

    // -- Audit run page --

    #[IconDefinition(Heroicon::OutlinedArrowRight, 'Trailing icon on an audit run\'s project/environment name, linking to that project page inside the panel.')]
    case AuditRunProjectLink = 'audit-run.project-link';

    // -- Actions --

    #[IconDefinition(Heroicon::ArrowTopRightOnSquare, 'Action button opening the vulnerability\'s source advisory (e.g. OSV, GitHub) on an external site.')]
    case ActionOpenVulnerabilitySource = 'action.open-vulnerability-source';

    #[IconDefinition(Heroicon::ArrowTopRightOnSquare, 'Action button opening the package\'s registry page (Packagist/npm) on an external site.')]
    case ActionOpenPackageWebsite = 'action.open-package-website';

    #[IconDefinition(Heroicon::OutlinedQuestionMarkCircle, 'Action button explaining why a package is installed: the dependency chain that pulls it in.')]
    case ActionWhyInstalled = 'action.why-installed';

    #[IconDefinition(Heroicon::ClipboardDocument, 'Action button on an environment page copying its system details as Markdown to the clipboard.')]
    case ActionCopyEnvironmentMarkdown = 'action.copy-environment-markdown';

    #[IconDefinition(Heroicon::OutlinedTag, 'Bulk action adding tags to all selected records in a table.')]
    case ActionApplyTags = 'action.apply-tags';

    #[IconDefinition(Heroicon::OutlinedBackspace, 'Bulk action removing tags from all selected records in a table.')]
    case ActionRemoveTags = 'action.remove-tags';

    #[IconDefinition(Heroicon::OutlinedTag, 'Action on a record\'s page opening a modal to edit its tags.')]
    case ActionEditTags = 'action.edit-tags';

    // -- Table filters --

    #[IconDefinition('heroicon-m-server-stack', 'Query-builder filter constraint on environment stage (production, staging, ...).')]
    case FilterEnvironmentStage = 'filter.environment-stage';

    #[IconDefinition('heroicon-m-tag', 'Query-builder filter constraint on an installed package version range.')]
    case FilterInstalledVersion = 'filter.installed-version';

    #[IconDefinition('heroicon-m-cube', 'Query-builder filter constraint on an installed package, optionally within a version range.')]
    case FilterInstalledPackage = 'filter.installed-package';

    // -- Widgets --

    #[IconDefinition(Heroicon::OutlinedShieldExclamation, 'Dashboard stat cards counting vulnerabilities per severity level.')]
    case WidgetSeverityOverview = 'widget.severity-overview';

    #[IconDefinition(Heroicon::OutlinedClock, 'Dashboard audit run status card for runs waiting to start.')]
    case WidgetAuditRunStatusPending = 'widget.audit-run-status.pending';

    #[IconDefinition(Heroicon::OutlinedArrowPath, 'Dashboard audit run status card for runs in progress.')]
    case WidgetAuditRunStatusRunning = 'widget.audit-run-status.running';

    #[IconDefinition(Heroicon::OutlinedCheckCircle, 'Dashboard audit run status card for successfully finished runs.')]
    case WidgetAuditRunStatusCompleted = 'widget.audit-run-status.completed';

    #[IconDefinition(Heroicon::OutlinedXCircle, 'Dashboard audit run status card for runs that failed.')]
    case WidgetAuditRunStatusFailed = 'widget.audit-run-status.failed';

    // -- Environments --

    #[IconDefinition('heroicon-o-check-circle', 'Health column of a project\'s environments table when the environment has no sync or scan issues.')]
    case EnvironmentHealthy = 'environment.healthy';

    #[IconDefinition(Heroicon::CodeBracket, 'Stat card on an environment page showing the PHP version.')]
    case EnvironmentStatPhp = 'environment.stat.php';

    #[IconDefinition(Heroicon::CircleStack, 'Stat card on an environment page showing the database engine and version.')]
    case EnvironmentStatDatabase = 'environment.stat.database';

    #[IconDefinition(Heroicon::GlobeAlt, 'Stat card on an environment page showing the app environment name (e.g. production).')]
    case EnvironmentStatEnvironment = 'environment.stat.environment';

    #[IconDefinition(Heroicon::BugAnt, 'Stat card on an environment page showing whether debug mode is on.')]
    case EnvironmentStatDebugMode = 'environment.stat.debug-mode';

    #[IconDefinition(Heroicon::Cog6Tooth, 'Section header for the app environment settings group of an environment\'s system details.')]
    case EnvironmentGroupEnvironment = 'environment.group.environment';

    #[IconDefinition(Heroicon::Bolt, 'Section header for the cache settings group of an environment\'s system details.')]
    case EnvironmentGroupCache = 'environment.group.cache';

    #[IconDefinition(Heroicon::CpuChip, 'Section header for the drivers group (queue, session, mail, ...) of an environment\'s system details.')]
    case EnvironmentGroupDrivers = 'environment.group.drivers';

    #[IconDefinition(Heroicon::CodeBracket, 'Section header for the PHP settings group of an environment\'s system details.')]
    case EnvironmentGroupPhp = 'environment.group.php';

    #[IconDefinition(Heroicon::ComputerDesktop, 'Section header for the operating system/hardware group of an environment\'s system details.')]
    case EnvironmentGroupSystem = 'environment.group.system';

    #[IconDefinition(Heroicon::RocketLaunch, 'Section header for the deployment group (commit, deploy time) of an environment\'s system details.')]
    case EnvironmentGroupDeployment = 'environment.group.deployment';

    #[IconDefinition(Heroicon::CircleStack, 'Section header for the database group of an environment\'s system details.')]
    case EnvironmentGroupDatabase = 'environment.group.database';

    #[IconDefinition(Heroicon::Bolt, 'Section header for the OPcache group of an environment\'s system details.')]
    case EnvironmentGroupOpcache = 'environment.group.opcache';

    #[IconDefinition(Heroicon::LockClosed, 'Section header for the SSL certificate group of an environment\'s system details.')]
    case EnvironmentGroupSsl = 'environment.group.ssl';

    #[IconDefinition(Heroicon::Squares2x2, 'Section header for any other, unrecognised group of an environment\'s system details.')]
    case EnvironmentGroupFallback = 'environment.group.fallback';

    #[IconDefinition(Heroicon::CheckCircle, 'Shown next to a storage symlink path on an environment page when the link exists.')]
    case EnvironmentStorageLinked = 'environment.storage-linked';

    #[IconDefinition(Heroicon::ExclamationTriangle, 'Shown next to a storage symlink path on an environment page when the link is missing.')]
    case EnvironmentStorageNotLinked = 'environment.storage-not-linked';

    #[IconDefinition(Heroicon::DocumentText, 'Shown next to a file path value (e.g. php.ini location) in an environment\'s system details.')]
    case EnvironmentFilePath = 'environment.file-path';

    #[IconDefinition(Heroicon::Folder, 'Shown next to a directory path value in an environment\'s system details.')]
    case EnvironmentDirectoryPath = 'environment.directory-path';

    // -- MCP access page --

    #[IconDefinition('heroicon-o-information-circle', 'Info callout on the MCP access page explaining that OAuth sign-in is unavailable.')]
    case McpOauthUnavailable = 'mcp.oauth-unavailable';

    #[IconDefinition('heroicon-o-clipboard-document', 'Button on the MCP access page copying a client setup snippet to the clipboard.')]
    case McpCopySnippet = 'mcp.copy-snippet';

    #[IconDefinition(Heroicon::OutlinedChatBubbleLeftRight, 'Card on the MCP access page with setup instructions for the Claude chat app.')]
    case McpClientClaude = 'mcp-client.claude';

    #[IconDefinition(Heroicon::OutlinedChatBubbleOvalLeftEllipsis, 'Card on the MCP access page with setup instructions for ChatGPT.')]
    case McpClientChatGpt = 'mcp-client.chatgpt';

    #[IconDefinition(Heroicon::OutlinedCodeBracketSquare, 'Card on the MCP access page with setup instructions for the Cursor editor.')]
    case McpClientCursor = 'mcp-client.cursor';

    #[IconDefinition(Heroicon::OutlinedCodeBracket, 'Card on the MCP access page with setup instructions for VS Code.')]
    case McpClientVsCode = 'mcp-client.vscode';

    #[IconDefinition(Heroicon::OutlinedCommandLine, 'Card on the MCP access page with setup instructions for the Claude Code CLI.')]
    case McpClientClaudeCode = 'mcp-client.claude-code';

    #[IconDefinition(Heroicon::OutlinedCommandLine, 'Card on the MCP access page with setup instructions for the Codex CLI.')]
    case McpClientCodex = 'mcp-client.codex';

    // -- Severity --

    #[IconDefinition('heroicon-o-shield-check', 'Severity "none": a vulnerability without a meaningful risk score. Shown in severity badges and filters.')]
    case SeverityNone = 'severity.none';

    #[IconDefinition('heroicon-o-information-circle', 'Severity "low": minor risk vulnerability. Shown in severity badges and filters.')]
    case SeverityLow = 'severity.low';

    #[IconDefinition('heroicon-o-exclamation-triangle', 'Severity "medium": moderate risk vulnerability. Shown in severity badges and filters.')]
    case SeverityMedium = 'severity.medium';

    #[IconDefinition('heroicon-o-exclamation-triangle', 'Severity "high": serious vulnerability that should be fixed soon. Shown in severity badges and filters.')]
    case SeverityHigh = 'severity.high';

    #[IconDefinition('heroicon-o-fire', 'Severity "critical": most urgent vulnerability level, needs immediate action. Shown in severity badges and filters.')]
    case SeverityCritical = 'severity.critical';

    // -- Audit run status --

    #[IconDefinition('heroicon-o-clock', 'Audit run status "pending": the scan is queued and has not started.')]
    case AuditRunStatusPending = 'audit-run-status.pending';

    #[IconDefinition('heroicon-o-arrow-path', 'Audit run status "running": the scan is in progress.')]
    case AuditRunStatusRunning = 'audit-run-status.running';

    #[IconDefinition('heroicon-o-check-circle', 'Audit run status "completed": the scan finished successfully.')]
    case AuditRunStatusCompleted = 'audit-run-status.completed';

    #[IconDefinition('heroicon-o-x-circle', 'Audit run status "failed": the scan errored and produced no result.')]
    case AuditRunStatusFailed = 'audit-run-status.failed';

    // -- Dependency sync status --

    #[IconDefinition('heroicon-o-clock', 'Lockfile sync status "pending": an uploaded lockfile waits to be processed.')]
    case DependencySyncStatusPending = 'dependency-sync-status.pending';

    #[IconDefinition('heroicon-o-arrow-path', 'Lockfile sync status "processing": an uploaded lockfile is being parsed.')]
    case DependencySyncStatusProcessing = 'dependency-sync-status.processing';

    #[IconDefinition('heroicon-o-check-circle', 'Lockfile sync status "completed": the lockfile was parsed and packages stored.')]
    case DependencySyncStatusCompleted = 'dependency-sync-status.completed';

    #[IconDefinition('heroicon-o-x-circle', 'Lockfile sync status "failed": the lockfile could not be processed.')]
    case DependencySyncStatusFailed = 'dependency-sync-status.failed';

    // -- Alert channel --

    #[IconDefinition('heroicon-o-envelope', 'Alert channel "email": vulnerability alerts are sent by email.')]
    case AlertChannelEmail = 'alert-channel.email';

    #[IconDefinition('heroicon-o-chat-bubble-left-right', 'Alert channel "Slack": vulnerability alerts are posted to a Slack channel.')]
    case AlertChannelSlack = 'alert-channel.slack';

    // -- Alert frequency --

    #[IconDefinition('heroicon-o-bolt', 'Alert frequency "immediate": an alert is sent as soon as a vulnerability is found.')]
    case AlertFrequencyImmediate = 'alert-frequency.immediate';

    #[IconDefinition('heroicon-o-calendar-days', 'Alert frequency "daily": alerts are bundled into a daily digest.')]
    case AlertFrequencyDaily = 'alert-frequency.daily';

    #[IconDefinition('heroicon-o-calendar', 'Alert frequency "weekly": alerts are bundled into a weekly digest.')]
    case AlertFrequencyWeekly = 'alert-frequency.weekly';

    // -- Audit run trigger --

    #[IconDefinition('heroicon-o-arrow-path', 'Audit run trigger "post sync": the scan ran after a new lockfile was uploaded.')]
    case AuditRunTriggerPostSync = 'audit-run-trigger.post-sync';

    #[IconDefinition('heroicon-o-moon', 'Audit run trigger "nightly": the scan ran as part of the scheduled nightly sweep.')]
    case AuditRunTriggerNightly = 'audit-run-trigger.nightly';

    #[IconDefinition('heroicon-o-hand-raised', 'Audit run trigger "manual": a user started the scan by hand.')]
    case AuditRunTriggerManual = 'audit-run-trigger.manual';

    // -- Dependency kind --

    #[IconDefinition('heroicon-o-arrow-down', 'Dependency graph edge "dependency": a regular required dependency.')]
    case DependencyKindDependency = 'dependency-kind.dependency';

    #[IconDefinition('heroicon-o-question-mark-circle', 'Dependency graph edge "optional": an optional dependency that may not be installed.')]
    case DependencyKindOptional = 'dependency-kind.optional';

    #[IconDefinition('heroicon-o-link', 'Dependency graph edge "peer": a peer dependency the consumer must provide.')]
    case DependencyKindPeer = 'dependency-kind.peer';

    // -- Vulnerability source --

    #[IconDefinition('heroicon-o-shield-exclamation', 'Vulnerability source "OSV": the advisory comes from the OSV.dev database.')]
    case VulnerabilitySourceOsv = 'vulnerability-source.osv';

    #[IconDefinition('heroicon-o-code-bracket-square', 'Vulnerability source "GitHub Advisory": the advisory comes from the GitHub Advisory Database.')]
    case VulnerabilitySourceGithubAdvisory = 'vulnerability-source.github-advisory';

    #[IconDefinition('heroicon-o-pencil-square', 'Vulnerability source "manual": the advisory was entered by hand.')]
    case VulnerabilitySourceManual = 'vulnerability-source.manual';

    // -- Environment issues --

    #[IconDefinition('heroicon-o-x-circle', 'Environment health issue: the last lockfile sync failed. Shown in health columns and callouts.')]
    case EnvironmentIssueSyncFailed = 'environment-issue.sync-failed';

    #[IconDefinition('heroicon-o-x-circle', 'Environment health issue: the last vulnerability scan failed. Shown in health columns and callouts.')]
    case EnvironmentIssueScanFailed = 'environment-issue.scan-failed';

    #[IconDefinition('heroicon-o-exclamation-triangle', 'Environment health issue: the last lockfile sync finished with warnings. Shown in health columns and callouts.')]
    case EnvironmentIssueSyncWarning = 'environment-issue.sync-warning';

    #[IconDefinition('heroicon-o-information-circle', 'Environment health issue: no lockfile has ever been synced. Shown in health columns and callouts.')]
    case EnvironmentIssueNeverSynced = 'environment-issue.never-synced';

    // -- Misc enums --

    #[IconDefinition('heroicon-o-exclamation-triangle', 'Warning recorded while syncing a lockfile (e.g. unsupported format, skipped file).')]
    case SyncWarning = 'sync-warning';

    #[IconDefinition('heroicon-o-cube', 'Package type "Composer": a PHP package from Packagist.')]
    case PackageTypeComposer = 'package-type.composer';

    #[IconDefinition('heroicon-o-cube', 'Package type "npm": a JavaScript package from the npm registry.')]
    case PackageTypeNpm = 'package-type.npm';

    /**
     * The Filament icon alias, e.g. "voight::severity.critical".
     */
    public function alias(): string
    {
        return self::ALIAS_PREFIX . $this->value;
    }

    /**
     * The icon to render: a registered Filament alias override, else the default.
     */
    public function resolve(): string | BackedEnum | Htmlable
    {
        return FilamentIcon::resolve($this->alias()) ?? $this->default();
    }

    public function isOverridden(): bool
    {
        return FilamentIcon::resolve($this->alias()) !== null;
    }

    public function default(): string | BackedEnum
    {
        return $this->definition()->default;
    }

    public function description(): string
    {
        return $this->definition()->description;
    }

    /**
     * The config overrides as Filament icon aliases; keys that match no case are ignored.
     *
     * @return array<string, string|BackedEnum|Htmlable>
     */
    public static function configOverrides(): array
    {
        $overrides = [];

        foreach (FilamentVoight::config()->getIconOverrides() as $value => $icon) {
            $case = self::tryFrom((string) $value);

            if ($case !== null) {
                $overrides[$case->alias()] = $icon;
            }
        }

        return $overrides;
    }

    private function definition(): IconDefinition
    {
        /** @var array<string, IconDefinition> $definitions */
        static $definitions = [];

        return $definitions[$this->name] ??= (new ReflectionEnumBackedCase(self::class, $this->name))
            ->getAttributes(IconDefinition::class)[0]
            ->newInstance();
    }
}
