<?php

namespace Tests\Unit;

use App\Services\Image\ImageRejectedException;
use App\Services\Image\ImageSanitizer;
use App\Services\Image\SanitizedImage;
use Closure;
use GdImage;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Concerns\BuildsTestImages;
use Tests\TestCase;

/**
 * Foto Aset — App\Services\Image\ImageSanitizer (GD only). Fixtures are built
 * with GD at test time; oversized images are only ever a PNG *header*
 * declaring the size, so nothing large is ever allocated.
 */
class ImageSanitizerTest extends TestCase
{
    use BuildsTestImages;

    protected function tearDown(): void
    {
        $this->cleanUpTestImages();
        parent::tearDown();
    }

    /**
     * A sanitizer whose decode()/encodeJpeg() can be observed or forced to fail.
     *
     * @param  array<string, int>  $limits
     */
    private function sanitizer(array $limits = [], ?Closure $memory = null, bool $failDecode = false, bool $failEncode = false): ImageSanitizer
    {
        $tracker = new class
        {
            public int $decodes = 0;
        };

        $sanitizer = new class($limits, $memory, $failDecode, $failEncode, $tracker) extends ImageSanitizer
        {
            public function __construct(array $limits, ?Closure $memory, private bool $failDecode, private bool $failEncode, public object $tracker)
            {
                parent::__construct($limits ?: null, $memory);
            }

            protected function decode(string $path, int $type): GdImage|false
            {
                $this->tracker->decodes++;

                return $this->failDecode ? false : parent::decode($path, $type);
            }

            protected function encodeJpeg(GdImage $image, int $quality): string|false
            {
                return $this->failEncode ? false : parent::encodeJpeg($image, $quality);
            }
        };

        return $sanitizer;
    }

    private function assertRejected(string $reason, Closure $call, ?string $messageContains = null): ImageRejectedException
    {
        try {
            $call();
        } catch (ImageRejectedException $e) {
            $this->assertSame($reason, $e->reason, "Rejected for '{$e->reason}' ({$e->getMessage()}), expected '{$reason}'.");
            if ($messageContains !== null) {
                $this->assertStringContainsString($messageContains, $e->getMessage());
            }

            return $e;
        }

        $this->fail("Expected the image to be rejected with '{$reason}'.");
    }

    private function assertIsCleanJpeg(SanitizedImage $result): void
    {
        $this->assertStringStartsWith("\xFF\xD8", $result->bytes);
        $info = getimagesizefromstring($result->bytes);
        $this->assertSame(IMAGETYPE_JPEG, $info[2]);
        $this->assertSame([$result->width, $result->height], [$info[0], $info[1]]);
        $this->assertSame('image/jpeg', $result->mime());
        $this->assertSame(strlen($result->bytes), $result->sizeBytes());
    }

    /* ================================================================== accepted formats */

    public function test_valid_jpeg_is_re_encoded_and_resized_to_at_most_1600px(): void
    {
        $result = (new ImageSanitizer)->sanitize($this->tempImagePath($this->jpegBytes(2000, 1000), '.jpg'));

        $this->assertIsCleanJpeg($result);
        $this->assertSame([1600, 800], [$result->width, $result->height]);
    }

    public function test_portrait_image_is_limited_by_its_height(): void
    {
        $result = (new ImageSanitizer)->sanitize($this->tempImagePath($this->jpegBytes(1000, 3000), '.jpg'));

        $this->assertSame([533, 1600], [$result->width, $result->height]);
    }

    public function test_small_image_is_never_upscaled(): void
    {
        $result = (new ImageSanitizer)->sanitize($this->tempImagePath($this->jpegBytes(300, 200), '.jpg'));

        $this->assertSame([300, 200], [$result->width, $result->height]);
    }

    public function test_png_with_transparency_becomes_jpeg_flattened_on_white(): void
    {
        $result = (new ImageSanitizer)->sanitize($this->tempImagePath($this->transparentPngBytes(100, 100), '.png'));

        $this->assertIsCleanJpeg($result);
        [$r, $g, $b] = $this->pixelOf($result->bytes, 20, 50);   // transparent half
        $this->assertGreaterThan(240, min($r, $g, $b), 'Transparent pixels must become white.');
        [$r, $g, $b] = $this->pixelOf($result->bytes, 80, 50);   // opaque black half
        $this->assertLessThan(20, max($r, $g, $b));
    }

