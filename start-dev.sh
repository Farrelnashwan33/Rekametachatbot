#!/usr/bin/env zsh
set -e

ROOT_DIR="$(cd "$(dirname "$0")" && pwd)"

echo "Starting Rekametachat Backend API on http://127.0.0.1:8000 ..."
(cd "$ROOT_DIR/backend" && php artisan serve --host=127.0.0.1 --port=8000) &
BACKEND_PID=$!

cleanup() {
  echo "\nStopping Rekametachat Backend (PID $BACKEND_PID)..."
  kill "$BACKEND_PID" 2>/dev/null || true
}
trap cleanup EXIT INT TERM

echo "Starting Rekametachat Frontend on http://localhost:5173 ..."
cd "$ROOT_DIR/frontend" && npm run dev -- --port 5173

