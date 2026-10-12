#!/bin/bash
# Poarta de baze de date a deployului BE (docs/postgresql-roles.md). Apelat de playbook-ul
# ansible_vps/petro-vps/deploy_validez_be.yml, cu release-ul nou deja în /var/www/validez-be/current,
# containerele db și php pornite și nginx OPRIT. Orice eșec întoarce cod nenul: playbook-ul nu pornește nginx.
#
#   normal:     migrări (validez_migrator, container temporar) → configure-roles.sql (administrativ)
#               → ca runtime, în containerul PHP: app:db:runtime-check, apoi app:votes:verify
#   --bootstrap (o singură dată, prima tranziție): migrări cu /etc/validez-be/bootstrap-admin.env
#               → configure-roles.sql → oprire; urmează parolele și deployul normal.
#
# Nu afișează și nu exportă secrete: citește din compose.env doar numele containerelor și căile.
set -euo pipefail
cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.."

COMPOSE_ENV=/etc/validez-be/compose.env
BOOTSTRAP_ENV=/etc/validez-be/bootstrap-admin.env
MODE=normal
while [ $# -gt 0 ]; do
    case "$1" in
        --compose-env) COMPOSE_ENV=$2; shift 2 ;;
        --bootstrap) MODE=bootstrap; shift ;;
        --bootstrap-env) BOOTSTRAP_ENV=$2; shift 2 ;;
        *) echo "Argument necunoscut: $1" >&2; exit 2 ;;
    esac
done

env_get(){ sed -n "s/^$1=//p" "$COMPOSE_ENV" | tail -n1 | sed 's/^"\(.*\)"$/\1/'; }
[ -r "$COMPOSE_ENV" ] || { echo "Lipsește $COMPOSE_ENV" >&2; exit 1; }
PROJECT=$(env_get COMPOSE_PROJECT_NAME); DB=$(env_get DB_CONTAINER); PHP=$(env_get PHP_CONTAINER)
MIGRATOR_ENV=$(env_get MIGRATOR_ENV_FILE); MIGRATOR_ENV=${MIGRATOR_ENV:-/etc/validez-be/migrator.env}
for v in PROJECT DB PHP; do [ -n "${!v}" ] || { echo "Lipsește $v în $COMPOSE_ENV" >&2; exit 1; }; done
if command -v docker-compose >/dev/null; then COMPOSE=(docker-compose); else COMPOSE=(docker compose); fi
compose(){ "${COMPOSE[@]}" -p "$PROJECT" --env-file "$COMPOSE_ENV" -f docker-compose.prod.yml "$@"; }

if [ $MODE = bootstrap ]; then
    [ -r "$BOOTSTRAP_ENV" ] || { echo "Bootstrap: lipsește $BOOTSTRAP_ENV" >&2; exit 1; }
    MIGRATION_ENV=$BOOTSTRAP_ENV
else
    [ ! -e "$BOOTSTRAP_ENV" ] || { echo "$BOOTSTRAP_ENV există încă: șterge-l după bootstrap" >&2; exit 1; }
    [ -r "$MIGRATOR_ENV" ] || { echo "Lipsește $MIGRATOR_ENV" >&2; exit 1; }
    MIGRATION_ENV=$MIGRATOR_ENV
fi

echo "== [1/3] PostgreSQL pregătit"
for i in $(seq 1 24); do
    docker exec "$DB" sh -c 'pg_isready -q -U "$POSTGRES_USER" -d "$POSTGRES_DB"' && break
    [ "$i" = 24 ] && { echo "PostgreSQL nu răspunde după 2 minute" >&2; exit 1; }
    sleep 5
done

echo "== [1/3] Migrări ($( [ $MODE = bootstrap ] && echo 'bootstrap, utilizatorul administrativ' || echo 'validez_migrator' ), container temporar)"
MIGRATOR_ENV_FILE=$MIGRATION_ENV compose run --rm --no-deps -T migrate

echo "== [2/3] Configurarea rolurilor (administrativ, configure-roles.sql)"
docker exec -i "$DB" sh -c 'psql -X -q -v ON_ERROR_STOP=1 -U "$POSTGRES_USER" -d "$POSTGRES_DB"' < docker/postgres/admin/configure-roles.sql

if [ $MODE = bootstrap ]; then
    echo "== Bootstrap încheiat: rolurile există. Site-ul rămâne oprit."
    echo "   Urmează: parolele cu \\password, /etc/validez-be/migrator.env, DATABASE_URL runtime cu validez_app,"
    echo "   ștergerea /etc/validez-be/bootstrap-admin.env, apoi deployul normal (docs/postgresql-roles.md)."
    exit 0
fi

echo "== [3/3] Verificare ca runtime (conexiunea Doctrine a containerului PHP)"
docker exec -u 1000:1000 -w /var/www/validez-be "$PHP" sh -c \
    'php bin/console app:db:runtime-check --expect-user=validez_app --env=prod && php bin/console app:votes:verify --env=prod'
echo "== Poarta de baze de date: OK"
