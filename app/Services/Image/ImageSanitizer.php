<?php

namespace App\Services\Image;

use Closure;
use finfo;
use GdImage;
use Throwable;

/**
 * Foto Aset — turns ONE untrusted uploaded file into a safe, freshly encoded
 * JPEG, using GD only (no Imagick path; GD is what the cPanel host provides).
 *
 * Nothing the client says is trusted — not the file name, the extension, the
 * browser-reported MIME type or any metadata. The steps, in order:
 *
 *  1. size on disk ≤ `max_upload_kb` (defence in depth; the FormRequest also checks);
 *  2. HEIC/HEIF (ISO-BMFF `ftyp` box) is recognised by signature and refused with
 *     its own message — the browser could not convert it and GD cannot read it;
 *  3. `finfo` (libmagic, content-based) must say JPEG, PNG or — only when this
 *     GD build really supports it — WebP;
 *  4. `getimagesize()` must parse the header as the SAME type and report the
 *     dimensions; then, still BEFORE anything is decoded:
 *       - longest side ≤ `max_input_side`, width × height ≤ `max_input_pixels`;
 *       - estimated memory (width × height × the FORMAT's `bytes_per_input_pixel`
 *         plus the `output_max_side`² × 4 destination canvas) must fit in what is
 *         left of `memory_limit` after current usage and a `memory_headroom_mb`
 *         reserve. The factor is per format because GD's decoders differ: PNG
 *         decodes into a full intermediate buffer before copying it into the GD
 *         image (measured peak ≈ 8.2 B/px), JPEG and WebP do not (≈ 4.1 B/px).
 *         A format without a configured factor gets the LARGEST configured one.
 *         With `memory_limit = -1` only the dimension caps apply — an unlimited
 *         limit is not treated as proof that decoding is safe.
 *     A GD decode that runs out of memory is a FATAL error PHP cannot catch,
 *     which is exactly why these checks run first;
 *  5. decode with GD (palette images are promoted to truecolor so transparency
 *     survives), resize to fit `output_max_side` (never upscaled) onto a WHITE
 *     canvas — this is where transparency is flattened;
 *  6. apply the EXIF orientation of a JPEG when `exif` is available (only the
 *     orientation tag is read; nothing of the metadata is carried over);
 *  7. re-encode as baseline JPEG, quality `jpeg_quality`. GD writes no EXIF/GPS,
 *     so the result never contains the original's metadata. The output is
 *     verified to be a JPEG of the expected dimensions.
 *
 * Every failure is an {@see ImageRejectedException} with a user-facing message;
 * GD/libjpeg warnings are swallowed locally (never turned into a 500 by the
 * framework error handler), and every GD image is released on every path.
 * Nothing is written to disk here — the caller stores {@see SanitizedImage::$bytes}.
 */
class ImageSanitizer
{
    /** ISO-BMFF brands of HEIC/HEIF stills and sequences. */
    private const HEIF_BRANDS = ['heic', 'heix', 'heim', 'heis', 'hevc', 'hevx', 'hevm', 'hevs', 'mif1', 'msf1'];

    /** ISO-BMFF brands of AVIF — same container, also not accepted. */
    private const AVIF_BRANDS = ['avif', 'avis'];

    private const MSG_NOT_IMAGE = 'File yang dipilih bukan gambar yang valid. Gunakan foto berformat JPEG atau PNG.';

    private const MSG_DECODE = 'Gambar tidak dapat dibaca (file rusak atau tidak lengkap). Coba ambil ulang foto atau pilih file lain.';

    private const MSG_PROCESSING = 'Gambar gagal diproses di server. Coba lagi atau gunakan foto lain.';

    /** @var array{max_upload_kb: int, max_input_side: int, max_input_pixels: int, memory_headroom_mb: int, output_max_side: int, jpeg_quality: int} */
    private readonly array $limits;

    /** @var array<string, int> bytes per input pixel, keyed `jpeg` / `png` / `webp` */
    private readonly array $bytesPerPixel;

    /** @var Closure(): array{limit: int, usage: int} */
    private readonly Closure $memoryProbe;