    public function test_palette_png_with_transparent_index_is_flattened_on_white(): void
    {
        $img = imagecreate(40, 40);
        $transparent = imagecolorallocate($img, 0, 255, 0);
        imagecolortransparent($img, $transparent);
        imagefilledrectangle($img, 0, 0, 39, 39, $transparent);
        ob_start();
        imagepng($img);
        $png = (string) ob_get_clean();

        $result = (new ImageSanitizer)->sanitize($this->tempImagePath($png, '.png'));

        [$r, $g, $b] = $this->pixelOf($result->bytes, 20, 20);
        $this->assertGreaterThan(240, min($r, $g, $b));
    }

    public function test_webp_is_accepted_only_when_gd_supports_it(): void
    {
        if (! ImageSanitizer::supportsWebp()) {
            $this->markTestSkipped('This GD build has no WebP support.');
        }

        $result = (new ImageSanitizer)->sanitize($this->tempImagePath($this->webpBytes(300, 200), '.webp'));

        $this->assertIsCleanJpeg($result);
        $this->assertSame([300, 200], [$result->width, $result->height]);
    }

    public function test_file_name_and_extension_are_irrelevant(): void
    {
        // a real PNG saved as ".txt" is still processed by its content
        $result = (new ImageSanitizer)->sanitize($this->tempImagePath($this->transparentPngBytes(), '.txt'));

        $this->assertIsCleanJpeg($result);
    }

    /* ================================================================== rejected content */

    public function test_text_file_named_jpg_is_rejected(): void
    {
        $path = $this->tempImagePath("bukan gambar\nhanya teks biasa\n", '.jpg');

        $this->assertRejected(ImageRejectedException::NOT_AN_IMAGE, fn () => (new ImageSanitizer)->sanitize($path), 'bukan gambar');
    }

    public function test_php_script_disguised_as_png_is_rejected(): void
    {
        $path = $this->tempImagePath("<?php echo 'x'; ?>", '.png');

        $this->assertRejected(ImageRejectedException::NOT_AN_IMAGE, fn () => (new ImageSanitizer)->sanitize($path));
    }

    public function test_jpeg_magic_bytes_without_a_real_image_are_rejected(): void
    {
        // finfo sees a JPEG signature, but there is no frame header to read
        $path = $this->tempImagePath("\xFF\xD8\xFF\xE0".str_repeat('A', 300), '.jpg');

        $this->assertRejected(ImageRejectedException::NOT_AN_IMAGE, fn () => (new ImageSanitizer)->sanitize($path));
    }

    public function test_empty_or_missing_file_is_rejected(): void
    {
        $empty = $this->tempImagePath('', '.jpg');

        $this->assertRejected(ImageRejectedException::NOT_AN_IMAGE, fn () => (new ImageSanitizer)->sanitize($empty));
        $this->assertRejected(ImageRejectedException::NOT_AN_IMAGE, fn () => (new ImageSanitizer)->sanitize($empty.'.missing'));
    }

    public function test_unsupported_image_format_is_rejected(): void
    {
        $img = imagecreatetruecolor(20, 20);
        ob_start();
        imagegif($img);
        $path = $this->tempImagePath((string) ob_get_clean(), '.jpg');

        $this->assertRejected(ImageRejectedException::UNSUPPORTED_FORMAT, fn () => (new ImageSanitizer)->sanitize($path), 'tidak didukung');
    }

    #[DataProvider('heifBrands')]
    public function test_heic_heif_is_rejected_with_its_own_message(string $brand): void
    {
        $path = $this->tempImagePath($this->heicBytes($brand), '.jpg');

        $this->assertRejected(ImageRejectedException::HEIC, fn () => (new ImageSanitizer)->sanitize($path), 'HEIC/HEIF belum didukung');
    }

    public static function heifBrands(): array
    {
        return ['heic' => ['heic'], 'heix' => ['heix'], 'mif1' => ['mif1'], 'hevc' => ['hevc']];
    }

