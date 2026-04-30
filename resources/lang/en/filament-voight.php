<?php

return [
    // Panel
    'panel' => [
        'brand_name' => 'Voight',
    ],

    // Navigation
    'navigation' => [
        'management' => 'Management',
        'dependencies' => 'Dependencies',
        'security' => 'Security',
    ],

    // Common fields
    'fields' => [
        'created_at' => 'Created',
        'updated_at' => 'Updated',
    ],

    // Enums
    'enums' => [
        'package_type' => [
            'composer' => 'Composer',
            'npm' => 'NPM',
        ],
        'dependency_sync_status' => [
            'pending' => 'Pending',
            'processing' => 'Processing',
            'completed' => 'Completed',
            'failed' => 'Failed',
        ],
        'audit_run_status' => [
            'pending' => 'Pending',
            'running' => 'Running',
            'completed' => 'Completed',
            'failed' => 'Failed',
        ],
        'vulnerability_source' => [
            'osv' => 'OSV',
            'github_advisory' => 'GitHub Advisory',
            'manual' => 'Manual',
        ],
        'alert_channel' => [
            'email' => 'Email',
            'slack' => 'Slack',
        ],
        'alert_frequency' => [
            'immediate' => 'Immediate',
            'daily' => 'Daily',
            'weekly' => 'Weekly',
        ],
        'severity' => [
            'none' => 'None',
            'low' => 'Low',
            'medium' => 'Medium',
            'high' => 'High',
            'critical' => 'Critical',
        ],
    ],

    // Models
    'models' => [
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
                'email' => 'Email',
                'select_user' => 'Select a user',
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
                'active_tokens' => 'Active Tokens',
                'active_tokens_count' => 'token(s) active',
                'no_tokens' => 'No API tokens have been generated yet.',
                'token_name' => 'Name',
                'token_last_used' => 'Last Used',
                'never_used' => 'Never',
            ],
            'sections' => [
                'general' => 'General',
                'assignment' => 'Assignment',
                'settings' => 'Settings',
                'api_token' => 'API Token',
                'api_token_description' => 'Generate tokens for CI/CD pipelines to sync lockfiles.',
            ],
            'actions' => [
                'generate_token' => 'Generate',
                'token_generated' => 'Token generated — copy it now, it won\'t be shown again',
                'revoke_tokens' => 'Revoke All',
                'tokens_revoked' => 'All tokens revoked',
                'copy_token' => 'Copy token'
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
            'actions' => [
                'open_website' => 'Open Website',
            ],
            'view' => [
                'installations_title' => 'Installations',
                'active_findings_title' => 'Active Findings',
                'known_vulnerabilities_title' => 'Known Vulnerabilities',
                'installed_summary' => ':environments environment(s) across :projects project(s)',
                'no_active_findings' => 'None',
                'filters' => [
                    'latest_only' => 'Latest scan only',
                    'latest_only_true' => 'Latest scan only',
                    'latest_only_false' => 'All history',
                    'observed_at' => 'Observed',
                    'observed_from' => 'From',
                    'observed_until' => 'Until',
                ],
                'columns' => [
                    'project' => 'Project',
                    'environment' => 'Environment',
                    'installed_version' => 'Installed Version',
                    'fixed_version' => 'Fixed Version',
                    'affected_range' => 'Affected Range',
                    'cvss' => 'CVSS',
                    'severity' => 'Severity',
                    'source_id' => 'ID',
                    'summary' => 'Summary',
                    'observed' => 'Observed',
                    'direct' => 'Direct',
                    'dev' => 'Dev',
                    'last_scan' => 'Last Scan',
                    'published' => 'Published',
                    'modified' => 'Modified',
                    'source' => 'Source',
                ],
                'header' => [
                    'installed_in' => 'Installed in',
                    'active_findings' => 'Active Findings',
                ],
                'empty' => [
                    'no_active_findings_heading' => 'All clear',
                    'no_active_findings_description' => 'No vulnerabilities were found in the latest audit runs across any environment using this package.',
                    'no_known_vulnerabilities_heading' => 'No known vulnerabilities',
                    'no_known_vulnerabilities_description' => 'There are no recorded vulnerabilities affecting this package.',
                ],
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
    ],
];
