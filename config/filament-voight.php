<?php

use Statikbe\FilamentVoight\Http\Middleware\AuthenticateProjectToken;
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
    | Push (client apps)
    |--------------------------------------------------------------------------
    |
    | Used by `voight:push-system-details` on the app being monitored. Same
    | variables as voight.sh. Leave base_url empty to disable pushing. The
    | environment name must match the one voight.sh sends; null = APP_ENV.
    |
    */
    'push' => [
        'base_url' => env('VOIGHT_API_BASE_URL'),
        'token' => env('VOIGHT_API_TOKEN'),
        'environment' => env('VOIGHT_PUSH_ENVIRONMENT'),
        // Base URL this app uses to call itself through the web server, so PHP ini values are the real web ones.
        // Null = app.url. In DDEV use http://localhost (the container does not trust the local certificate).
        'loopback_url' => env('VOIGHT_PUSH_LOOPBACK_URL'),
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
        'packages_url' => env('VOIGHT_SCANNER_PACKAGES_URL'),
        'token' => env('VOIGHT_SCANNER_TOKEN'),
        'batch_size' => (int) env('VOIGHT_SCANNER_BATCH_SIZE', 5000),

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