    /**
     * AVIF shares the ISO-BMFF container and the generic `mif1`/`msf1` brands
     * with HEIF; it must get the "unsupported format" answer, never the HEIC
     * advice. Every fixture lists its brands explicitly (no `heic` added).
     *
     * @param  list<string>  $compatible
     */
    #[DataProvider('avifBrandSets')]
    public function test_avif_is_rejected_as_unsupported_not_as_heic(string $major, array $compatible): void
    {
        $path = $this->tempImagePath($this->isoBmffBytes($major, $compatible), '.jpg');

        $e = $this->assertRejected(ImageRejectedException::UNSUPPORTED_FORMAT, fn () => (new ImageSanitizer)->sanitize($path), 'tidak didukung');
        $this->assertStringNotContainsString('HEIC', $e->getMessage());
    }

    public static function avifBrandSets(): array
    {
        return [
            'avif + mif1 miaf MA1B' => ['avif', ['mif1', 'miaf', 'MA1B']],
            'avis + msf1 miaf' => ['avis', ['msf1', 'miaf']],
        ];
    }

    /**
     * @param  list<string>  $compatible
     */
    #[DataProvider('heifBrandSets')]
    public function test_heic_heif_with_explicit_brands_keeps_its_own_message(string $major, array $compatible): void
    {
        $path = $this->tempImagePath($this->isoBmffBytes($major, $compatible), '.jpg');

        $this->assertRejected(ImageRejectedException::HEIC, fn () => (new ImageSanitizer)->sanitize($path), 'HEIC/HEIF belum didukung');
    }

    public static function heifBrandSets(): array
    {
        return [
            'iPhone heic + mif1' => ['heic', ['mif1']],
            'heif mif1 + heic' => ['mif1', ['heic']],
        ];
    }

    public function test_corrupt_image_that_cannot_be_decoded_is_rejected(): void
    {
        $path = $this->tempImagePath($this->corruptPngBytes(), '.png');

        $this->assertRejected(ImageRejectedException::DECODE_FAILED, fn () => (new ImageSanitizer)->sanitize($path), 'tidak dapat dibaca');
    }

    public function test_file_over_the_size_limit_is_rejected_before_anything_else(): void
    {
        $path = $this->tempImagePath($this->jpegBytes(400, 300), '.jpg');
        $this->assertGreaterThan(1024, filesize($path));

        $sanitizer = $this->sanitizer(['max_upload_kb' => 1]);
        $this->assertRejected(ImageRejectedException::FILE_TOO_LARGE, fn () => $sanitizer->sanitize($path), 'maksimal');
        $this->assertSame(0, $sanitizer->tracker->decodes);
    }

    /* ================================================================== limits checked before decode */

    public function test_side_longer_than_12000px_is_rejected_before_decode(): void
    {
        $path = $this->tempImagePath($this->pngHeaderOnly(12001, 10), '.png');
        $sanitizer = $this->sanitizer();

        $this->assertRejected(ImageRejectedException::DIMENSIONS_TOO_LARGE, fn () => $sanitizer->sanitize($path), '12.000');
        $this->assertSame(0, $sanitizer->tracker->decodes, 'Must be refused from the header, never decoded.');
    }

    public function test_extreme_declared_dimensions_are_rejected_before_decode(): void
    {
        $path = $this->tempImagePath($this->pngHeaderOnly(60000, 60000), '.png');
        $sanitizer = $this->sanitizer();

        $this->assertRejected(ImageRejectedException::DIMENSIONS_TOO_LARGE, fn () => $sanitizer->sanitize($path));
        $this->assertSame(0, $sanitizer->tracker->decodes);
    }

    public function test_more_than_50_megapixels_is_rejected_before_decode(): void
    {
        $path = $this->tempImagePath($this->pngHeaderOnly(8000, 7000), '.png'); // 56 MP, both sides < 12000
        $sanitizer = $this->sanitizer();

        $this->assertRejected(ImageRejectedException::TOO_MANY_PIXELS, fn () => $sanitizer->sanitize($path), 'megapiksel');
        $this->assertSame(0, $sanitizer->tracker->decodes);
    }

    public function test_estimated_memory_beyond_the_remaining_limit_is_rejected_before_decode(): void
    {
        // 3000×2000×5 B + 1600²×4 B ≈ 40 MB needed; 64 MB limit − 40 MB in use − 16 MB reserve = 8 MB left
        $path = $this->tempImagePath($this->pngHeaderOnly(3000, 2000), '.png');
        $sanitizer = $this->sanitizer([], fn () => ['limit' => 64 * 1024 * 1024, 'usage' => 40 * 1024 * 1024]);

        $this->assertRejected(ImageRejectedException::INSUFFICIENT_MEMORY, fn () => $sanitizer->sanitize($path), 'Perkecil resolusi');
        $this->assertSame(0, $sanitizer->tracker->decodes);
    }

