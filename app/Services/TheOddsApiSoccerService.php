<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use Throwable;

final class TheOddsApiSoccerService
{
    private string $apiBaseUrl = 'https://api.the-odds-api.com/v4';
    private string $brazilTimezone = 'America/Sao_Paulo';

    public function __construct(private readonly Database $db)
    {
    }

    /**
     * @param array{status_scope?: string, league_terms?: array<int, string>} $filters
     * @return array<int, array{
     *   oddapi_match_id: string,
     *   oddapi_sport_key: string,
     *   league_name: string,
     *   country_name: string,
     *   home_team: string,
     *   away_team: string,
     *   match_date: string,
     *   status: 'scheduled'|'live',
     *   odds: null
     * }>
     */
    public function fetchDailyMatchesIndex(array $filters = []): array
    {
        $config = $this->loadConfig();
        $sports = $this->resolveSoccerSports($config, $filters['league_terms'] ?? []);
        if ($sports === []) {
            return [];
        }

        [$commenceFrom, $commenceTo, $nowUtc] = $this->buildWindow();
        $matches = [];

        foreach ($sports as $sport) {
            $events = $this->fetchEventsForSport($config['api_key'], (string) ($sport['key'] ?? ''), $commenceFrom, $commenceTo);
            foreach ($events as $event) {
                $match = $this->buildMatchFromEvent($sport, $event, $nowUtc);
                if ($match !== null) {
                    $matches[] = $match;
                }
            }
        }

        usort($matches, static function (array $left, array $right): int {
            $dateCompare = strcmp((string) ($left['match_date'] ?? ''), (string) ($right['match_date'] ?? ''));
            if ($dateCompare !== 0) {
                return $dateCompare;
            }

            return strcasecmp((string) ($left['league_name'] ?? ''), (string) ($right['league_name'] ?? ''));
        });

        return $matches;
    }

    /**
     * @param array<int, array<string, mixed>> $matches
     * @return array<int, array<string, mixed>>
     */
    public function hydrateMatchesWithOdds(array $matches): array
    {
        if ($matches === []) {
            return [];
        }

        $config = $this->loadConfig();
        $bySport = [];

        foreach ($matches as $match) {
            $sportKey = trim((string) ($match['oddapi_sport_key'] ?? ''));
            $eventId = trim((string) ($match['oddapi_match_id'] ?? ''));

            if ($sportKey === '' || $eventId === '') {
                continue;
            }

            $bySport[$sportKey][$eventId] = true;
        }

        $oddsBySportEvent = [];

        foreach ($bySport as $sportKey => $eventIdsMap) {
            $eventIds = array_keys($eventIdsMap);
            foreach (array_chunk($eventIds, 25) as $chunk) {
                $events = $this->fetchOddsForEventIds($config, $sportKey, $chunk);
                foreach ($events as $event) {
                    if (!is_array($event)) {
                        continue;
                    }

                    $eventId = trim((string) ($event['id'] ?? ''));
                    if ($eventId === '') {
                        continue;
                    }

                    $odds = $this->selectEventOdds(
                        $event,
                        $this->bookmakerPriorityMap($config['bookmakers'])
                    );

                    if ($odds !== null) {
                        $oddsBySportEvent[$sportKey][$eventId] = $odds;
                    }
                }
            }
        }

        foreach ($matches as $index => $match) {
            $sportKey = trim((string) ($match['oddapi_sport_key'] ?? ''));
            $eventId = trim((string) ($match['oddapi_match_id'] ?? ''));
            $matches[$index]['odds'] = $oddsBySportEvent[$sportKey][$eventId] ?? null;
        }

        return $matches;
    }

    /**
     * @return array{api_key: string, regions: string, bookmakers: array<int, string>, sport_keys: array<int, string>}
     */
    private function loadConfig(): array
    {
        $wantedKeys = [
            'the_odds_api_key',
            'the_odds_api_regions',
            'the_odds_api_bookmakers',
            'the_odds_api_sport_keys',
        ];

        $settings = [];
        $placeholders = implode(',', array_fill(0, count($wantedKeys), '?'));
        $stmt = $this->db->pdo()->prepare(
            'SELECT setting_key, setting_value FROM settings WHERE setting_key IN (' . $placeholders . ')'
        );
        $stmt->execute($wantedKeys);

        foreach ($stmt->fetchAll() as $row) {
            $settings[(string) $row['setting_key']] = (string) ($row['setting_value'] ?? '');
        }

        $apiKey = trim((string) ($settings['the_odds_api_key'] ?? ''));
        if ($apiKey === '') {
            throw new RuntimeException('Configure a API key da The Odds API em Configuracoes antes de usar o sync OddAPI.');
        }

        $regions = trim((string) ($settings['the_odds_api_regions'] ?? ''));
        if ($regions === '') {
            $regions = 'eu';
        }

        return [
            'api_key' => $apiKey,
            'regions' => $regions,
            'bookmakers' => $this->csvValues((string) ($settings['the_odds_api_bookmakers'] ?? '')),
            'sport_keys' => $this->csvValues((string) ($settings['the_odds_api_sport_keys'] ?? '')),
        ];
    }

