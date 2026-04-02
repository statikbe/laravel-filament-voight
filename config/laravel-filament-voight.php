<?php

use Statikbe\FilamentVoight\Http\Middleware\AuthenticateProjectToken;
use Statikbe\FilamentVoight\Models\AlertSetting;
use Statikbe\FilamentVoight\Models\AuditFinding;
use Statikbe\FilamentVoight\Models\AuditRun;
use Statikbe\FilamentVoight\Models\Customer;
use Statikbe\FilamentVoight\Models\DependencySync;
use Statikbe\FilamentVoight\Models\Environment;
use Statikbe\FilamentVoight\Models\EnvironmentPackage;
use Statikbe\FilamentVoight\Models\Package;
use Statikbe\FilamentVoight\Models\Project;
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
        ],
    ],

    'api' => [
        'middleware' => [AuthenticateProjectToken::class],
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
        // 'vulnerability' => \Statikbe\FilamentVoight\Models\Vulnerability::class,
        // 'vulnerable_package_range' => \Statikbe\FilamentVoight\Models\VulnerablePackageRange::class,
        // 'audit_run' => \Statikbe\FilamentVoight\Models\AuditRun::class,
        // 'audit_finding' => \Statikbe\FilamentVoight\Models\AuditFinding::class,
        // 'alert_setting' => \Statikbe\FilamentVoight\Models\AlertSetting::class,
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
        'voight-vulnerability' => Vulnerability::class,
        'voight-vulnerable-package-range' => VulnerablePackageRange::class,
        'voight-audit-run' => AuditRun::class,
        'voight-audit-finding' => AuditFinding::class,
        'voight-alert-setting' => AlertSetting::class,
    ],
];
