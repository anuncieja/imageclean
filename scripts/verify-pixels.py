"""Independent decode check, not a watermark or C2PA validator."""
from pathlib import Path
from PIL import Image
root=Path(__file__).resolve().parents[1]
for name in ['metadata.jpg','progressive.jpg','metadata.png','metadata.webp']:
    with Image.open(root/'test/fixtures'/name) as source, Image.open(root/'results'/name) as output:
        source.load(); output.load()
        assert source.size == output.size, name
        assert source.convert('RGBA').tobytes() == output.convert('RGBA').tobytes(), name
        assert len(output.getexif()) == 0, name
        print(f'PASS {name}: decoded pixels identical; EXIF absent in independent decoder.')
