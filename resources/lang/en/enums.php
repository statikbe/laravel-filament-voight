<?php

return [
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
];
