<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PDOException;
use RuntimeException;
use Throwable;

final class AdminGameSyncService
{
    public function __construct(private readonly Database $db)
    {
    }

    /**
     * @param array{status_scope?: string, league_terms?: array<int, string>} $filters
     * @return array{created: int, updated: int, real_odds: int, source_total: int, matched_total: int, imported_total: int}
     */
    public function syncXscores(int $limit, int $adminUserId, array $filters = []): array
    {
        $service = new XscoresSoccerService();
        $html = $service->fetchSoccerPageHtml();
        $sourceMatches = $service->parseSoccerMatches($html);
        $matches = $this->applySyncFilters($sourceMatches, $filters);
        $matchedTotal = count($matches);
        $matches = array_slice($matches, 0, max(1, $limit));

        if ($matches === []) {
            throw new RuntimeException($this->emptySyncMessage('Xscore', $filters));
        }

        return $this->buildSyncStats(
            $this->importMatches($this->enrichXscoresMatchesWithReliableOdds($matches), $adminUserId),
            count($sourceMatches),
            $matchedTotal,
            count($matches)
        );
    }

    /**
     * @param array{status_scope?: string, league_terms?: array<int, string>} $filters
     * @return array{created: int, updated: int, real_odds: int, source_total: int, matched_total: int, imported_total: int}
     */
    public function syncSofascore(int $limit, int $adminUserId, array $filters = []): array
    {
        $timezone = new DateTimeZone('America/Sao_Paulo');
        $service = new SofascoreSoccerService();
        $sourceMatches = $service->fetchDailyMatchesIndex(new DateTimeImmutable('today', $timezone));
        $matches = $this->applySyncFilters($sourceMatches, $filters);
        $matchedTotal = count($matches);
        $matches = array_slice($matches, 0, max(1, $limit));

        if ($matches === []) {
            throw new RuntimeException($this->emptySyncMessage('SofaScore', $filters));
        }

        $oddsByEventId = $service->fetchOddsByEventIds(array_column($matches, 'sofascore_match_id'));
        foreach ($matches as &$match) {
            $match['odds'] = $oddsByEventId[(string) ($match['sofascore_match_id'] ?? '')] ?? null;
        }
        unset($match);
        $matches = $this->withInitialOddsSource($matches, 'sofascore');
        $matches = $this->enrichMatchesWithBestOdds($matches, true, true);

        return $this->buildSyncStats(
            $this->importMatches($matches, $adminUserId),
            count($sourceMatches),
            $matchedTotal,
            count($matches)
        );
    }

    /**
     * @param array{status_scope?: string, league_terms?: array<int, string>} $filters
     * @return array{created: int, updated: int, real_odds: int, source_total: int, matched_total: int, imported_total: int}
     */
    public function syncFlashscore(int $limit, int $adminUserId, array $filters = []): array
    {
        $service = new FlashscoreMobileSoccerService();
        $sourceMatches = $service->fetchDailyMatchesIndex();
        $matches = $this->applySyncFilters($sourceMatches, $filters);
        $matchedTotal = count($matches);
        $matches = array_slice($matches, 0, max(1, $limit));

        if ($matches === []) {
            throw new RuntimeException($this->emptySyncMessage('Flashscore', $filters));
        }

        $matches = $service->hydrateMatchesWithDetails($matches);
        if ($matches === []) {
            throw new RuntimeException('Nenhum jogo valido foi encontrado no Flashscore com os filtros informados.');
        }
        $matches = $this->withInitialOddsSource($matches, 'flashscore');
        $matches = $this->enrichMatchesWithBestOdds($matches, true, true);

        return $this->buildSyncStats(
            $this->importMatches($matches, $adminUserId),
            count($sourceMatches),
            $matchedTotal,
            count($matches)
        );
    }

    /**
     * @param array{status_scope?: string, league_terms?: array<int, string>} $filters
     * @return array{created: int, updated: int, real_odds: int, source_total: int, matched_total: int, imported_total: int}
     */
    public function syncBetfair(int $limit, int $adminUserId, array $filters = []): array
    {
        $service = new BetfairSportsbookService();
        $sourceMatches = $service->fetchFootballMatchesWithOdds();
        $matches = $this->applySyncFilters($sourceMatches, $filters);
        $matchedTotal = count($matches);
        $matches = array_slice($matches, 0, max(1, $limit));

        if ($matches === []) {
            throw new RuntimeException($this->emptySyncMessage('Betfair', $filters));
        }

        $matches = $this->withInitialOddsSource($matches, 'betfair');

        return $this->buildSyncStats(
            $this->importMatches($matches, $adminUserId),
            count($sourceMatches),
            $matchedTotal,
            count($matches)
        );
    }

    /**
     * @param array{status_scope?: string, league_terms?: array<int, string>} $filters
     * @return array{created: int, updated: int, real_odds: int, source_total: int, matched_total: int, imported_total: int}
     */
    public function syncOddApi(int $limit, int $adminUserId, array $filters = []): array
    {
        $service = new TheOddsApiSoccerService($this->db);
        $sourceMatches = $service->fetchDailyMatchesIndex($filters);
        $matches = $this->applySyncFilters($sourceMatches, $filters);
        $matchedTotal = count($matches);
        $matches = array_slice($matches, 0, max(1, $limit));

        if ($matches === []) {
            throw new RuntimeException($this->emptySyncMessage('OddAPI', $filters));
        }

        $matches = $service->hydrateMatchesWithOdds($matches);
        $matches = $this->withInitialOddsSource($matches, 'oddapi');

        return $this->buildSyncStats(
            $this->importMatches($matches, $adminUserId),
            count($sourceMatches),
            $matchedTotal,
            count($matches)
        );
    }

    /**
     * @param array<int, array<string, mixed>> $matches
     * @param array{status_scope?: string, league_terms?: array<int, string>} $filters
     * @return array<int, array<string, mixed>>
     */
    private function applySyncFilters(array $matches, array $filters): array
    {
        $statusScope = $this->normalizeSyncStatusScope((string) ($filters['status_scope'] ?? 'scheduled'));
        $leagueTerms = $this->normalizeSyncLeagueTerms($filters['league_terms'] ?? []);

        return array_values(array_filter($matches, function (array $match) use ($statusScope, $leagueTerms): bool {
            if (!$this->matchStatusAllowed((string) ($match['status'] ?? 'scheduled'), $statusScope)) {
                return false;
            }

            return $this->matchLeagueAllowed($match, $leagueTerms);
        }));
    }

