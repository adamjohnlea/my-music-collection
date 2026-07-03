<?php

namespace App\Presentation\Twig;

use App\Domain\Valuation\CurrencyFormat;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

final class DiscogsFilters extends AbstractExtension
{
    public function getFilters(): array
    {
        return [
            new TwigFilter('strip_discogs_suffix', [$this, 'stripDiscogsSuffix']),
            new TwigFilter('currency_symbol', [$this, 'currencySymbol']),
        ];
    }

    /**
     * Display prefix for an ISO currency code, e.g. "USD" -> "$".
     * Falls back to the code itself for unmapped currencies.
     */
    public function currencySymbol(?string $code): string
    {
        return CurrencyFormat::symbol($code);
    }

    /**
     * Strip trailing numeric Discogs disambiguation suffix like "Artist Name (2)" -> "Artist Name".
     * Only strips when the pattern is exactly a space + (digits) at the end of the string.
     */
    public function stripDiscogsSuffix(?string $name): ?string
    {
        if ($name === null || $name === '') {
            return $name;
        }
        return (string) preg_replace('/\s\(\d+\)$/', '', $name);
    }
}