    /**
     * @param  ?array<string, mixed>  $limits  overrides for `config('inventory.photos')`
     * @param  ?Closure(): array{limit: int, usage: int}  $memoryProbe  `limit` in bytes (-1 = unlimited)
     *                                                                  and `usage` in bytes; tests inject one
     */
    public function __construct(?array $limits = null, ?Closure $memoryProbe = null)
    {
        $config = (array) config('inventory.photos', []);

        $this->limits = [
            'max_upload_kb' => (int) ($limits['max_upload_kb'] ?? $config['max_upload_kb'] ?? 15360),
            'max_input_side' => (int) ($limits['max_input_side'] ?? $config['max_input_side'] ?? 12000),
            'max_input_pixels' => (int) ($limits['max_input_pixels'] ?? $config['max_input_pixels'] ?? 50_000_000),
            'memory_headroom_mb' => (int) ($limits['memory_headroom_mb'] ?? $config['memory_headroom_mb'] ?? 16),
            'output_max_side' => (int) ($limits['output_max_side'] ?? $config['output_max_side'] ?? 1600),
            'jpeg_quality' => (int) ($limits['jpeg_quality'] ?? $config['jpeg_quality'] ?? 80),
        ];

        $this->bytesPerPixel = self::normalizeBytesPerPixel(
            $limits['bytes_per_input_pixel'] ?? $config['bytes_per_input_pixel'] ?? self::DEFAULT_BYTES_PER_PIXEL,
        );

        $this->memoryProbe = $memoryProbe ?? static fn (): array => [
            'limit' => self::parseIniBytes((string) ini_get('memory_limit')),
            'usage' => memory_get_usage(true),
        ];
    }

    /** Conservative defaults, also used when the config omits the key. */
    public const DEFAULT_BYTES_PER_PIXEL = ['jpeg' => 6, 'png' => 9, 'webp' => 6];

    /**
     * Accepts the per-format map (`['jpeg' => 6, 'png' => 9, 'webp' => 6]`) or a
     * single number applied to every format. Non-positive / non-numeric entries
     * are dropped; an empty result falls back to the defaults.
     *
     * @return array<string, int>
     */
    public static function normalizeBytesPerPixel(mixed $value): array
    {
        if (is_numeric($value)) {
            $value = array_fill_keys(array_keys(self::DEFAULT_BYTES_PER_PIXEL), (int) $value);
        }

        $factors = [];
        foreach ((array) $value as $format => $factor) {
            if (is_string($format) && is_numeric($factor) && (int) $factor > 0) {
                $factors[strtolower($format)] = (int) $factor;
            }
        }

        return $factors !== [] ? $factors : self::DEFAULT_BYTES_PER_PIXEL;
    }

    /**
     * Bytes per input pixel used for the pre-decode memory estimate of `$type`
     * (an IMAGETYPE_* constant). Unknown/unconfigured formats get the largest
     * configured factor — when unsure, estimate high and reject early.
     */
    public function bytesPerPixelFor(int $type): int
    {
        $key = match ($type) {
            IMAGETYPE_JPEG => 'jpeg',
            IMAGETYPE_PNG => 'png',
            IMAGETYPE_WEBP => 'webp',
            default => null,
        };

        return $this->bytesPerPixel[$key] ?? max($this->bytesPerPixel);
    }

    /** Whether this GD build can decode WebP (checked, never assumed). */
    public static function supportsWebp(): bool
    {
        return function_exists('imagecreatefromwebp') && (gd_info()['WebP Support'] ?? false) === true;
    }

    /**
     * `memory_limit`-style value to bytes; -1 (or any negative) = unlimited.
     */
    public static function parseIniBytes(string $value): int
    {
        $value = trim($value);
        if ($value === '' || (int) $value < 0) {
            return -1;
        }

        $number = (int) $value;

        return match (strtolower(substr($value, -1))) {
            'g' => $number * 1024 * 1024 * 1024,
            'm' => $number * 1024 * 1024,
            'k' => $number * 1024,
            default => $number,
        };
    }