    public function test_memory_estimate_counts_memory_already_in_use(): void
    {
        $path = $this->tempImagePath($this->jpegBytes(400, 300), '.jpg');
        // 400×300×5 + 1600²×4 ≈ 10.8 MB; with 100 MB limit it only fails once usage eats the room
        $fits = $this->sanitizer([], fn () => ['limit' => 100 * 1024 * 1024, 'usage' => 20 * 1024 * 1024]);
        $this->assertSame(400, $fits->sanitize($path)->width);

        $tight = $this->sanitizer([], fn () => ['limit' => 100 * 1024 * 1024, 'usage' => 80 * 1024 * 1024]);
        $this->assertRejected(ImageRejectedException::INSUFFICIENT_MEMORY, fn () => $tight->sanitize($path));
    }

    public function test_unlimited_memory_still_applies_the_dimension_caps(): void
    {
        $unlimited = fn () => ['limit' => -1, 'usage' => 0];

        $ok = $this->sanitizer([], $unlimited)->sanitize($this->tempImagePath($this->jpegBytes(400, 300), '.jpg'));
        $this->assertSame([400, 300], [$ok->width, $ok->height]);

        $huge = $this->tempImagePath($this->pngHeaderOnly(8000, 7000), '.png');
        $this->assertRejected(ImageRejectedException::TOO_MANY_PIXELS, fn () => $this->sanitizer([], $unlimited)->sanitize($huge));
    }

    /*
     * Memory factor per format (final review S1). Measured peak of the whole
     * sanitize pipeline (PHP 8.3, bundled GD, 4000×3000): JPEG/WebP ≈ 4.9 B/px,
     * truecolor PNG ≈ 8.2 B/px. 6 MP below, with 50 MB left after usage + reserve:
     *   PNG  6 MP × 9 + 1600²×4 ≈ 64.2 MB  > 50 MB → rejected before decode
     *   (with the old flat 5 B/px: ≈ 40.2 MB — it would have been decoded)
     *   JPEG/WebP 6 MP × 6 + canvas ≈ 46.2 MB ≤ 50 MB → processed
     */
    private function fiftyMbLeft(): Closure
    {
        return fn () => ['limit' => 100 * 1024 * 1024, 'usage' => 34 * 1024 * 1024]; // 100 − 34 − 16 = 50 MB
    }

    public function test_configured_memory_factors_are_per_format(): void
    {
        $this->assertSame(['jpeg' => 6, 'png' => 9, 'webp' => 6], config('inventory.photos.bytes_per_input_pixel'));

        $sanitizer = new ImageSanitizer;
        $this->assertSame(6, $sanitizer->bytesPerPixelFor(IMAGETYPE_JPEG));
        $this->assertSame(9, $sanitizer->bytesPerPixelFor(IMAGETYPE_PNG));
        $this->assertSame(6, $sanitizer->bytesPerPixelFor(IMAGETYPE_WEBP));
    }

    public function test_png_that_passed_the_old_flat_estimate_is_now_rejected_before_decode(): void
    {
        $path = $this->tempImagePath($this->pngHeaderOnly(3000, 2000), '.png');

        // old behaviour (flat 5 B/px): the estimate let it through to the decoder
        $legacy = $this->sanitizer(['bytes_per_input_pixel' => 5], $this->fiftyMbLeft(), failDecode: true);
        $this->assertRejected(ImageRejectedException::DECODE_FAILED, fn () => $legacy->sanitize($path));
        $this->assertSame(1, $legacy->tracker->decodes, 'with 5 B/px the PNG reached the decoder');

        // current behaviour: refused from the header, the decoder is never called
        $current = $this->sanitizer([], $this->fiftyMbLeft());
        $e = $this->assertRejected(ImageRejectedException::INSUFFICIENT_MEMORY, fn () => $current->sanitize($path), 'Perkecil resolusi');
        $this->assertStringContainsString('PNG membutuhkan memori lebih besar', $e->getMessage());
        $this->assertSame(0, $current->tracker->decodes);
    }

    public function test_jpeg_of_the_same_size_and_memory_is_still_processed(): void
    {
        $path = $this->tempImagePath($this->jpegBytes(3000, 2000, 70), '.jpg');

        $result = $this->sanitizer([], $this->fiftyMbLeft())->sanitize($path);

        $this->assertSame([1600, 1067], [$result->width, $result->height]);
    }

