<?php
declare(strict_types=1);

namespace App\Services;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use Throwable;

final class BetfairSportsbookService
{
    private string $siteBaseUrl = 'https://www.betfair.bet.br';
    private string $sportPageUrl = 'https://www.betfair.bet.br/apostas/futebol/s-1';
    private string $graphqlEndpoint = 'https://apitbd.betfair.bet.br/api/tbd/bff-gql/v11/';
    private string $fallbackApplicationKey = 'K61C39rIC0WKzoQ7';
    private string $sportCurrentUrl = 'futebol/s-1';
    private string $sportViewUrn = 'ppb:tbd:view:sport:1';
    private string $viewDocumentId = 'View#13aa96d63b835e2b62b30a99f26e9fa2';
    private string $cardDocumentId = 'Card#081269e0cb095a8ada87bce2f43a3ccb';
    private string $resultMarketsGroupPattern = 'ppb:tbd:cardgroup:pebble:marketTemplateEvent:ZxDkyRIAACAAf2za/e/%s';
    private string $brazilTimezone = 'America/Sao_Paulo';

    /**
     * @return array<int, array{
     *   betfair_match_id: string,
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
    public function fetchFootballMatchesWithOdds(): array
    {
        $cookieFile = tempnam(sys_get_temp_dir(), 'betfair_sync_');
        if ($cookieFile === false) {
            throw new RuntimeException('Nao foi possivel criar o arquivo temporario de sessao do Betfair.');
        }

        try {
            $html = $this->request(
                $this->sportPageUrl,
                null,
                $cookieFile,
                $this->pageHeaders()
            );

            $applicationKey = $this->extractApplicationKey($html);
            $viewPayload = $this->requestGraphql(
                $cookieFile,
                $applicationKey,
                $this->viewDocumentId,
                [
                    'urn' => $this->sportViewUrn,
                    'numberOfFilledCardsInCardGroup' => 24,
                    'numberOfFilledCardsInView' => 40,
                    'withBottomBar' => true,
                    'withLeftSidebar' => true,
                    'withRegulatoryData' => true,
                    'withPageInfo' => true,
                    'productExclusions' => [],
                    'experiments' => [],
                    'decorationsOnly' => false,
                ]
            );

            $couponUrns = $this->extractCouponUrns($viewPayload);
            if ($couponUrns === []) {
                throw new RuntimeException('O Betfair nao retornou os grupos de jogos esperados para futebol.');
            }

            $matches = $this->fetchMatchesFromCoupons($cookieFile, $applicationKey, $couponUrns);
            if ($matches === []) {
                throw new RuntimeException('Nenhum jogo valido foi encontrado na pagina de futebol do Betfair.');
            }

            $oddsByEventId = $this->fetchMatchOddsByEventIds(
                $cookieFile,
                $applicationKey,
                array_column($matches, 'betfair_match_id')
            );

            foreach ($matches as $index => $match) {
                $eventId = (string) ($match['betfair_match_id'] ?? '');
                $matches[$index]['odds'] = $oddsByEventId[$eventId] ?? null;
            }

            return array_values($matches);
        } finally {
            @unlink($cookieFile);
        }
    }

    /**
     * @return array<int, string>
     */
    private function extractCouponUrns(array $payload): array
    {
        $urns = [];
        $this->collectUrnsByTypename($payload, 'FilteredCouponCardGroup', $urns);

        return array_keys($urns);
    }

    /**
     * @param array<string, bool> $urns
     */
    private function collectUrnsByTypename(mixed $node, string $typename, array &$urns): void
    {
        if (!is_array($node)) {
            return;
        }

        if (($node['__typename'] ?? null) === $typename) {
            $urn = trim((string) ($node['urn'] ?? ''));
            if ($urn !== '') {
                $urns[$urn] = true;
            }
        }

        foreach ($node as $value) {
            $this->collectUrnsByTypename($value, $typename, $urns);
        }
    }

