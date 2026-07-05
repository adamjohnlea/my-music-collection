<?php
declare(strict_types=1);

namespace App\Domain\Wantlist;

final class PriceSparkline
{
    /**
     * Build an SVG polyline model from a want's price history.
     *
     * @param array<int, array{lowest_price: float, captured_at: string}> $history ASC by captured_at
     * @return array{points:string, last_down:bool}|null
     */
    public static function build(array $history, int $width = 80, int $height = 24): array|null
    {
        $n = count($history);
        if ($n < 2) {
            return null;
        }

        $values = array_values(array_map(static fn (array $h): float => $h['lowest_price'], $history));
        $min = min($values);
        $max = max($values);
        $span = $max - $min;

        $parts = [];
        foreach ($values as $i => $v) {
            $x = ($i / ($n - 1)) * $width;
            // Flat series -> vertical middle; else invert so lower price sits lower on screen.
            $y = $span > 0.0 ? $height - (($v - $min) / $span) * $height : $height / 2;
            $parts[] = sprintf('%.2f,%.2f', $x, $y);
        }

        return [
            'points' => implode(' ', $parts),
            'last_down' => $values[$n - 1] < $values[0],
        ];
    }
}
