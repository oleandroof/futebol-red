<?php
declare(strict_types=1);

namespace App\Services;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use Throwable;

final class PinnacleGuestSoccerService
{
    private string $siteBaseUrl = 'https://www.pinnacle.com';
    private string $defaultGuestRoot = 'https://guest.api.arcadia.pinnacle.com';
    private string $defaultApiVersion = '0.1';
    private string $defaultApiKey = 'CmX2KcMrXuFmNg6YFbmTxE0y9CIrOi0R';
    private string $brazilTimezone = 'America/Sao_Paulo';
    private int $soccerSportId = 29;
    private int $brandId = 0;

    /**
     * @param array<int, array<string, mixed>> $referenceMatches
     * @return array<int, array{
     *   pinnacle_matchup_id: string,
     *   league_name: string,
     *   country_name: string,
     *   home_team: string,
     *   away_team: string,
     *   match_date: string,
     *   status: 'scheduled'|'live',
     *   odds: array{home: float, draw: float, away: float}
     * }>
     */
    public function fetchOddsCandidatesForMatches(array $referenceMatches): array
    {
        [$from, $to] = $this->buildReferenceWindow($referenceMatches);
        $config = $this->loadGuestApiConfig();
        $matchups = $this->fetchSoccerMatchups($config);
        $matchups = $this->filterMainMatchups($matchups, $from, $to);

        if ($matchups === []) {
            return [];
        }

        $leagueIds = [];
        foreach ($matchups as $matchup) {
            $leagueId = (int) ($matchup['pinnacle_league_id'] ?? 0);
            if ($leagueId > 0) {
                $leagueIds[$leagueId] = true;
            }
        }

        $oddsByMatchupId = $this->fetchMoneylineOddsByLeagueIds(array_keys($leagueIds), $config);
        $candidates = [];

        foreach ($matchups as $matchup) {
            $matchupId = (string) ($matchup['pinnacle_matchup_id'] ?? '');
            if ($matchupId === '' || !isset($oddsByMatchupId[$matchupId])) {
                continue;
            }

            unset($matchup['pinnacle_league_id']);
            $matchup['odds'] = $oddsByMatchupId[$matchupId];
            $candidates[] = $matchup;
        }

        return $candidates;
    }

    /**
     * @return array{guest_root: string, api_version: string, api_key: string}
     */
    private function loadGuestApiConfig(): array
    {
        $config = [
            'guest_root' => $this->defaultGuestRoot,
            'api_version' => $this->defaultApiVersion,
            'api_key' => $this->defaultApiKey,
        ];

        try {
            $payload = $this->requestJson($this->siteBaseUrl . '/config/app.json');
        } catch (Throwable) {
            return $config;
        }

        $apiVersion = trim((string) ($payload['api']['haywire']['apiVersion'] ?? ''));
        if ($apiVersion !== '') {
            $config['api_version'] = $apiVersion;
        }

        $apiKey = trim((string) ($payload['api']['haywire']['apiKey'] ?? ''));
        if ($apiKey !== '') {
            $config['api_key'] = $apiKey;
        }

        $routes = $payload['api']['haywire']['routes'] ?? null;
        if (!is_array($routes)) {
            return $config;
        }

        foreach (['nolicense', 'curacao', 'commercialanjouan', 'anjouan', 'malta'] as $routeKey) {
            $guestRoot = trim((string) ($routes[$routeKey]['guestRoot'] ?? ''));
            if ($guestRoot !== '') {
                $config['guest_root'] = rtrim($guestRoot, '/');
                return $config;
            }
        }

        foreach ($routes as $route) {
            if (!is_array($route)) {
                continue;
            }

            $guestRoot = trim((string) ($route['guestRoot'] ?? ''));
            if ($guestRoot !== '') {
                $config['guest_root'] = rtrim($guestRoot, '/');
                break;
            }
        }

        return $config;
    }

    /**
     * @param array{guest_root: string, api_version: string, api_key: string} $config
     * @return array<int, array<string, mixed>>
     */
    private function fetchSoccerMatchups(array $config): array
    {
        $url = $this->buildApiUrl(
            $config,
            'sports/' . $this->soccerSportId . '/matchups?brandId=' . $this->brandId
        );

        $payload = $this->requestJson($url, $this->headers($config['api_key']));

        return array_values(array_filter($payload, static fn (mixed $row): bool => is_array($row)));
    }

