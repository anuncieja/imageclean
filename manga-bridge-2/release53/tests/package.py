from pathlib import Path
import zipfile,hashlib
root=Path(__file__).resolve().parents[1]
source=root/'plugin'/'manga-bridge-importer'
out=root/'dist';out.mkdir(exist_ok=True)
zip_path=out/'manga-bridge-importer-v1.7.53.zip'
with zipfile.ZipFile(zip_path,'w',zipfile.ZIP_DEFLATED) as z:
 for p in sorted(source.rglob('*')):
  if p.is_file(): z.write(p,'manga-bridge-importer/'+p.relative_to(source).as_posix())
with zipfile.ZipFile(zip_path) as z:assert z.testzip() is None
(out/'SHA256SUMS.txt').write_text(hashlib.sha256(zip_path.read_bytes()).hexdigest()+'  '+zip_path.name+'\n')
