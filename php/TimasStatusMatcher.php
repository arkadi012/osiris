<?php

/**
 * Matches TIMAS board entries to OSIRIS persons and exposes a safe status color.
 *
 * Matching is deliberately performed in ordered, one-to-one phases. Exact names
 * always win before accents, transliterations, additional given names, or a
 * single-character spelling difference are considered.
 */
final class TimasStatusMatcher
{
    public const UNMATCHED_COLOR = '#c0c0c0';

    private const STATUS_COLORS = [
        'anwesend' => '#00ff00',
        'homeoffice' => '#ff9900',
        'abwesend' => '#ffffff',
    ];

    private array $entries = [];

    public function __construct(string $snapshotPath)
    {
        if (!is_readable($snapshotPath)) {
            return;
        }

        $contents = file_get_contents($snapshotPath);
        if ($contents === false) {
            return;
        }

        $snapshot = json_decode($contents, true);
        if (!is_array($snapshot) || !isset($snapshot['mitarbeiter']) || !is_array($snapshot['mitarbeiter'])) {
            return;
        }

        foreach ($snapshot['mitarbeiter'] as $index => $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $first = trim((string) ($entry['vorname'] ?? ''));
            $last = trim((string) ($entry['nachname'] ?? ''));
            if ($first === '' && $last === '') {
                continue;
            }

            $status = strtolower(trim((string) ($entry['status'] ?? '')));
            if (!isset(self::STATUS_COLORS[$status])) {
                $status = $this->statusFromColor((string) ($entry['color'] ?? ''));
            }

            $this->entries[(string) $index] = array_merge(
                $this->prepareName($first, $last),
                ['status' => $status]
            );
        }
    }

    /**
     * @param array<int, array|object> $persons
     * @return array<string, array{status: string, color: string, matched: bool}>
     */
    public function matchPersons(array $persons): array
    {
        $personData = [];
        $result = [];

        foreach ($persons as $person) {
            $person = (array) $person;
            $username = trim((string) ($person['username'] ?? ''));
            if ($username === '') {
                continue;
            }

            $personData[$username] = $this->prepareName(
                (string) ($person['first'] ?? ''),
                (string) ($person['last'] ?? '')
            );
            $result[$username] = $this->statusResult('unmatched', false);
        }

        $remainingPersons = array_fill_keys(array_keys($personData), true);
        $remainingEntries = array_fill_keys(array_keys($this->entries), true);

        $strategies = [
            fn(array $person, array $entry): bool =>
                $person['basic_name'] === $entry['basic_name'],

            fn(array $person, array $entry): bool =>
                $person['folded_name'] === $entry['folded_name'],

            fn(array $person, array $entry): bool =>
                $person['folded_last'] !== '' &&
                $person['folded_last'] === $entry['folded_last'] &&
                count(array_intersect($person['first_tokens'], $entry['first_tokens'])) > 0,

            fn(array $person, array $entry): bool =>
                levenshtein($person['folded_name'], $entry['folded_name']) <= 1,
        ];

        foreach ($strategies as $strategy) {
            $this->assignMutualUniqueMatches(
                $personData,
                $remainingPersons,
                $remainingEntries,
                $result,
                $strategy
            );
        }

        return $result;
    }

    /**
     * Assign only pairs that are unique from both the OSIRIS and TIMAS side.
     */
    private function assignMutualUniqueMatches(
        array $persons,
        array &$remainingPersons,
        array &$remainingEntries,
        array &$result,
        callable $matches
    ): void {
        $personCandidates = [];
        $entryCandidates = [];

        foreach (array_keys($remainingPersons) as $username) {
            foreach (array_keys($remainingEntries) as $entryId) {
                if (!$matches($persons[$username], $this->entries[$entryId])) {
                    continue;
                }

                $personCandidates[$username][] = $entryId;
                $entryCandidates[$entryId][] = $username;
            }
        }

        foreach ($personCandidates as $username => $entryIds) {
            if (count($entryIds) !== 1) {
                continue;
            }

            $entryId = $entryIds[0];
            if (count($entryCandidates[$entryId] ?? []) !== 1) {
                continue;
            }

            $result[$username] = $this->statusResult($this->entries[$entryId]['status'], true);
            unset($remainingPersons[$username], $remainingEntries[$entryId]);
        }
    }

    private function statusResult(string $status, bool $matched): array
    {
        return [
            'status' => $status,
            'color' => self::STATUS_COLORS[$status] ?? self::UNMATCHED_COLOR,
            'matched' => $matched,
        ];
    }

    private function statusFromColor(string $color): string
    {
        $color = strtolower(trim($color));
        $status = array_search($color, self::STATUS_COLORS, true);
        return $status === false ? 'unbekannt' : $status;
    }

    private function prepareName(string $first, string $last): array
    {
        $first = trim($first);
        $last = trim($last);

        return [
            'first' => $first,
            'last' => $last,
            'basic_name' => $this->basicName($first, $last),
            'folded_name' => $this->foldedName($first, $last),
            'folded_last' => $this->fold($last),
            'first_tokens' => $this->foldedTokens($first),
        ];
    }

    private function basicName(string $first, string $last): string
    {
        return $this->basic($first) . '|' . $this->basic($last);
    }

    private function basic(string $value): string
    {
        $value = mb_strtolower(trim($value), 'UTF-8');
        return preg_replace('/\s+/u', ' ', $value) ?? $value;
    }

    private function foldedName(string $first, string $last): string
    {
        return $this->fold($first . ' ' . $last);
    }

    private function foldedTokens(string $value): array
    {
        $tokens = preg_split('/[\s\-]+/u', trim($value), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $tokens = array_map(fn(string $token): string => $this->fold($token), $tokens);
        return array_values(array_unique(array_filter($tokens, fn(string $token): bool => $token !== '')));
    }

    private function fold(string $value): string
    {
        $value = mb_strtolower(trim($value), 'UTF-8');
        $value = strtr($value, [
            'ä' => 'a', 'ö' => 'o', 'ü' => 'u', 'ß' => 'ss',
            'æ' => 'ae', 'œ' => 'oe', 'ø' => 'o', 'ł' => 'l',
        ]);

        if (function_exists('iconv')) {
            $transliterated = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
            if ($transliterated !== false) {
                $value = $transliterated;
            }
        }

        $value = strtolower($value);
        $value = str_replace(['ae', 'oe', 'ue'], ['a', 'o', 'u'], $value);
        return preg_replace('/[^a-z0-9]+/', '', $value) ?? '';
    }
}
