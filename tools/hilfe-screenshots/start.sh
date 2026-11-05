#!/usr/bin/env bash
#
# One command for everything (docs/spec/12-hilfe.md section 6):
#
#   tools/hilfe-screenshots/start.sh                 # record all guides, both modes
#   tools/hilfe-screenshots/start.sh --pruefen       # only check, write no pictures
#   tools/hilfe-screenshots/start.sh --nur anmelden  # one guide
#   tools/hilfe-screenshots/start.sh --projekt issue-115 --pruefen
#
# Starts an own docker project (default "hilfe") with a free host port,
# migrates, lets aufnehmen.js create the sample data and take the pictures,
# and removes the project again. Needs: docker, node with Playwright
# (README.md), and vendor/ in the repository (composer install).
set -euo pipefail

cd "$(dirname "$0")/../.."

projekt=hilfe
behalten=0
weiter=()
while [ $# -gt 0 ]; do
    case "$1" in
        --projekt)
            projekt="${2:?--projekt braucht einen Namen}"
            shift 2
            ;;
        --behalten)
            behalten=1
            shift
            ;;
        *)
            weiter+=("$1")
            shift
            ;;
    esac
done

if [ ! -f vendor/autoload.php ]; then
    echo "vendor/ fehlt. Zuerst: docker compose run --rm app composer install" >&2
    exit 2
fi

compose=(docker compose -p "$projekt" -f docker-compose.yml -f tools/hilfe-screenshots/compose.override.yml)

aufraeumen() {
    if [ "$behalten" = 0 ]; then
        "${compose[@]}" down -v >/dev/null 2>&1 || true
    fi
}
trap aufraeumen EXIT

"${compose[@]}" up -d --build --wait
"${compose[@]}" exec -T app php bin/migrate.php

node tools/hilfe-screenshots/aufnehmen.js --projekt "$projekt" "${weiter[@]}"