    /**
     * @throws ImageRejectedException
     */
    public function sanitize(string $path): SanitizedImage
    {
        $size = is_file($path) ? (int) filesize($path) : 0;
        if ($size <= 0) {
            throw new ImageRejectedException(ImageRejectedException::NOT_AN_IMAGE, self::MSG_NOT_IMAGE);
        }
        if ($size > $this->limits['max_upload_kb'] * 1024) {
            throw new ImageRejectedException(
                ImageRejectedException::FILE_TOO_LARGE,
                'Ukuran file foto maksimal '.$this->formatMegabytes($this->limits['max_upload_kb'] * 1024).'.',
            );
        }

        $this->rejectIsoBmff($path);

        $type = $this->detectType($path);
        [$width, $height] = $this->readDimensions($path, $type);
        $this->assertWithinLimits($width, $height, $type);

        return $this->process($path, $type, $width, $height);
    }

    /* ------------------------------------------------------------------ checks (no decode) */

    private function rejectIsoBmff(string $path): void
    {
        $head = (string) $this->quietly(static fn () => file_get_contents($path, false, null, 0, 64));
        if (strlen($head) < 12 || substr($head, 4, 4) !== 'ftyp') {
            return;
        }

        // ftyp box: [size:4][ftyp][major brand:4][minor version:4][compatible brands:4×n]
        $boxSize = min(strlen($head), max(16, (int) unpack('N', substr($head, 0, 4))[1]));
        $brands = [strtolower(substr($head, 8, 4))];
        for ($offset = 16; $offset + 4 <= $boxSize; $offset += 4) {
            $brands[] = strtolower(substr($head, $offset, 4));
        }

        if (array_intersect($brands, self::HEIF_BRANDS) !== []) {
            throw new ImageRejectedException(
                ImageRejectedException::HEIC,
                'Format HEIC/HEIF belum didukung. Gunakan foto JPEG, atau atur kamera iPhone ke '.
                '"Most Compatible" (Pengaturan → Kamera → Format), lalu coba lagi.',
            );
        }

        if (array_intersect($brands, self::AVIF_BRANDS) !== []) {
            throw new ImageRejectedException(ImageRejectedException::UNSUPPORTED_FORMAT, $this->unsupportedMessage());
        }
    }

