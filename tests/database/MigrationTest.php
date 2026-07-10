<?php

use Illuminate\Support\Facades\Schema;

it('creates all voight tables', function () {
    $tables = [
        'voight_customers',
        'voight_teams',
        'voight_team_user',
        'voight_projects',
        'voight_environments',
        'voight_packages',
        'voight_environment_packages',
        'voight_dependency_syncs',
        'voight_vulnerabilities',
        'voight_vulnerable_package_ranges',
        'voight_audit_runs',
        'voight_audit_findings',
        'voight_alert_settings',
        'voight_alert_recipients',
    ];

    foreach ($tables as $table) {
        expect(Schema::hasTable($table))->toBeTrue("Table {$table} should exist");
    }
});

it('has expected columns on voight_alert_recipients', function () {
    expect(Schema::hasColumns('voight_alert_recipients', [
        'id', 'alert_setting_id', 'recipient_type', 'recipient_id', 'created_at', 'updated_at',
    ]))->toBeTrue();
});

it('adds slack_channel and last_sent_at columns to voight_alert_settings', function () {
    expect(Schema::hasColumns('voight_alert_settings', [
        'slack_channel', 'last_sent_at',
    ]))->toBeTrue();
});

it('has expected columns on voight_projects', function () {
    expect(Schema::hasColumns('voight_projects', [
        'id', 'project_code', 'name', 'description', 'repo_url',
        'customer_id', 'team_id', 'is_muted', 'created_at', 'updated_at',
    ]))->toBeTrue();
});

it('has expected columns on voight_vulnerabilities', function () {
    expect(Schema::hasColumns('voight_vulnerabilities', [
        'id', 'source', 'source_id', 'aliases', 'summary', 'details',
        'vulnerability_score', 'published_at', 'modified_at', 'created_at', 'updated_at',
    ]))->toBeTrue();
});

it('has expected columns on voight_dependency_syncs', function () {
    expect(Schema::hasColumns('voight_dependency_syncs', [
        'id', 'environment_id', 'lockfile_hash', 'lockfile_paths',
        'package_count', 'status', 'error_message', 'synced_at',
    ]))->toBeTrue();
});