    /**
     * @param array<int, string> $couponUrns
     * @return array<int, array{
     *   betfair_match_id: string,
     *   source_url: string,
     *   match_date: string,
     *   home_team: string,
     *   away_team: string,
     *   league_name: string,
     *   country_name: string,
     *   status: 'scheduled'|'live',
     *   odds: null
     * }>
     */
    private function fetchMatchesFromCoupons(string $cookieFile, string $applicationKey, array $couponUrns): array
    {
        $matches = [];

        foreach (array_chunk($couponUrns, 2) as $chunk) {
            $payload = $this->requestGraphql(
                $cookieFile,
                $applicationKey,
                $this->cardDocumentId,
                [
                    'urn' => array_values($chunk),
                    'numberOfFilledCardsInCardGroup' => 120,
                    'productExclusions' => [],
                    'experiments' => [],
                ]
            );

            foreach (($payload['data']['Cards'] ?? []) as $card) {
                if (!is_array($card) || ($card['__typename'] ?? '') !== 'FilteredCouponCardGroup') {
                    continue;
                }

                foreach (($card['full']['edges'] ?? []) as $edge) {
                    $node = $edge['node'] ?? null;
                    if (!is_array($node) || ($node['__typename'] ?? '') !== 'EventMarketCard') {
                        continue;
                    }

                    $match = $this->parseCouponMatch($node);
                    if ($match === null) {
                        continue;
                    }

                    $matches[$match['betfair_match_id']] = $match;
                }
            }
        }

        return array_values($matches);
    }

    /**
     * @param array<string, mixed> $node
     * @return array{
     *   betfair_match_id: string,
     *   source_url: string,
     *   match_date: string,
     *   home_team: string,
     *   away_team: string,
     *   league_name: string,
     *   country_name: string,
     *   status: 'scheduled'|'live',
     *   odds: null
     * }|null
     */
    private function parseCouponMatch(array $node): ?array
    {
        $eventId = trim((string) ($node['sportevent']['eventId'] ?? ''));
        $homeTeam = trim((string) ($node['fixture']['runnerNames']['home'] ?? $node['fixture']['home']['name'] ?? ''));
        $awayTeam = trim((string) ($node['fixture']['runnerNames']['away'] ?? $node['fixture']['away']['name'] ?? ''));
        $leagueName = trim((string) ($node['sportevent']['competition']['name'] ?? ''));
        $matchDate = $this->convertToBrazilDate(
            (string) ($node['fixture']['scheduledAt'] ?? $node['sportevent']['openDate'] ?? '')
        );
        $status = $this->matchStatus($node['fixture'] ?? [], $node['displayRunners']['sportsbook']['market']['liveData'] ?? []);

        if ($eventId === '' || $homeTeam === '' || $awayTeam === '' || $leagueName === '' || $matchDate === '' || $status === null) {
            return null;
        }

        $sourceUrl = $this->absoluteAppUrl((string) ($node['eventViewLink']['viewUrl'] ?? ''));

        return [
            'betfair_match_id' => $eventId,
            'source_url' => $sourceUrl !== '' ? $sourceUrl : $this->sportPageUrl,
            'match_date' => $matchDate,
            'home_team' => $homeTeam,
            'away_team' => $awayTeam,
            'league_name' => $leagueName,
            'country_name' => '',
            'status' => $status,
            'odds' => null,
        ];
    }