    /**
     * Content-based type via libmagic. Returns an IMAGETYPE_* constant.
     */
    private function detectType(string $path): int
    {
        $mime = $this->quietly(static fn () => (new finfo(FILEINFO_MIME_TYPE))->file($path));

        $type = match ($mime) {
            'image/jpeg', 'image/pjpeg' => IMAGETYPE_JPEG,
            'image/png' => IMAGETYPE_PNG,
            'image/webp' => self::supportsWebp() ? IMAGETYPE_WEBP : null,
            default => null,
        };

        if ($type !== null) {
            return $type;
        }

        if (is_string($mime) && str_starts_with($mime, 'image/')) {
            throw new ImageRejectedException(ImageRejectedException::UNSUPPORTED_FORMAT, $this->unsupportedMessage());
        }

        throw new ImageRejectedException(ImageRejectedException::NOT_AN_IMAGE, self::MSG_NOT_IMAGE);
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function readDimensions(string $path, int $expectedType): array
    {
        $info = $this->quietly(static fn () => getimagesize($path));

        if (! is_array($info) || ($info[2] ?? null) !== $expectedType) {
            throw new ImageRejectedException(ImageRejectedException::NOT_AN_IMAGE, self::MSG_NOT_IMAGE);
        }

        $width = (int) $info[0];
        $height = (int) $info[1];
        if ($width <= 0 || $height <= 0) {
            throw new ImageRejectedException(ImageRejectedException::NOT_AN_IMAGE, self::MSG_NOT_IMAGE);
        }

        return [$width, $height];
    }

    private function assertWithinLimits(int $width, int $height, int $type): void
    {
        $maxSide = $this->limits['max_input_side'];
        if (max($width, $height) > $maxSide) {
            throw new ImageRejectedException(
                ImageRejectedException::DIMENSIONS_TOO_LARGE,
                "Resolusi gambar terlalu besar ({$width}×{$height} piksel). Sisi terpanjang maksimal ".
                number_format($maxSide, 0, ',', '.').' piksel.',
            );
        }

        $maxPixels = $this->limits['max_input_pixels'];
        if ($width * $height > $maxPixels) {
            throw new ImageRejectedException(
                ImageRejectedException::TOO_MANY_PIXELS,
                "Resolusi gambar terlalu besar ({$width}×{$height} piksel). Maksimal ".
                rtrim(rtrim(number_format($maxPixels / 1_000_000, 1, ',', '.'), '0'), ',').' megapiksel.',
            );
        }

        ['limit' => $limit, 'usage' => $usage] = ($this->memoryProbe)();
        if ($limit < 0) {
            return; // unlimited: the dimension caps above are the only guard
        }

        $outputSide = $this->limits['output_max_side'];
        $needed = $width * $height * $this->bytesPerPixelFor($type) + $outputSide * $outputSide * 4;
        $available = $limit - $usage - $this->limits['memory_headroom_mb'] * 1024 * 1024;

        if ($needed > $available) {
            throw new ImageRejectedException(
                ImageRejectedException::INSUFFICIENT_MEMORY,
                "Gambar terlalu besar untuk diproses server ({$width}×{$height} piksel). ".
                'Perkecil resolusi foto lalu coba lagi.'.
                ($type === IMAGETYPE_PNG ? ' File PNG membutuhkan memori lebih besar — simpan sebagai JPEG bila memungkinkan.' : ''),
            );
        }
    }

    /* ------------------------------------------------------------------ decode / resize / encode */

    private function process(string $path, int $type, int $width, int $height): SanitizedImage
    {
        $source = null;
        $canvas = null;

        try {
            $source = $this->decode($path, $type);
            if (! $source instanceof GdImage) {
                throw new ImageRejectedException(ImageRejectedException::DECODE_FAILED, self::MSG_DECODE);
            }

            // GD may disagree with the header (e.g. a JPEG whose SOF lies) — trust the decoded image.
            $width = imagesx($source);
            $height = imagesy($source);

            if (! imageistruecolor($source) && ! $this->quietly(static fn () => imagepalettetotruecolor($source))) {
                throw new ImageRejectedException(ImageRejectedException::PROCESSING_FAILED, self::MSG_PROCESSING);
            }

            [$targetWidth, $targetHeight] = $this->fitWithin($width, $height, $this->limits['output_max_side']);
            $canvas = $this->flattenResized($source, $width, $height, $targetWidth, $targetHeight);
            $source = null; // release the (large) decoded original before anything else allocates

            if ($type === IMAGETYPE_JPEG) {
                $canvas = $this->applyExifOrientation($canvas, $path);
            }

            $bytes = $this->encodeJpeg($canvas, $this->limits['jpeg_quality']);
            if (! is_string($bytes) || ! str_starts_with($bytes, "\xFF\xD8")) {
                throw new ImageRejectedException(ImageRejectedException::PROCESSING_FAILED, self::MSG_PROCESSING);
            }

            $check = $this->quietly(static fn () => getimagesizefromstring($bytes));
            if (! is_array($check) || $check[2] !== IMAGETYPE_JPEG
                || $check[0] !== imagesx($canvas) || $check[1] !== imagesy($canvas)) {
                throw new ImageRejectedException(ImageRejectedException::PROCESSING_FAILED, self::MSG_PROCESSING);
            }

            return new SanitizedImage($bytes, (int) $check[0], (int) $check[1]);
        } catch (ImageRejectedException $e) {
            throw $e;
        } catch (Throwable) {
            throw new ImageRejectedException(ImageRejectedException::PROCESSING_FAILED, self::MSG_PROCESSING);
        } finally {
            // PHP 8 frees a GdImage when its last reference goes; drop ours on every path.
            $source = null;
            $canvas = null;
        }
    }

    /**
     * Decode with GD. Overridable only so tests can simulate a decoder failure.
     */
    protected function decode(string $path, int $type): GdImage|false
    {
        return $this->quietly(static fn () => match ($type) {
            IMAGETYPE_JPEG => imagecreatefromjpeg($path),
            IMAGETYPE_PNG => imagecreatefrompng($path),
            IMAGETYPE_WEBP => imagecreatefromwebp($path),
            default => false,
        }) ?? false;
    }

    /**
     * Encode to JPEG bytes. Overridable only so tests can simulate an encoder failure.
     */
    protected function encodeJpeg(GdImage $image, int $quality): string|false
    {
        imageinterlace($image, false);

        $level = ob_get_level();
        ob_start();
        try {
            $ok = $this->quietly(static fn () => imagejpeg($image, null, $quality));
            $bytes = (string) ob_get_contents();
        } finally {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
        }

        return $ok === true && $bytes !== '' ? $bytes : false;
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function fitWithin(int $width, int $height, int $maxSide): array
    {
        $scale = min(1.0, $maxSide / max($width, $height));

        return [
            max(1, min($maxSide, (int) round($width * $scale))),
            max(1, min($maxSide, (int) round($height * $scale))),
        ];
    }

    private function flattenResized(GdImage $source, int $width, int $height, int $targetWidth, int $targetHeight): GdImage
    {
        $canvas = imagecreatetruecolor($targetWidth, $targetHeight);
        if (! $canvas instanceof GdImage) {
            throw new ImageRejectedException(ImageRejectedException::PROCESSING_FAILED, self::MSG_PROCESSING);
        }

        $white = imagecolorallocate($canvas, 255, 255, 255);
        imagefilledrectangle($canvas, 0, 0, $targetWidth - 1, $targetHeight - 1, (int) $white);
        imagealphablending($canvas, true); // blend transparent source pixels onto the white background

        $ok = $this->quietly(static fn () => imagecopyresampled(
            $canvas, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height,
        ));
        if ($ok !== true) {
            throw new ImageRejectedException(ImageRejectedException::PROCESSING_FAILED, self::MSG_PROCESSING);
        }

        return $canvas;
    }

    /**
     * Reads ONLY the IFD0 Orientation tag (1–8) and rotates/flips accordingly.
     * Without the exif extension, or with unreadable/absent EXIF, the image is
     * kept as decoded.
     */
    private function applyExifOrientation(GdImage $image, string $path): GdImage
    {
        if (! function_exists('exif_read_data')) {
            return $image;
        }

        $exif = $this->quietly(static fn () => exif_read_data($path, 'IFD0'));
        $orientation = is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1;

        // imagerotate() angles are counter-clockwise.
        $rotate = static function (GdImage $img, int $angle): GdImage {
            $rotated = imagerotate($img, $angle, 0);
            if (! $rotated instanceof GdImage) {
                throw new ImageRejectedException(ImageRejectedException::PROCESSING_FAILED, self::MSG_PROCESSING);
            }

            return $rotated;
        };

        switch ($orientation) {
            case 2:
                imageflip($image, IMG_FLIP_HORIZONTAL);
                break;
            case 3:
                $image = $rotate($image, 180);
                break;
            case 4:
                imageflip($image, IMG_FLIP_VERTICAL);
                break;
            case 5:
                $image = $rotate($image, -90);
                imageflip($image, IMG_FLIP_HORIZONTAL);
                break;
            case 6:
                $image = $rotate($image, -90);
                break;
            case 7:
                $image = $rotate($image, 90);
                imageflip($image, IMG_FLIP_HORIZONTAL);
                break;
            case 8:
                $image = $rotate($image, 90);
                break;
        }

        return $image;
    }

    /* ------------------------------------------------------------------ helpers */

    /**
     * Runs a GD/finfo/exif call with PHP warnings swallowed, so libjpeg/libpng
     * diagnostics never reach the framework error handler (which would turn
     * them into an uncontrolled 500). The return value is still checked by
     * every caller.
     *
     * @template T
     *
     * @param  callable(): T  $call
     * @return T|null
     */
    private function quietly(callable $call): mixed
    {
        set_error_handler(static fn (): bool => true);
        try {
            return $call();
        } catch (Throwable) {
            return null;
        } finally {
            restore_error_handler();
        }
    }

    private function unsupportedMessage(): string
    {
        return self::supportsWebp()
            ? 'Format gambar tidak didukung. Gunakan foto berformat JPEG, PNG, atau WebP.'
            : 'Format gambar tidak didukung. Gunakan foto berformat JPEG atau PNG.';
    }

    private function formatMegabytes(int $bytes): string
    {
        return rtrim(rtrim(number_format($bytes / 1024 / 1024, 1, ',', '.'), '0'), ',').' MB';
    }
}
