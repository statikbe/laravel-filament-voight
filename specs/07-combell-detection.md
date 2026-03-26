# Combell Detection

## Overview

Integration with Combell hosting-specific security tools. Reads results from Combell's built-in checks for projects hosted on their platform.

## Detection Types

### Password Check
- Checks for weak or compromised passwords in the hosting environment.
- Result: pass/fail/warning with details.

### Maldetect (Malware Detection)
- Scans for known malware signatures in project files.
- Result: pass/fail with list of detected threats.

### PHP Security Checker
- Checks PHP configuration and known vulnerable PHP versions.
- Result: pass/fail/warning with recommendations.

## Data Flow

1. A script on the Combell server runs the checks and sends results to the API.
2. Alternatively, the application polls Combell's control panel API (if available).
3. Results stored as `CombellDetection` records linked to the environment.

## API Endpoint

### POST /api/v1/combell-detection

```json
{
  "project_code": "my-project",
  "environment": "production",
  "detections": [
    {
      "type": "password_check",
      "status": "pass",
      "output": "All passwords meet complexity requirements."
    },
    {
      "type": "maldetect",
      "status": "fail",
      "output": "Detected: PHP/BackDoor.A in /public/uploads/shell.php"
    }
  ]
}
```

## Filament UI

- Detection results shown on environment detail page.
- Status badges: green (pass), red (fail), yellow (warning).
- History of previous detections.
- Combell detections only shown for environments flagged as Combell-hosted.

## Alerting Integration

- Failed Combell detections trigger alerts (same system as vulnerability alerts).
- Severity mapping: `fail` → critical, `warning` → medium.

## Future

- This is Combell-specific. The architecture should allow adding similar integrations for other hosting providers.
