<?php
declare(strict_types=1);

namespace App\Domain\Valuation;

final class ConditionGrades
{
    /** @var array<int, string> Best → worst; keys match Discogs price_suggestions keys. */
    public const CANONICAL = [
        'Mint (M)',
        'Near Mint (NM or M-)',
        'Very Good Plus (VG+)',
        'Very Good (VG)',
        'Good Plus (G+)',
        'Good (G)',
        'Fair (F)',
        'Poor (P)',
    ];

    public static function normalize(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }
        $clean = trim((string)preg_replace('/\s+/', ' ', $raw));
        if ($clean === '') {
            return null;
        }
        foreach (self::CANONICAL as $grade) {
            if (strcasecmp($clean, $grade) === 0) {
                return $grade;
            }
        }
        return null;
    }

    public static function mediaConditionFromNotes(?string $notesJson): ?string
    {
        if ($notesJson === null || $notesJson === '') {
            return null;
        }
        $decoded = json_decode($notesJson, true);
        if (!is_array($decoded)) {
            return null;
        }
        foreach ($decoded as $field) {
            if (is_array($field) && (int)($field['field_id'] ?? 0) === 1) {
                return self::normalize(isset($field['value']) ? (string)$field['value'] : null);
            }
        }
        return null;
    }
}