    private function normalizeSyncStatusScope(string $statusScope): string
    {
        return in_array($statusScope, ['scheduled', 'live', 'all'], true) ? $statusScope : 'scheduled';
    }

    /**
     * @param mixed $leagueTerms
     * @return array<int, string>
     */
    private function normalizeSyncLeagueTerms(mixed $leagueTerms): array
    {
        $values = [];

        if (is_string($leagueTerms)) {
            $values = preg_split('/[\r\n,;]+/u', $leagueTerms) ?: [];
        } elseif (is_array($leagueTerms)) {
            foreach ($leagueTerms as $value) {
                if (is_scalar($value)) {
                    $values[] = (string) $value;
                }
            }
        }

        $normalized = [];
        foreach ($values as $value) {
            $key = $this->normalizeKey((string) $value);
            if ($key !== '') {
                $normalized[$key] = true;
            }
        }

        return array_keys($normalized);
    }

    private function matchStatusAllowed(string $status, string $statusScope): bool
    {
        $status = strtolower(trim($status));

        return match ($statusScope) {
            'live' => $status === 'live',
            'all' => in_array($status, ['scheduled', 'live'], true),
            default => $status === 'scheduled',
        };
    }

    /**
     * @param array<string, mixed> $match
     * @param array<int, string> $leagueTerms
     */
    private function matchLeagueAllowed(array $match, array $leagueTerms): bool
    {
        if ($leagueTerms === []) {
            return true;
        }

        $leagueName = $this->normalizeKey((string) ($match['league_name'] ?? ''));
        $countryName = $this->normalizeKey((string) ($match['country_name'] ?? ''));
        $haystacks = array_values(array_unique(array_filter([
            $leagueName,
            trim($countryName . ' ' . $leagueName),
            trim($leagueName . ' ' . $countryName),
        ])));

        foreach ($leagueTerms as $term) {
            foreach ($haystacks as $haystack) {
                if (str_contains($haystack, $term)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param array{created: int, updated: int, real_odds: int} $stats
     * @return array{created: int, updated: int, real_odds: int, source_total: int, matched_total: int, imported_total: int}
     */
    private function buildSyncStats(array $stats, int $sourceTotal, int $matchedTotal, int $importedTotal): array
    {
        $stats['source_total'] = $sourceTotal;
        $stats['matched_total'] = $matchedTotal;
        $stats['imported_total'] = $importedTotal;

        return $stats;
    }

    /**
     * @param array{status_scope?: string, league_terms?: array<int, string>} $filters
     */
    private function emptySyncMessage(string $sourceName, array $filters): string
    {
        $leagueTerms = $this->normalizeSyncLeagueTerms($filters['league_terms'] ?? []);
        $statusScope = $this->normalizeSyncStatusScope((string) ($filters['status_scope'] ?? 'scheduled'));

        if ($leagueTerms !== [] || $statusScope !== 'all') {
            return 'Nenhum jogo encontrado no ' . $sourceName . ' com os filtros informados.';
        }

        return 'Nenhum jogo encontrado no ' . $sourceName . ' para sincronizar.';
    }

    /**
     * @param array<int, array<string, mixed>> $matches
     * @return array{created: int, updated: int, real_odds: int}
     */
    private function importMatches(array $matches, int $adminUserId): array
    {
        $pdo = $this->db->pdo();
        $this->ensureGameSourceColumns();
        $this->ensureGameResultsTable();
        $this->ensureGameVisibilitySchema();
        (new BettingLockService($this->db))->ensureSchema();
        $matches = $this->deduplicateMatches($matches);
        $isVisible = $this->syncImportVisibleFlag();

        $categoryId = $this->footballCategoryId();
        $leagues = $this->loadLeagueMap($categoryId);
        $teams = $this->loadTeamMap();
        $sourceGames = $this->loadSourceGameMap($matches);
        $fixtureGames = $this->loadFixtureGameMap($matches);

        $created = 0;
        $updated = 0;
        $realOdds = 0;

        $pdo->beginTransaction();

        try {
            foreach ($matches as $raw) {
                $match = $this->prepareMatch($raw);
                if ($match === null) {
                    continue;
                }

                $leagueId = $this->resolveLeagueId($pdo, $leagues, $categoryId, $match['league_name'], $match['country_name']);
                $homeTeamId = $this->resolveTeamId($pdo, $teams, $match['home_team']);
                $awayTeamId = $this->resolveTeamId($pdo, $teams, $match['away_team']);
                $gameId = $this->findExistingGameId($match, $sourceGames, $fixtureGames);

                if ($gameId !== null) {
                    $pdo->prepare(
                        'UPDATE games
                         SET league_id = :league_id,
                             sport = "Futebol",
                             home_team_id = :home_team_id,
                             away_team_id = :away_team_id,
                             match_date = :match_date,
                             status = CASE WHEN status = "finished" THEN "finished" ELSE :status END,
                             risk_level = "medium",
                             game_origin = "admin",
                             is_visible = :is_visible
                         WHERE id = :id'
                    )->execute([
                        'league_id' => $leagueId,
                        'home_team_id' => $homeTeamId,
                        'away_team_id' => $awayTeamId,
                        'match_date' => $match['match_date'],
                        'status' => $match['status'],
                        'is_visible' => $isVisible,
                        'id' => $gameId,
                    ]);
                    $updated++;
                } else {
                    try {
                        $pdo->prepare(
                            'INSERT INTO games
                                (league_id, sport, home_team_id, away_team_id, match_date, status, risk_level, is_visible, game_origin, created_by_user_id, created_at)
                             VALUES
                                (:league_id, "Futebol", :home_team_id, :away_team_id, :match_date, :status, "medium", :is_visible, "admin", :created_by_user_id, NOW())'
                        )->execute([
                            'league_id' => $leagueId,
                            'home_team_id' => $homeTeamId,
                            'away_team_id' => $awayTeamId,
                            'match_date' => $match['match_date'],
                            'status' => $match['status'],
                            'is_visible' => $isVisible,
                            'created_by_user_id' => $adminUserId,
                        ]);
                        $gameId = (int) $pdo->lastInsertId();
                        $created++;
                    } catch (Throwable $exception) {
                        if (!$this->isDuplicateException($exception)) {
                            throw $exception;
                        }

                        $existingGameId = $this->findExistingGameId($match, $sourceGames, $fixtureGames);
                        if ($existingGameId === null) {
                            throw $exception;
                        }

                        $gameId = $existingGameId;
                        $pdo->prepare(
                            'UPDATE games
                             SET league_id = :league_id,
                                 sport = "Futebol",
                                 home_team_id = :home_team_id,
                                 away_team_id = :away_team_id,
                                 match_date = :match_date,
                                 status = CASE WHEN status = "finished" THEN "finished" ELSE :status END,
                                 risk_level = "medium",
                                 game_origin = "admin",
                                 is_visible = :is_visible
                             WHERE id = :id'
                        )->execute([
                            'league_id' => $leagueId,
                            'home_team_id' => $homeTeamId,
                            'away_team_id' => $awayTeamId,
                            'match_date' => $match['match_date'],
                            'status' => $match['status'],
                            'is_visible' => $isVisible,
                            'id' => $gameId,
                        ]);
                        $updated++;
                    }
                }

                $this->updateGameSourceIds($gameId, $match);
                $this->registerSourceIds($sourceGames, $gameId, $match);
                $this->registerFixture($fixtureGames, $gameId, $match['match_date'], $match['home_team'], $match['away_team']);
                $this->applyDefaultSyncBettingWindow($gameId);

                if ($this->applyOdds($gameId, $match['odds'])) {
                    $realOdds++;
                }
            }

            if ($created === 0 && $updated === 0) {
                throw new RuntimeException('Nenhum jogo valido foi encontrado para importar.');
            }

            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }

        return ['created' => $created, 'updated' => $updated, 'real_odds' => $realOdds];
    }

    /**
     * @param array<int, array<string, mixed>> $matches
     * @return array<int, array<string, mixed>>
     */
    private function withInitialOddsSource(array $matches, string $source): array
    {
        foreach ($matches as $index => $match) {
            $odds = $this->normalizeOdds($match['odds'] ?? null);
            $matches[$index]['odds'] = $odds;
            $matches[$index]['odds_source'] = $odds !== null ? $source : '';
        }

        return $matches;
    }

    /**
     * @param array<int, array<string, mixed>> $matches
     * @return array<int, array<string, mixed>>
     */
    private function enrichXscoresMatchesWithReliableOdds(array $matches): array
    {
        foreach ($matches as $index => $match) {
            $matches[$index]['odds'] = $this->normalizeOdds($match['odds'] ?? null);
            $matches[$index]['odds_source'] = ($matches[$index]['odds'] ?? null) !== null
                ? (string) ($match['odds_source'] ?? '')
                : '';
        }

        $pinnacleCandidates = [];
        try {
            $pinnacleCandidates = $this->withInitialOddsSource(
                (new PinnacleGuestSoccerService())->fetchOddsCandidatesForMatches($matches),
                'pinnacle'
            );
        } catch (Throwable) {
            $pinnacleCandidates = [];
        }

        foreach ($matches as $index => $match) {
            $pinnacleCandidate = $this->findCandidate($match, $pinnacleCandidates);
            if ($pinnacleCandidate === null) {
                continue;
            }

            $this->applyOddsCandidate(
                $matches[$index],
                $pinnacleCandidate['odds'] ?? null,
                'pinnacle',
                false,
                true
            );
        }

        $timezone = new DateTimeZone('America/Sao_Paulo');
        $sofascoreService = null;
        $sofascoreCandidates = [];

        try {
            $sofascoreService = new SofascoreSoccerService();
            $sofascoreCandidates = $this->withInitialOddsSource(
                $sofascoreService->fetchDailyMatchesIndex(new DateTimeImmutable('today', $timezone)),
                'sofascore'
            );
        } catch (Throwable) {
            $sofascoreService = null;
            $sofascoreCandidates = [];
        }

        $flashscoreCandidates = [];
        try {
            $flashscoreCandidates = $this->withInitialOddsSource(
                (new FlashscoreMobileSoccerService())->fetchDailyMatchesIndex(),
                'flashscore'
            );
        } catch (Throwable) {
            $flashscoreCandidates = [];
        }

        $wantedSofascoreIds = [];

        foreach ($matches as $index => $match) {
            if ($this->normalizeOdds($matches[$index]['odds'] ?? null) !== null) {
                continue;
            }

            $sofascoreCandidate = $this->findCandidate($match, $sofascoreCandidates);
            if ($sofascoreCandidate === null) {
                continue;
            }

            $eventId = trim((string) ($sofascoreCandidate['sofascore_match_id'] ?? ''));
            if ($eventId === '') {
                continue;
            }

            $matches[$index]['sofascore_match_id'] = $eventId;
            $wantedSofascoreIds[$index] = $eventId;
        }

        if ($wantedSofascoreIds !== [] && $sofascoreService instanceof SofascoreSoccerService) {
            try {
                $oddsByEvent = $sofascoreService->fetchOddsByEventIds(array_values(array_unique($wantedSofascoreIds)));
            } catch (Throwable) {
                $oddsByEvent = [];
            }

            foreach ($wantedSofascoreIds as $index => $eventId) {
                $this->applyOddsCandidate(
                    $matches[$index],
                    $oddsByEvent[$eventId] ?? null,
                    'sofascore',
                    false,
                    true
                );
            }
        }

        foreach ($matches as $index => $match) {
            $flashscoreCandidate = $this->findCandidate($match, $flashscoreCandidates);
            if ($flashscoreCandidate === null) {
                continue;
            }

            $flashscoreMatchId = trim((string) ($flashscoreCandidate['flashscore_match_id'] ?? ''));
            if ($flashscoreMatchId !== '') {
                $matches[$index]['flashscore_match_id'] = $flashscoreMatchId;
            }

            if ($this->normalizeOdds($matches[$index]['odds'] ?? null) !== null) {
                continue;
            }

            $this->applyOddsCandidate(
                $matches[$index],
                $flashscoreCandidate['odds'] ?? null,
                'flashscore',
                false,
                true
            );
        }

        return $matches;
    }

    /**
     * @param array<int, array<string, mixed>> $matches
     * @return array<int, array<string, mixed>>
     */
    private function enrichMatchesWithBestOdds(array $matches, bool $preferFlashscore = true, bool $replaceWeakExistingOdds = true): array
    {
        foreach ($matches as $index => $match) {
            $matches[$index]['odds'] = $this->normalizeOdds($match['odds'] ?? null);
            $matches[$index]['odds_source'] = ($matches[$index]['odds'] ?? null) !== null
                ? (string) ($match['odds_source'] ?? '')
                : '';
        }

        $flashscoreCandidates = [];
        try {
            $flashscoreCandidates = $this->withInitialOddsSource(
                (new FlashscoreMobileSoccerService())->fetchDailyMatchesIndex(),
                'flashscore'
            );
        } catch (Throwable) {
            $flashscoreCandidates = [];
        }

        $timezone = new DateTimeZone('America/Sao_Paulo');
        $sofascoreService = null;
        $sofascoreCandidates = [];

        try {
            $sofascoreService = new SofascoreSoccerService();
            $sofascoreCandidates = $this->withInitialOddsSource(
                $sofascoreService->fetchDailyMatchesIndex(new DateTimeImmutable('today', $timezone)),
                'sofascore'
            );
        } catch (Throwable) {
            $sofascoreService = null;
            $sofascoreCandidates = [];
        }

        $wantedSofascoreIds = [];

        foreach ($matches as $index => $match) {
            $flashscoreCandidate = $this->findCandidate($match, $flashscoreCandidates);
            if ($flashscoreCandidate !== null) {
                $flashscoreMatchId = trim((string) ($flashscoreCandidate['flashscore_match_id'] ?? ''));
                if ($flashscoreMatchId !== '') {
                    $matches[$index]['flashscore_match_id'] = $flashscoreMatchId;
                }

                $this->applyOddsCandidate(
                    $matches[$index],
                    $flashscoreCandidate['odds'] ?? null,
                    'flashscore',
                    $preferFlashscore,
                    $replaceWeakExistingOdds
                );
            }

            $sofascoreCandidate = $this->findCandidate($match, $sofascoreCandidates);
            if ($sofascoreCandidate === null) {
                continue;
            }

            $eventId = trim((string) ($sofascoreCandidate['sofascore_match_id'] ?? ''));
            if ($eventId === '') {
                continue;
            }

            $matches[$index]['sofascore_match_id'] = $eventId;
            $wantedSofascoreIds[$index] = $eventId;
        }

        if ($wantedSofascoreIds === [] || !$sofascoreService instanceof SofascoreSoccerService) {
            return $matches;
        }

        try {
            $oddsByEvent = $sofascoreService->fetchOddsByEventIds(array_values(array_unique($wantedSofascoreIds)));
        } catch (Throwable) {
            return $matches;
        }

        foreach ($wantedSofascoreIds as $index => $eventId) {
            $this->applyOddsCandidate(
                $matches[$index],
                $oddsByEvent[$eventId] ?? null,
                'sofascore',
                $preferFlashscore,
                $replaceWeakExistingOdds
            );
        }

        return $matches;
    }

    /**
     * @param array<string, mixed> $match
     * @param array{home: float, draw: float, away: float}|array<string, mixed>|null $candidateOdds
     */
    private function applyOddsCandidate(
        array &$match,
        mixed $candidateOdds,
        string $candidateSource,
        bool $preferFlashscore,
        bool $replaceWeakExistingOdds
    ): void {
        $candidate = $this->normalizeOdds($candidateOdds);
        if ($candidate === null) {
            return;
        }

        $current = $this->normalizeOdds($match['odds'] ?? null);
        $currentSource = trim((string) ($match['odds_source'] ?? ''));

        if ($current === null) {
            $match['odds'] = $candidate;
            $match['odds_source'] = $candidateSource;
            return;
        }

        $currentOverround = $this->oddsOverround($current);
        $candidateOverround = $this->oddsOverround($candidate);

        $shouldReplace = false;

        if ($replaceWeakExistingOdds && $currentOverround < 1.02 && $candidateOverround > ($currentOverround + 0.01)) {
            $shouldReplace = true;
        }

        if (
            !$shouldReplace
            && $preferFlashscore
            && $candidateSource === 'flashscore'
            && $currentSource !== 'flashscore'
            && $candidateOverround > ($currentOverround + 0.02)
        ) {
            $shouldReplace = true;
        }

        if ($shouldReplace) {
            $match['odds'] = $candidate;
            $match['odds_source'] = $candidateSource;
        }
    }

    /**
     * @param array<string, mixed> $target
     * @param array<int, array<string, mixed>> $candidates
     * @return array<string, mixed>|null
     */
    private function findCandidate(array $target, array $candidates): ?array
    {
        $targetDate = $this->normalizeMatchDate((string) ($target['match_date'] ?? ''));
        $targetTs = strtotime($targetDate);
        if ($targetDate === '' || $targetTs === false) {
            return null;
        }

        $best = null;
        $bestScore = 0.0;
        $bestDiff = PHP_INT_MAX;

        foreach ($candidates as $candidate) {
            $candidateDate = $this->normalizeMatchDate((string) ($candidate['match_date'] ?? ''));
            $candidateTs = strtotime($candidateDate);
            if ($candidateDate === '' || $candidateTs === false) {
                continue;
            }

            $diff = abs($candidateTs - $targetTs);
            if ($diff > 5400) {
                continue;
            }

            $homeScore = $this->teamMatchScore(
                (string) ($target['home_team'] ?? ''),
                (string) ($candidate['home_team'] ?? '')
            );
            $awayScore = $this->teamMatchScore(
                (string) ($target['away_team'] ?? ''),
                (string) ($candidate['away_team'] ?? '')
            );

            if ($homeScore < 0.72 || $awayScore < 0.72) {
                continue;
            }

            $leagueScore = $this->teamMatchScore(
                (string) ($target['league_name'] ?? ''),
                (string) ($candidate['league_name'] ?? '')
            );
            $score = $homeScore + $awayScore + ($leagueScore * 0.15);

            if ($score > $bestScore || (abs($score - $bestScore) < 0.0001 && $diff < $bestDiff)) {
                $best = $candidate;
                $bestScore = $score;
                $bestDiff = $diff;
            }
        }

        return $best;
    }

    private function footballCategoryId(): int
    {
        $pdo = $this->db->pdo();
        $categoryId = $pdo->query('SELECT id FROM categories WHERE slug = ' . $pdo->quote('futebol') . ' LIMIT 1')->fetchColumn();

        if ($categoryId === false || (int) $categoryId <= 0) {
            throw new RuntimeException('Categoria "futebol" nao encontrada no banco.');
        }

        return (int) $categoryId;
    }

    private function ensureGameSourceColumns(): void
    {
        foreach ($this->sourceColumns() as $column) {
            $this->ensureGameSourceColumn($column);
        }
    }

    private function ensureGameSourceColumn(string $column): void
    {
        $pdo = $this->db->pdo();
        $exists = $pdo->query('SHOW COLUMNS FROM games LIKE ' . $pdo->quote($column))->fetch();
        if ($exists === false) {
            $pdo->exec('ALTER TABLE games ADD COLUMN ' . $column . ' VARCHAR(64) NULL AFTER created_at');
        }
    }

    private function ensureGameVisibilitySchema(): void
    {
        $pdo = $this->db->pdo();
        $exists = $pdo->query('SHOW COLUMNS FROM games LIKE "is_visible"')->fetch();
        if ($exists === false) {
            $pdo->exec('ALTER TABLE games ADD COLUMN is_visible TINYINT(1) NOT NULL DEFAULT 1 AFTER risk_level');
            $pdo->exec('UPDATE games SET is_visible = 1 WHERE is_visible IS NULL');
        }
    }

    private function syncImportVisibleFlag(): int
    {
        $stmt = $this->db->pdo()->prepare('SELECT setting_value FROM settings WHERE setting_key = :key LIMIT 1');
        $stmt->execute(['key' => 'sync_import_visibility']);
        $value = trim((string) $stmt->fetchColumn());

        return $value === 'hidden' ? 0 : 1;
    }

    /**
     * @return array<int, string>
     */
    private function sourceColumns(): array
    {
        return [
            'xscores_match_id',
            'sofascore_match_id',
            'flashscore_match_id',
            'betfair_match_id',
            'oddapi_match_id',
        ];
    }

    /**
     * @return array<string, int>
     */
    private function loadLeagueMap(int $categoryId): array
    {
        $map = [];
        $stmt = $this->db->pdo()->prepare('SELECT id, name, country_code FROM leagues WHERE category_id = :category_id');
        $stmt->execute(['category_id' => $categoryId]);

        foreach ($stmt->fetchAll() as $row) {
            $key = $this->leagueKey((string) ($row['name'] ?? ''), (string) ($row['country_code'] ?? 'int'));
            if ($key !== '') {
                $map[$key] = (int) $row['id'];
            }
        }

        return $map;
    }

    /**
     * @return array<string, int>
     */
    private function loadTeamMap(): array
    {
        $map = [];
        $rows = $this->db->pdo()->query('SELECT id, name FROM teams')->fetchAll();

        foreach ($rows as $row) {
            $key = $this->normalizeKey((string) ($row['name'] ?? ''));
            if ($key !== '' && !isset($map[$key])) {
                $map[$key] = (int) $row['id'];
            }
        }

        return $map;
    }

    /**
     * @param array<int, array<string, mixed>> $matches
     * @return array<string, array<string, int>>
     */
    private function loadSourceGameMap(array $matches): array
    {
        $pdo = $this->db->pdo();
        $map = [];

        foreach ($this->sourceColumns() as $column) {
            $map[$column] = [];
            $ids = [];

            foreach ($matches as $match) {
                $value = trim((string) ($match[$column] ?? ''));
                if ($value !== '') {
                    $ids[$value] = true;
                }
            }

            if ($ids === []) {
                continue;
            }

            $values = array_keys($ids);
            $placeholders = implode(',', array_fill(0, count($values), '?'));
            $stmt = $pdo->prepare('SELECT id, ' . $column . ' FROM games WHERE ' . $column . ' IN (' . $placeholders . ')');
            $stmt->execute($values);

            foreach ($stmt->fetchAll() as $row) {
                $sourceId = trim((string) ($row[$column] ?? ''));
                if ($sourceId !== '') {
                    $map[$column][$sourceId] = (int) $row['id'];
                }
            }
        }

        return $map;
    }

    /**
     * @param array<int, array<string, mixed>> $matches
     * @return array<string, array<int, array{id: int, timestamp: int, home_key: string, away_key: string}>>
     */
    private function loadFixtureGameMap(array $matches): array
    {
        $days = [];
        foreach ($matches as $match) {
            $matchDate = $this->normalizeMatchDate((string) ($match['match_date'] ?? ''));
            if ($matchDate !== '') {
                $days[substr($matchDate, 0, 10)] = true;
            }
        }

        if ($days === []) {
            return [];
        }

        $values = array_keys($days);
        $placeholders = implode(',', array_fill(0, count($values), '?'));
        $stmt = $this->db->pdo()->prepare(
            'SELECT g.id, g.match_date, ht.name AS home_name, at.name AS away_name
             FROM games g
             INNER JOIN teams ht ON ht.id = g.home_team_id
             INNER JOIN teams at ON at.id = g.away_team_id
             WHERE g.sport = "Futebol" AND DATE(g.match_date) IN (' . $placeholders . ')'
        );
        $stmt->execute($values);

        $map = [];
        foreach ($stmt->fetchAll() as $row) {
            $this->registerFixture(
                $map,
                (int) ($row['id'] ?? 0),
                (string) ($row['match_date'] ?? ''),
                (string) ($row['home_name'] ?? ''),
                (string) ($row['away_name'] ?? '')
            );
        }

        return $map;
    }

    /**
     * @param array<string, int> $leagues
     */
    private function resolveLeagueId(PDO $pdo, array &$leagues, int $categoryId, string $leagueName, string $countryName): int
    {
        $leagueName = $this->cleanText($leagueName);
        if ($leagueName === '') {
            $leagueName = 'Futebol';
        }

        $countryCode = $this->countryCode($countryName);
        $key = $this->leagueKey($leagueName, $countryCode);

        if (!isset($leagues[$key])) {
            try {
                $pdo->prepare('INSERT INTO leagues (category_id, name, country_code) VALUES (:category_id, :name, :country_code)')->execute([
                    'category_id' => $categoryId,
                    'name' => $leagueName,
                    'country_code' => $countryCode,
                ]);
                $leagues[$key] = (int) $pdo->lastInsertId();
            } catch (Throwable $exception) {
                if (!$this->isDuplicateException($exception)) {
                    throw $exception;
                }

                $existing = $pdo->prepare('SELECT id FROM leagues WHERE category_id = :category_id AND name = :name AND country_code = :country_code ORDER BY id ASC LIMIT 1');
                $existing->execute([
                    'category_id' => $categoryId,
                    'name' => $leagueName,
                    'country_code' => $countryCode,
                ]);
                $row = $existing->fetch();
                if (!$row) {
                    throw $exception;
                }
                $leagues[$key] = (int) $row['id'];
            }
        }

        return $leagues[$key];
    }

    /**
     * @param array<string, int> $teams
     */
    private function resolveTeamId(PDO $pdo, array &$teams, string $teamName): int
    {
        $teamName = $this->cleanText($teamName);
        $key = $this->normalizeKey($teamName);

        if ($key === '') {
            throw new RuntimeException('Nome de time invalido durante a sincronizacao.');
        }

        if (!isset($teams[$key])) {
            try {
                $pdo->prepare('INSERT INTO teams (name, logo) VALUES (:name, NULL)')->execute(['name' => $teamName]);
                $teams[$key] = (int) $pdo->lastInsertId();
            } catch (Throwable $exception) {
                if (!$this->isDuplicateException($exception)) {
                    throw $exception;
                }

                $existing = $pdo->prepare('SELECT id FROM teams WHERE name = :name ORDER BY id ASC LIMIT 1');
                $existing->execute(['name' => $teamName]);
                $row = $existing->fetch();
                if (!$row) {
                    throw $exception;
                }
                $teams[$key] = (int) $row['id'];
            }
        }

        return $teams[$key];
    }

    /**
     * @param array<string, mixed> $match
     * @param array<string, array<string, int>> $sourceGames
     * @param array<string, array<int, array{id: int, timestamp: int, home_key: string, away_key: string}>> $fixtureGames
     */
    private function findExistingGameId(array $match, array $sourceGames, array $fixtureGames): ?int
    {
        foreach ($this->sourceColumns() as $column) {
            $sourceId = trim((string) ($match[$column] ?? ''));
            if ($sourceId !== '' && isset($sourceGames[$column][$sourceId])) {
                return (int) $sourceGames[$column][$sourceId];
            }
        }

        $timestamp = strtotime($match['match_date']);
        $day = substr($match['match_date'], 0, 10);
        if ($timestamp === false || !isset($fixtureGames[$day])) {
            return null;
        }

        $homeKey = $this->normalizeKey($match['home_team']);
        $awayKey = $this->normalizeKey($match['away_team']);
        $bestId = null;
        $bestDiff = PHP_INT_MAX;

        foreach ($fixtureGames[$day] as $candidate) {
            if ($candidate['home_key'] !== $homeKey || $candidate['away_key'] !== $awayKey) {
                continue;
            }

            $diff = abs($candidate['timestamp'] - $timestamp);
            if ($diff <= 5400 && $diff < $bestDiff) {
                $bestId = $candidate['id'];
                $bestDiff = $diff;
            }
        }

        return $bestId;
    }

    /**
     * @param array<string, array<int, array{id: int, timestamp: int, home_key: string, away_key: string}>> $fixtureGames
     */
    private function registerFixture(array &$fixtureGames, int $gameId, string $matchDate, string $homeTeam, string $awayTeam): void
    {
        $matchDate = $this->normalizeMatchDate($matchDate);
        $timestamp = strtotime($matchDate);

        if ($gameId <= 0 || $matchDate === '' || $timestamp === false) {
            return;
        }

        $day = substr($matchDate, 0, 10);
        $fixtureGames[$day][] = [
            'id' => $gameId,
            'timestamp' => $timestamp,
            'home_key' => $this->normalizeKey($homeTeam),
            'away_key' => $this->normalizeKey($awayTeam),
        ];
    }

    /**
     * @param array<string, array<string, int>> $sourceGames
     * @param array<string, mixed> $match
     */
    private function registerSourceIds(array &$sourceGames, int $gameId, array $match): void
    {
        foreach ($this->sourceColumns() as $column) {
            $sourceId = trim((string) ($match[$column] ?? ''));
            if ($sourceId !== '') {
                $sourceGames[$column][$sourceId] = $gameId;
            }
        }
    }

    /**
     * @param array<string, mixed> $match
     */
    private function updateGameSourceIds(int $gameId, array $match): void
    {
        $pdo = $this->db->pdo();

        foreach ($this->sourceColumns() as $column) {
            $sourceId = trim((string) ($match[$column] ?? ''));
            if ($sourceId !== '') {
                $pdo->prepare('UPDATE games SET ' . $column . ' = :value WHERE id = :id')->execute([
                    'value' => $sourceId,
                    'id' => $gameId,
                ]);
            }
        }
    }

    private function applyDefaultSyncBettingWindow(int $gameId): void
    {
        if ($gameId <= 0) {
            return;
        }

        $this->db->pdo()->prepare(
            'UPDATE games
             SET betting_only_before_start = CASE
                 WHEN betting_lock_after_minutes IS NOT NULL AND betting_lock_after_minutes > 0 THEN betting_only_before_start
                 ELSE 1
             END
             WHERE id = :id AND game_origin = "admin"'
        )->execute([
            'id' => $gameId,
        ]);
    }

    private function applyOdds(int $gameId, ?array $odds): bool
    {
        $normalized = $this->normalizeOdds($odds);
        if ($normalized !== null) {
            $this->upsertOdd($gameId, 'Resultado final', 'Casa', $normalized['home']);
            $this->upsertOdd($gameId, 'Resultado final', 'Empate', $normalized['draw']);
            $this->upsertOdd($gameId, 'Resultado final', 'Fora', $normalized['away']);
            return true;
        }

        $this->db->pdo()->prepare(
            'DELETE FROM odds
             WHERE game_id = :game_id
               AND market_name = :market_name
               AND option_name IN ("Casa", "Empate", "Fora")'
        )->execute([
            'game_id' => $gameId,
            'market_name' => 'Resultado final',
        ]);

        return false;
    }

    /**
     * @param array<string, mixed> $match
     * @return array{
     *   league_name: string,
     *   country_name: string,
     *   home_team: string,
     *   away_team: string,
     *   match_date: string,
     *   status: string,
     *   odds: array<string, mixed>|null,
     *   xscores_match_id: string,
     *   sofascore_match_id: string,
     *   flashscore_match_id: string,
     *   betfair_match_id: string,
     *   oddapi_match_id: string
     * }|null
     */
    private function prepareMatch(array $match): ?array
    {
        $homeTeam = $this->cleanText((string) ($match['home_team'] ?? ''));
        $awayTeam = $this->cleanText((string) ($match['away_team'] ?? ''));
        $matchDate = $this->normalizeMatchDate((string) ($match['match_date'] ?? ''));

        if ($homeTeam === '' || $awayTeam === '' || $matchDate === '') {
            return null;
        }

        $prepared = [
            'league_name' => $this->cleanText((string) ($match['league_name'] ?? '')) ?: 'Futebol',
            'country_name' => $this->cleanText((string) ($match['country_name'] ?? '')),
            'home_team' => $homeTeam,
            'away_team' => $awayTeam,
            'match_date' => $matchDate,
            'status' => (($match['status'] ?? 'scheduled') === 'live') ? 'live' : 'scheduled',
            'odds' => $this->normalizeOdds($match['odds'] ?? null),
            'xscores_match_id' => trim((string) ($match['xscores_match_id'] ?? '')),
            'sofascore_match_id' => trim((string) ($match['sofascore_match_id'] ?? '')),
            'flashscore_match_id' => trim((string) ($match['flashscore_match_id'] ?? '')),
            'betfair_match_id' => trim((string) ($match['betfair_match_id'] ?? '')),
            'oddapi_match_id' => trim((string) ($match['oddapi_match_id'] ?? '')),
        ];

        if (
            $prepared['xscores_match_id'] === ''
            && $prepared['sofascore_match_id'] === ''
            && $prepared['flashscore_match_id'] === ''
            && $prepared['betfair_match_id'] === ''
            && $prepared['oddapi_match_id'] === ''
        ) {
            return null;
        }

        return $prepared;
    }

    private function upsertOdd(int $gameId, string $marketName, string $optionName, float $oddValue): void
    {
        $pdo = $this->db->pdo();
        $find = $pdo->prepare('SELECT id FROM odds WHERE game_id = :game_id AND market_name = :market_name AND option_name = :option_name LIMIT 1');
        $find->execute([
            'game_id' => $gameId,
            'market_name' => $marketName,
            'option_name' => $optionName,
        ]);
        $row = $find->fetch();

        if ($row) {
            $pdo->prepare('UPDATE odds SET odd_value = :odd_value WHERE id = :id')->execute([
                'odd_value' => $oddValue,
                'id' => (int) $row['id'],
            ]);
            return;
        }

        $pdo->prepare('INSERT INTO odds (game_id, market_name, option_name, odd_value) VALUES (:game_id, :market_name, :option_name, :odd_value)')->execute([
            'game_id' => $gameId,
            'market_name' => $marketName,
            'option_name' => $optionName,
            'odd_value' => $oddValue,
        ]);
    }

    private function leagueKey(string $leagueName, string $countryCode): string
    {
        $leagueName = $this->normalizeKey($leagueName);
        return $leagueName === '' ? '' : strtolower(trim($countryCode)) . '|' . $leagueName;
    }

    private function countryCode(string $countryName): string
    {
        $country = $this->normalizeKey($countryName);

        $map = [
            'argentina' => 'ar',
            'belarus' => 'by',
            'belgium' => 'be',
            'brazil' => 'br',
            'cameroon' => 'cm',
            'chile' => 'cl',
            'colombia' => 'co',
            'croatia' => 'hr',
            'cyprus' => 'cy',
            'czech republic' => 'cz',
            'denmark' => 'dk',
            'dr congo' => 'cd',
            'ecuador' => 'ec',
            'egypt' => 'eg',
            'england' => 'gb',
            'europe' => 'int',
            'france' => 'fr',
            'germany' => 'de',
            'greece' => 'gr',
            'hungary' => 'hu',
            'international' => 'int',
            'ireland' => 'ie',
            'italy' => 'it',
            'jamaica' => 'jm',
            'mexico' => 'mx',
            'morocco' => 'ma',
            'netherlands' => 'nl',
            'north central america' => 'int',
            'northern ireland' => 'gb',
            'norway' => 'no',
            'paraguay' => 'py',
            'peru' => 'pe',
            'poland' => 'pl',
            'portugal' => 'pt',
            'romania' => 'ro',
            'scotland' => 'gb',
            'serbia' => 'rs',
            'slovakia' => 'sk',
            'south america' => 'int',
            'spain' => 'es',
            'sweden' => 'se',
            'switzerland' => 'ch',
            'turkey' => 'tr',
            'ukraine' => 'ua',
            'united states' => 'us',
            'uruguay' => 'uy',
            'usa' => 'us',
            'wales' => 'gb',
            'world' => 'int',
        ];

        return $map[$country] ?? 'int';
    }

    private function cleanText(string $value): string
    {
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
        return trim($value);
    }

    private function normalizeKey(string $value): string
    {
        $value = mb_strtolower($this->cleanText($value));
        $value = preg_replace('/\([^)]*\)/u', ' ', $value) ?? $value;

        $ascii = function_exists('iconv') ? @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) : false;
        if (is_string($ascii) && $ascii !== '') {
            $value = $ascii;
        } else {
            $value = strtr($value, [
                'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a',
                'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
                'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
                'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o',
                'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
                'ç' => 'c', 'ñ' => 'n',
            ]);
        }

        $value = str_replace('&', ' and ', $value);
        $value = preg_replace('/\bsub\s*(\d{2})\b/u', 'u$1', $value) ?? $value;
        $value = preg_replace('/\bunder\s*(\d{2})\b/u', 'u$1', $value) ?? $value;
        $value = preg_replace('/\bfeminina\b|\bfeminino\b|\bwomen\b/u', ' f ', $value) ?? $value;
        $value = preg_replace('/[^a-z0-9]+/u', ' ', $value) ?? $value;
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? $value);

        $aliases = [
            'africa' => 'africa',
            'alemanha' => 'germany',
            'america do norte e central' => 'north central america',
            'america do sul' => 'south america',
            'belgica' => 'belgium',
            'bielorrussia' => 'belarus',
            'brasil' => 'brazil',
            'camaroes' => 'cameroon',
            'chipre' => 'cyprus',
            'colombia' => 'colombia',
            'croacia' => 'croatia',
            'dinamarca' => 'denmark',
            'emirados arabes unidos' => 'united arab emirates',
            'equador' => 'ecuador',
            'escocia' => 'scotland',
            'eslovaquia' => 'slovakia',
            'espanha' => 'spain',
            'estados unidos' => 'united states',
            'eua' => 'usa',
            'europa' => 'europe',
            'franca' => 'france',
            'grecia' => 'greece',
            'holanda' => 'netherlands',
            'hungria' => 'hungary',
            'inglaterra' => 'england',
            'internacional' => 'international',
            'irlanda do norte' => 'northern ireland',
            'islandia' => 'iceland',
            'italia' => 'italy',
            'marrocos' => 'morocco',
            'mundo' => 'world',
            'noruega' => 'norway',
            'pais de gales' => 'wales',
            'paraguai' => 'paraguay',
            'polonia' => 'poland',
            'r d congo' => 'dr congo',
            'czechia' => 'czech republic',
            'republica checa' => 'czech republic',
            'republica tcheca' => 'czech republic',
            'romenia' => 'romania',
            'servia' => 'serbia',
            'suica' => 'switzerland',
            'suecia' => 'sweden',
            'turkiye' => 'turkey',
            'turquia' => 'turkey',
            'ucrania' => 'ukraine',
            'uruguai' => 'uruguay',
        ];

        return $aliases[$value] ?? $value;
    }

    /**
     * @param mixed $odds
     * @return array{home: float, draw: float, away: float}|null
     */
    private function normalizeOdds(mixed $odds): ?array
    {
        if (!is_array($odds)) {
            return null;
        }

        $normalized = [];
        foreach (['home', 'draw', 'away'] as $key) {
            if (!isset($odds[$key]) || !is_numeric((string) $odds[$key])) {
                return null;
            }

            $value = round((float) $odds[$key], 2);
            if ($value <= 1) {
                return null;
            }

            $normalized[$key] = $value;
        }

        $overround = $this->oddsOverround($normalized);
        if (!is_finite($overround) || $overround < 1.01 || $overround > 1.40) {
            return null;
        }

        return [
            'home' => $normalized['home'],
            'draw' => $normalized['draw'],
            'away' => $normalized['away'],
        ];
    }

    /**
     * @param array{home: float, draw: float, away: float} $odds
     */
    private function oddsOverround(array $odds): float
    {
        return (1 / $odds['home']) + (1 / $odds['draw']) + (1 / $odds['away']);
    }

    private function teamMatchScore(string $left, string $right): float
    {
        $leftKey = $this->normalizeKey($left);
        $rightKey = $this->normalizeKey($right);

        if ($leftKey === '' || $rightKey === '') {
            return 0.0;
        }

        if ($leftKey === $rightKey) {
            return 1.0;
        }

        $leftTokens = $this->significantNameTokens($leftKey);
        $rightTokens = $this->significantNameTokens($rightKey);
        if ($leftTokens === [] || $rightTokens === []) {
            return 0.0;
        }

        $intersection = array_values(array_intersect($leftTokens, $rightTokens));
        if ($intersection === []) {
            return 0.0;
        }

        $smallerCount = min(count($leftTokens), count($rightTokens));
        $largerCount = max(count($leftTokens), count($rightTokens));
        $coverage = count($intersection) / max(1, $smallerCount);
        $precision = count($intersection) / max(1, $largerCount);
        $score = ($coverage * 0.7) + ($precision * 0.3);

        if (str_contains($leftKey, $rightKey) || str_contains($rightKey, $leftKey)) {
            $score = min(1.0, $score + 0.1);
        }

        return $score;
    }

    /**
     * @return array<int, string>
     */
    private function significantNameTokens(string $value): array
    {
        $tokens = preg_split('/\s+/u', trim($value)) ?: [];
        $stopwords = [
            'a', 'ac', 'afc', 'as', 'at', 'ca', 'cd', 'cf', 'club', 'clube', 'da', 'de', 'del', 'do',
            'el', 'fc', 'if', 'la', 'sa', 'sc', 'sv', 'the',
        ];

        $filtered = [];
        foreach ($tokens as $token) {
            $token = trim($token);
            if ($token === '' || in_array($token, $stopwords, true)) {
                continue;
            }

            $filtered[$token] = true;
        }

        return array_keys($filtered);
    }

    private function normalizeMatchDate(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        $timezone = new DateTimeZone('America/Sao_Paulo');

        foreach (['Y-m-d H:i:s', 'Y-m-d H:i', 'd.m.Y H:i', 'd/m/Y H:i'] as $format) {
            $date = DateTimeImmutable::createFromFormat($format, $value, $timezone);
            if ($date instanceof DateTimeImmutable) {
                return $date->format('Y-m-d H:i:s');
            }
        }

        try {
            return (new DateTimeImmutable($value, $timezone))->setTimezone($timezone)->format('Y-m-d H:i:s');
        } catch (Throwable) {
            return '';
        }
    }

    /**
     * @param array<int, array<string, mixed>> $matches
     * @return array<int, array<string, mixed>>
     */
    private function deduplicateMatches(array $matches): array
    {
        $result = [];
        $seenSource = [];
        $seenFixture = [];

        foreach ($matches as $match) {
            if (!is_array($match)) {
                continue;
            }

            $sourceKeyParts = [];
            foreach ($this->sourceColumns() as $column) {
                $value = trim((string) ($match[$column] ?? ''));
                if ($value !== '') {
                    $sourceKeyParts[] = $column . ':' . $value;
                }
            }

            if ($sourceKeyParts !== []) {
                $sourceKey = implode('|', $sourceKeyParts);
                if (isset($seenSource[$sourceKey])) {
                    continue;
                }
                $seenSource[$sourceKey] = true;
            }

            $fixtureKey = $this->normalizeKey((string) ($match['home_team'] ?? ''))
                . '|'
                . $this->normalizeKey((string) ($match['away_team'] ?? ''))
                . '|'
                . $this->normalizeMatchDate((string) ($match['match_date'] ?? ''));

            if ($fixtureKey !== '||' && isset($seenFixture[$fixtureKey])) {
                continue;
            }
            $seenFixture[$fixtureKey] = true;
            $result[] = $match;
        }

        return $result;
    }

    private function isDuplicateException(Throwable $exception): bool
    {
        if ($exception instanceof PDOException) {
            $sqlState = (string) $exception->getCode();
            $message = strtolower((string) $exception->getMessage());
            if ($sqlState === '23000' || str_contains($message, 'duplicate') || str_contains($message, 'duplicado')) {
                return true;
            }
        }

        $message = strtolower((string) $exception->getMessage());
        return str_contains($message, 'duplicate') || str_contains($message, 'duplicado');
    }

    private function ensureGameResultsTable(): void
    {
        $this->db->pdo()->exec('CREATE TABLE IF NOT EXISTS game_results (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            game_id INT UNSIGNED NOT NULL,
            market_name VARCHAR(120) NOT NULL,
            result_option VARCHAR(120) NOT NULL,
            settled_at DATETIME NOT NULL,
            UNIQUE KEY uq_game_market_result (game_id, market_name),
            CONSTRAINT fk_game_results_game FOREIGN KEY (game_id) REFERENCES games(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }
}
