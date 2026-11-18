"""Build only the isolated staging extension; never label it an Importer update."""
import hashlib
import json
from pathlib import Path
import zipfile

root = Path(__file__).resolve().parents[1]
source = root / "control" / "manga-bridge-control"
destination = root / "dist"
destination.mkdir(exist_ok=True)
archive = destination / "manga-bridge-control-0.2.0-staging.zip"
with zipfile.ZipFile(archive, "w", zipfile.ZIP_DEFLATED) as out:
    for path in sorted(source.rglob("*")):
        if path.is_file():
            out.write(path, "manga-bridge-control/" + path.relative_to(source).as_posix())
with zipfile.ZipFile(archive) as check:
    assert check.testzip() is None
    assert "manga-bridge-control/manga-bridge-control.php" in check.namelist()
    assert not any("wp-config" in name or ".env" in name for name in check.namelist())
(destination / "SHA256SUMS.txt").write_text(hashlib.sha256(archive.read_bytes()).hexdigest() + "  " + archive.name + "\n")
(destination / "validation-scope.json").write_text(json.dumps({
    "package": archive.name, "stage": "github_publication_pilot",
    "legacy_baseline_examined": False, "production_installation": False,
    "legacy_importer_replaced": False, "catalogue_publication_requires_staging_opt_in": True
}, indent=2))
print("Packaged isolated staging transport; legacy Importer update is not released.")
