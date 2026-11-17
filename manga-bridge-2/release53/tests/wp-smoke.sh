#!/usr/bin/env bash
set -euo pipefail
export MBI53_REPO_ROOT="$(pwd)"
task_site_dir="$(mktemp -d /tmp/mbi53-wp.XXXXXX)"
curl --fail --silent --show-error --location https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar --output /tmp/mbi53-wp-cli.phar
wp53(){ php /tmp/mbi53-wp-cli.phar --allow-root --path="$task_site_dir" "$@"; }
wp53 core download --version=latest --skip-content
wp53 config create --dbname=mbi53_ci --dbuser=root --dbpass="$MBI53_CI_DB_PASSWORD" --dbhost=127.0.0.1 --extra-php <<'PHP'
define('WP_ENVIRONMENT_TYPE','staging');
define('DISABLE_WP_CRON',true);
PHP
task_password="$(php -r 'echo bin2hex(random_bytes(24));')"
wp53 core install --url=http://127.0.0.1:8085 --title="Disposable Importer CI" --admin_user=mbi53_ci --admin_password="$task_password" --admin_email=ci@example.org --skip-email
wp53 plugin install "$MBI53_REPO_ROOT/manga-bridge-2/release53/dist/manga-bridge-importer-v1.7.53.zip" --activate
MBI53_PHASE=seed wp53 eval 'require getenv("MBI53_REPO_ROOT")."/manga-bridge-2/release53/tests/wordpress.php";'
MBI53_PHASE=verify wp53 eval 'require getenv("MBI53_REPO_ROOT")."/manga-bridge-2/release53/tests/wordpress.php";'
wp53 plugin deactivate manga-bridge-importer
MBI53_PHASE=preserved wp53 eval 'require getenv("MBI53_REPO_ROOT")."/manga-bridge-2/release53/tests/wordpress.php";'
wp53 plugin activate manga-bridge-importer
MBI53_PHASE=preserved wp53 eval 'require getenv("MBI53_REPO_ROOT")."/manga-bridge-2/release53/tests/wordpress.php";'
