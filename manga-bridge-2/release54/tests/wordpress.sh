#!/usr/bin/env bash
set -euo pipefail
export MBI54_ROOT="$(pwd)/manga-bridge-2/release54"
task_baseline="$(pwd)/base-testada/manga-bridge-2/release53"
task_site="$(mktemp -d)"
task_build="$(mktemp -d)"
export MBI54_JOB_FILE="$task_build/job.json"
export MBI54_TOKEN="ci_only_configuration_1234567890"
export MBI2_HMAC_KEY_B64="$(php -r 'echo base64_encode(random_bytes(32));')"
wp54() { wp --allow-root --path="$task_site" "$@"; }
cleanup() {
    if [[ -n "${task_server:-}" ]]; then kill "$task_server" 2>/dev/null || true; fi
    rm -rf "$task_site" "$task_build"
}
trap cleanup EXIT
cp -a "$task_baseline/plugin/manga-bridge-importer" "$task_build/"
(cd "$task_build" && zip -qr base.zip manga-bridge-importer)
cp -a "$MBI54_ROOT/overlay/." "$task_build/manga-bridge-importer/"
find "$task_build/manga-bridge-importer" -name '*.php' -print0 | xargs -0 -n1 php -l > "$task_build/php-lint.txt"
find "$task_build/manga-bridge-importer" -name '*.js' -print0 | xargs -0 -n1 node --check
(cd "$task_build" && zip -qr manga-bridge-importer-v1.7.54.zip manga-bridge-importer)
wp54 core download --version=latest --skip-content
wp54 config create --dbname=mbi54_ci --dbuser=root --dbpass="$MBI54_DB_PASSWORD" --dbhost=127.0.0.1 --extra-php <<'PHP'
define('WP_ENVIRONMENT_TYPE','production');
define('DISABLE_WP_CRON',true);
if (getenv('MBI54_ENABLE') === '1') {
    define('MBI2_EXTERNAL_ENABLED',true);
    define('MBI2_EXTERNAL_PUBLISH',true);
    define('MBI2_WORKFLOW_REGISTERED',true);
    define('MBI2_GITHUB_TOKEN',getenv('MBI54_TOKEN'));
    define('MBI2_HMAC_KEY_B64',getenv('MBI2_HMAC_KEY_B64'));
}
PHP
wp54 core install --url=http://127.0.0.1:8085 --title='Disposable Manga Bridge CI' --admin_user=mbi_ci --admin_password="$(php -r 'echo bin2hex(random_bytes(24));')" --admin_email=ci@example.org --skip-email
wp54 plugin install "$task_build/base.zip" --activate
export MBI54_PHASE=seed
wp54 eval-file "$MBI54_ROOT/tests/wordpress.php"
wp54 --skip-plugins=manga-bridge-importer plugin install "$task_build/manga-bridge-importer-v1.7.54.zip" --force
export MBI54_PHASE=upgrade
wp54 eval-file "$MBI54_ROOT/tests/wordpress.php"
export MBI54_ENABLE=1
export MBI54_PHASE=wrong_host
wp54 eval-file "$MBI54_ROOT/tests/wordpress.php"
# Still connects to disposable Localhost. Only the WordPress home option is changed to exercise the production activation guard.
wp54 option update home https://lermangaonline.com.br
wp54 rewrite structure "/%postname%/"
php -S 127.0.0.1:8085 -t "$task_site" "$task_site/index.php" > "$task_build/wordpress-http.log" 2>&1 &
task_server=$!
for i in {1..20}; do
    if curl -fsS 'http://127.0.0.1:8085/?rest_route=/' -o /dev/null; then break; fi
    if [[ "$i" == 20 ]]; then cat "$task_build/wordpress-http.log"; exit 1; fi
    sleep 1
done
for kind in success duplicate incomplete wrong_generation resume; do
    export MBI54_PHASE="prepare_$kind"
    wp54 eval-file "$MBI54_ROOT/tests/wordpress.php"
    python3 "$MBI54_ROOT/tests/engine.py"
    export MBI54_PHASE="check_$kind"
    wp54 eval-file "$MBI54_ROOT/tests/wordpress.php"
done
unset MBI54_ENABLE
wp54 --skip-plugins=manga-bridge-importer plugin install "$task_build/base.zip" --force
export MBI54_PHASE=rollback
wp54 eval-file "$MBI54_ROOT/tests/wordpress.php"
