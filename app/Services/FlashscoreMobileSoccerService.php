<?php
declare(strict_types=1);

namespace App\Services;

use DateTimeImmutable;
use DateTimeZone;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;
use DOMXPath;
use RuntimeException;

final class FlashscoreMobileSoccerService
{
    private string $baseUrl = 'https://m.flashscore.com.br';
    private string $brazilTimezone = 'America/Sao_Paulo';

    /**
     * @return array<int, array{
     *   flashscore_match_id: string,
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
    public function fetchDailyMatchesWithOdds(?int $limit = 200): array
    {
        $matches = $this->fetchDailyMatchesIndex();
        if ($limit !== null) {
            $matches = array_slice($matches, 0, max(1, $limit));
        }

        return $this->hydrateMatchesWithDetails($matches);
    }

    /**
     * @return array<int, array{
     *   flashscore_match_id: string,
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
    public function fetchDailyMatchesIndex(): array
    {
        return $this->parseOddsPageMatches($this->fetchOddsPageHtml());
    }

    /**
     * @param array<int, array{
     *   flashscore_match_id: string,
     *   source_url: string,
     *   match_date: string,
     *   home_team: string,
     *   away_team: string,
     *   league_name: string,
     *   country_name: string,
     *   status: 'scheduled'|'live',
     *   odds: array{home: float, draw: float, away: float}|null
     * }> $matches
     * @return array<int, array{
     *   flashscore_match_id: string,
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
    public function hydrateMatchesWithDetails(array $matches): array
    {
        $detailIds = [];
        foreach ($matches as $match) {
            if ($match['match_date'] === '') {
                $detailIds[] = $match['flashscore_match_id'];
            }
        }

        $detailsByMatchId = $this->fetchMatchDetailsByIds($detailIds);

        foreach ($matches as &$match) {
            $detail = $detailsByMatchId[(string) $match['flashscore_match_id']] ?? null;
            if (!is_array($detail)) {
                continue;
            }

            if ($match['match_date'] === '' && ($detail['match_date'] ?? '') !== '') {
                $match['match_date'] = (string) $detail['match_date'];
            }

            if (($match['home_team'] ?? '') === '' && ($detail['home_team'] ?? '') !== '') {
                $match['home_team'] = (string) $detail['home_team'];
            }

            if (($match['away_team'] ?? '') === '' && ($detail['away_team'] ?? '') !== '') {
                $match['away_team'] = (string) $detail['away_team'];
            }

            if (($match['odds'] ?? null) === null && isset($detail['odds']) && is_array($detail['odds'])) {
                $match['odds'] = $detail['odds'];
            }
        }

        return array_values(array_filter($matches, static function (array $match): bool {
            return ($match['home_team'] ?? '') !== ''
                && ($match['away_team'] ?? '') !== ''
                && ($match['match_date'] ?? '') !== '';
        }));
    }
    public function fetchOddsPageHtml(): string
    {
        return $this->request($this->baseUrl . '/?d=0&s=5');
    }

    /**
     * @return array<int, array{
     *   flashscore_match_id: string,
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
    public function parseOddsPageMatches(string $html): array
    {
        $dom = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $dom->loadHTML($html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($loaded === false) {
            return [];
        }

        $xpath = new DOMXPath($dom);
        $scoreData = $xpath->query('//*[@id="score-data"]')->item(0);
        if (!$scoreData instanceof DOMElement) {
            return [];
        }

        $matches = [];
        $currentHeading = [
            'country_name' => '',
            'league_name' => '',
        ];
        $pendingLabel = '';
        $buffer = '';
        $lastMatchIndex = null;

        foreach ($scoreData->childNodes as $node) {
            if ($node instanceof DOMText) {
                $buffer .= $node->textContent;
                continue;
            }

            if (!($node instanceof DOMElement)) {
                continue;
            }

            $tag = strtolower($node->tagName);

            if ($tag === 'h4') {
                $currentHeading = $this->parseHeading($node->textContent);
                $pendingLabel = '';
                $buffer = '';
                $lastMatchIndex = null;
                continue;
            }

            if ($tag === 'span' && !$this->hasClass($node, 'mobi-odds')) {
                $pendingLabel = $this->normalizeInlineText($node->textContent);
                $buffer = '';
                continue;
            }

            if ($tag === 'a') {
                $href = trim((string) $node->getAttribute('href'));
                if (preg_match('#^/jogo/([^/]+)/#', $href, $matchesId) !== 1) {
                    continue;
                }

                $class = trim((string) $node->getAttribute('class'));
                $status = $this->statusFromLinkClass($class);
                $bufferText = $this->normalizeInlineText($buffer);
                $buffer = '';

                [$homeTeam, $awayTeam] = $this->splitTeams($bufferText);
                if ($homeTeam === '' || $awayTeam === '' || $status === 'finished') {
                    $pendingLabel = '';
                    $lastMatchIndex = null;
                    continue;
                }

                $matches[] = [
                    'flashscore_match_id' => $matchesId[1],
                    'source_url' => $this->baseUrl . '/jogo/' . $matchesId[1] . '/',
                    'match_date' => $this->parseListDateLabel($pendingLabel),
                    'home_team' => $homeTeam,
                    'away_team' => $awayTeam,
                    'league_name' => $currentHeading['league_name'],
                    'country_name' => $currentHeading['country_name'],
                    'status' => $status,
                    'odds' => null,
                ];

                $lastMatchIndex = array_key_last($matches);
                continue;
            }

            if ($tag === 'span' && $this->hasClass($node, 'mobi-odds') && $lastMatchIndex !== null) {
                $matches[$lastMatchIndex]['odds'] = $this->parseOddsFromSpan($node);
                continue;
            }

            if ($tag === 'br') {
                $pendingLabel = '';
                $buffer = '';
                $lastMatchIndex = null;
            }
        }

        return $matches;
    }

    /**
     * @param array<int, string> $matchIds
     * @return array<string, array{match_date: string, home_team: string, away_team: string, odds: array{home: float, draw: float, away: float}|null}>
     */
    private function fetchMatchDetailsByIds(array $matchIds): array
    {
        $matchIds = array_values(array_unique(array_filter(array_map(
            static fn (mixed $id): string => trim((string) $id),
            $matchIds
        ))));

        if ($matchIds === []) {
            return [];
        }

        $results = [];

        foreach (array_chunk($matchIds, 10) as $chunk) {
            $multiHandle = curl_multi_init();
            if ($multiHandle === false) {
                throw new RuntimeException('Falha ao inicializar multi cURL do Flashscore.');
            }

            $handles = [];

            foreach ($chunk as $matchId) {
                $url = $this->baseUrl . '/jogo/' . rawurlencode($matchId) . '/';
                $ch = curl_init();
                if ($ch === false) {
                    continue;
                }

                curl_setopt_array($ch, [
                    CURLOPT_URL => $url,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_TIMEOUT => 20,
                    CURLOPT_CONNECTTIMEOUT => 10,
                    CURLOPT_ENCODING => '',
                    CURLOPT_HTTPHEADER => $this->headers(),
                ]);

                $handles[(int) $ch] = [
                    'match_id' => $matchId,
                    'handle' => $ch,
                ];

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

            foreach ($handles as $item) {
                $matchId = $item['match_id'];
                $handle = $item['handle'];

                $raw = curl_multi_getcontent($handle);
                $httpCode = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);

                if ($raw !== false && $httpCode < 400) {
                    $results[$matchId] = $this->parseMatchDetailPage((string) $raw);
                }

                curl_multi_remove_handle($multiHandle, $handle);
                curl_close($handle);
            }

            curl_multi_close($multiHandle);
        }

        return $results;
    }

