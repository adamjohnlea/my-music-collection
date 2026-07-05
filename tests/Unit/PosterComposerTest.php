<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Images\PosterComposer;
use Imagick;
use ImagickPixel;
use PHPUnit\Framework\TestCase;

final class PosterComposerTest extends TestCase
{
    protected function setUp(): void
    {
        if (!extension_loaded('imagick')) {
            $this->markTestSkipped('Imagick not available');
        }
    }

    private function solid(string $color): string
    {
        $path = tempnam(sys_get_temp_dir(), 'tile_') . '.png';
        $img = new Imagick();
        $img->newImage(30, 30, new ImagickPixel($color));
        $img->setImageFormat('png');
        $img->writeImage($path);
        $img->clear();
        return $path;
    }

    public function testComposesTwoByTwoGridAtRequestedResolution(): void
    {
        $tiles = [
            ['path' => $this->solid('rgb(255,0,0)')],
            ['path' => $this->solid('rgb(0,255,0)')],
            ['path' => $this->solid('rgb(0,0,255)')],
            ['path' => null, 'color' => '#123456'], // placeholder
        ];
        $out = tempnam(sys_get_temp_dir(), 'poster_') . '.png';

        $result = (new PosterComposer())->compose($tiles, [
            'cols' => 2, 'resolution' => 100, 'gap' => 0,
            'bg' => '#000000', 'format' => 'png', 'quality' => 90,
        ], $out);

        $this->assertFileExists($result);
        $img = new Imagick($result);
        $this->assertSame(100, $img->getImageWidth());
        $this->assertSame(100, $img->getImageHeight());
        $img->clear();

        foreach ($tiles as $t) {
            if ($t['path']) { @unlink($t['path']); }
        }
        @unlink($out);
    }

    public function testFooterBandAddsHeightWhenTitlePresent(): void
    {
        $tiles = [['path' => $this->solid('rgb(255,0,0)')], ['path' => $this->solid('rgb(0,255,0)')]];
        $out = tempnam(sys_get_temp_dir(), 'poster_') . '.png';

        (new PosterComposer())->compose($tiles, [
            'cols' => 2, 'resolution' => 100, 'gap' => 0,
            'bg' => '#000000', 'format' => 'png', 'quality' => 90,
            'title' => 'My Collection', 'subtitle' => '2 releases  •  2026-07-04',
        ], $out);

        $img = new Imagick($out);
        // Grid is 100x50 (2 cols, 1 row of 50px tiles); footer adds max(60, 100/12)=60 → 110 tall.
        $this->assertSame(100, $img->getImageWidth());
        $this->assertSame(110, $img->getImageHeight());
        $img->clear();

        foreach ($tiles as $t) { @unlink($t['path']); }
        @unlink($out);
    }
}
