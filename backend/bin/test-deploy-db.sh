#!/bin/bash
# Simulare locală a deployului cu roluri separate (docs/postgresql-roles.md), înainte de orice aplicare pe server.
# Rulare, din backend/: bash bin/test-deploy-db.sh
#
# Folosește docker-compose.prod.yml și bin/deploy-db.sh exact ca playbook-ul, pe un proiect izolat
# (validez_prodtest), cu baza goală și căile în /tmp; fără nginx. Codul e cel din checkout-ul curent,
# imaginea PHP cea de dev. La final șterge containerele, rețeaua și directorul temporar, inclusiv la eșec.
#
# Verifică: bootstrap → parole → deploy normal; PHP folosește validez_app; nicio parolă administrativă sau
# de migrator în mediul ori în fișierele vizibile PHP; poarta prinde o tabelă fără GRANT introdusă DUPĂ
# configurarea rolurilor (și reaplicarea o remediază), runtime-ul conectat cu alt rol și un bootstrap uitat.
set -euo pipefail
cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.."

PROJECT=validez_prodtest
IMAGE=${DOCKER_IMAGE:-$(docker inspect -f '{{.Config.Image}}' validez-php)}
if command -v docker-compose >/dev/null; then COMPOSE=(docker-compose); else COMPOSE=(docker compose); fi
pass(){ printf '  OK   %s\n' "$1"; }
die(){ printf '  FAIL %s\n' "$1" >&2; exit 1; }
expect_failure(){
    local label=$1 message=$2 out; shift 2
    if out=$("$@" 2>&1); then die "$label: a reușit, trebuia să eșueze"; fi
    grep -qF "$message" <<<"$out" || die "$label: alt mesaj: $(tail -n 15 <<<"$out")"
    pass "$label"
}

# O singură instanță: proiectul are nume fix.
[ -z "$(docker ps -aq --filter "label=com.docker.compose.project=$PROJECT")" ] || die "există deja containere $PROJECT (altă rulare?)"
TMP=$(mktemp -d /tmp/validez-prodtest.XXXXXX); started=0
cleanup(){
    if [ $started = 1 ]; then MIGRATOR_ENV_FILE=$TMP/bootstrap-admin.env compose down --remove-orphans >/dev/null 2>&1 \
        || MIGRATOR_ENV_FILE=$TMP/migrator.env compose down --remove-orphans >/dev/null 2>&1 || true; fi
    # Fișierele PostgreSQL aparțin UID-ului din container.
    docker run --rm -v "$TMP:/t" "$IMAGE" sh -c 'rm -rf /t/* 2>/dev/null' >/dev/null 2>&1 || true
    docker run --rm -v "$TMP:/t" postgis/postgis:16-3.5-alpine sh -c 'rm -rf /t/*' >/dev/null 2>&1 || true
    rm -rf "$TMP" 2>/dev/null || echo "  ATENȚIE: șterge manual $TMP" >&2
}
trap cleanup EXIT

rnd(){ openssl rand -hex 16; }
ADMIN_PW=$(rnd); MIGRATOR_PW=$(rnd); APP_PW=$(rnd)
mkdir -p "$TMP"/{db,var,uploads,jwt}
cat > "$TMP/compose.env" <<EOF
COMPOSE_PROJECT_NAME=$PROJECT
VIRTUAL_HOST=prodtest.validez.local
DB_CONTAINER=${PROJECT}_db
PHP_CONTAINER=${PROJECT}_php
NGINX_CONTAINER=${PROJECT}_nginx
DOCKER_IMAGE=$IMAGE
DB_NAME=validez
DB_USER=validez
DB_PASSWORD=$ADMIN_PW
MIGRATOR_ENV_FILE=$TMP/migrator.env
RUNTIME_ENV_FILE=$TMP/runtime.env
DB_DATA_DIR=$TMP/db
VAR_DIR=$TMP/var
VENDOR_DIR=$PWD/vendor
MEDIA_HOST_DIR=$TMP/media
JWT_DIR=$TMP/jwt
INTERNAL_NETWORK=${PROJECT}_internal
EOF
sed -e "s/^APP_SECRET=.*/APP_SECRET=$(rnd)/" -e "s/^JWT_PASSPHRASE=.*/JWT_PASSPHRASE=$(rnd)/" \
    -e 's#^MAILER_DSN=.*#MAILER_DSN=null://null#' -e 's/^VOTING_ENABLED=.*/VOTING_ENABLED=false/' \
    -e "s#^DATABASE_URL=.*#DATABASE_URL=\"postgresql://validez_app:$APP_PW@db:5432/validez?serverVersion=16\&charset=utf8\"#" \
    .env.prod.sample > "$TMP/runtime.env"