    /**
     * @param array<int, string> $eventIds
     * @return array<string, array{home: float, draw: float, away: float}>
     */
    private function fetchMatchOddsByEventIds(string $cookieFile, string $applicationKey, array $eventIds): array
    {
        $eventIds = array_values(array_unique(array_filter(array_map(
            static fn (mixed $eventId): string => trim((string) $eventId),
            $eventIds
        ))));

        if ($eventIds === []) {
            return [];
        }

        $oddsByEventId = [];

        foreach (array_chunk($eventIds, 8) as $chunk) {
            $urns = array_map(
                fn (string $eventId): string => sprintf($this->resultMarketsGroupPattern, $eventId),
                $chunk
            );

            try {
                $payload = $this->requestGraphql(
                    $cookieFile,
                    $applicationKey,
                    $this->cardDocumentId,
                    [
                        'urn' => $urns,
                        'numberOfFilledCardsInCardGroup' => 12,
                        'productExclusions' => [],
                        'experiments' => [],
                    ]
                );
            } catch (Throwable) {
                continue;
            }

            foreach (($payload['data']['Cards'] ?? []) as $card) {
                if (!is_array($card) || ($card['__typename'] ?? '') !== 'PebbleCardGroup') {
                    continue;
                }

                $eventId = '';
                $odds = null;

                foreach (($card['full']['edges'] ?? []) as $edge) {
                    $node = $edge['node'] ?? null;
                    if (!is_array($node) || ($node['__typename'] ?? '') !== 'MarketCard') {
                        continue;
                    }

                    $market = $node['displayRunners']['sportsbook']['market'] ?? null;
                    if (!is_array($market)) {
                        continue;
                    }

                    if (strtoupper(trim((string) ($market['marketType'] ?? ''))) !== 'MATCH_ODDS') {
                        continue;
                    }

                    $eventId = trim((string) ($market['hierarchy']['sportevent']['eventId'] ?? ''));
                    if ($eventId === '') {
                        $eventId = $this->extractEventIdFromUrn((string) ($card['urn'] ?? ''));
                    }

                    $odds = $this->extractMatchOdds($market);
                    if ($eventId !== '' && $odds !== null) {
                        break;
                    }
                }

                if ($eventId !== '' && $odds !== null) {
                    $oddsByEventId[$eventId] = $odds;
                }
            }
        }

        return $oddsByEventId;
    }

