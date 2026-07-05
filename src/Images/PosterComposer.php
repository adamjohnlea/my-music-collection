<?php
declare(strict_types=1);

namespace App\Images;

use Imagick;
use ImagickDraw;
use ImagickPixel;

final class PosterComposer
{
    /**
     * @param array<int, array{path?: ?string, color?: ?string, caption?: ?string}> $tiles
     * @param array{cols:int, resolution:int, gap:int, bg:string, format:string, quality:int, title?: ?string, subtitle?: ?string} $opts
     */
    public function compose(array $tiles, array $opts, string $outPath): string
    {
        $count = count($tiles);
        $cols = max(1, (int)$opts['cols']);
        $rows = (int)ceil($count / $cols);
        $resolution = min(7200, max(1, (int)$opts['resolution']));
        $gap = max(0, (int)$opts['gap']);

        $tileSize = intdiv($resolution - ($gap * ($cols + 1)), $cols);
        $tileSize = max(1, $tileSize);

        $gridWidth = $cols * $tileSize + $gap * ($cols + 1);
        $gridHeight = $rows * $tileSize + $gap * ($rows + 1);

        $title = trim((string)($opts['title'] ?? ''));
        $subtitle = trim((string)($opts['subtitle'] ?? ''));
        $hasFooter = ($title !== '' || $subtitle !== '');
        $footerHeight = $hasFooter ? max(60, intdiv($gridWidth, 12)) : 0;

        $width = $gridWidth;
        $height = $gridHeight + $footerHeight;

        $isPng = ($opts['format'] === 'png');

        $canvas = new Imagick();
        $canvas->newImage($width, $height, new ImagickPixel($opts['bg']));
        $canvas->setImageFormat($isPng ? 'png' : 'jpeg');

        foreach (array_values($tiles) as $i => $tile) {
            $col = $i % $cols;
            $row = intdiv($i, $cols);
            $x = $gap + $col * ($tileSize + $gap);
            $y = $gap + $row * ($tileSize + $gap);
            $tileImg = $this->renderTile($tile, $tileSize);
            $canvas->compositeImage($tileImg, Imagick::COMPOSITE_OVER, $x, $y);
            $tileImg->clear();
        }

        if ($hasFooter) {
            $this->drawFooter($canvas, $gridHeight, $footerHeight, $title, $subtitle);
        }

        if (!$isPng) {
            $canvas->setImageCompressionQuality(max(1, min(100, (int)$opts['quality'])));
        }
        $canvas->writeImage($outPath);
        $canvas->clear();

        return $outPath;
    }

    /** Draw the title/subtitle band starting at y=$top with height $footerHeight. */
    private function drawFooter(Imagick $canvas, int $top, int $footerHeight, string $title, string $subtitle): void
    {
        try {
            if ($title !== '') {
                $draw = new ImagickDraw();
                $draw->setFillColor(new ImagickPixel('#ffffff'));
                $draw->setFontSize(max(14.0, $footerHeight * 0.35));
                $draw->setGravity(Imagick::GRAVITY_NORTH);
                // GRAVITY_NORTH: y offset is distance from the top of the canvas.
                $canvas->annotateImage($draw, 0, $top + $footerHeight * 0.15, 0, $title);
            }
            if ($subtitle !== '') {
                $draw2 = new ImagickDraw();
                $draw2->setFillColor(new ImagickPixel('#bbbbbb'));
                $draw2->setFontSize(max(10.0, $footerHeight * 0.20));
                $draw2->setGravity(Imagick::GRAVITY_NORTH);
                $canvas->annotateImage($draw2, 0, $top + $footerHeight * 0.58, 0, $subtitle);
            }
        } catch (\Throwable $e) {
            // No font available — leave the band as a solid colour rather than fail the poster.
        }
    }

    /** @param array{path?: ?string, color?: ?string, caption?: ?string} $tile */
    private function renderTile(array $tile, int $size): Imagick
    {
        $path = $tile['path'] ?? null;
        if ($path !== null && is_file($path)) {
            try {
                $img = new Imagick($path);
                $img->cropThumbnailImage($size, $size); // centre-crop to a square
                return $img;
            } catch (\Throwable $e) {
                // fall through to placeholder
            }
        }
        return $this->placeholder($tile, $size);
    }

    /** @param array{path?: ?string, color?: ?string, caption?: ?string} $tile */
    private function placeholder(array $tile, int $size): Imagick
    {
        $color = $tile['color'] ?? '#333333';
        $img = new Imagick();
        $img->newImage($size, $size, new ImagickPixel($color));
        $img->setImageFormat('png');

        $caption = trim((string)($tile['caption'] ?? ''));
        if ($caption !== '') {
            try {
                $draw = new ImagickDraw();
                $draw->setFillColor(new ImagickPixel('#ffffff'));
                $draw->setFontSize(max(8.0, $size / 12));
                $draw->setGravity(Imagick::GRAVITY_CENTER);
                $img->annotateImage($draw, 0, 0, 0, $caption);
            } catch (\Throwable $e) {
                // no font available — leave the solid tile as-is
            }
        }
        return $img;
    }
}
