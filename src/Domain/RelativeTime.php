<?php
declare(strict_types=1);

namespace App\Domain;

final class RelativeTime
{
    /** Human-readable "time ago" for an ISO-8601 timestamp; '' if unparseable. */
    public static function ago(string $iso, int $nowTs): string
    {
        $ts = strtotime($iso);
        if ($ts === false) {
            return '';
        }
        $diff = max(0, $nowTs - $ts);
        if ($diff < 60) {
            return 'just now';
        }
        if ($diff < 3600) {
            return (int)floor($diff / 60) . 'm ago';
        }
        if ($diff < 86400) {
            return (int)floor($diff / 3600) . 'h ago';
        }
        return (int)floor($diff / 86400) . 'd ago';
    }
}
