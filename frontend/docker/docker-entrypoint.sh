#!/bin/sh
set -e

# Named volume can keep a stale node_modules after package.json changes.
# Reinstall when the Tailwind Vite plugin (or node_modules) is missing.
if [ ! -d node_modules/@tailwindcss/vite ]; then
  echo "Installing frontend dependencies into node_modules volume..."
  if [ -f package-lock.json ]; then
    npm ci
  else
    npm install
  fi
fi

exec "$@"
