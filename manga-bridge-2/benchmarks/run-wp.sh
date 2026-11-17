#!/usr/bin/env bash
set -euo pipefail
export MB2_DISPOSABLE_BENCH=I_UNDERSTAND_DISPOSABLE
export MB2_BENCH_ROOT="$(pwd)"
wp_path="$(mktemp -d /tmp/mb2-benchmark-wp.XXXXXX)"
wp_cli="/tmp/mb2-bench-wp-cli.phar"
curl --fail --silent --show-error --location https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar --output "$wp_cli"
wpbm() { php "$wp_cli" --allow-root --path="$wp_path" "$@"; }
wpbm core download --version=latest --skip-content
wpbm config create --dbname=mb2_benchmark --dbuser=root --dbpass="$MB2_BENCH_DB_PASSWORD" --dbhost=127.0.0.1 --extra-php <<'PHP'
define('WP_ENVIRONMENT_TYPE', 'staging');
define('DISABLE_WP_CRON', true);
PHP
wpbm core install --url=http://127.0.0.1:8099 --title='Disposable Manga Bridge Benchmark' --admin_user=bench_ci --admin_password="$(php -r 'echo bin2hex(random_bytes(20));')" --admin_email=ci@example.org --skip-email
python manga-bridge-2/release53/tests/package.py
wpbm plugin install "$MB2_BENCH_ROOT/manga-bridge-2/release53/dist/manga-bridge-importer-v1.7.53.zip" --activate
mkdir -p manga-bridge-2/benchmarks/out
export MB2_BENCH_REPORT="$MB2_BENCH_ROOT/manga-bridge-2/benchmarks/out/wp-measured.json"
wpbm eval-file "$MB2_BENCH_ROOT/manga-bridge-2/benchmarks/wp-publish.php"
python manga-bridge-2/benchmarks/model.py --wp-report "$MB2_BENCH_REPORT" --out manga-bridge-2/benchmarks/out/model.json
python -m unittest discover -s manga-bridge-2/benchmarks -p 'test_*.py' -v
