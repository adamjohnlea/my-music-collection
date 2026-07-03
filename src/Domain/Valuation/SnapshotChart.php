<?php
declare(strict_types=1);

namespace App\Domain\Valuation;

final class SnapshotChart
{
    /**
     * Build an SVG polyline `points` string from a list of value snapshots.
     *
     * X is scaled linearly across `$width` by index (first=0, last=$width).
     * Y is inverted so that the maximum value maps to y=0 (top) and a zero
     * value maps to y=$height (bottom). Empty input returns an empty string.
     *
     * @param array<int, array{total_value: float|string, captured_at: string}> $snapshots
     * @param int $width  SVG viewport width in user units
     * @param int $height SVG viewport height in user units
     * @return string Space-separated "x,y" pairs suitable for `<polyline points="...">`, or '' if empty.
     */
    public static function polylinePoints(array $snapshots, int $width, int $height): string
    {
        $n = count($snapshots);
        if ($n === 0) {
            return '';
        }

        $values = array_map(static fn($s): float => (float) $s['total_value'], $snapshots);
        $max = max($values);
        $max = $max > 0.0 ? $max : 1.0;

        $points = [];
        foreach ($values as $i => $v) {
            $x = $n === 1 ? 0 : (int) round($i * $width / ($n - 1));
            $y = (int) round($height - ($v / $max) * $height);
            $points[] = $x . ',' . $y;
        }

        return implode(' ', $points);
    }
}
