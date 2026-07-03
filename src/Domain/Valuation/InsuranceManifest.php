<?php
declare(strict_types=1);

namespace App\Domain\Valuation;

final class InsuranceManifest
{
    /**
     * Build a CSV insurance manifest from valuation rows and scope totals.
     *
     * @param array<int, array<string, mixed>> $rows
     * @param array{total: float, item_count: int, valued_count: int, currency: ?string} $totals
     */
    public static function toCsv(array $rows, array $totals): string
    {
        $lines = [];
        $lines[] = self::csvLine(['Artist', 'Title', 'Condition', 'Value', 'Currency', 'Source']);
        foreach ($rows as $r) {
            $lines[] = self::csvLine([
                (string)($r['artist'] ?? ''),
                (string)($r['title'] ?? ''),
                (string)($r['condition_used'] ?? ''),
                $r['value'] !== null ? number_format((float)$r['value'], 2, '.', '') : '',
                (string)($r['currency'] ?? ''),
                (string)($r['source'] ?? ''),
            ]);
        }
        $lines[] = '';
        $lines[] = self::csvLine(['Total', '', '', number_format($totals['total'], 2, '.', ''), (string)($totals['currency'] ?? ''), '']);
        $lines[] = self::csvLine(['Coverage', $totals['valued_count'] . ' of ' . $totals['item_count'] . ' valued']);
        return implode("\n", $lines) . "\n";
    }

    /** @param array<int, string> $fields */
    private static function csvLine(array $fields): string
    {
        return implode(',', array_map(static function (string $f): string {
            if (preg_match('/[",\n]/', $f) === 1) {
                return '"' . str_replace('"', '""', $f) . '"';
            }
            return $f;
        }, $fields));
    }
}