    /**
     * @param array<int, array<string, mixed>> $matchups
     * @return array<int, array<string, mixed>>
     */
    private function filterMainMatchups(array $matchups, ?DateTimeImmutable $from, ?DateTimeImmutable $to): array
    {
        $timezone = new DateTimeZone($this->brazilTimezone);
        $result = [];

        foreach ($matchups as $matchup) {
            if (($matchup['type'] ?? '') !== 'matchup') {
                continue;
            }

            if (($matchup['parentId'] ?? null) !== null) {
                continue;
            }

            $matchupId = trim((string) ($matchup['id'] ?? ''));
            $leagueId = (int) ($matchup['league']['id'] ?? 0);
            $participants = $matchup['participants'] ?? null;
            if ($matchupId === '' || $leagueId <= 0 || !is_array($participants)) {
                continue;
            }

            $homeTeam = $this->participantNameByAlignment($participants, 'home');
            $awayTeam = $this->participantNameByAlignment($participants, 'away');
            if ($homeTeam === '' || $awayTeam === '') {
                continue;
            }

            if (!$this->matchupHasPeriodZeroMoneyline($matchup['periods'] ?? null)) {
                continue;
            }

            $startTime = trim((string) ($matchup['startTime'] ?? ''));
            if ($startTime === '') {
                continue;
            }

            try {
                $start = (new DateTimeImmutable($startTime))
                    ->setTimezone($timezone);
            } catch (Throwable) {
                continue;
            }

            if ($from instanceof DateTimeImmutable && $start < $from) {
                continue;
            }

            if ($to instanceof DateTimeImmutable && $start > $to) {
                continue;
            }

            [$countryName, $leagueName] = $this->splitLeagueNames(
                trim((string) ($matchup['league']['group'] ?? '')),
                trim((string) ($matchup['league']['name'] ?? ''))
            );

            $result[] = [
                'pinnacle_matchup_id' => $matchupId,
                'pinnacle_league_id' => $leagueId,
                'league_name' => $leagueName !== '' ? $leagueName : 'Pinnacle',
                'country_name' => $countryName,
                'home_team' => $homeTeam,
                'away_team' => $awayTeam,
                'match_date' => $start->format('Y-m-d H:i:s'),
                'status' => !empty($matchup['isLive']) ? 'live' : 'scheduled',
            ];
        }

        return $result;
    }

    /**
     * @param array<int, int|string> $leagueIds
     * @param array{guest_root: string, api_version: string, api_key: string} $config
     * @return array<string, array{home: float, draw: float, away: float}>
     */
    private function fetchMoneylineOddsByLeagueIds(array $leagueIds, array $config): array
    {
        $leagueIds = array_values(array_unique(array_filter(array_map(
            static fn (mixed $leagueId): int => (int) $leagueId,
            $leagueIds
        ), static fn (int $leagueId): bool => $leagueId > 0)));

        if ($leagueIds === []) {
            return [];
        }

        $multiHandle = curl_multi_init();
        if ($multiHandle === false) {
            return $this->fetchMoneylineOddsSequentially($leagueIds, $config);
        }

        $results = [];

        foreach (array_chunk($leagueIds, 12) as $chunk) {
            $handles = [];

            foreach ($chunk as $leagueId) {
                $ch = curl_init();
                if ($ch === false) {
                    continue;
                }

                curl_setopt_array($ch, [
                    CURLOPT_URL => $this->buildApiUrl($config, 'leagues/' . $leagueId . '/markets/straight'),
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_TIMEOUT => 25,
                    CURLOPT_CONNECTTIMEOUT => 10,
                    CURLOPT_ENCODING => '',
                    CURLOPT_HTTPHEADER => $this->headers($config['api_key']),
                ]);

                $handles[(int) $ch] = $ch;
                curl_multi_add_handle($multiHandle, $ch);
            }

            do {
                $status = curl_multi_exec($multiHandle, $running);
                if ($running > 0) {
                    $selected = curl_multi_select($multiHandle, 1.0);
                    if ($selected === -1) {
                        usleep(100000);
                    }
                }
            } while ($running > 0 && $status === CURLM_OK);

            foreach ($handles as $handle) {
                $raw = curl_multi_getcontent($handle);
                $httpCode = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);

                if (is_string($raw) && $raw !== '' && $httpCode < 400) {
                    $decoded = json_decode($raw, true);
                    if (is_array($decoded)) {
                        $results = $this->mergeOddsMaps(
                            $results,
                            $this->parseMoneylineMarkets($decoded)
                        );
                    }
                }

                curl_multi_remove_handle($multiHandle, $handle);
                curl_close($handle);
            }
        }

