#!/bin/sh
#
# Content Seed Startup Script
#
# This script is run during Docker container startup to seed the content
# table with initial data from the seed file if available.
#
# The seed file is expected at: /var/www/html/database/seeds/content-seed.json
#
# Existing content rows are skipped to ensure only new content is added.
# After seeding, base URLs are fixed to match the current environment.
#

SEED_FILE="/var/www/html/database/seeds/content-seed.json"
IMPORT_SCRIPT="/var/www/html/scripts/import-content-seed.php"
FIX_URLS_SCRIPT="/var/www/html/scripts/fix-content-base-urls.php"
BACKFILL_IMAGES_SCRIPT="/var/www/html/scripts/backfill-shared-images.php"
LOG_PREFIX="[content-seed]"

echo "$LOG_PREFIX Starting content seed check..."

# Check if seed file exists
if [ ! -f "$SEED_FILE" ]; then
    echo "$LOG_PREFIX No seed file found at $SEED_FILE, skipping seed import."
    # Still run URL fix in case content was added through other means
    if [ -f "$FIX_URLS_SCRIPT" ]; then
        echo "$LOG_PREFIX Running base URL fix..."
        php "$FIX_URLS_SCRIPT" --verbose 2>&1 || true
    fi
    # Backfill any missing shared images
    if [ -f "$BACKFILL_IMAGES_SCRIPT" ]; then
        echo "$LOG_PREFIX Running shared images backfill..."
        php "$BACKFILL_IMAGES_SCRIPT" --verbose 2>&1 || true
    fi
    exit 0
fi

# Check if import script exists
if [ ! -f "$IMPORT_SCRIPT" ]; then
    echo "$LOG_PREFIX Import script not found at $IMPORT_SCRIPT, skipping seed import."
    exit 0
fi

echo "$LOG_PREFIX Seed file found, running import..."

# Wait for database to be ready (simple retry loop)
MAX_RETRIES=30
RETRY_INTERVAL=2
RETRY_COUNT=0

while [ $RETRY_COUNT -lt $MAX_RETRIES ]; do
    # Try to run the import script
    php "$IMPORT_SCRIPT" --verbose 2>&1
    RESULT=$?

    if [ $RESULT -eq 0 ]; then
        echo "$LOG_PREFIX Content seed import completed successfully."

        # Fix base URLs to match current environment
        if [ -f "$FIX_URLS_SCRIPT" ]; then
            echo "$LOG_PREFIX Running base URL fix..."
            php "$FIX_URLS_SCRIPT" --verbose 2>&1 || true
        fi

        # Backfill any missing shared images (downloads from login.phishme.com)
        if [ -f "$BACKFILL_IMAGES_SCRIPT" ]; then
            echo "$LOG_PREFIX Running shared images backfill..."
            php "$BACKFILL_IMAGES_SCRIPT" --verbose 2>&1 || true
        fi

        exit 0
    fi

    # Check if it's a database connection error (exit code 1 with connection message)
    # If so, wait and retry
    RETRY_COUNT=$((RETRY_COUNT + 1))

    if [ $RETRY_COUNT -lt $MAX_RETRIES ]; then
        echo "$LOG_PREFIX Import failed (attempt $RETRY_COUNT/$MAX_RETRIES), retrying in ${RETRY_INTERVAL}s..."
        sleep $RETRY_INTERVAL
    fi
done

echo "$LOG_PREFIX Content seed import failed after $MAX_RETRIES attempts."
# Don't exit with error code to avoid blocking container startup
exit 0
