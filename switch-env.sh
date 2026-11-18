#!/bin/bash
# switch-env.sh — Switch Laravel API between Local MySQL and Cloud Supabase
set -e

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$DIR"

TARGET="$1"

if [ "$TARGET" = "local" ]; then
    if [ ! -f ".env.local" ]; then
        echo "❌ Error: .env.local not found."
        exit 1
    fi
    cp .env.local .env
    php artisan config:clear >/dev/null 2>&1 || true
    echo "=========================================================="
    echo "✅ Switched API to LOCAL mode"
    echo "   • Database:  MySQL (127.0.0.1:3306 / phpMyAdmin: mycovision)"
    echo "   • ML Engine: Local Flask (http://127.0.0.1:5000)"
    echo "   • Credits:   0 Modal credits used"
    echo "=========================================================="
elif [ "$TARGET" = "cloud" ]; then
    if [ ! -f ".env.cloud" ]; then
        echo "❌ Error: .env.cloud not found."
        exit 1
    fi
    cp .env.cloud .env
    php artisan config:clear >/dev/null 2>&1 || true
    echo "=========================================================="
    echo "☁️  Switched API to CLOUD mode"
    echo "   • Database:  Supabase PostgreSQL (aws-0-ap-southeast-1)"
    echo "   • ML Engine: Modal.com (mariaslm05--mycovision-ml)"
    echo "=========================================================="
else
    echo "Usage: ./switch-env.sh [local|cloud]"
    echo "   ./switch-env.sh local  -> Switch to XAMPP MySQL + Local Flask"
    echo "   ./switch-env.sh cloud  -> Switch to Supabase PostgreSQL + Modal ML"
    exit 1
fi

