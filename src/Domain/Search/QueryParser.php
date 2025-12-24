<?php
declare(strict_types=1);

namespace App\Domain\Search;

/**
 * Extracted from public/index.php to make the search parsing testable and reusable.
 * The logic is intentionally kept identical to the original function.
 */
final class QueryParser
{
    /**
     * Parse advanced search query into an FTS MATCH string and optional year filters.
     * Returns an array with keys: match, year_from, year_to, chips.
     */
    public function parse(string $q): array
    {
        $q = trim($q);
        // Normalize: remove any whitespace immediately after a field prefix like artist:, year:, label:, etc.
        // This turns 'year: 1980' into 'year:1980' so parsing is consistent.
        $q = preg_replace('/(\b\w+):\s+/', '$1:', $q);
        if ($q === '') return ['match' => '', 'chips' => []];

        $tokens = [];
        $buf = '';
        $inQuotes = false;
        for ($i = 0, $n = strlen($q); $i < $n; $i++) {
            $ch = $q[$i];
            if ($ch === '"') { $inQuotes = !$inQuotes; $buf .= $ch; continue; }
            if (!$inQuotes && ctype_space($ch)) {
                if ($buf !== '') { $tokens[] = $buf; $buf = ''; }
                continue;
            }
            $buf .= $ch;
        }
        if ($buf !== '') $tokens[] = $buf;

        $colMap = [
            'artist' => 'artist',
            'title' => 'title',
            'label' => 'label_text',
            'format' => 'format_text',
            'genre' => 'genre_style_text',
            'style' => 'genre_style_text',
            'country' => 'country',
            'credit' => 'credit_text',
            'company' => 'company_text',
            'identifier' => 'identifier_text',
            'barcode' => 'identifier_text',
            'notes' => 'release_notes', // also search user_notes separately
        ];

        $ftsParts = [];
        $chips = [];
        $yearFrom = null; $yearTo = null;
        $general = [];
        $isDiscogsSearch = false;

        foreach ($tokens as $tok) {
            $tok = trim($tok);
            if ($tok === '') continue;

            // discogs search prefix
            if (str_starts_with(strtolower($tok), 'discogs:')) {
                $isDiscogsSearch = true;
                $tok = substr($tok, 8);
                if ($tok === '') continue;
            }

            // year filter
            if (str_starts_with(strtolower($tok), 'year:')) {
                $range = substr($tok, 5);
                if (preg_match('/^(\d{4})\.\.(\d{4})$/', $range, $m)) {
                    $yearFrom = (int)$m[1]; $yearTo = (int)$m[2];
                    $chips[] = ['label' => 'Year '.$m[1].'–'.$m[2]];
                    continue;
                } elseif (preg_match('/^(\d{4})$/', $range, $m)) {
                    $yearFrom = (int)$m[1]; $yearTo = (int)$m[1];
                    $chips[] = ['label' => 'Year '.$m[1]];
                    continue;
                }
            }

            // fielded token key:"value" or key:value
            if (preg_match('/^(\w+):(.*)$/', $tok, $m)) {
                $key = strtolower($m[1]);
                $val = trim($m[2]);
                if ($val === '') continue;
                $quoted = $val;
                if ($quoted[0] !== '"') {
                    // add prefix wildcard to last term if not quoted
                    if (!str_contains($quoted, ' ') && !str_ends_with($quoted, '*')) $quoted .= '*';
                }
                if (isset($colMap[$key])) {
                    $col = $colMap[$key];
                    if ($key === 'notes') {
                        $ftsParts[] = $col.':'.$quoted;
                        $ftsParts[] = 'user_notes:'.$quoted;
                        $chips[] = ['label' => 'Notes: '.trim($val, '"')];
                    } else {
                        $ftsParts[] = $col.':'.$quoted;
                        $chips[] = ['label' => ucfirst($key).': '.trim($val, '"')];
                    }
                    continue;
                }
            }

            // general term → prefix
            $t = strtolower($tok);
            $t = preg_replace('/[^a-z0-9\-\*\s\"]+/', ' ', $t);
            if ($t === '') continue;
            if ($t[0] !== '"' && !str_ends_with($t, '*')) $t .= '*';
            $general[] = $t;
        }

        if ($general) {
            $ftsParts = array_merge($general, $ftsParts);
        }

        $match = implode(' ', $ftsParts);
        return [
            'match' => $match,
            'year_from' => $yearFrom,
            'year_to' => $yearTo,
            'chips' => $chips,
            'is_discogs' => $isDiscogsSearch,
        ];
    }
}
