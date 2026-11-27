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

    #[IconDefinition(Heroicon::OutlinedCommandLine, 'Sidebar navigation item for the MCP access page, where users connect AI assistants to Voight.')]
    case NavigationMcpAccess = 'navigation.mcp-access';

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

    #[IconDefinition(Heroicon::OutlinedBolt, 'Tab on a tag page listing its auto-tag rules.')]
    case TabTagRules = 'tab.tag.rules';

    #[IconDefinition(Heroicon::OutlinedFolder, 'Tab on a customer page listing the projects of that customer.')]
    case TabCustomerProjects = 'tab.customer.projects';

    // -- List page tabs --

    #[IconDefinition(Heroicon::OutlinedQueueList, 'Preset tab on a list page showing all records, without a preset filter.')]
    case ListTabAll = 'list-tab.all';

    #[IconDefinition(Heroicon::OutlinedClock, 'Preset tab on the audit runs list showing only the latest run of each environment.')]
    case ListTabAuditRunsLatestPerEnvironment = 'list-tab.audit-runs.latest-per-environment';

    #[IconDefinition(Heroicon::OutlinedXCircle, 'Preset tab on the audit runs list showing only failed runs.')]
    case ListTabAuditRunsFailed = 'list-tab.audit-runs.failed';

    #[IconDefinition(Heroicon::OutlinedUserGroup, 'Preset tab on the projects list showing the projects of the current user\'s teams.')]
    case ListTabProjectsMyTeam = 'list-tab.projects.my-team';

    #[IconDefinition(Heroicon::OutlinedFire, 'Preset tab on the projects list showing projects with current critical or high findings on production.')]
    case ListTabProjectsCriticalHighOnProduction = 'list-tab.projects.critical-high-on-production';

    #[IconDefinition(Heroicon::OutlinedShieldExclamation, 'Preset tab on the vulnerabilities list showing those that currently affect an environment.')]
    case ListTabVulnerabilitiesActive = 'list-tab.vulnerabilities.active';

    #[IconDefinition(Heroicon::OutlinedGlobeAlt, 'Preset tab on the vulnerabilities list showing those that currently affect a production environment.')]
    case ListTabVulnerabilitiesOnProduction = 'list-tab.vulnerabilities.on-production';

    #[IconDefinition(Heroicon::OutlinedShieldExclamation, 'Preset tab on the packages list showing packages with open findings.')]
    case ListTabPackagesVulnerableInUse = 'list-tab.packages.vulnerable-in-use';

    #[IconDefinition(Heroicon::OutlinedFire, 'Preset tab on the open findings list showing critical and high findings on production.')]
    case ListTabOpenFindingsProductionCriticalHigh = 'list-tab.open-findings.production-critical-high';

    #[IconDefinition(Heroicon::OutlinedGlobeAlt, 'Preset tab on the open findings list showing findings on production environments.')]
    case ListTabOpenFindingsProduction = 'list-tab.open-findings.production';

    #[IconDefinition(Heroicon::OutlinedBeaker, 'Preset tab on the open findings list showing findings on non-production environments (staging, test, ...).')]
    case ListTabOpenFindingsOtherStages = 'list-tab.open-findings.other-stages';

    #[IconDefinition(Heroicon::OutlinedGlobeAlt, 'Preset tab on the environments list showing production environments.')]
    case ListTabEnvironmentsProduction = 'list-tab.environments.production';

    #[IconDefinition(Heroicon::OutlinedXCircle, 'Preset tab on the environments list showing environments whose last sync or scan failed.')]
    case ListTabEnvironmentsFailing = 'list-tab.environments.failing';

    #[IconDefinition(Heroicon::OutlinedExclamationTriangle, 'Preset tab on the environments list showing environments whose last sync had warnings.')]
    case ListTabEnvironmentsWarnings = 'list-tab.environments.warnings';

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

    #[IconDefinition(Heroicon::OutlinedBookmarkSquare, 'Header action on a filtered list table saving the filter as an auto-tag rule on a tag.')]
    case ActionSaveTagRule = 'action.save-tag-rule';

    #[IconDefinition(Heroicon::OutlinedTableCells, 'Action on an auto-tag rule opening its source table with the saved filter applied.')]
    case ActionOpenTagRuleInTable = 'action.open-tag-rule-in-table';

    #[IconDefinition(Heroicon::OutlinedPause, 'Action pausing an auto-tag rule: it stops running and its tags stay.')]
    case ActionPauseTagRule = 'action.pause-tag-rule';

    #[IconDefinition(Heroicon::OutlinedPlay, 'Action resuming a paused auto-tag rule.')]
    case ActionResumeTagRule = 'action.resume-tag-rule';

    #[IconDefinition(Heroicon::OutlinedArrowPath, 'Action retrying a failed auto-tag rule\'s query.')]
    case ActionRetryTagRule = 'action.retry-tag-rule';

    #[IconDefinition(Heroicon::OutlinedTrash, 'Action deleting an auto-tag rule, optionally with the tags it added.')]
    case ActionDeleteTagRule = 'action.delete-tag-rule';

    // -- Table filters --

    #[IconDefinition(Heroicon::Flag, 'Query-builder filter constraint on environment stage (production, staging, ...).')]
    case FilterEnvironmentStage = 'filter.environment-stage';

    #[IconDefinition(Heroicon::Hashtag, 'Query-builder filter constraint on an installed package version range.')]
    case FilterInstalledVersion = 'filter.installed-version';

    #[IconDefinition(Heroicon::Cube, 'Query-builder filter constraint on an installed package, optionally within a version range.')]
    case FilterInstalledPackage = 'filter.installed-package';

    #[IconDefinition(Heroicon::Tag, 'Query-builder filter constraint on tags of one tag type, on the record or a related model.')]
    case FilterTag = 'filter.tag';

    #[IconDefinition(Heroicon::Folder, 'Query-builder filter constraint on a project, by name or selection.')]
    case FilterProject = 'filter.project';

    #[IconDefinition(Heroicon::Identification, 'Query-builder filter constraint on a project code.')]
    case FilterProjectCode = 'filter.project-code';

    #[IconDefinition(Heroicon::BuildingOffice, 'Query-builder filter constraint on the customer a project belongs to.')]
    case FilterCustomer = 'filter.customer';

    #[IconDefinition(Heroicon::UserGroup, 'Query-builder filter constraint on the team responsible for a project.')]
    case FilterTeam = 'filter.team';

    #[IconDefinition(Heroicon::ServerStack, 'Query-builder filter constraint on environments: by name, or how many a project has.')]
    case FilterEnvironment = 'filter.environment';

    #[IconDefinition(Heroicon::Cube, 'Query-builder filter constraint on a package name, or on the affected packages of a vulnerability.')]
    case FilterPackage = 'filter.package';

    #[IconDefinition(Heroicon::Squares2x2, 'Query-builder filter constraint on package type (Composer, npm).')]
    case FilterPackageType = 'filter.package-type';

    #[IconDefinition(Heroicon::Hashtag, 'Query-builder filter constraint on a version string: installed, latest or affected range.')]
    case FilterVersion = 'filter.version';

    #[IconDefinition(Heroicon::Wrench, 'Query-builder filter constraint on the fixed version; "is filled" means a fix is available.')]
    case FilterFixedVersion = 'filter.fixed-version';

    #[IconDefinition(Heroicon::CodeBracket, 'Query-builder filter constraint on the PHP version of a project or environment.')]
    case FilterPhpVersion = 'filter.php-version';

    #[IconDefinition(Heroicon::ArrowUpCircle, 'Query-builder filter constraint on a project\'s upgrade status (how far behind its stack is).')]
    case FilterUpgradeStatus = 'filter.upgrade-status';

    #[IconDefinition(Heroicon::ExclamationTriangle, 'Query-builder filter constraint on vulnerability severity (critical, high, ...).')]
    case FilterSeverity = 'filter.severity';

    #[IconDefinition(Heroicon::ChartBar, 'Query-builder filter constraint on the numeric vulnerability (CVSS) score.')]
    case FilterVulnerabilityScore = 'filter.vulnerability-score';

    #[IconDefinition(Heroicon::Newspaper, 'Query-builder filter constraint on the advisory source of a vulnerability (OSV, GitHub, manual).')]
    case FilterVulnerabilitySource = 'filter.vulnerability-source';

    #[IconDefinition(Heroicon::FingerPrint, 'Query-builder filter constraint on the advisory identifier (e.g. CVE or GHSA id).')]
    case FilterVulnerabilityId = 'filter.vulnerability-id';

    #[IconDefinition(Heroicon::DocumentText, 'Query-builder filter constraint on the vulnerability summary text.')]
    case FilterSummary = 'filter.summary';

    #[IconDefinition(Heroicon::ShieldExclamation, 'Query-builder filter constraint on related vulnerabilities, e.g. how many known vulnerabilities a package has.')]
    case FilterVulnerabilities = 'filter.vulnerabilities';

    #[IconDefinition(Heroicon::BugAnt, 'Query-builder filter constraint on related audit findings, e.g. how many a run or package has.')]
    case FilterFindings = 'filter.findings';

    #[IconDefinition(Heroicon::ArrowDownTray, 'Query-builder filter constraint on how many environments install a package.')]
    case FilterInstallations = 'filter.installations';

    #[IconDefinition(Heroicon::Signal, 'Query-builder filter constraint on an audit run status (pending, running, completed, failed).')]
    case FilterAuditRunStatus = 'filter.audit-run-status';

    #[IconDefinition(Heroicon::Bolt, 'Query-builder filter constraint on what triggered an audit run (sync, nightly, manual).')]
    case FilterAuditRunTrigger = 'filter.audit-run-trigger';

    #[IconDefinition(Heroicon::ArrowPath, 'Query-builder filter constraint on the latest lockfile sync status of an environment.')]
    case FilterSyncStatus = 'filter.sync-status';

    #[IconDefinition(Heroicon::Calendar, 'Query-builder filter constraint on a date: created, started, published, scanned, ...')]
    case FilterDate = 'filter.date';

    #[IconDefinition(Heroicon::BellSlash, 'Query-builder filter constraint on whether a project\'s alerts are muted.')]
    case FilterMuted = 'filter.muted';

    #[IconDefinition(Heroicon::ArrowDown, 'Query-builder filter constraint on whether a package is a direct (not transitive) dependency.')]
    case FilterDirectDependency = 'filter.direct-dependency';

    #[IconDefinition(Heroicon::Beaker, 'Query-builder filter constraint on whether a package is a dev dependency.')]
    case FilterDevDependency = 'filter.dev-dependency';

    #[IconDefinition(Heroicon::Moon, 'Query-builder filter constraint on whether an environment is included in the nightly scan.')]
    case FilterNightlyScan = 'filter.nightly-scan';

    #[IconDefinition(Heroicon::CpuChip, 'Query-builder filter constraint on whether an environment has sent its system details.')]
    case FilterSystemDetails = 'filter.system-details';

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

    // -- Tag rule status --

    #[IconDefinition('heroicon-o-play-circle', 'Auto-tag rule status "active": the rule keeps its tag on matching records.')]
    case TagRuleStatusActive = 'tag-rule-status.active';

    #[IconDefinition('heroicon-o-pause-circle', 'Auto-tag rule status "paused": the rule is switched off; its tags stay.')]
    case TagRuleStatusPaused = 'tag-rule-status.paused';

    #[IconDefinition('heroicon-o-exclamation-circle', 'Auto-tag rule status "failed": its stored query errored; it no longer runs until retried or saved again.')]
    case TagRuleStatusFailed = 'tag-rule-status.failed';

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
