#!/usr/bin/env bash
set -euo pipefail

# Launches a disposable WordPress + WooCommerce store with this plugin
# auto-mounted, via WordPress Playground. See TESTING.md for the full
# manual test walkthrough.
#
# Defaults to local so you can never accidentally run a test session
# against production without explicitly asking for it.
#
# Usage:
#   bin/dev.sh              # plugin talks to http://localhost:8000
#   bin/dev.sh --local-api   #   (same — explicit form)
#                            # (run `php artisan serve` in skin-ai-api first)
#   bin/dev.sh --production  # plugin talks to the real skin-ai-api (prod)

cd "$(dirname "$0")/.."

if [[ "${1:-}" == "--production" ]]; then
  BLUEPRINT="bin/dev-blueprint.json"
  TARGET="production (https://api.oysterskin.com)"
else
  BLUEPRINT="bin/dev-blueprint.local.json"
  TARGET="local (http://localhost:8000 — make sure 'php artisan serve' is running in skin-ai-api)"
fi

echo "==> Launching Playground — plugin will talk to: $TARGET"
echo "==> (blueprint: $BLUEPRINT)"
npx @wp-playground/cli@latest server \
  --auto-mount \
  --blueprint="$BLUEPRINT" \
  --php=8.1
