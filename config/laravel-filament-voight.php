<?php

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
        ],
    ],

    'api' => [
        'middleware' => [\Statikbe\FilamentVoight\Http\Middleware\AuthenticateProjectToken::class],
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
        'voight-customer' => \Statikbe\FilamentVoight\Models\Customer::class,
        'voight-project' => \Statikbe\FilamentVoight\Models\Project::class,
        'voight-environment' => \Statikbe\FilamentVoight\Models\Environment::class,
        'voight-package' => \Statikbe\FilamentVoight\Models\Package::class,
        'voight-environment-package' => \Statikbe\FilamentVoight\Models\EnvironmentPackage::class,
        'voight-dependency-sync' => \Statikbe\FilamentVoight\Models\DependencySync::class,
        'voight-vulnerability' => \Statikbe\FilamentVoight\Models\Vulnerability::class,
        'voight-vulnerable-package-range' => \Statikbe\FilamentVoight\Models\VulnerablePackageRange::class,
        'voight-audit-run' => \Statikbe\FilamentVoight\Models\AuditRun::class,
        'voight-audit-finding' => \Statikbe\FilamentVoight\Models\AuditFinding::class,
        'voight-alert-setting' => \Statikbe\FilamentVoight\Models\AlertSetting::class,
    ],
];
