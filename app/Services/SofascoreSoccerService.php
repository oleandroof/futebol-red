<?php
declare(strict_types=1);

namespace App\Services;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use RuntimeException;

final class SofascoreSoccerService
{
    private string $baseUrl = 'https://www.sofascore.com';
    private string $brazilTimezone = 'America/Sao_Paulo';

    /**
     * @return array<int, array{
     *   sofascore_match_id: string,
     *   source_url: string,
     *   match_date: string,
     *   home_team: string,
     *   away_team: string,
     *   league_name: string,
     *   country_name: string,
     *   status: 'scheduled'|'live'
     * }>
     */
    public function fetchDailyMatchesIndex(?DateTimeInterface $day = null): array
    {
        $timezone = new DateTimeZone($this->brazilTimezone);
        $day ??= new DateTimeImmutable('today', $timezone);
        $date = DateTimeImmutable::createFromInterface($day)->setTimezone($timezone)->format('Y-m-d');

        $payload = $this->requestJson($this->baseUrl . '/api/v1/sport/football/scheduled-events/' . $date);
        $events = $payload['events'] ?? null;
        if (!is_array($events)) {
            return [];
        }

        $matches = [];

        foreach ($events as $event) {
            if (!is_array($event)) {
                continue;
            }

            $eventId = trim((string) ($event['id'] ?? ''));
            $homeTeam = trim((string) ($event['homeTeam']['name'] ?? ''));
            $awayTeam = trim((string) ($event['awayTeam']['name'] ?? ''));
            $startTimestamp = (int) ($event['startTimestamp'] ?? 0);
            $statusType = strtolower(trim((string) ($event['status']['type'] ?? '')));

            if ($eventId === '' || $homeTeam === '' || $awayTeam === '' || $startTimestamp <= 0) {
                continue;
            }

            if (!in_array($statusType, ['notstarted', 'inprogress'], true)) {
                continue;
            }

            $matchDate = (new DateTimeImmutable('@' . $startTimestamp))
                ->setTimezone($timezone)
                ->format('Y-m-d H:i:s');

            $leagueName = trim((string) ($event['tournament']['name'] ?? ''));
            if ($leagueName === '') {
                $leagueName = trim((string) ($event['tournament']['uniqueTournament']['name'] ?? ''));
            }

            $countryName = trim((string) ($event['tournament']['category']['name'] ?? ''));
            if ($countryName === '') {
                $countryName = trim((string) ($event['tournament']['uniqueTournament']['category']['name'] ?? ''));
            }

            $matches[] = [
                'sofascore_match_id' => $eventId,
                'source_url' => $this->buildSourceUrl($event),
                'match_date' => $matchDate,
                'home_team' => $homeTeam,
                'away_team' => $awayTeam,
                'league_name' => $leagueName !== '' ? $leagueName : 'Sofascore',
                'country_name' => $countryName,
                'status' => $statusType === 'inprogress' ? 'live' : 'scheduled',
            ];
        }

        return $matches;
    }

    /**
     * @return array<int, array{
     *   sofascore_match_id: string,
     *   source_url: string,
     *   match_date: string,
     *   home_team: string,
     *   away_team: string,
     *   league_name: string,
     *   country_name: string,
     *   status: 'scheduled'|'live',
     *   odds: array{home: float, draw: float, away: float}|null
     * }>
     */
    public function fetchDailyMatchesWithOdds(?DateTimeInterface $day = null, int $limit = 200): array
    {
        $matches = array_slice($this->fetchDailyMatchesIndex($day), 0, max(1, $limit));
        $oddsByEventId = $this->fetchOddsByEventIds(array_column($matches, 'sofascore_match_id'));

        foreach ($matches as &$match) {
            $match['odds'] = $oddsByEventId[(string) $match['sofascore_match_id']] ?? null;
        }

        return $matches;
    }

    /**
     * @param array<int, string> $eventIds
     * @return array<string, array{home: float, draw: float, away: float}>
     */
    public function fetchOddsByEventIds(array $eventIds): array
    {
        $eventIds = array_values(array_unique(array_filter(array_map(
            static fn (mixed $id): string => trim((string) $id),
            $eventIds
        ))));

        if ($eventIds === []) {
            return [];
        }

        $results = [];
        foreach ($eventIds as $eventId) {
            try {
                $payload = $this->requestJson($this->baseUrl . '/api/v1/event/' . rawurlencode($eventId) . '/odds/1/all');
            } catch (Throwable) {
                continue;
            }

            $odds = $this->parseOddsPayload($payload);
            if ($odds !== null) {
                $results[$eventId] = $odds;
            }
        }

        return $results;
    }

