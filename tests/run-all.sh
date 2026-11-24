#!/usr/bin/env bash
#
# Todas las pruebas, en orden de rapidez. Se ejecuta antes de dar una fase por terminada (docs/PLAN.md).
# Se detiene en la primera suite que falle.

set -eu
cd "$(dirname "$0")/.."

echo "################ Unitarias"
docker compose exec -T php php tests/unit.php

echo; echo "################ Integración (BD temporal)"
bin/as-migrator php tests/integration.php

echo; echo "################ Humo (curl)"
tests/smoke.sh

echo; echo "################ Copias de seguridad"
tests/backup.sh

echo; echo "Todas las suites pasaron."