grep -q 'schimba-aici' "$TMP/runtime.env" && die "runtime.env de test are încă valori schimba-aici"
echo "DATABASE_URL=\"postgresql://validez:$ADMIN_PW@db:5432/validez?serverVersion=16&charset=utf8\"" > "$TMP/bootstrap-admin.env"
chmod 600 "$TMP"/*.env
compose(){ "${COMPOSE[@]}" -p "$PROJECT" --env-file "$TMP/compose.env" -f docker-compose.prod.yml "$@"; }
deploy_db(){ bash bin/deploy-db.sh --compose-env "$TMP/compose.env" --bootstrap-env "$TMP/bootstrap-admin.env" "$@"; }
php_console(){ docker exec -u 1000:1000 -w /var/www/validez-be ${PROJECT}_php php bin/console "$@" --env=prod; }
admin_sql(){ docker exec -i ${PROJECT}_db psql -X -q -tA -v ON_ERROR_STOP=1 -U validez -d validez; }

echo "== bootstrap (bază goală, ca la prima tranziție)"
started=1
MIGRATOR_ENV_FILE=$TMP/bootstrap-admin.env compose up -d db php >/dev/null 2>&1 || die "pornirea db/php"
out=$(deploy_db --bootstrap 2>&1) || { echo "$out" | tail -n 20; die "bootstrap"; }
grep -q 'Bootstrap încheiat' <<<"$out" || die "bootstrap fără mesajul de oprire"
[ "$(admin_sql <<<"SELECT COUNT(*) FROM pg_roles WHERE rolname IN ('validez_owner','validez_migrator','validez_app')" | tr -d ' ')" = 3 ] || die "rolurile nu există după bootstrap"
pass "migrări cu utilizatorul administrativ, roluri create, oprire înainte de publicare"

echo "== parole, migrator.env, ștergerea fișierului de bootstrap"
# Pe server: \password interactiv. Aici, doar pentru test, prin stdin (nu apare în argumente).
admin_sql >/dev/null <<SQL
ALTER ROLE validez_app PASSWORD '$APP_PW';
ALTER ROLE validez_migrator PASSWORD '$MIGRATOR_PW';
SQL
echo "DATABASE_URL=\"postgresql://validez_migrator:$MIGRATOR_PW@db:5432/validez?serverVersion=16&charset=utf8\"" > "$TMP/migrator.env"
chmod 600 "$TMP/migrator.env"
cp "$TMP/bootstrap-admin.env" "$TMP/bootstrap-admin.env.keep"
rm "$TMP/bootstrap-admin.env"
pass "parole setate; bootstrap-admin.env șters"

echo "== deploy normal"
out=$(deploy_db 2>&1) || { echo "$out" | tail -n 25; die "deploy normal"; }
grep -q 'Conexiune runtime: rol validez_app (sesiune validez_app)' <<<"$out" || die "runtime nu a raportat validez_app"
grep -q 'Date de vot consistente' <<<"$out" || die "app:votes:verify nu a rulat"
pass "migrări ca validez_migrator, roluri reaplicate, PHP conectat ca validez_app, app:votes:verify OK"
# Fără obiectele extensiilor (spatial_ref_sys de la postgis rămâne a superuserului care a creat extensia).
[ "$(admin_sql <<<"SELECT COUNT(*) FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname='public' AND c.relkind='r' AND pg_get_userbyid(c.relowner) <> 'validez_owner' AND NOT EXISTS (SELECT 1 FROM pg_depend d WHERE d.classid='pg_class'::regclass AND d.objid=c.oid AND d.deptype='e')" | tr -d ' ')" = 0 ] \
    || die "există tabele ale aplicației care nu aparțin validez_owner"
pass "toate tabelele aplicației aparțin validez_owner"
[ "$(admin_sql <<<"SELECT COUNT(*) FROM pg_extension WHERE extname = 'postgis'" | tr -d ' ')" = 1 ] || die "extensia postgis lipsește după deploy"
grep -q 'Extensii (administrativ, extensions.sql)' <<<"$out" || die "deployul nu a rulat pasul de extensii"
pass "postgis prezent, pasul administrativ de extensii a rulat înaintea migrărilor (ADR-0012 p.3)"

echo "== secretele nu ajung în PHP runtime"
envdump=$(docker exec ${PROJECT}_php env)
for secret in "$ADMIN_PW" "$MIGRATOR_PW"; do
    grep -qF "$secret" <<<"$envdump" && die "o parolă administrativă/de migrator e în mediul PHP"
    docker exec ${PROJECT}_php sh -c "grep -rIlF '$secret' /var/www/validez-be --exclude-dir=vendor --exclude-dir=var 2>/dev/null" && die "o parolă administrativă/de migrator e într-un fișier vizibil PHP"
done
grep -qE '^(DB_PASSWORD|POSTGRES_)' <<<"$envdump" && die "variabile DB de bootstrap în mediul PHP"
pass "nici parola de bootstrap, nici cea a migratorului în mediul sau în fișierele PHP"

echo "== tabelă fără GRANT introdusă DUPĂ configurarea rolurilor"
admin_sql <<<"CREATE TABLE public.__prodtest_nogrant (id integer)"
expect_failure "app:db:runtime-check o prinde" "lipsește SELECT pe __prodtest_nogrant" php_console app:db:runtime-check --expect-user=validez_app
out=$(deploy_db 2>&1) || { echo "$out" | tail -n 25; die "remedierea prin deploy"; }
php_console app:db:runtime-check --expect-user=validez_app >/dev/null || die "runtime-check după remediere"
admin_sql <<<"DROP TABLE public.__prodtest_nogrant"
pass "reaplicarea în deploy îi dă proprietarul și DML pentru runtime"

echo "== poarta oprește deployul"
cp "$TMP/runtime.env" "$TMP/runtime.env.ok"
# Scriere pe același inode (sed -i ar crea alt fișier, iar bind mount-ul containerului l-ar vedea pe cel vechi).
swapped=$(sed "s#^DATABASE_URL=.*#DATABASE_URL=\"postgresql://validez_migrator:$MIGRATOR_PW@db:5432/validez?serverVersion=16\&charset=utf8\"#" "$TMP/runtime.env.ok")
printf '%s\n' "$swapped" > "$TMP/runtime.env"
expect_failure "PHP conectat cu alt rol decât validez_app" "rolul conexiunii este validez_migrator" deploy_db
cat "$TMP/runtime.env.ok" > "$TMP/runtime.env"
cp "$TMP/bootstrap-admin.env.keep" "$TMP/bootstrap-admin.env"
expect_failure "deploy normal cu fișierul de bootstrap uitat" "există încă: șterge-l după bootstrap" deploy_db
rm "$TMP/bootstrap-admin.env"
out=$(deploy_db 2>&1) || { echo "$out" | tail -n 25; die "deploy după restaurare"; }
pass "după corectare, deployul normal trece din nou"

echo "Simularea deployului cu roluri separate: OK (containerele și $TMP se șterg la ieșire)."
