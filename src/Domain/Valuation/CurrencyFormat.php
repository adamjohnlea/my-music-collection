<?php
declare(strict_types=1);

namespace App\Domain\Valuation;

final class CurrencyFormat
{
    /** @var array<string, string> ISO 4217 code → display prefix. */
    private const SYMBOLS = [
        'USD' => '$',
        'GBP' => '£',
        'EUR' => '€',
        'JPY' => '¥',
        'AUD' => 'A$',
        'CAD' => 'CA$',
        'NZD' => 'NZ$',
        'MXN' => 'MX$',
        'BRL' => 'R$',
        'ZAR' => 'R',
    ];

    /**
     * Display prefix for an ISO currency code, e.g. `USD` → `$`.
     *
     * Falls back to the uppercased code itself for currencies without a mapped
     * symbol (e.g. `sek` → `SEK`), so amounts always render honestly. `$`-family
     * currencies are region-disambiguated (`AUD` → `A$`). Returns an empty string
     * for a null/empty code.
     */
    public static function symbol(?string $code): string
    {
        if ($code === null || $code === '') {
            return '';
        }
        $upper = strtoupper($code);
        return self::SYMBOLS[$upper] ?? $upper;
    }
}
