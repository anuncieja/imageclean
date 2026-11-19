#!/usr/bin/env bash
set -euo pipefail
export MBI55_ROOT="$(pwd)/manga-bridge-2/release55"
baseline="$(pwd)/base-testada/manga-bridge-2/release53/plugin/manga-bridge-importer"
prior="$(pwd)/manga-bridge-2/release54/overlay"
site="$(mktemp -d)"
testdir="$(mktemp -d)"
export MBI55_TEST_STATE="$testdir/state.json"
export MBI55_TEST_METRICS="$testdir/metrics.json"
export MBI2_HMAC_KEY_B64="$(php -r 'echo base64_encode(random_bytes(32));')"
export MBI2_WORDPRESS_ENDPOINT="http://127.0.0.1:8085/wp-json/mbi2/v1"
export MBI55_ENABLE=0
wp55() { wp --allow-root --path="$site" "$@"; }
cleanup() {
    if [[ -n "${server:-}" ]]; then kill "$server" 2>/dev/null || true; fi
    rm -rf "$site" "$testdir"
}
trap cleanup EXIT
cp -a "$baseline" "$testdir/"
cp -a "$prior/." "$testdir/manga-bridge-importer/"
(cd "$testdir" && zip -qr v54.zip manga-bridge-importer)
cp -a "$MBI55_ROOT/overlay/." "$testdir/manga-bridge-importer/"
find "$testdir/manga-bridge-importer" -name '*.php' -print0 | xargs -0 -n1 php -l > "$testdir/php-lint.txt"
find "$testdir/manga-bridge-importer" -name '*.js' -print0 | xargs -0 -n1 node --check
(cd "$testdir" && zip -qr v55.zip manga-bridge-importer)
wp55 core download --version=latest --skip-content
wp55 config create --dbname=mbi54_ci --dbuser=root --dbpass="$MBI55_DB_PASSWORD" --dbhost=127.0.0.1 --extra-php <<'PHP'
define('WP_ENVIRONMENT_TYPE','production');
define('DISABLE_WP_CRON',true);
define('MBI2_EXTERNAL_ENABLED',true);
define('MBI2_EXTERNAL_PUBLISH',true);
define('MBI2_WORKFLOW_REGISTERED',true);
define('MBI2_GITHUB_TOKEN','ci_only_configuration_1234567890');
define('MBI2_HMAC_KEY_B64',getenv('MBI2_HMAC_KEY_B64'));
define('MBI2_BATCH_ENABLED',getenv('MBI55_ENABLE')==='1');
PHP
wp55 core install --url=http://127.0.0.1:8085 --title='Disposable Manga Bridge batches' --admin_user=mbi_ci --admin_password="$(php -r 'echo bin2hex(random_bytes(24));')" --admin_email=ci@example.org --skip-email
wp55 plugin install "$testdir/v54.zip" --activate
MBI55_PHASE=baseline wp55 eval-file "$MBI55_ROOT/tests/batch.php"
wp55 --skip-plugins=manga-bridge-importer plugin install "$testdir/v55.zip" --force
MBI55_PHASE=disabled wp55 eval-file "$MBI55_ROOT/tests/batch.php"
export MBI55_ENABLE=1
wp55 option update home https://lermangaonline.com.br
wp55 rewrite structure '/%postname%/'
php -S 127.0.0.1:8085 -t "$site" "$site/index.php" > "$testdir/wp-http.log" 2>&1 &
server=$!
for i in {1..20}; do
    if curl -fsS "http://127.0.0.1:8085/wp-json/" -o /dev/null; then break; fi
    if [[ "$i" == 20 ]]; then cat "$testdir/wp-http.log"; exit 1; fi
    sleep 1
done
test_lot() {
    local which="$1" state=""
    MBI55_PHASE="seed_$which" wp55 eval-file "$MBI55_ROOT/tests/batch.php"
    for i in {1..100}; do
        state="$(MBI55_PHASE=tick wp55 eval-file "$MBI55_ROOT/tests/batch.php")"
        case "$state" in
            dispatching) python3 "$MBI55_ROOT/tests/run.py" || { tail -60 "$testdir/wp-http.log"; return 1; };;
            published|blocked) break;;
            received|queued) ;;
            *) printf 'Unexpected state: %s\n' "$state"; tail -40 "$testdir/wp-http.log"; return 1;;
        esac
    done
    MBI55_PHASE="verify_$which" wp55 eval-file "$MBI55_ROOT/tests/batch.php"
}
test_lot main
test_lot partial
python3 "$MBI55_ROOT/tests/metrics.py"
wp55 --skip-plugins=manga-bridge-importer plugin install "$testdir/v54.zip" --force
MBI55_PHASE=rollback wp55 eval-file "$MBI55_ROOT/tests/batch.php"