    /**
     * @return array{match_date: string, home_team: string, away_team: string, odds: array{home: float, draw: float, away: float}|null}
     */
    private function parseMatchDetailPage(string $html): array
    {
        $dom = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $dom->loadHTML($html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($loaded === false) {
            return [
                'match_date' => '',
                'home_team' => '',
                'away_team' => '',
                'odds' => null,
            ];
        }

        $xpath = new DOMXPath($dom);
        $teamLinks = $xpath->query('//*[@id="main"]/h3/a');
        $homeTeam = '';
        $awayTeam = '';

        if ($teamLinks !== false && $teamLinks->length >= 2) {
            $homeTeam = $this->normalizeInlineText((string) $teamLinks->item(0)?->textContent);
            $awayTeam = $this->normalizeInlineText((string) $teamLinks->item(1)?->textContent);
        }

        $matchDate = '';
        $detailNodes = $xpath->query('//*[@id="main"]/div[contains(@class,"detail")]');
        if ($detailNodes !== false) {
            foreach ($detailNodes as $detailNode) {
                if (!($detailNode instanceof DOMElement)) {
                    continue;
                }

                $text = $this->normalizeInlineText($detailNode->textContent);
                if (preg_match('/\d{2}\.\d{2}\.\d{4}\s+\d{2}:\d{2}/', $text, $matchesDate) === 1) {
                    $matchDate = $this->parseDetailDateText($matchesDate[0]);
                    break;
                }
            }
        }

        $odds = null;
        $oddsNodes = $xpath->query('//*[contains(@class,"odds-detail")]//a');
        if ($oddsNodes !== false && $oddsNodes->length >= 3) {
            $values = [];
            foreach ($oddsNodes as $oddsNode) {
                $value = $this->parseOddValue($oddsNode->textContent);
                if ($value !== null) {
                    $values[] = $value;
                }
                if (count($values) === 3) {
                    break;
                }
            }

            if (count($values) === 3) {
                $odds = [
                    'home' => $values[0],
                    'draw' => $values[1],
                    'away' => $values[2],
                ];
            }
        }

        return [
            'match_date' => $matchDate,
            'home_team' => $homeTeam,
            'away_team' => $awayTeam,
            'odds' => $odds,
        ];
    }

    /**
     * @return array{country_name: string, league_name: string}
     */
    private function parseHeading(string $heading): array
    {
        $heading = $this->normalizeInlineText($heading);
        $heading = preg_replace('/\s+Classifica(?:coes|ções)$/u', '', $heading) ?? $heading;

        $parts = explode(':', $heading, 2);
        if (count($parts) === 2) {
            return [
                'country_name' => trim($parts[0]),
                'league_name' => trim($parts[1]),
            ];
        }

        return [
            'country_name' => '',
            'league_name' => trim($heading),
        ];
    }

    /**
     * @return array{home: float, draw: float, away: float}|null
     */
    private function parseOddsFromSpan(DOMElement $span): ?array
    {
        $values = [];

        foreach ($span->getElementsByTagName('a') as $link) {
            $value = $this->parseOddValue($link->textContent);
            if ($value !== null) {
                $values[] = $value;
            }

            if (count($values) === 3) {
                break;
            }
        }

        if (count($values) !== 3) {
            return null;
        }

        return [
            'home' => $values[0],
            'draw' => $values[1],
            'away' => $values[2],
        ];
    }

    private function parseOddValue(string $value): ?float
    {
        $value = trim(str_replace(',', '.', $value));
        if ($value === '' || !is_numeric($value)) {
            return null;
        }

        $decimal = round((float) $value, 2);
        return $decimal > 1 ? $decimal : null;
    }

    private function parseListDateLabel(string $label): string
    {
        if (preg_match('/(\d{2}):(\d{2})/', $label, $matches) !== 1) {
            return '';
        }

        $timezone = new DateTimeZone($this->brazilTimezone);
        $today = new DateTimeImmutable('today', $timezone);

        return $today
            ->setTime((int) $matches[1], (int) $matches[2])
            ->format('Y-m-d H:i:s');
    }

    private function parseDetailDateText(string $value): string
    {
        $timezone = new DateTimeZone($this->brazilTimezone);
        $date = DateTimeImmutable::createFromFormat('d.m.Y H:i', trim($value), $timezone);

        return $date ? $date->format('Y-m-d H:i:s') : '';
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function splitTeams(string $teamLine): array
    {
        $teamLine = trim($teamLine);
        if ($teamLine === '') {
            return ['', ''];
        }

        $parts = preg_split('/\s+-\s+/u', $teamLine);
        if (!is_array($parts) || count($parts) < 2) {
            return ['', ''];
        }

        $awayTeam = trim((string) array_pop($parts));
        $homeTeam = trim(implode(' - ', $parts));

        return [$homeTeam, $awayTeam];
    }

    private function statusFromLinkClass(string $className): string
    {
        $className = strtolower(trim($className));
        if (str_contains($className, 'live')) {
            return 'live';
        }

        if (str_contains($className, 'sched')) {
            return 'scheduled';
        }

        return 'finished';
    }

    private function hasClass(DOMElement $element, string $className): bool
    {
        $classes = preg_split('/\s+/', trim((string) $element->getAttribute('class'))) ?: [];
        return in_array($className, $classes, true);
    }

    private function normalizeInlineText(string $value): string
    {
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
        return trim($value);
    }

    /**
     * @return array<string>
     */
    private function headers(): array
    {
        return [
            'User-Agent: Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1',
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language: pt-BR,pt;q=0.9,en-US;q=0.8,en;q=0.7',
            'Referer: https://m.flashscore.com.br/',
        ];
    }

    private function request(string $url): string
    {
        $ch = curl_init();
        if ($ch === false) {
            throw new RuntimeException('Falha ao inicializar cURL do Flashscore.');
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
            throw new RuntimeException('Falha ao buscar dados do Flashscore: ' . $error);
        }

        $httpCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($httpCode >= 400) {
            throw new RuntimeException('Flashscore retornou HTTP ' . $httpCode);
        }

        return (string) $raw;
    }
}
