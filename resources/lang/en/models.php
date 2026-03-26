<?php

return [
    'customer' => [
        'label' => 'Customer',
        'plural' => 'Customers',
        'fields' => [
            'name' => 'Name',
            'slug' => 'Slug',
        ],
    ],
    'team' => [
        'label' => 'Team',
        'plural' => 'Teams',
        'fields' => [
            'name' => 'Name',
            'users' => 'Users',
        ],
    ],
    'project' => [
        'label' => 'Project',
        'plural' => 'Projects',
        'fields' => [
            'project_code' => 'Project Code',
            'name' => 'Name',
            'description' => 'Description',
            'repo_url' => 'Repository URL',
            'customer' => 'Customer',
            'team' => 'Team',
            'is_muted' => 'Muted',
            'is_muted_help' => 'Muted projects will not send alert notifications.',
        ],
        'sections' => [
            'general' => 'General',
            'assignment' => 'Assignment',
            'settings' => 'Settings',
        ],
    ],
    'environment' => [
        'label' => 'Environment',
        'plural' => 'Environments',
        'fields' => [
            'name' => 'Name',
            'scanned_at' => 'Last Scanned',
        ],
        'never_scanned' => 'Never scanned',
    ],
    'package' => [
        'label' => 'Package',
        'plural' => 'Packages',
        'fields' => [
            'name' => 'Name',
            'type' => 'Type',
            'latest_version' => 'Latest Version',
            'latest_version_updated_at' => 'Latest Version Updated',
            'installations' => 'Installations',
        ],
    ],
    'environment_package' => [
        'label' => 'Environment Package',
        'plural' => 'Environment Packages',
        'fields' => [
            'version' => 'Version',
            'is_direct' => 'Direct Dependency',
            'is_dev' => 'Dev Dependency',
            'parent_package' => 'Parent Package',
        ],
    ],
    'dependency_sync' => [
        'label' => 'Dependency Sync',
        'plural' => 'Dependency Syncs',
        'fields' => [
            'lockfile_hash' => 'Lockfile Hash',
            'lockfile_paths' => 'Lockfile Paths',
            'package_count' => 'Package Count',
            'status' => 'Status',
            'error_message' => 'Error Message',
            'synced_at' => 'Synced At',
        ],
    ],
    'vulnerability' => [
        'label' => 'Vulnerability',
        'plural' => 'Vulnerabilities',
        'fields' => [
            'source' => 'Source',
            'source_id' => 'Source ID',
            'aliases' => 'Aliases',
            'summary' => 'Summary',
            'details' => 'Details',
            'vulnerability_score' => 'CVSS Score',
            'severity' => 'Severity',
            'published_at' => 'Published',
            'modified_at' => 'Modified',
        ],
    ],
    'vulnerable_package_range' => [
        'label' => 'Vulnerable Package Range',
        'plural' => 'Vulnerable Package Ranges',
        'fields' => [
            'affected_range' => 'Affected Range',
            'fixed_version' => 'Fixed Version',
        ],
    ],
    'audit_run' => [
        'label' => 'Audit Run',
        'plural' => 'Audit Runs',
        'fields' => [
            'status' => 'Status',
            'started_at' => 'Started',
            'completed_at' => 'Completed',
        ],
    ],
    'audit_finding' => [
        'label' => 'Audit Finding',
        'plural' => 'Audit Findings',
        'fields' => [
            'installed_version' => 'Installed Version',
            'fixed_version' => 'Fixed Version',
        ],
    ],
    'alert_setting' => [
        'label' => 'Alert Setting',
        'plural' => 'Alert Settings',
        'fields' => [
            'channel' => 'Channel',
            'severity_threshold' => 'Severity Threshold',
            'frequency' => 'Frequency',
            'webhook_url' => 'Webhook URL',
            'is_enabled' => 'Enabled',
        ],
    ],
];