    /**
     * @param array{api_key: string, regions: string, bookmakers: array<int, string>, sport_keys: array<int, string>} $config
     * @param mixed $leagueTerms
     * @return array<int, array<string, mixed>>
     */
    private function resolveSoccerSports(array $config, mixed $leagueTerms): array
    {
        $sports = $this->requestJson('/sports/', [
            'apiKey' => $config['api_key'],
        ]);

        if (!is_array($sports)) {
            return [];
        }

        $sportKeysFilter = array_fill_keys($config['sport_keys'], true);
        $normalizedTerms = [];

        foreach ($this->normalizeLeagueTerms($leagueTerms) as $term) {
            $normalizedTerms[$this->normalizeKey($term)] = true;
        }

        $resolved = [];

        foreach ($sports as $sport) {
            if (!is_array($sport)) {
                continue;
            }

            $sportKey = trim((string) ($sport['key'] ?? ''));
            if ($sportKey === '') {
                continue;
            }

            if (strtolower(trim((string) ($sport['group'] ?? ''))) !== 'soccer') {
                continue;
            }

            if (empty($sport['active']) || !empty($sport['has_outrights'])) {
                continue;
            }

            if ($sportKeysFilter !== [] && !isset($sportKeysFilter[$sportKey])) {
                continue;
            }

            if ($normalizedTerms !== []) {
                $haystack = $this->normalizeKey(implode(' ', array_filter([
                    (string) ($sport['title'] ?? ''),
                    (string) ($sport['description'] ?? ''),
                    $sportKey,
                ])));

                $matched = false;
                foreach (array_keys($normalizedTerms) as $term) {
                    if ($term !== '' && str_contains($haystack, $term)) {
                        $matched = true;
                        break;
                    }
                }

                if (!$matched) {
                    continue;
                }
            }

            $resolved[] = $sport;
        }

        usort($resolved, static function (array $left, array $right): int {
            return strcasecmp((string) ($left['title'] ?? ''), (string) ($right['title'] ?? ''));
        });

        return $resolved;
    }

