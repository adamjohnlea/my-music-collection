<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Images\CoverColorExtractor;
use Imagick;
use ImagickPixel;
use PHPUnit\Framework\TestCase;

final class CoverColorExtractorTest extends TestCase
{
    protected function setUp(): void
    {
        if (!extension_loaded('imagick')) {
            $this->markTestSkipped('Imagick not available');
        }
    }

    private function solidPng(string $color): string
    {
        $path = tempnam(sys_get_temp_dir(), 'cover_') . '.png';
        $img = new Imagick();
        $img->newImage(20, 20, new ImagickPixel($color));
        $img->setImageFormat('png');
        $img->writeImage($path);
        $img->clear();
        return $path;
    }

    public function testExtractsSolidColour(): void
    {
        $path = $this->solidPng('rgb(200,50,50)');
        $hex = (new CoverColorExtractor())->extract($path);
        @unlink($path);
        $this->assertSame('#c83232', $hex);
    }

    public function testReturnsNullForMissingFile(): void
    {
        $this->assertNull((new CoverColorExtractor())->extract('/no/such/file.png'));
    }
}
