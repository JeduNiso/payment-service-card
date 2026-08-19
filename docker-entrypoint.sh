#!/bin/bash
set -e

# Run migrations
php artisan migrate --force

# Start the normal container
exec /start-container.sh