    /**
     * @return array{0: string, 1: string, 2: DateTimeImmutable}
     */
    private function buildWindow(): array
    {
        $timezone = new DateTimeZone($this->brazilTimezone);
        $utc = new DateTimeZone('UTC');
        $todayStart = new DateTimeImmutable('today', $timezone);
        $tomorrowStart = $todayStart->modify('+1 day');

        return [
            $todayStart->setTimezone($utc)->format('Y-m-d\TH:i:s\Z'),
            $tomorrowStart->setTimezone($utc)->format('Y-m-d\TH:i:s\Z'),
            new DateTimeImmutable('now', $utc),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchEventsForSport(string $apiKey, string $sportKey, string $commenceFrom, string $commenceTo): array
    {
        if ($sportKey === '') {
            return [];
        }

        $payload = $this->requestJson('/sports/' . rawurlencode($sportKey) . '/events', [
            'apiKey' => $apiKey,
            'dateFormat' => 'iso',
            'commenceTimeFrom' => $commenceFrom,
            'commenceTimeTo' => $commenceTo,
        ]);

        return is_array($payload) ? $payload : [];
    }

    /**
     * @param array<string, mixed> $sport
     * @param array<string, mixed> $event
     * @return array{
     *   oddapi_match_id: string,
     *   oddapi_sport_key: string,
     *   league_name: string,
     *   country_name: string,
     *   home_team: string,
     *   away_team: string,
     *   match_date: string,
     *   status: 'scheduled'|'live',
     *   odds: null
     * }|null
     */
    private function buildMatchFromEvent(array $sport, array $event, DateTimeImmutable $nowUtc): ?array
    {
        $eventId = trim((string) ($event['id'] ?? ''));
        $sportKey = trim((string) ($event['sport_key'] ?? $sport['key'] ?? ''));
        $homeTeam = trim((string) ($event['home_team'] ?? ''));
        $awayTeam = trim((string) ($event['away_team'] ?? ''));
        $commenceTime = trim((string) ($event['commence_time'] ?? ''));

        if ($eventId === '' || $sportKey === '' || $homeTeam === '' || $awayTeam === '' || $commenceTime === '') {
            return null;
        }

        try {
            $commenceUtc = new DateTimeImmutable($commenceTime, new DateTimeZone('UTC'));
        } catch (Throwable) {
            return null;
        }

        $leagueName = trim((string) ($event['sport_title'] ?? $sport['title'] ?? $sportKey));
        if ($leagueName === '') {
            $leagueName = $sportKey;
        }

        return [
            'oddapi_match_id' => $eventId,
            'oddapi_sport_key' => $sportKey,
            'league_name' => $leagueName,
            'country_name' => $this->countryNameFromSport($sport),
            'home_team' => $homeTeam,
            'away_team' => $awayTeam,
            'match_date' => $commenceUtc
                ->setTimezone(new DateTimeZone($this->brazilTimezone))
                ->format('Y-m-d H:i:s'),
            'status' => $commenceUtc < $nowUtc ? 'live' : 'scheduled',
            'odds' => null,
        ];
    }

    /**
     * @param array{api_key: string, regions: string, bookmakers: array<int, string>, sport_keys: array<int, string>} $config
     * @param array<int, string> $eventIds
     * @return array<int, array<string, mixed>>
     */
    private function fetchOddsForEventIds(array $config, string $sportKey, array $eventIds): array
    {
        $eventIds = array_values(array_unique(array_filter(array_map(
            static fn (mixed $eventId): string => trim((string) $eventId),
            $eventIds
        ))));

        if ($sportKey === '' || $eventIds === []) {
            return [];
        }

        $query = [
            'apiKey' => $config['api_key'],
            'markets' => 'h2h',
            'dateFormat' => 'iso',
            'oddsFormat' => 'decimal',
            'eventIds' => implode(',', $eventIds),
        ];

        if ($config['bookmakers'] !== []) {
            $query['bookmakers'] = implode(',', $config['bookmakers']);
        } else {
            $query['regions'] = $config['regions'];
        }

        $payload = $this->requestJson('/sports/' . rawurlencode($sportKey) . '/odds/', $query);

        return is_array($payload) ? $payload : [];
    }

    /**
     * @param array<string, mixed> $event
     * @param array<string, int> $bookmakerPriority
     * @return array{home: float, draw: float, away: float}|null
     */
    private function selectEventOdds(array $event, array $bookmakerPriority): ?array
    {
        $homeTeam = trim((string) ($event['home_team'] ?? ''));
        $awayTeam = trim((string) ($event['away_team'] ?? ''));
        if ($homeTeam === '' || $awayTeam === '') {
            return null;
        }

        $candidates = [];

        foreach (($event['bookmakers'] ?? []) as $bookmaker) {
            if (!is_array($bookmaker)) {
                continue;
            }

            $market = null;
            foreach (($bookmaker['markets'] ?? []) as $candidateMarket) {
                if (is_array($candidateMarket) && (($candidateMarket['key'] ?? '') === 'h2h')) {
                    $market = $candidateMarket;
                    break;
                }
            }

            if (!is_array($market)) {
                continue;
            }

            $odds = $this->parseH2hOutcomes($market['outcomes'] ?? null, $homeTeam, $awayTeam);
            if ($odds === null) {
                continue;
            }

            $bookmakerKey = trim((string) ($bookmaker['key'] ?? ''));
            $lastUpdate = strtotime((string) ($market['last_update'] ?? $bookmaker['last_update'] ?? '')) ?: 0;

            $candidates[] = [
                'priority' => $bookmakerPriority[$bookmakerKey] ?? PHP_INT_MAX,
                'updated_at' => $lastUpdate,
                'odds' => $odds,
            ];
        }

        if ($candidates === []) {
            return null;
        }

        usort($candidates, static function (array $left, array $right): int {
            if ($left['priority'] !== $right['priority']) {
                return $left['priority'] <=> $right['priority'];
            }

            if ($left['updated_at'] !== $right['updated_at']) {
                return $right['updated_at'] <=> $left['updated_at'];
            }

            return 0;
        });

        return $candidates[0]['odds'] ?? null;
    }

    /**
     * @param mixed $outcomes
     * @return array{home: float, draw: float, away: float}|null
     */
    private function parseH2hOutcomes(mixed $outcomes, string $homeTeam, string $awayTeam): ?array
    {
        if (!is_array($outcomes)) {
            return null;
        }

        $normalizedHome = $this->normalizeKey($homeTeam);
        $normalizedAway = $this->normalizeKey($awayTeam);
        $resolved = [
            'home' => null,
            'draw' => null,
            'away' => null,
        ];
        $unmatched = [];

        foreach ($outcomes as $outcome) {
            if (!is_array($outcome)) {
                continue;
            }

            $name = trim((string) ($outcome['name'] ?? ''));
            $normalizedName = $this->normalizeKey($name);
            $price = $this->decimalPrice($outcome['price'] ?? null);
            if ($normalizedName === '' || $price === null) {
                continue;
            }

            if ($normalizedName === $normalizedHome || str_contains($normalizedHome, $normalizedName) || str_contains($normalizedName, $normalizedHome)) {
                $resolved['home'] = $price;
                continue;
            }

            if ($normalizedName === $normalizedAway || str_contains($normalizedAway, $normalizedName) || str_contains($normalizedName, $normalizedAway)) {
                $resolved['away'] = $price;
                continue;
            }

            if (in_array($normalizedName, ['draw', 'empate', 'tie', 'x'], true)) {
                $resolved['draw'] = $price;
                continue;
            }

            $unmatched[] = $price;
        }

        if ($resolved['draw'] === null && count($unmatched) === 1 && $resolved['home'] !== null && $resolved['away'] !== null) {
            $resolved['draw'] = $unmatched[0];
        }

        if ($resolved['home'] === null || $resolved['draw'] === null || $resolved['away'] === null) {
            return null;
        }

        return [
            'home' => $resolved['home'],
            'draw' => $resolved['draw'],
            'away' => $resolved['away'],
        ];
    }

    private function decimalPrice(mixed $value): ?float
    {
        if (!is_numeric((string) $value)) {
            return null;
        }

        $price = round((float) $value, 2);
        return $price > 1 ? $price : null;
    }

    /**
     * @param array<int, string> $bookmakers
     * @return array<string, int>
     */
    private function bookmakerPriorityMap(array $bookmakers): array
    {
        $priority = [];
        foreach ($bookmakers as $index => $bookmaker) {
            $key = trim((string) $bookmaker);
            if ($key !== '') {
                $priority[$key] = $index;
            }
        }

        return $priority;
    }

    private function countryNameFromSport(array $sport): string
    {
        $title = trim((string) ($sport['title'] ?? ''));
        if (preg_match('/-\s*([A-Za-z ]+)$/', $title, $matches) === 1) {
            return trim($matches[1]);
        }

        $sportKey = trim((string) ($sport['key'] ?? ''));
        if (str_starts_with($sportKey, 'soccer_')) {
            $parts = explode('_', substr($sportKey, strlen('soccer_')));
            $token = strtolower(trim((string) ($parts[0] ?? '')));

            return match ($token) {
                'usa' => 'USA',
                'uk' => 'UK',
                'uefa' => 'UEFA',
                'fifa' => 'FIFA',
                default => $token !== '' ? ucfirst($token) : '',
            };
        }

        return '';
    }

    /**
     * @param mixed $leagueTerms
     * @return array<int, string>
     */
    private function normalizeLeagueTerms(mixed $leagueTerms): array
    {
        if (!is_array($leagueTerms)) {
            return [];
        }

        $terms = [];
        foreach ($leagueTerms as $term) {
            $term = trim((string) $term);
            if ($term !== '') {
                $terms[$term] = true;
            }
        }

        return array_keys($terms);
    }

    /**
     * @return array<int, string>
     */
    private function csvValues(string $value): array
    {
        $parts = preg_split('/[\r\n,;]+/u', $value) ?: [];
        $values = [];

        foreach ($parts as $part) {
            $part = trim((string) $part);
            if ($part !== '') {
                $values[$part] = true;
            }
        }

        return array_keys($values);
    }

    private function normalizeKey(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        if (function_exists('transliterator_transliterate')) {
            $value = (string) transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', $value);
        } else {
            $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
            if (is_string($converted) && $converted !== '') {
                $value = strtolower($converted);
            } else {
                $value = strtolower($value);
            }
        }

        $value = preg_replace('/[^a-z0-9]+/u', ' ', $value) ?? $value;
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? $value);

        return $value;
    }

    /**
     * @param array<string, string> $query
     * @return array<int|string, mixed>
     */
    private function requestJson(string $path, array $query): array
    {
        $url = rtrim($this->apiBaseUrl, '/') . '/' . ltrim($path, '/');
        $url .= '?' . http_build_query($query);

        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Falha ao inicializar cURL da The Odds API.');
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_ENCODING => '',
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/123.0 Safari/537.36',
            ],
        ]);

        $raw = curl_exec($ch);
        if ($raw === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException('Falha ao buscar dados da The Odds API: ' . $error);
        }

        $httpCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($httpCode >= 400) {
            $message = 'The Odds API retornou HTTP ' . $httpCode;
            $decodedError = json_decode((string) $raw, true);
            if (is_array($decodedError)) {
                $errorMessage = trim((string) ($decodedError['message'] ?? $decodedError['error'] ?? ''));
                if ($errorMessage !== '') {
                    $message .= ': ' . $errorMessage;
                }
            }

            throw new RuntimeException($message);
        }

        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Resposta invalida recebida da The Odds API.');
        }

        return $decoded;
    }
}
