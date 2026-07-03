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
            new TwigFilter('discogs_markup', [$this, 'discogsMarkup'], ['is_safe' => ['html']]),
        ];
    }

    /**
     * Convert Discogs' reference markup in free-text fields to clean, safe HTML.
     *
     * Handles the notation Discogs uses in notes, credits, and titles:
     *   [b]/[i]/[u]        -> real emphasis
     *   [url=X]label[/url] -> link (http/https only)
     *   [a=Name]/[l=Name]  -> the plain name
     *   [a123]/[l123]/[r=123]/[m=123] -> a link to the matching Discogs page
     *
     * The input is HTML-escaped first, so every tag emitted here is controlled
     * and the result is safe to render without further escaping.
     */
    public function discogsMarkup(?string $text): string
    {
        if ($text === null || $text === '') {
            return '';
        }

        $out = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');

        // Emphasis
        $out = (string) preg_replace('/\[b\](.*?)\[\/b\]/is', '<strong>$1</strong>', $out);
        $out = (string) preg_replace('/\[i\](.*?)\[\/i\]/is', '<em>$1</em>', $out);
        $out = (string) preg_replace('/\[u\](.*?)\[\/u\]/is', '<u>$1</u>', $out);

        // Explicit links (validate scheme to avoid javascript: and friends)
        $out = (string) preg_replace_callback('/\[url=(.+?)\](.*?)\[\/url\]/is', function (array $m): string {
            return self::safeLink(html_entity_decode($m[1], ENT_QUOTES, 'UTF-8'), $m[2]);
        }, $out);
        $out = (string) preg_replace_callback('/\[url\](.+?)\[\/url\]/is', function (array $m): string {
            return self::safeLink(html_entity_decode($m[1], ENT_QUOTES, 'UTF-8'), $m[1]);
        }, $out);

        // Entity references by id -> Discogs page
        $refs = [
            '#\[r=?(\d+)\]#i' => 'release',
            '#\[m=?(\d+)\]#i' => 'master',
            '#\[a(\d+)\]#i'   => 'artist',
            '#\[l(\d+)\]#i'   => 'label',
        ];
        foreach ($refs as $pattern => $kind) {
            $out = (string) preg_replace(
                $pattern,
                '<a href="https://www.discogs.com/' . $kind . '/$1" target="_blank" rel="noopener">' . $kind . '</a>',
                $out
            );
        }

        // Named artist/label references -> plain name
        $out = (string) preg_replace('#\[a=([^\]]+)\]#i', '$1', $out);
        $out = (string) preg_replace('#\[l=([^\]]+)\]#i', '$1', $out);

        return $out;
    }

    /**
     * Build an anchor only for http/https URLs; otherwise fall back to the
     * (already-escaped) label text so unsafe schemes can't be clicked.
     */
    private static function safeLink(string $url, string $escapedLabel): string
    {
        if (!preg_match('#^https?://#i', $url)) {
            return $escapedLabel;
        }
        $href = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
        return '<a href="' . $href . '" target="_blank" rel="noopener">' . $escapedLabel . '</a>';
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
