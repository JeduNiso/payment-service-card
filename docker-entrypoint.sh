#!/bin/bash
set -e

# Run migrations
php artisan migrate --force --verbose

# Verify the cache table exists
php artisan tinker --execute="echo \Illuminate\Support\Facades\DB::table('cache')->count()"

# Start the normal container
exec /start-container.sh
