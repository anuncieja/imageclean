#!/usr/bin/env bash
# Disposable GitHub runner only. No production endpoint or production credentials.
set -euo pipefail
export MBI2_CI_PROJECT_DIR
MBI2_CI_PROJECT_DIR="$(pwd)"
task_wp_dir="$(mktemp -d /tmp/mbi2-wp.XXXXXX)"
task_cli="/tmp/mbi2-wp-cli.phar"
curl --fail --silent --show-error --location https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar --output "$task_cli"
wpci() { php "$task_cli" --allow-root --path="$task_wp_dir" "$@"; }
export MBI2_HMAC_KEY_B64
MBI2_HMAC_KEY_B64="$(php -r 'echo base64_encode(random_bytes(32));')"
wpci core download --version=latest --skip-content
wpci config create --dbname=mbi2_ci --dbuser=root --dbpass="$MBI2_CI_DB_PASSWORD" --dbhost=127.0.0.1 --extra-php <<'PHP'
define('WP_ENVIRONMENT_TYPE', 'staging');
define('MBI2_EXTERNAL_ENABLED', true);
define('MBI2_HMAC_KEY_B64', getenv('MBI2_HMAC_KEY_B64'));
define('DISABLE_WP_CRON', true);
PHP
task_admin_password="$(php -r 'echo bin2hex(random_bytes(24));')"
wpci core install --url=http://127.0.0.1:8085 --title="Disposable Manga Bridge CI" --admin_user=mbi2_ci --admin_password="$task_admin_password" --admin_email=ci@example.org --skip-email
wpci plugin install "$MBI2_CI_PROJECT_DIR/manga-bridge-2/dist/manga-bridge-control-0.1.0-staging.zip"
wpci option update permalink_structure '/%postname%/'
wpci eval 'require getenv("MBI2_CI_PROJECT_DIR") . "/manga-bridge-2/tests/wp-integration.php";'
php -S 127.0.0.1:8085 -t "$task_wp_dir" "$task_wp_dir/index.php" > /tmp/mbi2-loopback-server.log 2>&1 &
task_server_pid=$!
trap 'kill "$task_server_pid" 2>/dev/null || true' EXIT
python - <<'PY'
import socket
import time
for attempt in range(50):
    try:
        with socket.create_connection(("127.0.0.1", 8085), timeout=0.5):
            break
    except OSError:
        time.sleep(0.1)
else:
    raise RuntimeError("Loopback server unavailable")
PY
python manga-bridge-2/tests/http_smoke.py
wpci eval 'require getenv("MBI2_CI_PROJECT_DIR") . "/manga-bridge-2/tests/wp-preservation.php";'
wpci plugin deactivate manga-bridge-control
wpci eval 'require getenv("MBI2_CI_PROJECT_DIR") . "/manga-bridge-2/tests/wp-preservation.php";'
wpci plugin activate manga-bridge-control
wpci eval 'require getenv("MBI2_CI_PROJECT_DIR") . "/manga-bridge-2/tests/wp-preservation.php";'
