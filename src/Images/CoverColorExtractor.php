<?php
declare(strict_types=1);

namespace App\Images;

use Imagick;

final class CoverColorExtractor
{
    /** Returns the average colour of the image as #rrggbb, or null if unreadable. */
    public function extract(string $path): ?string
    {
        if (!is_file($path)) {
            return null;
        }
        $img = null;
        try {
            $img = new Imagick($path);
            $img->setImageColorspace(Imagick::COLORSPACE_SRGB);
            $img->resizeImage(1, 1, Imagick::FILTER_LANCZOS, 1);
            $c = $img->getImagePixelColor(0, 0)->getColor();
            return sprintf('#%02x%02x%02x', (int)$c['r'], (int)$c['g'], (int)$c['b']);
        } catch (\Throwable) {
            return null;
        } finally {
            $img?->clear();
        }
    }
}
