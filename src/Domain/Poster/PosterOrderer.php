<?php
declare(strict_types=1);

namespace App\Domain\Poster;

final class PosterOrderer
{
    /**
     * @param array<int, array<string,mixed>> $rows
     * @return array<int, array<string,mixed>>
     */
    public function order(array $rows, string $key, int $seed = 0): array
    {
        $rows = array_values($rows);

        if ($key === 'shuffle') {
            mt_srand($seed);
            for ($i = count($rows) - 1; $i > 0; $i--) {
                $j = mt_rand(0, $i);
                [$rows[$i], $rows[$j]] = [$rows[$j], $rows[$i]];
            }
            return $rows;
        }

        $cmp = match ($key) {
            'artist'    => fn($a, $b) => strcasecmp((string)$a['artist'], (string)$b['artist']),
            'title'     => fn($a, $b) => strcasecmp((string)$a['title'], (string)$b['title']),
            'year'      => fn($a, $b) => ((int)($a['year'] ?? 0)) <=> ((int)($b['year'] ?? 0)),
            'rating'    => fn($a, $b) => ((int)($b['rating'] ?? 0)) <=> ((int)($a['rating'] ?? 0)),
            'valuation' => fn($a, $b) => ((float)($b['valuation'] ?? 0)) <=> ((float)($a['valuation'] ?? 0)),
            'added'     => fn($a, $b) => strcmp((string)($b['added_at'] ?? ''), (string)($a['added_at'] ?? '')),
            'color'     => fn($a, $b) => $this->colorKey((string)($a['cover_color'] ?? '')) <=> $this->colorKey((string)($b['cover_color'] ?? '')),
            default     => fn($a, $b) => 0,
        };

        usort($rows, fn($a, $b) => $cmp($a, $b) ?: (((int)$a['id']) <=> ((int)$b['id'])));
        return $rows;
    }

    /** Sortable key: hue*1000 + lightness. Missing/invalid colors sort last. */
    private function colorKey(string $hex): float
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) !== 6 || !ctype_xdigit($hex)) {
            return 9_999_999.0;
        }
        $r = hexdec(substr($hex, 0, 2)) / 255;
        $g = hexdec(substr($hex, 2, 2)) / 255;
        $b = hexdec(substr($hex, 4, 2)) / 255;
        $max = max($r, $g, $b);
        $min = min($r, $g, $b);
        $l = ($max + $min) / 2;
        $d = $max - $min;
        if ($d == 0.0) {
            $h = 0.0;
        } elseif ($max === $r) {
            $h = fmod((($g - $b) / $d), 6);
        } elseif ($max === $g) {
            $h = (($b - $r) / $d) + 2;
        } else {
            $h = (($r - $g) / $d) + 4;
        }
        $h *= 60;
        if ($h < 0) {
            $h += 360;
        }
        return $h * 1000 + $l;
    }
}
