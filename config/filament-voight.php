<?php

use Statikbe\FilamentVoight\Http\Middleware\AuthenticateProjectToken;
use Statikbe\FilamentVoight\Http\Middleware\EnsureMcpUserToken;
use Statikbe\FilamentVoight\Models\AlertRecipient;
use Statikbe\FilamentVoight\Models\AlertSetting;
use Statikbe\FilamentVoight\Models\AuditFinding;
use Statikbe\FilamentVoight\Models\AuditRun;
use Statikbe\FilamentVoight\Models\Customer;
use Statikbe\FilamentVoight\Models\DependencySync;
use Statikbe\FilamentVoight\Models\Environment;
use Statikbe\FilamentVoight\Models\EnvironmentPackage;
use Statikbe\FilamentVoight\Models\EnvironmentSystemDetail;
use Statikbe\FilamentVoight\Models\Package;
use Statikbe\FilamentVoight\Models\Project;
use Statikbe\FilamentVoight\Models\Team;
use Statikbe\FilamentVoight\Models\Vulnerability;
use Statikbe\FilamentVoight\Models\VulnerablePackageRange;

// config for Statikbe/FilamentVoight
return [
    /*
    |--------------------------------------------------------------------------
    | Panel
    |--------------------------------------------------------------------------
    |
    | Configuration for the standalone Voight panel. To use it, register
    | FilamentVoightPanelProvider in your app's service providers.
    | Alternatively, register FilamentVoightPlugin in your existing panel.
    |
    */
    'panel' => [
        'path' => 'voight',
    ],

    'lockfiles' => [
        'disk' => env('VOIGHT_LOCKFILES_DISK', 'voight-lockfiles'),
        'allowed_names' => [
            'composer.lock',
            'package-lock.json',
            'yarn.lock',
            'pnpm-lock.yaml',
            'package.json',
            'composer.json',
        ],
    ],

    'api' => [
        'middleware' => [AuthenticateProjectToken::class],
    ],

    /*
    |--------------------------------------------------------------------------
    | MCP server
    |--------------------------------------------------------------------------
    |
    | Lets AI clients (Claude Code, Cursor, ...) query Voight. When enabled, a
    | local server is available through `php artisan mcp:start voight` and a
    | remote one at `path`. Remote users authenticate with a personal access
    | token from the "MCP access" page; the user model needs HasApiTokens.
    | `write_tools` exposes the tools that create projects and project tokens.
    |
    */
    'mcp' => [
        'enabled' => (bool) env('VOIGHT_MCP_ENABLED', false),
        'path' => 'mcp/voight',
        'middleware' => ['auth:sanctum', EnsureMcpUserToken::class],
        'write_tools' => (bool) env('VOIGHT_MCP_WRITE_TOOLS', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | OSV Scanner Lambda
    |--------------------------------------------------------------------------
    |
    | URL and bearer token for the voight-osv-scanner-lambda endpoint.
    | The voight app calls this on its daily cron and after each sync.
    |
    */
    'scanner' => [
        'url' => env('VOIGHT_SCANNER_URL'),
        'token' => env('VOIGHT_SCANNER_TOKEN'),

        /*
        | Where the scanner posts completion callbacks. Unset uses the package's
        | own route; an empty string disables callbacks (polling still works),
        | e.g. for local development the scanner cannot reach.
        */
        'callback_url' => env('VOIGHT_SCANNER_CALLBACK_URL'),
        'callback_secret' => env('VOIGHT_SCANNER_CALLBACK_SECRET'),

        // Packages per nightly batch: 1..8000 (the scanner's limit); clamped into range.
        'batch_size' => (int) env('VOIGHT_SCANNER_BATCH_SIZE', 5000),
        'poll_interval' => (int) env('VOIGHT_SCANNER_POLL_INTERVAL', 15),
        'max_resubmits' => (int) env('VOIGHT_SCANNER_MAX_RESUBMITS', 2),

        /*
        | The hourly voight:reap-stranded-scans command fails scans still pending
        | or processing after this many minutes, and nightly sweeps still scanning
        | or recording after this many minutes, recording failed audit runs.
        */
        'reap_scans_after_minutes' => (int) env('VOIGHT_SCANNER_REAP_SCANS_AFTER_MINUTES', 120),
        'reap_sweeps_after_minutes' => (int) env('VOIGHT_SCANNER_REAP_SWEEPS_AFTER_MINUTES', 360),

        /*
        | Cron expression for the nightly deduplicated OSV scan. Defaults to
        | midnight daily. Set to any valid cron expression (e.g. '0 2 * * *'
        | for 02:00) or an empty string to disable the automatic schedule.
        */
        'nightly_cron' => env('VOIGHT_SCANNER_NIGHTLY_CRON', '0 0 * * *'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Notifications
    |--------------------------------------------------------------------------
    |
    | Configuration for the audit alert notifications (email + Slack). The
    | Slack bot token itself lives in the host app's config/services.php
    | (services.slack.notifications); only the default channel is set here.
    |
    */
    'notifications' => [
        'slack_default_channel' => env('VOIGHT_SLACK_CHANNEL'),
        'mailer' => env('VOIGHT_ALERT_MAILER'),
        'mail_from_address' => env('VOIGHT_ALERT_MAIL_FROM'),
        'mail_from_name' => env('VOIGHT_ALERT_MAIL_FROM_NAME'),
        'panel_id' => 'voight',
        'queue' => env('VOIGHT_ALERTS_QUEUE'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Models
    |--------------------------------------------------------------------------
    |
    | Override the default models used by the package. This allows host
    | applications to extend the models with custom behavior.
    |
    */
    'models' => [
        // 'customer' => \Statikbe\FilamentVoight\Models\Customer::class,
        // 'project' => \Statikbe\FilamentVoight\Models\Project::class,
        // 'environment' => \Statikbe\FilamentVoight\Models\Environment::class,
        // 'package' => \Statikbe\FilamentVoight\Models\Package::class,
        // 'environment_package' => \Statikbe\FilamentVoight\Models\EnvironmentPackage::class,
        // 'dependency_sync' => \Statikbe\FilamentVoight\Models\DependencySync::class,
        // 'environment_system_detail' => \Statikbe\FilamentVoight\Models\EnvironmentSystemDetail::class,
        // 'vulnerability' => \Statikbe\FilamentVoight\Models\Vulnerability::class,
        // 'vulnerable_package_range' => \Statikbe\FilamentVoight\Models\VulnerablePackageRange::class,
        // 'audit_run' => \Statikbe\FilamentVoight\Models\AuditRun::class,
        // 'audit_finding' => \Statikbe\FilamentVoight\Models\AuditFinding::class,
        // 'osv_scan' => \Statikbe\FilamentVoight\Models\OsvScan::class,
        // 'nightly_sweep' => \Statikbe\FilamentVoight\Models\NightlySweep::class,
        // 'alert_setting' => \Statikbe\FilamentVoight\Models\AlertSetting::class,
        // 'user' => \App\Models\User::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Morph Map
    |--------------------------------------------------------------------------
    |
    | Morph map aliases for the package models. These are registered
    | automatically via Relation::morphMap(). Override to customize
    | the aliases used in polymorphic relationships (e.g. Sanctum tokens).
    |
    */
    'morph_map' => [
        'voight-customer' => Customer::class,
        'voight-project' => Project::class,
        'voight-environment' => Environment::class,
        'voight-package' => Package::class,
        'voight-environment-package' => EnvironmentPackage::class,
        'voight-dependency-sync' => DependencySync::class,
        'voight-environment-system-detail' => EnvironmentSystemDetail::class,
        'voight-vulnerability' => Vulnerability::class,
        'voight-vulnerable-package-range' => VulnerablePackageRange::class,
        'voight-audit-run' => AuditRun::class,
        'voight-audit-finding' => AuditFinding::class,
        'voight-alert-setting' => AlertSetting::class,
        'voight-alert-recipient' => AlertRecipient::class,
        'voight-team' => Team::class,
    ],
];
