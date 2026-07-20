<?php

namespace App\Services\Bow\VoterImport;

use Illuminate\Support\Str;

class LocationNormalizer
{
    public function text(mixed $value): string
    {
        $normalized = Str::upper(Str::ascii(trim((string) $value)));
        $normalized = preg_replace('/[^A-Z0-9]+/', ' ', $normalized) ?? $normalized;
        $normalized = preg_replace('/\s+/', ' ', $normalized) ?? $normalized;

        return trim($normalized);
    }

    public function compact(mixed $value): string
    {
        return str_replace(' ', '', $this->text($value));
    }

    public function locationKey(mixed $address, array $geography = []): string
    {
        $value = $this->text($address);
        foreach ($geography as $part) {
            $part = $this->text($part);
            if ($part !== '') {
                $value = preg_replace('/\b' . preg_quote($part, '/') . '\b/', ' ', $value) ?? $value;
            }
        }

        $value = preg_replace('/\b(PURPOK|PUROKK|PUROK\d*|PURO|PURK|PUOK|POROK|PROK|PREK|PRK|PK|RK|PR|BRGY|BARANGAY|SITIO|ZONE)\b/', ' ', $value) ?? $value;
        $value = preg_replace('/\s+/', ' ', $value) ?? $value;
        $value = trim($value);

        return $this->standardizePurokWords($value);
    }

    public function purokKey(mixed $name): string
    {
        return $this->locationKey($name);
    }

    public function precinctCode(mixed $value): string
    {
        return preg_replace('/[^A-Z0-9]/', '', $this->text($value)) ?? '';
    }

    public function same(mixed $left, mixed $right): bool
    {
        $leftText = $this->text($left);
        $rightText = $this->text($right);

        return $leftText !== '' && ($leftText === $rightText || $this->compact($leftText) === $this->compact($rightText));
    }

    public function rawAddressKey(mixed $address): string
    {
        $key = $this->text($address);
        return $key === '' ? '__BLANK__' : $key;
    }

    public function newPurokName(mixed $address): string
    {
        $name = trim(preg_replace('/\s+/u', ' ', (string) $address) ?? '');
        return $name === '' ? 'NO PUROK PROVIDED' : Str::upper(Str::limit($name, 150, ''));
    }

    public function similarity(mixed $left, mixed $right): float
    {
        $left = $this->standardizePurokWords($this->text($left));
        $right = $this->standardizePurokWords($this->text($right));
        if ($left === '' || $right === '') {
            return 0.0;
        }
        if ($left === $right || $this->compact($left) === $this->compact($right)) {
            return 100.0;
        }

        $leftNumbers = $this->numberTokens($left);
        $rightNumbers = $this->numberTokens($right);
        if ($leftNumbers !== $rightNumbers && ($leftNumbers !== [] || $rightNumbers !== [])) {
            return 0.0;
        }

        $maximumLength = max(strlen($left), strlen($right));
        $levenshteinScore = $maximumLength > 0
            ? (1 - (levenshtein($left, $right) / $maximumLength)) * 100
            : 0;
        similar_text($left, $right, $similarTextScore);

        return round(max($levenshteinScore, $similarTextScore), 2);
    }

    public function editDistance(mixed $left, mixed $right): int
    {
        return levenshtein(
            $this->compact($this->standardizePurokWords($this->text($left))),
            $this->compact($this->standardizePurokWords($this->text($right)))
        );
    }

    private function standardizePurokWords(string $value): string
    {
        $value = preg_replace('/^B\s+(?=SIKAT\b|SILANG\b)/', 'BAGONG ', $value) ?? $value;
        $value = preg_replace('/\bPAG\s*ASA\s+II\b/', 'PAG ASA 2', $value) ?? $value;
        $value = preg_replace('/\bPAG\s*ASA\s+I\b/', 'PAG ASA 1', $value) ?? $value;
        return trim(preg_replace('/\s+/', ' ', $value) ?? $value);
    }

    private function numberTokens(string $value): array
    {
        preg_match_all('/\b\d+\b/', $value, $matches);
        return array_values(array_unique($matches[0] ?? []));
    }
}
