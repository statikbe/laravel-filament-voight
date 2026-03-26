#!/usr/bin/env bash
set -euo pipefail

# Load .env file if present
if [[ -f ".env" ]]; then
  set -a
  # shellcheck source=.env
  source .env
  set +a
fi

# Required env vars
: "${API_URL:?API_URL is not set}"
: "${API_TOKEN:?API_TOKEN is not set}"
: "${PROJECT_CODE:?PROJECT_CODE is not set}"
: "${APP_ENV:?APP_ENV is not set}"

# ── Collect lock files ────────────────────────────────────────────────────────

echo ""
echo "Collecting lock files..."

declare -a LOCK_FILES=(
  "yarn.lock"
  "package-lock.json"
  "pnpm-lock.yaml"
  "bun.lock"
  "composer.lock"
)

declare -a FOUND_LOCKS=()
for lock in "${LOCK_FILES[@]}"; do
  if [[ -f "$lock" ]]; then
    FOUND_LOCKS+=("$lock")
    echo "  Found: $lock"
  fi
done

if [[ ${#FOUND_LOCKS[@]} -eq 0 ]]; then
  echo "No lock files found. Exiting."
  exit 0
fi

# ── Send to API ───────────────────────────────────────────────────────────────

echo ""
echo "Sending lock files to API..."

# Build multipart form-data with all lock files
CURL_ARGS=(
  -s -w "\n%{http_code}"
  -X POST "$API_URL"
  -H "Authorization: Bearer $API_TOKEN"
  -F "project_code=$PROJECT_CODE"
  -F "environment=$APP_ENV"
)

for lock in "${FOUND_LOCKS[@]}"; do
  # Use the filename as the field name (dots replaced with underscores)
  field_name="${lock//[.\/]/_}"
  CURL_ARGS+=(-F "${field_name}=@${lock};filename=${lock}")
done

RESPONSE=$(curl "${CURL_ARGS[@]}")
HTTP_STATUS=$(echo "$RESPONSE" | tail -n1)
BODY=$(echo "$RESPONSE" | sed '$d')

echo "  HTTP status: $HTTP_STATUS"
if [[ -n "$BODY" ]]; then
  echo "  Response: $BODY"
fi

if [[ "$HTTP_STATUS" -ge 200 && "$HTTP_STATUS" -lt 300 ]]; then
  echo ""
  echo "Done. Lock files sent successfully."
else
  echo ""
  echo "Error: API returned status $HTTP_STATUS" >&2
  exit 1
fi
