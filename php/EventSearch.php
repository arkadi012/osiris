<?php

/**
 * Shared, dependency-free event matching for autocomplete and duplicate warnings.
 *
 * No search index is required: projected documents stream in batches of 128 and
 * only the best results remain in memory. This deliberately scans all events so
 * a query in which every word contains a typo cannot be lost to a regex prefilter.
 * CPU and database reads are O(events). Both database execution and elapsed scan
 * time have a three-second budget; timeouts fail explicitly, never return an
 * incomplete shortlist. A future index requires an explicit data policy.
 */
class EventSearch
{
    public const MAX_QUERY_LENGTH = 200;
    public const MAX_RESULTS = 10;
    // Covers every token in the endpoint's 200-character query and the form's
    // 500-character title, even if each word is only one character long.
    private const MAX_TOKENS = 250;
    private const MAX_MATCH_BYTES = 16384;
    private const PROJECTION = [
        '_id' => 1, 'title' => 1, 'title_full' => 1, 'start' => 1,
        'end' => 1, 'location' => 1, 'country' => 1, 'year' => 1,
    ];

    private $collection;

    public function __construct($collection)
    {
        $this->collection = $collection;
    }

    /** Normalize typography without changing the canonical values returned to callers. */
    public static function normalize(string $text): string
    {
        $text = self::matchingText($text);
        if ($text === '') {
            return '';
        }
        // Compose decomposed umlauts even on deployments without ext-intl.
        $text = strtr($text, [
            "a\u{0308}" => 'ä', "o\u{0308}" => 'ö', "u\u{0308}" => 'ü',
            "A\u{0308}" => 'Ä', "O\u{0308}" => 'Ö', "U\u{0308}" => 'Ü',
            'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'Ä' => 'ae', 'Ö' => 'oe', 'Ü' => 'ue',
            'ß' => 'ss', 'ẞ' => 'ss', 'æ' => 'ae', 'Æ' => 'ae', 'œ' => 'oe', 'Œ' => 'oe',
        ]);
        // strtr does not reprocess its own replacements.
        $text = strtr($text, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'Ä' => 'ae', 'Ö' => 'oe', 'Ü' => 'ue']);
        if (class_exists('Normalizer')) {
            $text = Normalizer::normalize($text, Normalizer::FORM_KD) ?: $text;
        }
        $text = preg_replace('/\p{M}+/u', '', $text);
        // Deterministic fallback for the Docker images, which do not require
        // intl/mbstring. Avoid iconv's locale-dependent Alpine transliteration.
        $text = strtr($text, self::characterMap());
        $text = function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
        $text = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text);
        return trim(preg_replace('/\s+/u', ' ', $text));
    }

    /** @return array<int, array<string, mixed>> */
    public function search(string $query, int $limit = 10, ?string $excludeId = null, array $context = []): array
    {
        $query = self::normalize($query);
        if (self::length($query) < 2) {
            return [];
        }
        $tokens = self::tokens($query, self::MAX_TOKENS);
        return $this->scan($query, $tokens, max(1, min(self::MAX_RESULTS, $limit)), $excludeId, $context);
    }

    /**
     * Conservative warnings, never identity decisions. Different known start
     * dates/years and numeric title tokens represent distinct editions.
     */
    public function likelyDuplicates(array $values, ?string $excludeId = null): array
    {
        $queries = [];
        foreach (['title', 'title_full'] as $field) {
            $query = self::normalize(self::stringValue($values[$field] ?? ''));
            if (self::length($query) >= 2) {
                $queries[$query] = self::tokens($query, self::MAX_TOKENS);
            }
        }
        if (!$queries) {
            return [];
        }
        // Filter while streaming, before the top-k cut, so unrelated editions
        // cannot displace a duplicate further down the collection.
        $results = [];
        foreach ($this->documents() as $document) {
            $event = self::eventData($document);
            if ($event['id'] === $excludeId || !$this->sameEdition($values, $event, $document)) {
                continue;
            }
            foreach ($queries as $query => $tokens) {
                $match = $this->rank($query, $tokens, $event, $document, $values);
                if (!$match || !$match['match']['all_words'] || $match['match']['quality'] < 60) {
                    continue;
                }
                // A single generic word in a long title is not a likely duplicate.
                $variantTokens = self::tokens(self::normalize($event[$match['match']['field']]), self::MAX_TOKENS);
                $reverse = self::assign($variantTokens, $tokens);
                $reverseCoverage = count($variantTokens) ? $reverse['count'] / count($variantTokens) : 0;
                if (!$match['match']['exact_title'] && $reverseCoverage < 0.75) {
                    continue;
                }
                $results[$event['id']] = isset($results[$event['id']]) && self::compare($results[$event['id']], $match) < 0
                    ? $results[$event['id']] : $match;
                $results = $this->trim($results, self::MAX_RESULTS);
            }
        }
        return array_values($results);
    }

    /** Public canonical event shape used by suggestions and exact-duplicate responses. */
    public static function eventData($document): array
    {
        $result = ['id' => self::stringValue(self::field($document, '_id'))];
        foreach (['title', 'title_full', 'start', 'end', 'location', 'country'] as $field) {
            $result[$field] = self::stringValue(self::field($document, $field));
        }
        return $result;
    }

    private function documents(): iterable
    {
        $deadline = hrtime(true) + 3000000000;
        $cursor = $this->collection->find([], [
            'projection' => self::PROJECTION,
            'batchSize' => 128,
            'maxTimeMS' => 3000,
        ]);
        foreach ($cursor as $document) {
            if (hrtime(true) > $deadline) {
                throw new RuntimeException('Event search exceeded its scan time budget.');
            }
            yield $document;
        }
    }

    private function scan(string $query, array $tokens, int $limit, ?string $excludeId, array $context): array
    {
        $results = [];
        foreach ($this->documents() as $document) {
            $event = self::eventData($document);
            if ($event['id'] === $excludeId) {
                continue;
            }
            $match = $this->rank($query, $tokens, $event, $document, $context);
            if ($match) {
                $results[] = $match;
                $results = $this->trim($results, $limit);
            }
        }
        return array_values($results);
    }

    private function rank(string $query, array $tokens, array $event, $document, array $context): ?array
    {
        $best = null;
        $year = self::year($event['start'], self::field($document, 'year'));
        foreach (['title', 'title_full'] as $field) {
            $title = self::normalize($event[$field]);
            if ($title === '') {
                continue;
            }
            $titleTokens = self::tokens($title, self::MAX_TOKENS);
            // A query may include an edition year even when the title omits it.
            if ($year !== '' && !in_array($year, $titleTokens, true)) {
                $titleTokens[] = $year;
            }
            $matched = self::assign($tokens, $titleTokens);
            if (!$matched['count']) {
                continue;
            }
            $coverage = $matched['count'] / count($tokens);
            $quality = $matched['quality'] / $matched['count'];
            $exact = $query === $title;
            // Coverage dominates every quality/context signal. Exact titles
            // dominate all other matches. Context is only a small tie signal.
            // Every result in a request shares the same query denominator, so
            // matched-word count is equivalent to query coverage for ranking.
            // Using the count also raises confidence as matching words are added.
            $score = ($exact ? 100000000 : 0) + $matched['count'] * 100000
                + (int)round($quality * 100) + $this->contextScore($event, $year, $context);
            $match = $event + ['score' => $score, 'match' => [
                'field' => $field,
                'exact_title' => $exact,
                'matched_words' => $matched['count'],
                'query_words' => count($tokens),
                'coverage' => round($coverage, 4),
                'all_words' => $matched['count'] === count($tokens),
                'quality' => round($quality, 2),
            ]];
            if (!$best || self::compare($match, $best) < 0) {
                $best = $match;
            }
        }
        return $best;
    }

    /** Maximum-weight one-to-one token assignment (rectangular Hungarian method). */
    private static function assign(array $query, array $title): array
    {
        if (!$query || !$title) {
            return ['count' => 0, 'quality' => 0];
        }
        $rows = [];
        foreach ($query as $word) {
            $row = [];
            foreach ($title as $j => $target) {
                $quality = self::tokenQuality($word, $target);
                if ($quality) {
                    $row[$j + 1] = -(100000 + $quality);
                }
            }
            // Impossible words do not participate in assignment. They remain
            // in rank()'s coverage denominator and cannot be counted as matched.
            if ($row) {
                $rows[] = $row;
            }
        }
        $n = count($rows);
        if (!$n) {
            return ['count' => 0, 'quality' => 0];
        }
        $m = count($title) + $n; // Dummy columns permit unmatched query words.
        $cost = [];
        foreach ($rows as $i => $row) {
            $cost[$i + 1] = $row + array_fill(0, $m + 1, 0);
        }
        $u = $v = $p = $way = array_fill(0, $m + 1, 0);
        for ($i = 1; $i <= $n; $i++) {
            $p[0] = $i;
            $j0 = 0;
            $minv = array_fill(0, $m + 1, PHP_INT_MAX);
            $used = array_fill(0, $m + 1, false);
            do {
                $used[$j0] = true;
                $i0 = $p[$j0];
                $delta = PHP_INT_MAX;
                $j1 = 0;
                for ($j = 1; $j <= $m; $j++) {
                    if (!$used[$j]) {
                        $cur = $cost[$i0][$j] - $u[$i0] - $v[$j];
                        if ($cur < $minv[$j]) {
                            $minv[$j] = $cur;
                            $way[$j] = $j0;
                        }
                        if ($minv[$j] < $delta) {
                            $delta = $minv[$j];
                            $j1 = $j;
                        }
                    }
                }
                for ($j = 0; $j <= $m; $j++) {
                    if ($used[$j]) {
                        $u[$p[$j]] += $delta;
                        $v[$j] -= $delta;
                    } elseif ($j > 0) {
                        $minv[$j] -= $delta;
                    }
                }
                $j0 = $j1;
            } while ($p[$j0] !== 0);
            do {
                $j1 = $way[$j0];
                $p[$j0] = $p[$j1];
                $j0 = $j1;
            } while ($j0 !== 0);
        }
        $result = ['count' => 0, 'quality' => 0];
        for ($j = 1; $j <= $m; $j++) {
            if ($p[$j] && $cost[$p[$j]][$j] < 0) {
                $result['count']++;
                $result['quality'] += -$cost[$p[$j]][$j] - 100000;
            }
        }
        return $result;
    }

    private static function tokenQuality(string $word, string $target): int
    {
        if ($word === $target) {
            return 100;
        }
        // Numeric tokens, ordinals, and alphanumeric edition labels are exact.
        if (preg_match('/\p{N}/u', $word . $target)) {
            return 0;
        }
        $length = self::length($word);
        $targetLength = self::length($target);
        if ($length >= 2 && str_starts_with($target, $word)) {
            return 80 + (int)floor(9 * $length / $targetLength);
        }
        if ($length < 4 || $targetLength < 4) {
            return 0;
        }
        // Bound edit-distance work for malformed legacy values and unusually
        // long single words. Their exact and prefix matches still work above.
        if ($length > 64 || $targetLength > 64) {
            return 0;
        }
        $threshold = min($length, $targetLength) >= 6 ? 2 : 1;
        if (abs($length - $targetLength) <= $threshold) {
            $distance = self::distance($word, $target, $threshold);
            if ($distance <= $threshold) {
                return 70 - $distance * 5;
            }
        }
        // Typo tolerance also applies to a sufficiently long unfinished word.
        if ($length >= 5 && $targetLength > $length) {
            $prefix = self::substring($target, $length);
            if (self::distance($word, $prefix, 1) <= 1) {
                return 55;
            }
        }
        return 0;
    }

    private static function distance(string $a, string $b, int $threshold): int
    {
        if (!preg_match('/[^\x00-\x7F]/', $a . $b)) {
            return levenshtein($a, $b);
        }
        // Native levenshtein measures bytes. Preserve character edits in Greek,
        // Cyrillic, etc. without requiring an extension or external library.
        $left = preg_split('//u', $a, -1, PREG_SPLIT_NO_EMPTY);
        $right = preg_split('//u', $b, -1, PREG_SPLIT_NO_EMPTY);
        $previous = range(0, count($right));
        foreach ($left as $i => $char) {
            $current = [$i + 1];
            foreach ($right as $j => $other) {
                $current[] = min($current[$j] + 1, $previous[$j + 1] + 1, $previous[$j] + ($char === $other ? 0 : 1));
            }
            if (min($current) > $threshold) {
                return $threshold + 1;
            }
            $previous = $current;
        }
        return $previous[count($right)];
    }

    private function contextScore(array $event, string $year, array $context): int
    {
        $score = 0;
        $start = self::stringValue($context['start'] ?? '');
        if ($start !== '' && substr($start, 0, 10) === substr($event['start'], 0, 10)) {
            $score += 30;
        }
        $contextYear = self::year($start, $context['year'] ?? '');
        if ($contextYear !== '' && $contextYear === $year) {
            $score += 10;
        }
        $location = self::normalize(self::stringValue($context['location'] ?? ''));
        if ($location !== '' && $location === self::normalize($event['location'])) {
            $score += 10;
        }
        return $score;
    }

    private function sameEdition(array $values, array $event, $document): bool
    {
        // Explicit numbers in either supported title variant are stronger
        // evidence than a possibly incorrect stored date. Include words beyond
        // the token work limit, within the matching text byte bound.
        $numbers = self::numericTokens(self::stringValue($values['title'] ?? '') . ' ' . self::stringValue($values['title_full'] ?? ''));
        $eventNumbers = self::numericTokens($event['title'] . ' ' . $event['title_full']);
        if ($numbers && $eventNumbers && $numbers !== $eventNumbers) {
            return false;
        }
        $start = self::stringValue($values['start'] ?? '');
        $year = self::year($start, $values['year'] ?? '');
        $eventYear = self::year($event['start'], self::field($document, 'year'));
        if ($year !== '' && $eventYear !== '' && $year !== $eventYear) {
            return false;
        }
        return $start === '' || $event['start'] === '' || substr($start, 0, 10) === substr($event['start'], 0, 10);
    }

    private function trim(array $results, int $limit): array
    {
        uasort($results, [self::class, 'compare']);
        return array_slice($results, 0, $limit, true);
    }

    private static function compare(array $a, array $b): int
    {
        return ($b['score'] <=> $a['score'])
            ?: strcmp(self::normalize($a['title']), self::normalize($b['title']))
            ?: strcmp($a['start'], $b['start'])
            ?: strcmp($a['id'], $b['id']);
    }

    private static function tokens(string $text, int $limit): array
    {
        $tokens = array_values(array_unique(array_filter(explode(' ', $text), static fn($word) => $word !== '')));
        if (count($tokens) <= $limit) {
            return $tokens;
        }
        // Preserve an edition year at the end of a long title before reducing
        // lexical work. The duplicate check additionally compares all numbers.
        $numeric = array_values(array_filter($tokens, static fn($word) => preg_match('/\p{N}/u', $word)));
        return array_slice(array_values(array_unique(array_merge($numeric, $tokens))), 0, $limit);
    }

    private static function numericTokens(string $text): array
    {
        $text = self::matchingText($text);
        preg_match_all('/\p{N}+/u', $text, $matches);
        $numbers = array_values(array_unique($matches[0]));
        sort($numbers, SORT_STRING);
        return $numbers;
    }

    /** Bound matching work without changing canonical metadata returned to forms. */
    private static function matchingText(string $text): string
    {
        if (strlen($text) > self::MAX_MATCH_BYTES) {
            $text = substr($text, 0, self::MAX_MATCH_BYTES);
            // The byte boundary may split one UTF-8 code point.
            for ($i = 0; $i < 3 && preg_match('//u', $text) !== 1; $i++) {
                $text = substr($text, 0, -1);
            }
        }
        return preg_match('//u', $text) === 1 ? $text : '';
    }

    private static function characterMap(): array
    {
        static $map = null;
        if ($map !== null) {
            return $map;
        }
        $map = [];
        $groups = [
            'a' => 'ÀÁÂÃÅĀĂĄǍǞǠǺȀȂȦẠẢẤẦẨẪẬẮẰẲẴẶàáâãåāăąǎǟǡǻȁȃȧạảấầẩẫậắằẳẵặ',
            'b' => 'ḂḄḆḃḅḇ', 'c' => 'ÇĆĈĊČḈçćĉċčḉ', 'd' => 'ĎĐÐḊḌḎḐḒďđðḋḍḏḑḓ',
            'e' => 'ÈÉÊËĒĔĖĘĚȄȆȨẸẺẼẾỀỂỄỆèéêëēĕėęěȅȇȩẹẻẽếềểễệ',
            'f' => 'Ḟḟ', 'g' => 'ĜĞĠĢǦǴḠĝğġģǧǵḡ', 'h' => 'ĤȞḢḤḦḨḪĥȟḣḥḧḩḫẖ',
            'i' => 'ÌÍÎÏĨĪĬĮİǏȈȊỈỊìíîïĩīĭįıǐȉȋỉị', 'j' => 'Ĵĵǰ', 'k' => 'ĶǨḰḲḴķǩḱḳḵ',
            'l' => 'ĹĻĽŁḶḸḺḼĺļľłḷḹḻḽ', 'm' => 'ḾṀṂḿṁṃ', 'n' => 'ÑŃŅŇǸṄṆṈṊñńņňǹṅṇṉṋ',
            'o' => 'ÒÓÔÕØŌŎŐƠǑǪǬȌȎȪȬȮȰỌỎỐỒỔỖỘỚỜỞỠỢòóôõøōŏőơǒǫǭȍȏȫȭȯȱọỏốồổỗộớờởỡợ',
            'p' => 'ṔṖṕṗ', 'r' => 'ŔŖŘȐȒṘṚṜṞŕŗřȑȓṙṛṝṟ', 's' => 'ŚŜŞŠȘṠṢṤṦṨśŝşšșṡṣṥṧṩ',
            't' => 'ŢŤȚṪṬṮṰţťțṫṭṯṱẗ',
            'u' => 'ÙÚÛŨŪŬŮŰŲƯǓǕǗǙǛȔȖṲṴṶṸṺỤỦỨỪỬỮỰùúûũūŭůűųưǔǖǘǚǜȕȗṳṵṷṹṻụủứừửữự',
            'v' => 'ṼṾṽṿ', 'w' => 'ŴẀẂẄẆẈŵẁẃẅẇẉẘ', 'x' => 'ẊẌẋẍ',
            'y' => 'ÝŶŸȲẎỲỴỶỸýÿŷȳẏỳỵỷỹẙ', 'z' => 'ŹŻŽẐẒẔźżžẑẓẕ', 'th' => 'Þþ',
            'α' => 'ΑΆά', 'ε' => 'ΕΈέ', 'η' => 'ΗΉή', 'ι' => 'ΙΊΪίϊΐ',
            'ο' => 'ΟΌό', 'υ' => 'ΥΎΫύϋΰ', 'ω' => 'ΩΏώ', 'σ' => 'Σς', 'е' => 'ЕЁё', 'и' => 'ИЙй',
        ];
        foreach ($groups as $replacement => $characters) {
            foreach (preg_split('//u', $characters, -1, PREG_SPLIT_NO_EMPTY) as $character) {
                $map[$character] = $replacement;
            }
        }
        $upper = preg_split('//u', 'ΒΓΔΖΘΚΛΜΝΞΠΡΤΦΧΨАБВГДЖЗКЛМНОПРСТУФХЦЧШЩЪЫЬЭЮЯ', -1, PREG_SPLIT_NO_EMPTY);
        $lower = preg_split('//u', 'βγδζθκλμνξπρτφχψабвгджзклмнопрстуфхцчшщъыьэюя', -1, PREG_SPLIT_NO_EMPTY);
        return $map += array_combine($upper, $lower);
    }

    private static function year(string $start, $fallback): string
    {
        if (preg_match('/^(\d{4})-\d{2}-\d{2}/', $start, $match)) {
            return $match[1];
        }
        $fallback = self::stringValue($fallback);
        return preg_match('/^\d{4}$/', $fallback) ? $fallback : '';
    }

    private static function field($document, string $field)
    {
        if (is_array($document) || $document instanceof ArrayAccess) {
            return $document[$field] ?? '';
        }
        return is_object($document) ? ($document->$field ?? '') : '';
    }

    private static function stringValue($value): string
    {
        return is_scalar($value) || $value instanceof Stringable ? (string)$value : '';
    }

    private static function length(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : count(preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY));
    }

    private static function substring(string $value, int $length): string
    {
        return function_exists('mb_substr') ? mb_substr($value, 0, $length, 'UTF-8') : implode('', array_slice(preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY), 0, $length));
    }
}