    /**
     * @param array<string, mixed> $market
     * @return array{home: float, draw: float, away: float}|null
     */
    private function extractMatchOdds(array $market): ?array
    {
        $definitions = $market['runners'] ?? null;
        $liveData = $market['liveData']['runners'] ?? null;

        if (!is_array($definitions) || !is_array($liveData)) {
            return null;
        }

        $liveBySelectionId = [];
        $liveByRunnerUrn = [];

        foreach ($liveData as $runner) {
            if (!is_array($runner)) {
                continue;
            }

            $selectionId = trim((string) ($runner['selectionId'] ?? ''));
            if ($selectionId !== '') {
                $liveBySelectionId[$selectionId] = $runner;
            }

            $runnerUrn = trim((string) ($runner['runnerURN'] ?? ''));
            if ($runnerUrn !== '') {
                $liveByRunnerUrn[$runnerUrn] = $runner;
            }
        }

        $odds = [
            'home' => null,
            'draw' => null,
            'away' => null,
        ];

        foreach ($definitions as $runner) {
            if (!is_array($runner)) {
                continue;
            }

            $key = match (strtoupper(trim((string) ($runner['resultType'] ?? '')))) {
                'HOME' => 'home',
                'DRAW' => 'draw',
                'AWAY' => 'away',
                default => null,
            };

            if ($key === null) {
                continue;
            }

            $selectionId = trim((string) ($runner['selectionId'] ?? ''));
            $runnerUrn = trim((string) ($runner['runnerURN'] ?? ''));
            $liveRunner = $selectionId !== '' && isset($liveBySelectionId[$selectionId])
                ? $liveBySelectionId[$selectionId]
                : ($runnerUrn !== '' ? ($liveByRunnerUrn[$runnerUrn] ?? null) : null);

            $decimal = $this->extractDecimalOdd($liveRunner);
            if ($decimal !== null) {
                $odds[$key] = $decimal;
            }
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

    private function extractDecimalOdd(mixed $runner): ?float
    {
        if (!is_array($runner)) {
            return null;
        }

        $value = $runner['displayOdds']['decimal'] ?? $runner['odds']['decimal'] ?? null;
        if (!is_numeric((string) $value)) {
            return null;
        }

        $decimal = round((float) $value, 2);
        return $decimal > 1 ? $decimal : null;
    }

    private function matchStatus(array $fixture, mixed $liveData): ?string
    {
        $durationStatus = strtoupper(trim((string) ($fixture['duration']['status'] ?? '')));
        $inplay = is_array($liveData) && !empty($liveData['inplay']);

        if ($inplay) {
            return 'live';
        }

        if ($durationStatus === '' || in_array($durationStatus, ['PRE_MATCH', 'NOT_STARTED'], true)) {
            return 'scheduled';
        }

        if (in_array($durationStatus, ['POST_MATCH', 'FULL_TIME', 'FINISHED', 'ENDED', 'RESULT'], true)) {
            return null;
        }

        return 'live';
    }

    private function convertToBrazilDate(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        try {
            return (new DateTimeImmutable($value))
                ->setTimezone(new DateTimeZone($this->brazilTimezone))
                ->format('Y-m-d H:i:s');
        } catch (Throwable) {
            return '';
        }
    }

    private function extractEventIdFromUrn(string $urn): string
    {
        if (preg_match('#/e/(\d+)$#', trim($urn), $matches) === 1) {
            return $matches[1];
        }

        return '';
    }

    private function absoluteAppUrl(string $viewUrl): string
    {
        $viewUrl = trim($viewUrl);
        if ($viewUrl === '') {
            return '';
        }

        if (str_starts_with($viewUrl, 'http://') || str_starts_with($viewUrl, 'https://')) {
            return $viewUrl;
        }

        return rtrim($this->siteBaseUrl, '/') . '/apostas/' . ltrim($viewUrl, '/');
    }

    private function extractApplicationKey(string $html): string
    {
        if (preg_match('/"applicationKey"\s*:\s*"([^"]+)"/', $html, $matches) === 1) {
            return trim((string) $matches[1]);
        }

        if (preg_match('/_ak=([A-Za-z0-9]+)/', $html, $matches) === 1) {
            return trim((string) $matches[1]);
        }

        return $this->fallbackApplicationKey;
    }

    /**
     * @param array<string, mixed> $variables
     * @return array<string, mixed>
     */
    private function requestGraphql(
        string $cookieFile,
        string $applicationKey,
        string $documentId,
        array $variables
    ): array {
        $query = http_build_query([
            '_ak' => $applicationKey,
            'currentUrl' => $this->sportCurrentUrl,
            'currentViewUrn' => $this->sportViewUrn,
        ]);

        $body = json_encode([
            'documentId' => $documentId,
            'variables' => $variables,
        ], JSON_UNESCAPED_SLASHES);

        if (!is_string($body) || $body === '') {
            throw new RuntimeException('Falha ao montar a requisicao do Betfair.');
        }

        $response = $this->request(
            $this->graphqlEndpoint . '?' . $query,
            $body,
            $cookieFile,
            $this->graphqlHeaders()
        );

        $payload = json_decode($response, true);
        if (!is_array($payload)) {
            throw new RuntimeException('O Betfair retornou uma resposta invalida.');
        }

        if (isset($payload['errors']) && is_array($payload['errors']) && $payload['errors'] !== []) {
            $message = trim((string) ($payload['errors'][0]['message'] ?? 'Erro desconhecido no Betfair.'));
            throw new RuntimeException($message !== '' ? $message : 'Erro desconhecido no Betfair.');
        }

        return $payload;
    }

    /**
     * @param array<int, string> $headers
     */
    private function request(string $url, ?string $body, string $cookieFile, array $headers): string
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Falha ao inicializar cURL do Betfair.');
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_ENCODING => '',
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/123.0 Safari/537.36',
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_COOKIEJAR => $cookieFile,
            CURLOPT_COOKIEFILE => $cookieFile,
        ]);

        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $raw = curl_exec($ch);
        if ($raw === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException('Falha ao buscar dados do Betfair: ' . $error);
        }

        $httpCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($httpCode >= 400) {
            throw new RuntimeException('Betfair retornou HTTP ' . $httpCode);
        }

        return (string) $raw;
    }

    /**
     * @return array<int, string>
     */
    private function pageHeaders(): array
    {
        return [
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language: pt-BR,pt;q=0.9,en-US;q=0.8,en;q=0.7',
        ];
    }

    /**
     * @return array<int, string>
     */
    private function graphqlHeaders(): array
    {
        return [
            'Accept: */*',
            'Accept-Language: pt-BR,pt;q=0.9,en-US;q=0.8,en;q=0.7',
            'Content-Type: application/json',
            'Origin: ' . $this->siteBaseUrl,
            'Referer: ' . $this->sportPageUrl,
        ];
    }
}
