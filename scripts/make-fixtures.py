"""Generate synthetic, non-personal fixtures. Requires Pillow; not needed by the app."""
from pathlib import Path
from PIL import Image, PngImagePlugin
from struct import pack
import zlib

root = Path(__file__).resolve().parents[1]
dest = root / 'test/fixtures'
dest.mkdir(parents=True, exist_ok=True)
image = Image.new('RGB', (48, 32))
image.putdata([((x * 5) % 256, (y * 7) % 256, (x * 3 + y * 4) % 256) for y in range(32) for x in range(48)])
exif = Image.Exif()
exif[0x010f] = 'ImageClean Synthetic'
exif[0x0110] = 'Fixture 01'
exif[0x0131] = 'ImageClean Lab Test'
exif[0x013b] = 'Synthetic fixture'
exif[0x0112] = 1
exif[0x8825] = {1: 'N', 2: (0.0, 0.0, 0.0), 3: 'E', 4: (0.0, 0.0, 0.0)}
image.save(dest / 'metadata.jpg', exif=exif, quality=95)
image.save(dest / 'progressive.jpg', exif=exif, quality=90, progressive=True)
exif[0x0112] = 6
image.save(dest / 'rotated.jpg', exif=exif)
exif[0x0112] = 1
info = PngImagePlugin.PngInfo()
info.add_text('Software', 'ImageClean Lab Test')
info.add_itxt('XML:com.adobe.xmp', '<x:xmpmeta xmlns:x="adobe:ns:meta/"><test>Synthetic</test></x:xmpmeta>')
rgba = image.convert('RGBA')
rgba.putpixel((0, 0), (20, 30, 40, 0))
rgba.save(dest / 'metadata.png', pnginfo=info, exif=exif)
rgba.save(dest / 'metadata.webp', exif=exif, xmp=b'<x:xmpmeta xmlns:x="adobe:ns:meta/">Synthetic</x:xmpmeta>', lossless=True)
rgba.save(dest / 'plain.png')
image.save(dest / 'animated.webp', save_all=True, append_images=[image.transpose(Image.Transpose.FLIP_LEFT_RIGHT)], duration=100, loop=0)
image.save(dest / 'animated.png', save_all=True, append_images=[image.transpose(Image.Transpose.FLIP_LEFT_RIGHT)], duration=100, loop=0)
raw = (dest / 'plain.png').read_bytes()
payload = b'jumb-synthetic-container-not-a-signed-manifest'
chunk = pack('>I', len(payload)) + b'caBX' + payload + pack('>I', zlib.crc32(b'caBX' + payload))
(dest / 'provenance.png').write_bytes(raw[:-12] + chunk + raw[-12:])
image.resize((640, 420)).save(root / 'web/demo.jpg', exif=exif, quality=90)
print('Synthetic fixtures generated. No personal images included.')