    /**
     * @return array{home: float, draw: float, away: float}|null
     */
    private function parseOddsPayload(array $payload): ?array
    {
        $markets = $payload['markets'] ?? null;
        if (!is_array($markets)) {
            return null;
        }

        foreach ($markets as $market) {
            if (!is_array($market)) {
                continue;
            }

            $marketId = (int) ($market['marketId'] ?? 0);
            $marketGroup = strtolower(trim((string) ($market['marketGroup'] ?? '')));
            if ($marketId !== 1 && $marketGroup !== '1x2') {
                continue;
            }

            $choices = $market['choices'] ?? null;
            if (!is_array($choices)) {
                continue;
            }

            $result = [
                'home' => null,
                'draw' => null,
                'away' => null,
            ];

            foreach ($choices as $choice) {
                if (!is_array($choice)) {
                    continue;
                }

                $name = strtoupper(trim((string) ($choice['name'] ?? '')));
                $fractional = trim((string) ($choice['fractionalValue'] ?? $choice['initialFractionalValue'] ?? ''));
                $decimal = $this->fractionalToDecimal($fractional);

                if ($decimal === null) {
                    continue;
                }

                if ($name === '1') {
                    $result['home'] = $decimal;
                } elseif ($name === 'X') {
                    $result['draw'] = $decimal;
                } elseif ($name === '2') {
                    $result['away'] = $decimal;
                }
            }

            if ($result['home'] !== null && $result['draw'] !== null && $result['away'] !== null) {
                return [
                    'home' => $result['home'],
                    'draw' => $result['draw'],
                    'away' => $result['away'],
                ];
            }
        }

        return null;
    }

    private function fractionalToDecimal(string $fractional): ?float
    {
        if ($fractional === '') {
            return null;
        }

        if (preg_match('/^\s*(\d+)\s*\/\s*(\d+)\s*$/', $fractional, $matches) === 1) {
            $numerator = (float) $matches[1];
            $denominator = (float) $matches[2];

            if ($denominator <= 0) {
                return null;
            }

            return round(1 + ($numerator / $denominator), 2);
        }

        if (is_numeric($fractional)) {
            return round((float) $fractional, 2);
        }

        return null;
    }

    /**
     * @return array<string>
     */
    private function headers(): array
    {
        return [
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0 Safari/537.36',
            'Accept: application/json, text/plain, */*',
            'Accept-Language: en-US,en;q=0.9,pt-BR;q=0.8,pt;q=0.7',
            'Origin: https://www.sofascore.com',
            'Referer: https://www.sofascore.com/',
            'Sec-Fetch-Site: same-origin',
            'Sec-Fetch-Mode: cors',
            'Sec-Fetch-Dest: empty',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function requestJson(string $url): array
    {
        $raw = $this->request($url);
        $decoded = json_decode($raw, true);

        if (!is_array($decoded)) {
            throw new RuntimeException('Sofascore retornou JSON invalido.');
        }

        return $decoded;
    }

    private function request(string $url): string
    {
        $ch = curl_init();
        if ($ch === false) {
            $fallback = $this->requestWithCurlCli($url);
            if ($fallback !== null) {
                return $fallback;
            }

            throw new RuntimeException('Falha ao inicializar cURL do Sofascore.');
        }

        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 25,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_ENCODING => '',
            CURLOPT_HTTPHEADER => $this->headers(),
        ]);

        $raw = curl_exec($ch);
        if ($raw === false) {
            $error = curl_error($ch);
            curl_close($ch);

            $fallback = $this->requestWithCurlCli($url);
            if ($fallback !== null) {
                return $fallback;
            }

            throw new RuntimeException('Falha ao buscar dados do Sofascore: ' . $error);
        }

        $httpCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($httpCode >= 400) {
            $fallback = $this->requestWithCurlCli($url);
            if ($fallback !== null) {
                return $fallback;
            }

            throw new RuntimeException('Sofascore retornou HTTP ' . $httpCode);
        }

        return (string) $raw;
    }

    private function requestWithCurlCli(string $url): ?string
    {
        $where = shell_exec('where curl.exe 2>NUL');
        if (!is_string($where) || trim($where) === '') {
            return null;
        }

        $parts = ['curl.exe', '-sS', '-L', '--compressed', escapeshellarg($url)];
        foreach ($this->headers() as $header) {
            $parts[] = '-H';
            $parts[] = escapeshellarg($header);
        }

        $raw = shell_exec(implode(' ', $parts));
        if (!is_string($raw) || trim($raw) === '') {
            return null;
        }

        return $raw;
    }

    /**
     * @param array<string, mixed> $event
     */
    private function buildSourceUrl(array $event): string
    {
        $slug = trim((string) ($event['slug'] ?? ''));
        $customId = trim((string) ($event['customId'] ?? ''));

        if ($slug !== '' && $customId !== '') {
            return $this->baseUrl . '/football/match/' . rawurlencode($slug) . '/' . rawurlencode($customId);
        }

        return $this->baseUrl . '/';
    }
}
