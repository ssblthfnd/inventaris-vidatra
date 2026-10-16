<?php

namespace Tests\Concerns;

use GdImage;
use Illuminate\Http\UploadedFile;

/**
 * Foto Aset — small image fixtures built at test time with GD (no binary
 * fixtures in the repository). Every file goes to the system temp dir and is
 * removed again by {@see self::cleanUpTestImages()}.
 */
trait BuildsTestImages
{
    /** @var list<string> */
    private array $testImageFiles = [];

    protected function cleanUpTestImages(): void
    {
        foreach ($this->testImageFiles as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        $this->testImageFiles = [];
    }

    protected function tempImagePath(string $bytes, string $suffix = '.bin'): string
    {
        $path = tempnam(sys_get_temp_dir(), 'imgtest');
        @unlink($path);
        $path .= $suffix;
        file_put_contents($path, $bytes);
        $this->testImageFiles[] = $path;

        return $path;
    }

    /** Two-colour image: left half `$left`, right half `$right` (RGB triples). */
    protected function gdImage(int $width, int $height, array $left = [200, 30, 30], array $right = [30, 30, 200]): GdImage
    {
        $img = imagecreatetruecolor($width, $height);
        imagefilledrectangle($img, 0, 0, intdiv($width, 2) - 1, $height - 1, imagecolorallocate($img, ...$left));
        imagefilledrectangle($img, intdiv($width, 2), 0, $width - 1, $height - 1, imagecolorallocate($img, ...$right));

        return $img;
    }

    protected function jpegBytes(int $width = 400, int $height = 300, int $quality = 90): string
    {
        ob_start();
        imagejpeg($this->gdImage($width, $height), null, $quality);

        return (string) ob_get_clean();
    }

    /** PNG whose LEFT half is fully transparent and right half opaque black. */
    protected function transparentPngBytes(int $width = 100, int $height = 100): string
    {
        $img = imagecreatetruecolor($width, $height);
        imagealphablending($img, false);
        imagesavealpha($img, true);
        imagefilledrectangle($img, 0, 0, $width - 1, $height - 1, imagecolorallocatealpha($img, 0, 0, 0, 127));
        imagefilledrectangle($img, intdiv($width, 2), 0, $width - 1, $height - 1, imagecolorallocatealpha($img, 0, 0, 0, 0));
        ob_start();
        imagepng($img);

        return (string) ob_get_clean();
    }

    protected function webpBytes(int $width = 300, int $height = 200): string
    {
        ob_start();
        imagewebp($this->gdImage($width, $height), null, 90);

        return (string) ob_get_clean();
    }

    /**
     * A PNG that is only a signature + IHDR (declaring ANY dimensions) + IEND.
     * `getimagesize()` reads the declared size; decoding it would fail — the
     * point is that oversized ones are refused before any decode is attempted.
     */
    protected function pngHeaderOnly(int $width, int $height): string
    {
        $chunk = static fn (string $type, string $data): string => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));

        return "\x89PNG\r\n\x1a\n"
            .$chunk('IHDR', pack('NNCCCCC', $width, $height, 8, 2, 0, 0, 0))
            .$chunk('IEND', '');
    }

    /** Valid PNG header and dimensions, but an IDAT that is not valid zlib data. */
    protected function corruptPngBytes(int $width = 50, int $height = 50): string
    {
        $chunk = static fn (string $type, string $data): string => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));

        return "\x89PNG\r\n\x1a\n"
            .$chunk('IHDR', pack('NNCCCCC', $width, $height, 8, 2, 0, 0, 0))
            .$chunk('IDAT', str_repeat("\xAB\xCD", 64))
            .$chunk('IEND', '');
    }

    /** An ISO-BMFF `ftyp` box as written by an iPhone camera (HEIC). */
    protected function heicBytes(string $majorBrand = 'heic'): string
    {
        $ftyp = 'ftyp'.$majorBrand."\x00\x00\x00\x00".'mif1'.'heic';

        return pack('N', strlen($ftyp) + 4).$ftyp.str_repeat("\x00", 200);
    }

    /**
     * Inserts an EXIF APP1 segment (IFD0: Make="SECRETCAM", Orientation=$orientation,
     * GPS IFD with GPSLatitudeRef="S") right after the SOI marker of `$jpeg`.
     */
    protected function withExif(string $jpeg, int $orientation = 1): string
    {
        $entry = static fn (int $tag, int $type, int $count, string $value): string => pack('vvV', $tag, $type, $count).str_pad($value, 4, "\x00");

        $tiff = 'II'.pack('v', 42).pack('V', 8);                      // header, IFD0 at 8
        $tiff .= pack('v', 3)                                          // IFD0: 3 entries (sorted by tag)
            .$entry(0x010F, 2, 10, pack('V', 50))                      // Make -> offset 50
            .$entry(0x0112, 3, 1, pack('v', $orientation))             // Orientation
            .$entry(0x8825, 4, 1, pack('V', 60))                       // GPS IFD -> offset 60
            .pack('V', 0);                                             // no IFD1 (ends at 50)
        $tiff .= "SECRETCAM\x00";                                      // 50..60
        $tiff .= pack('v', 1).$entry(0x0001, 2, 2, "S\x00").pack('V', 0); // GPS IFD

        $payload = "Exif\x00\x00".$tiff;
        $app1 = "\xFF\xE1".pack('n', strlen($payload) + 2).$payload;

        return substr($jpeg, 0, 2).$app1.substr($jpeg, 2);
    }

    protected function uploadedImage(string $bytes, string $name = 'foto.jpg', string $clientMime = 'image/jpeg'): UploadedFile
    {
        return new UploadedFile($this->tempImagePath($bytes, '.upload'), $name, $clientMime, null, true);
    }

    /** @return array{0: int, 1: int, 2: int} r, g, b of one pixel of a JPEG */
    protected function pixelOf(string $jpeg, int $x, int $y): array
    {
        $img = imagecreatefromstring($jpeg);
        $rgb = imagecolorat($img, $x, $y);

        return [($rgb >> 16) & 0xFF, ($rgb >> 8) & 0xFF, $rgb & 0xFF];
    }
}
