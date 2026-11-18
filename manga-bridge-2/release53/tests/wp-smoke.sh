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
task_release="$MBI53_REPO_ROOT/manga-bridge-2/release53"
task_baseline="$(mktemp -d /tmp/mbi52-code.XXXXXX)"
cp -a "$task_release/plugin/manga-bridge-importer" "$task_baseline/"
patch -s --batch --fuzz=0 -d "$task_baseline/manga-bridge-importer" -p1 < "$task_release/tests/rollback-52.patch"
python - "$task_baseline" "$task_release/tests/baseline-52-executable-sha256.json" <<'PYTHON'
from pathlib import Path
import hashlib,json,sys,zipfile
base=Path(sys.argv[1])/'manga-bridge-importer'
expected=json.loads(Path(sys.argv[2]).read_text())
actual={p.relative_to(base).as_posix():hashlib.sha256(p.read_bytes()).hexdigest() for p in base.rglob('*') if p.suffix in {'.php','.js','.css'}}
assert actual == expected, 'Rebuilt 1.7.52 executable files differ from reference ZIP'
with zipfile.ZipFile(Path(sys.argv[1])/'manga-bridge-importer-v1.7.52.zip','w',zipfile.ZIP_DEFLATED) as z:
    for path in sorted(expected):z.write(base/path,'manga-bridge-importer/'+path)
print(f'Verified {len(actual)} executable file hashes against original 1.7.52 ZIP')
PYTHON
task_zip52="$task_baseline/manga-bridge-importer-v1.7.52.zip"
task_zip53="$task_release/dist/manga-bridge-importer-v1.7.53.zip"
wp53 option add mbi_version 1.7.52
wp53 plugin install "$task_zip52" --activate
MBI53_PHASE=seed wp53 eval 'require getenv("MBI53_REPO_ROOT")."/manga-bridge-2/release53/tests/wordpress.php";'
wp53 plugin install "$task_zip53" --force
MBI53_PHASE=verify wp53 eval 'require getenv("MBI53_REPO_ROOT")."/manga-bridge-2/release53/tests/wordpress.php";'
wp53 plugin deactivate manga-bridge-importer
MBI53_PHASE=preserved wp53 eval 'require getenv("MBI53_REPO_ROOT")."/manga-bridge-2/release53/tests/wordpress.php";'
wp53 plugin activate manga-bridge-importer
MBI53_PHASE=preserved wp53 eval 'require getenv("MBI53_REPO_ROOT")."/manga-bridge-2/release53/tests/wordpress.php";'
wp53 plugin install "$task_zip52" --force
MBI53_PHASE=rollback wp53 eval 'require getenv("MBI53_REPO_ROOT")."/manga-bridge-2/release53/tests/wordpress.php";'
wp53 plugin install "$task_zip53" --force
wp53 eval 'require getenv("MBI53_REPO_ROOT")."/manga-bridge-2/release53/tests/publication.php";'
