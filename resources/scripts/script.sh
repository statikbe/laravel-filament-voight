#!/bin/sh
set -eu

# Read only the required vars from .env
if [ -f ".env" ]; then
  for var in API_URL API_TOKEN PROJECT_CODE APP_ENV; do
    line=$(grep "^${var}=" .env | head -1)
    if [ -n "$line" ]; then
      # Strip optional surrounding single or double quotes from the value
      value=$(printf '%s' "${line#*=}" | sed "s/^['\"]//;s/['\"]$//")
      export "${var}=${value}"
    fi
  done
fi

# Required env vars
: "${API_URL:?API_URL is not set}"
: "${API_TOKEN:?API_TOKEN is not set}"
: "${PROJECT_CODE:?PROJECT_CODE is not set}"
: "${APP_ENV:?APP_ENV is not set}"

# ── Collect lock files ────────────────────────────────────────────────────────

echo ""
echo "Collecting lock files..."

FOUND_LOCKS=""
for lock in yarn.lock package-lock.json pnpm-lock.yaml bun.lock composer.lock; do
  if [ -f "$lock" ]; then
    FOUND_LOCKS="${FOUND_LOCKS}${FOUND_LOCKS:+ }${lock}"
    echo "  Found: $lock"
  fi
done

if [ -z "$FOUND_LOCKS" ]; then
  echo "No lock files found. Exiting."
  exit 0
fi

# ── Send to API ───────────────────────────────────────────────────────────────

echo ""
echo "Sending lock files to API..."

# Build multipart form-data with all lock files
set -- \
  -s -w "\n%{http_code}" \
  -X POST "$API_URL" \
  -H "Authorization: Bearer $API_TOKEN" \
  -F "project_code=$PROJECT_CODE" \
  -F "environment=$APP_ENV"

for lock in $FOUND_LOCKS; do
  field_name=$(printf '%s' "$lock" | tr './' '_')
  set -- "$@" -F "${field_name}=@${lock};filename=${lock}"
done

RESPONSE=$(curl "$@")
HTTP_STATUS=$(printf '%s' "$RESPONSE" | tail -n1)
BODY=$(printf '%s' "$RESPONSE" | sed '$d')

echo "  HTTP status: $HTTP_STATUS"
if [ -n "$BODY" ]; then
  echo "  Response: $BODY"
fi

if [ "$HTTP_STATUS" -ge 200 ] && [ "$HTTP_STATUS" -lt 300 ]; then
  echo ""
  echo "Done. Lock files sent successfully."
else
  echo ""
  echo "Error: API returned status $HTTP_STATUS" >&2
  exit 1
fi