    public function test_webp_of_the_same_size_and_memory_is_still_processed_when_supported(): void
    {
        if (! ImageSanitizer::supportsWebp()) {
            $this->markTestSkipped('This GD build has no WebP support.');
        }
        $path = $this->tempImagePath($this->webpBytes(3000, 2000), '.webp');

        $result = $this->sanitizer([], $this->fiftyMbLeft())->sanitize($path);

        $this->assertSame([1600, 1067], [$result->width, $result->height]);
    }

    public function test_png_of_ordinary_size_is_processed_under_the_real_memory_limit(): void
    {
        $img = $this->gdImage(2000, 1500);
        ob_start();
        imagepng($img);
        $path = $this->tempImagePath((string) ob_get_clean(), '.png');

        $result = (new ImageSanitizer)->sanitize($path); // real memory_limit / usage

        $this->assertSame([1600, 1200], [$result->width, $result->height]);
    }

    public function test_png_estimate_counts_memory_already_in_use(): void
    {
        $path = $this->tempImagePath($this->pngHeaderOnly(2000, 1500), '.png'); // 3 MP × 9 + canvas ≈ 37.2 MB

        $roomy = $this->sanitizer([], fn () => ['limit' => 128 * 1024 * 1024, 'usage' => 60 * 1024 * 1024], failDecode: true);
        $this->assertRejected(ImageRejectedException::DECODE_FAILED, fn () => $roomy->sanitize($path)); // 52 MB left: estimate passes
        $this->assertSame(1, $roomy->tracker->decodes);

        $busy = $this->sanitizer([], fn () => ['limit' => 128 * 1024 * 1024, 'usage' => 80 * 1024 * 1024]);
        $this->assertRejected(ImageRejectedException::INSUFFICIENT_MEMORY, fn () => $busy->sanitize($path)); // 32 MB left
        $this->assertSame(0, $busy->tracker->decodes);
    }

    public function test_memory_factor_config_accepts_a_map_or_a_number_and_falls_back_to_the_largest(): void
    {
        $this->assertSame(['jpeg' => 7, 'png' => 7, 'webp' => 7], ImageSanitizer::normalizeBytesPerPixel(7));
        $this->assertSame(ImageSanitizer::DEFAULT_BYTES_PER_PIXEL, ImageSanitizer::normalizeBytesPerPixel(['jpeg' => 0, 'png' => 'x']));
        $this->assertSame(ImageSanitizer::DEFAULT_BYTES_PER_PIXEL, ImageSanitizer::normalizeBytesPerPixel(null));

        // a format missing from the map is estimated with the LARGEST configured factor
        $partial = new ImageSanitizer(['bytes_per_input_pixel' => ['JPEG' => 6, 'png' => 9]]);
        $this->assertSame(6, $partial->bytesPerPixelFor(IMAGETYPE_JPEG));
        $this->assertSame(9, $partial->bytesPerPixelFor(IMAGETYPE_WEBP));
        $this->assertSame(9, $partial->bytesPerPixelFor(IMAGETYPE_GIF));
    }

    public function test_memory_limit_strings_are_parsed(): void
    {
        $this->assertSame(-1, ImageSanitizer::parseIniBytes('-1'));
        $this->assertSame(128 * 1024 * 1024, ImageSanitizer::parseIniBytes('128M'));
        $this->assertSame(1024 * 1024 * 1024, ImageSanitizer::parseIniBytes('1G'));
        $this->assertSame(512 * 1024, ImageSanitizer::parseIniBytes('512k'));
        $this->assertSame(134217728, ImageSanitizer::parseIniBytes('134217728'));
    }

    /* ================================================================== processing failures */

    public function test_decoder_failure_is_a_controlled_rejection(): void
    {
        $path = $this->tempImagePath($this->jpegBytes(), '.jpg');

        $this->assertRejected(ImageRejectedException::DECODE_FAILED, fn () => $this->sanitizer(failDecode: true)->sanitize($path));
    }

    public function test_encoder_failure_is_a_controlled_rejection(): void
    {
        $path = $this->tempImagePath($this->jpegBytes(), '.jpg');

        $this->assertRejected(ImageRejectedException::PROCESSING_FAILED, fn () => $this->sanitizer(failEncode: true)->sanitize($path), 'gagal diproses');
    }

