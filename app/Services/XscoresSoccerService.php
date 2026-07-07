<?php
declare(strict_types=1);

namespace App\Services;

use DateTimeImmutable;
use DateTimeZone;
use DOMDocument;
use DOMXPath;
use RuntimeException;

final class XscoresSoccerService
{
    private string $baseUrl = 'https://www.xscores.com';
    private string $brazilTimezone = 'America/Sao_Paulo';

    public function fetchSoccerPageHtml(): string
    {
        $url = $this->baseUrl . '/soccer';

        $ch = curl_init();
        if ($ch === false) {
            throw new RuntimeException('Falha ao inicializar cURL.');
        }

        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 25,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER => [
                'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0 Safari/537.36',
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language: pt-BR,pt;q=0.9,en-US;q=0.8,en;q=0.7',
            ],
        ]);

        $raw = curl_exec($ch);
        if ($raw === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException('Falha ao buscar dados do Xscores: ' . $error);
        }

        $httpCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($httpCode >= 400) {
            throw new RuntimeException('Xscores retornou HTTP ' . $httpCode);
        }

        return (string) $raw;
    }

    /**
     * @return array<int, array{
     *  xscores_match_id: string,
     *  source_url: string,
     *  match_date: string,
     *  ko_time: string,
     *  home_team: string,
     *  away_team: string,
     *  league_name: string,
     *  country_name: string,
     *  status: 'scheduled'|'live'
     * }>
     */
    public function parseSoccerMatches(string $html): array
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
        $nodes = $xpath->query('//a[contains(@class,"match_line") and contains(@href,"/soccer/match/")]');
        if ($nodes === false) {
            return [];
        }

        $matches = [];
        $sourceTimezone = $this->detectSourceTimezone($html);

        foreach ($nodes as $node) {
            if (!($node instanceof \DOMElement)) {
                continue;
            }

            $xscoresMatchId = trim((string) $node->getAttribute('id'));
            $href = trim((string) $node->getAttribute('href'));

            $matchDay = trim((string) $node->getAttribute('data-matchday'));
            $koTime = trim((string) $node->getAttribute('data-ko'));
            $homeTeam = trim((string) $node->getAttribute('data-home-team'));
            $awayTeam = trim((string) $node->getAttribute('data-away-team'));
            $leagueName = trim((string) $node->getAttribute('data-league-name'));
            $countryName = trim((string) $node->getAttribute('data-country-name'));

            $statusType = trim((string) $node->getAttribute('data-statustype'));
            $visible = strtolower(trim((string) $node->getAttribute('data-visible')));

            if ($visible !== '' && $visible !== 'true') {
                continue;
            }

            if ($xscoresMatchId === '' || $matchDay === '' || $koTime === '' || $homeTeam === '' || $awayTeam === '') {
                continue;
            }

            // Evita importar jogos finalizados.
            if ($statusType === 'finished') {
                continue;
            }

            // Normaliza status para o seu banco.
            $status = 'scheduled';
            if ($statusType === 'inprogress') {
                $status = 'live';
            }

            // Algumas páginas usam "competition-name" em vez de "league-name".
            if ($leagueName === '') {
                $leagueName = trim((string) $node->getAttribute('data-competition-name'));
            }
            if ($leagueName === '') {
                $leagueName = trim((string) $node->getAttribute('data-parent-competition'));
            }
            if ($leagueName === '') {
                $leagueName = 'Xscores';
            }

            $sourceUrl = $href;
            if ($sourceUrl !== '' && str_starts_with($sourceUrl, '/')) {
                $sourceUrl = $this->baseUrl . $sourceUrl;
            }

            $matchDate = $this->convertMatchDateToBrazil($matchDay, $koTime, $sourceTimezone);

            $matches[] = [
                'xscores_match_id' => $xscoresMatchId,
                'source_url' => $sourceUrl,
                'match_date' => $matchDate,
                'ko_time' => $koTime,
                'home_team' => $homeTeam,
                'away_team' => $awayTeam,
                'league_name' => $leagueName,
                'country_name' => $countryName,
                'status' => $status,
            ];
        }

        return $matches;
    }

    private function detectSourceTimezone(string $html): DateTimeZone
    {
        if (preg_match('/PAGE_UTC_OFFSET\s*=\s*(-?\d+)/', $html, $matches) === 1) {
            $seconds = (int) $matches[1];
            $sign = $seconds < 0 ? '-' : '+';
            $seconds = abs($seconds);
            $hours = intdiv($seconds, 3600);
            $minutes = intdiv($seconds % 3600, 60);

            return new DateTimeZone(sprintf('%s%02d:%02d', $sign, $hours, $minutes));
        }

        if (preg_match('/Timezone:[^<]*<span[^>]*>\s*([^<]+)\s*<\/span>/i', $html, $matches) === 1) {
            $timezone = trim((string) $matches[1]);
            if ($timezone !== '') {
                try {
                    return new DateTimeZone($timezone);
                } catch (\Throwable) {
                }
            }
        }

        return new DateTimeZone('Europe/Athens');
    }

    private function convertMatchDateToBrazil(string $matchDay, string $koTime, DateTimeZone $sourceTimezone): string
    {
        $matchDay = trim($matchDay);
        $koTime = trim($koTime);

        foreach (['Y-m-d H:i:s', 'Y-m-d H:i'] as $format) {
            $date = DateTimeImmutable::createFromFormat($format, $matchDay . ' ' . $koTime, $sourceTimezone);
            if ($date instanceof DateTimeImmutable) {
                return $date
                    ->setTimezone(new DateTimeZone($this->brazilTimezone))
                    ->format('Y-m-d H:i:s');
            }
        }

        return $matchDay . ' ' . $koTime . ':00';
    }
}