        curl_multi_close($multiHandle);

        return $results;
    }

    /**
     * @param array<int, int> $leagueIds
     * @param array{guest_root: string, api_version: string, api_key: string} $config
     * @return array<string, array{home: float, draw: float, away: float}>
     */
    private function fetchMoneylineOddsSequentially(array $leagueIds, array $config): array
    {
        $results = [];

        foreach ($leagueIds as $leagueId) {
            try {
                $payload = $this->requestJson(
                    $this->buildApiUrl($config, 'leagues/' . $leagueId . '/markets/straight'),
                    $this->headers($config['api_key'])
                );
            } catch (Throwable) {
                continue;
            }

            $results = $this->mergeOddsMaps($results, $this->parseMoneylineMarkets($payload));
        }

        return $results;
    }

    /**
     * @param array<int, array<string, mixed>> $markets
     * @return array<string, array{home: float, draw: float, away: float}>
     */
    private function parseMoneylineMarkets(array $markets): array
    {
        $results = [];

        foreach ($markets as $market) {
            if (!is_array($market)) {
                continue;
            }

            if (strtolower(trim((string) ($market['type'] ?? ''))) !== 'moneyline') {
                continue;
            }

            if ((int) ($market['period'] ?? -1) !== 0) {
                continue;
            }

            if (!empty($market['isAlternate'])) {
                continue;
            }

            $status = strtolower(trim((string) ($market['status'] ?? 'open')));
            if ($status !== '' && $status !== 'open') {
                continue;
            }

            $matchupId = trim((string) ($market['matchupId'] ?? ''));
            if ($matchupId === '') {
                continue;
            }

            $odds = $this->parseThreeWayPrices($market['prices'] ?? null);
            if ($odds === null) {
                continue;
            }

            $results[$matchupId] = $odds;
        }

        return $results;
    }

    /**
     * @param mixed $prices
     * @return array{home: float, draw: float, away: float}|null
     */
    private function parseThreeWayPrices(mixed $prices): ?array
    {
        if (!is_array($prices)) {
            return null;
        }

        $odds = [
            'home' => null,
            'draw' => null,
            'away' => null,
        ];

        foreach ($prices as $price) {
            if (!is_array($price)) {
                continue;
            }

            $designation = strtolower(trim((string) ($price['designation'] ?? '')));
            if (!array_key_exists($designation, $odds)) {
                continue;
            }

            $decimal = $this->americanToDecimal($price['price'] ?? null);
            if ($decimal === null) {
                continue;
            }

            $odds[$designation] = $decimal;
        }

        if ($odds['home'] === null || $odds['draw'] === null || $odds['away'] === null) {
            return null;
        }

        return [
            'home' => $odds['home'],
            'draw' => $odds['draw'],
            'away' => $odds['away'],
        ];
    }

    private function americanToDecimal(mixed $price): ?float
    {
        if (!is_numeric((string) $price)) {
            return null;
        }

        $price = (int) $price;
        if ($price === 0) {
            return null;
        }

        if ($price > 0) {
            return round(1 + ($price / 100), 2);
        }

        return round(1 + (100 / abs($price)), 2);
    }

    /**
     * @param array<int, array<string, mixed>> $referenceMatches
     * @return array{0: ?DateTimeImmutable, 1: ?DateTimeImmutable}
     */
    private function buildReferenceWindow(array $referenceMatches): array
    {
        $timezone = new DateTimeZone($this->brazilTimezone);
        $from = null;
        $to = null;

        foreach ($referenceMatches as $match) {
            $matchDate = trim((string) ($match['match_date'] ?? ''));
            if ($matchDate === '') {
                continue;
            }

            $date = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $matchDate, $timezone);
            if (!$date instanceof DateTimeImmutable) {
                try {
                    $date = new DateTimeImmutable($matchDate, $timezone);
                } catch (Throwable) {
                    continue;
                }
            }

            $from = $from === null || $date < $from ? $date : $from;
            $to = $to === null || $date > $to ? $date : $to;
        }

        if ($from === null || $to === null) {
            return [null, null];
        }

        return [
            $from->modify('-12 hours'),
            $to->modify('+12 hours'),
        ];
    }

    private function participantNameByAlignment(array $participants, string $alignment): string
    {
        foreach ($participants as $participant) {
            if (!is_array($participant)) {
                continue;
            }

            if (strtolower(trim((string) ($participant['alignment'] ?? ''))) !== $alignment) {
                continue;
            }

            return trim((string) ($participant['name'] ?? ''));
        }

        return '';
    }

    private function matchupHasPeriodZeroMoneyline(mixed $periods): bool
    {
        if (!is_array($periods)) {
            return false;
        }

        foreach ($periods as $period) {
            if (!is_array($period)) {
                continue;
            }

            if ((int) ($period['period'] ?? -1) !== 0) {
                continue;
            }

            if (empty($period['hasMoneyline'])) {
                continue;
            }

            $status = strtolower(trim((string) ($period['status'] ?? 'open')));

            return $status === '' || $status === 'open';
        }

        return false;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function splitLeagueNames(string $countryName, string $leagueName): array
    {
        $countryName = trim($countryName);
        $leagueName = trim($leagueName);

        if ($leagueName !== '' && str_contains($leagueName, ' - ')) {
            [$prefix, $suffix] = explode(' - ', $leagueName, 2);
            $prefix = trim($prefix);
            $suffix = trim($suffix);

            if ($countryName === '' && $prefix !== '') {
                $countryName = $prefix;
            }

            if ($suffix !== '') {
                $leagueName = $suffix;
            }
        }

        return [$countryName, $leagueName];
    }

    /**
     * @param array<string, array{home: float, draw: float, away: float}> $left
     * @param array<string, array{home: float, draw: float, away: float}> $right
     * @return array<string, array{home: float, draw: float, away: float}>
     */
    private function mergeOddsMaps(array $left, array $right): array
    {
        foreach ($right as $matchupId => $odds) {
            $left[$matchupId] = $odds;
        }

        return $left;
    }

    /**
     * @param array{guest_root: string, api_version: string, api_key: string} $config
     */
    private function buildApiUrl(array $config, string $path): string
    {
        return rtrim($config['guest_root'], '/')
            . '/'
            . trim($config['api_version'], '/')
            . '/'
            . ltrim($path, '/');
    }

    /**
     * @return array<string>
     */
    private function headers(?string $apiKey = null): array
    {
        $headers = [
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0 Safari/537.36',
            'Accept: application/json, text/plain, */*',
            'Accept-Language: en-US,en;q=0.9,pt-BR;q=0.8,pt;q=0.7',
            'Origin: ' . $this->siteBaseUrl,
            'Referer: ' . $this->siteBaseUrl . '/',
        ];

        if ($apiKey !== null && trim($apiKey) !== '') {
            $headers[] = 'x-api-key: ' . trim($apiKey);
        }

        return $headers;
    }

    /**
     * @return array<string, mixed>
     */
    private function requestJson(string $url, array $headers = []): array
    {
        $raw = $this->request($url, $headers);
        $decoded = json_decode($raw, true);

        if (!is_array($decoded)) {
            throw new RuntimeException('Pinnacle retornou JSON invalido.');
        }

        return $decoded;
    }

    private function request(string $url, array $headers = []): string
    {
        $ch = curl_init();
        if ($ch === false) {
            $fallback = $this->requestWithCurlCli($url, $headers);
            if ($fallback !== null) {
                return $fallback;
            }

            throw new RuntimeException('Falha ao inicializar cURL da Pinnacle.');
        }

        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 25,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_ENCODING => '',
            CURLOPT_HTTPHEADER => $headers !== [] ? $headers : $this->headers(),
        ]);

        $raw = curl_exec($ch);
        if ($raw === false) {
            $error = curl_error($ch);
            curl_close($ch);

            $fallback = $this->requestWithCurlCli($url, $headers);
            if ($fallback !== null) {
                return $fallback;
            }

            throw new RuntimeException('Falha ao buscar dados da Pinnacle: ' . $error);
        }

        $httpCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($httpCode >= 400) {
            $fallback = $this->requestWithCurlCli($url, $headers);
            if ($fallback !== null) {
                return $fallback;
            }

            throw new RuntimeException('Pinnacle retornou HTTP ' . $httpCode);
        }

        return (string) $raw;
    }

    private function requestWithCurlCli(string $url, array $headers): ?string
    {
        $where = shell_exec('where curl.exe 2>NUL');
        if (!is_string($where) || trim($where) === '') {
            return null;
        }

        $parts = ['curl.exe', '-sS', '-L', '--compressed', escapeshellarg($url)];
        foreach (($headers !== [] ? $headers : $this->headers()) as $header) {
            $parts[] = '-H';
            $parts[] = escapeshellarg($header);
        }

        $raw = shell_exec(implode(' ', $parts));
        if (!is_string($raw) || trim($raw) === '') {
            return null;
        }

        return $raw;
    }
}