    public function test_failed_processing_releases_its_images(): void
    {
        $path = $this->tempImagePath($this->jpegBytes(1500, 1500), '.jpg');
        $sanitizer = $this->sanitizer(failEncode: true);

        // warm up once so lazily-allocated internals don't count as growth
        try {
            $sanitizer->sanitize($path);
        } catch (ImageRejectedException) {
        }
        gc_collect_cycles();
        $baseline = memory_get_usage();

        for ($i = 0; $i < 15; $i++) {
            try {
                $sanitizer->sanitize($path);
            } catch (ImageRejectedException) {
            }
        }
        gc_collect_cycles();

        // each leaked 1500² source + 1600-canvas would be ~9 MB+; 15 leaks ≈ 135 MB
        $this->assertLessThan(4 * 1024 * 1024, memory_get_usage() - $baseline);
    }

    public function test_gd_warnings_never_escape_as_exceptions(): void
    {
        // a truncated JPEG makes libjpeg emit warnings; the framework turns warnings
        // into ErrorException — the sanitizer must answer with a rejection or a result
        $jpeg = $this->jpegBytes(400, 300);
        $path = $this->tempImagePath(substr($jpeg, 0, (int) (strlen($jpeg) * 0.6)), '.jpg');

        try {
            $result = (new ImageSanitizer)->sanitize($path);
            $this->assertIsCleanJpeg($result);
        } catch (ImageRejectedException $e) {
            $this->assertNotSame('', $e->getMessage());
        } catch (RuntimeException $e) {
            $this->fail('Unexpected exception: '.$e::class);
        }
    }

    /* ================================================================== metadata & orientation */

    public function test_exif_and_gps_metadata_are_not_kept(): void
    {
        $path = $this->tempImagePath($this->withExif($this->jpegBytes(400, 300), 1), '.jpg');
        $this->assertStringContainsString('SECRETCAM', (string) file_get_contents($path));

        $result = (new ImageSanitizer)->sanitize($path);

        $this->assertStringNotContainsString('Exif', $result->bytes);
        $this->assertStringNotContainsString('SECRETCAM', $result->bytes);
        if (function_exists('exif_read_data')) {
            $out = $this->tempImagePath($result->bytes, '.jpg');
            $exif = @exif_read_data($out);
            $this->assertFalse(is_array($exif) && (isset($exif['GPSLatitudeRef']) || isset($exif['Make']) || isset($exif['Orientation'])));
        }
    }

    public function test_exif_orientation_is_applied_when_exif_is_available(): void
    {
        if (! function_exists('exif_read_data')) {
            $this->markTestSkipped('exif extension not available.');
        }

        // stored landscape 200×100: left half red, right half blue; Orientation 6 = rotate 90° clockwise
        $path = $this->tempImagePath($this->withExif($this->jpegBytes(200, 100), 6), '.jpg');
        $this->assertSame(6, (int) (exif_read_data($path)['Orientation'] ?? 0));

        $result = (new ImageSanitizer)->sanitize($path);

        $this->assertSame([100, 200], [$result->width, $result->height]);
        [$r, , $b] = $this->pixelOf($result->bytes, 50, 40);   // top half = former left half (red)
        $this->assertGreaterThan($b + 80, $r);
        [$r, , $b] = $this->pixelOf($result->bytes, 50, 160);  // bottom half = former right half (blue)
        $this->assertGreaterThan($r + 80, $b);
    }

    #[DataProvider('orientations')]
    public function test_every_exif_orientation_produces_the_upright_dimensions(int $orientation, array $expected): void
    {
        if (! function_exists('exif_read_data')) {
            $this->markTestSkipped('exif extension not available.');
        }

        $result = (new ImageSanitizer)->sanitize($this->tempImagePath($this->withExif($this->jpegBytes(200, 100), $orientation), '.jpg'));

        $this->assertSame($expected, [$result->width, $result->height]);
    }

    public static function orientations(): array
    {
        return [
            '1' => [1, [200, 100]], '2' => [2, [200, 100]], '3' => [3, [200, 100]], '4' => [4, [200, 100]],
            '5' => [5, [100, 200]], '6' => [6, [100, 200]], '7' => [7, [100, 200]], '8' => [8, [100, 200]],
        ];
    }
}
