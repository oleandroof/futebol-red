<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use RuntimeException;
use Throwable;

final class AdminCleanupService
{
    /** @var array<string, bool> */
    private array $tableExistsCache = [];

    public function __construct(private readonly Database $db)
    {
    }

    /**
     * @return array{
     *   total_games: int,
     *   deletable_games: int,
     *   linked_games: int,
     *   locked_games: int,
     *   orphan_leagues: int,
     *   orphan_teams: int,
     *   games_without_odds: int,
     *   total_tickets: int,
     *   open_tickets: int,
     *   settled_tickets: int,
     *   gateway_logs: int
     * }
     */
    public function summary(): array
    {
        $this->ensureCleanupSchema();
        $pdo = $this->db->pdo();

        $gatewayLogs = 0;
        if ($this->tableExists('pix_transactions')) {
            $gatewayLogs += (int) $pdo->query('SELECT COUNT(*) FROM pix_transactions WHERE request_payload IS NOT NULL OR response_payload IS NOT NULL OR qr_code IS NOT NULL')->fetchColumn();
        }

        if ($this->tableExists('agent_payment_requests')) {
            $gatewayLogs += (int) $pdo->query('SELECT COUNT(*) FROM agent_payment_requests WHERE request_payload IS NOT NULL OR response_payload IS NOT NULL OR qr_code IS NOT NULL')->fetchColumn();
        }

        return [
            'total_games' => (int) $pdo->query('SELECT COUNT(*) FROM games WHERE game_origin = "admin"')->fetchColumn(),
            'deletable_games' => (int) $pdo->query('SELECT COUNT(*) FROM games g LEFT JOIN bet_ticket_items bti ON bti.game_id = g.id WHERE g.game_origin = "admin" AND bti.id IS NULL')->fetchColumn(),
            'linked_games' => (int) $pdo->query('SELECT COUNT(DISTINCT g.id) FROM games g INNER JOIN bet_ticket_items bti ON bti.game_id = g.id WHERE g.game_origin = "admin"')->fetchColumn(),
            'locked_games' => (int) $pdo->query('SELECT COUNT(*) FROM games g WHERE g.game_origin = "admin" AND ' . $this->lockedGamesCondition('g'))->fetchColumn(),
            'orphan_leagues' => (int) $pdo->query('SELECT COUNT(*) FROM leagues l LEFT JOIN games g ON g.league_id = l.id WHERE g.id IS NULL')->fetchColumn(),
            'orphan_teams' => (int) $pdo->query('SELECT COUNT(*) FROM teams t LEFT JOIN games gh ON gh.home_team_id = t.id LEFT JOIN games ga ON ga.away_team_id = t.id WHERE gh.id IS NULL AND ga.id IS NULL')->fetchColumn(),
            'games_without_odds' => (int) $pdo->query('SELECT COUNT(*) FROM (SELECT g.id FROM games g LEFT JOIN odds o ON o.game_id = g.id WHERE g.game_origin = "admin" GROUP BY g.id HAVING COUNT(o.id) = 0) AS clean_games')->fetchColumn(),
            'total_tickets' => $this->tableExists('bet_tickets') ? (int) $pdo->query('SELECT COUNT(*) FROM bet_tickets')->fetchColumn() : 0,
            'open_tickets' => $this->tableExists('bet_tickets') ? (int) $pdo->query('SELECT COUNT(*) FROM bet_tickets WHERE status = "open"')->fetchColumn() : 0,
            'settled_tickets' => $this->tableExists('bet_tickets') ? (int) $pdo->query('SELECT COUNT(*) FROM bet_tickets WHERE status <> "open"')->fetchColumn() : 0,
            'gateway_logs' => $gatewayLogs,
        ];
    }

    /**
     * @param array{
     *   category_id?: int,
     *   status?: string,
     *   source?: string,
     *   match_from?: string,
     *   match_to?: string,
     *   without_odds?: bool,
     *   locked_only?: bool
     * } $filters
     * @return array{matched: int, deleted: int, skipped_linked: int}
     */
    public function cleanupGames(array $filters, int $limit = 1000, bool $allowNoFilters = false): array
    {
        $this->ensureCleanupSchema();

        [$conditions, $params, $activeFilters] = $this->buildGameFilterParts($filters);
        if (!$allowNoFilters && $activeFilters === 0) {
            throw new RuntimeException('Escolha pelo menos um filtro para limpar em massa.');
        }

        $limit = max(1, min(10000, $limit));

        $sql = 'SELECT g.id, COUNT(bti.id) AS linked_count
                FROM games g
                INNER JOIN leagues l ON l.id = g.league_id
                LEFT JOIN bet_ticket_items bti ON bti.game_id = g.id
                WHERE g.game_origin = "admin"';

        if ($conditions !== []) {
            $sql .= ' AND ' . implode(' AND ', $conditions);
        }

        $sql .= ' GROUP BY g.id ORDER BY g.match_date DESC LIMIT ' . $limit;

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        if ($rows === []) {
            return [
                'matched' => 0,
                'deleted' => 0,
                'skipped_linked' => 0,
            ];
        }

        $deletableIds = [];
        $skippedLinked = 0;

        foreach ($rows as $row) {
            if ((int) ($row['linked_count'] ?? 0) > 0) {
                $skippedLinked++;
                continue;
            }

            $deletableIds[] = (int) $row['id'];
        }

        if ($deletableIds !== []) {
            $this->deleteGamesByIds($deletableIds);
        }

        return [
            'matched' => count($rows),
            'deleted' => count($deletableIds),
            'skipped_linked' => $skippedLinked,
        ];
    }

    /**
     * @return array{matched: int, deleted: int, skipped_linked: int}
     */
    public function cleanupFinishedOlderThanDays(int $days, int $limit = 1000): array
    {
        $days = max(1, min(3650, $days));
        $timezone = new DateTimeZone('America/Sao_Paulo');
        $cutoff = (new DateTimeImmutable('now', $timezone))
            ->modify('-' . $days . ' days')
            ->format('Y-m-d H:i:s');

        return $this->cleanupGames([
            'status' => 'finished',
            'match_to' => $cutoff,
        ], $limit);
    }

    /**
     * @return array{matched: int, deleted: int, skipped_linked: int}
     */
    public function cleanupGamesWithoutOdds(int $limit = 1000): array
    {
        return $this->cleanupGames([
            'without_odds' => true,
        ], $limit);
    }

    /**
     * @return array{matched: int, deleted: int, skipped_linked: int}
     */
    public function cleanupLockedGames(int $limit = 1000): array
    {
        return $this->cleanupGames([
            'locked_only' => true,
        ], $limit);
    }

    /**
     * @return array{
     *   matched_tickets: int,
     *   deleted_tickets: int,
     *   deleted_items: int,
     *   detached_agent_requests: int
     * }
     */
    public function cleanupAllTickets(int $limit = 1000): array
    {
        if (!$this->tableExists('bet_tickets')) {
            return $this->emptyTicketCleanupResult();
        }

        $limit = max(1, min(10000, $limit));
        $stmt = $this->db->pdo()->query('SELECT id FROM bet_tickets ORDER BY created_at DESC LIMIT ' . $limit);
        $ticketIds = array_map(
            static fn (array $row): int => (int) ($row['id'] ?? 0),
            $stmt->fetchAll()
        );

        return $this->cleanupTicketsByIds($ticketIds);
    }

    /**
     * @return array{
     *   matched_tickets: int,
     *   deleted_tickets: int,
     *   deleted_items: int,
     *   detached_agent_requests: int
     * }
     */
    public function cleanupSettledTicketsOlderThanDays(int $days, int $limit = 1000): array
    {
        if (!$this->tableExists('bet_tickets')) {
            return $this->emptyTicketCleanupResult();
        }

        $days = max(1, min(3650, $days));
        $limit = max(1, min(10000, $limit));
        $timezone = new DateTimeZone('America/Sao_Paulo');
        $cutoff = (new DateTimeImmutable('now', $timezone))
            ->modify('-' . $days . ' days')
            ->format('Y-m-d H:i:s');

        $stmt = $this->db->pdo()->prepare(
            'SELECT id
             FROM bet_tickets
             WHERE status IN ("won", "lost", "cancelled")
               AND created_at <= :cutoff
             ORDER BY created_at ASC
             LIMIT ' . $limit
        );
        $stmt->execute(['cutoff' => $cutoff]);
        $ticketIds = array_map(
            static fn (array $row): int => (int) ($row['id'] ?? 0),
            $stmt->fetchAll()
        );

        return $this->cleanupTicketsByIds($ticketIds);
    }

    /**
     * @return array{
     *   matched_games: int,
     *   deleted_games: int,
     *   matched_tickets: int,
     *   deleted_tickets: int,
     *   deleted_items: int,
     *   detached_agent_requests: int
     * }
     */
    public function cleanupGamesWithLinkedTickets(int $limit = 1000): array
    {
        if (!$this->tableExists('bet_ticket_items')) {
            return [
                'matched_games' => 0,
                'deleted_games' => 0,
                'matched_tickets' => 0,
                'deleted_tickets' => 0,
                'deleted_items' => 0,
                'detached_agent_requests' => 0,
            ];
        }

        $limit = max(1, min(10000, $limit));
        $stmt = $this->db->pdo()->query(
            'SELECT DISTINCT g.id
             FROM games g
             INNER JOIN bet_ticket_items bti ON bti.game_id = g.id
             WHERE g.game_origin = "admin"
             ORDER BY g.match_date DESC
             LIMIT ' . $limit
        );
        $gameIds = array_map(
            static fn (array $row): int => (int) ($row['id'] ?? 0),
            $stmt->fetchAll()
        );

        if ($gameIds === []) {
            return [
                'matched_games' => 0,
                'deleted_games' => 0,
                'matched_tickets' => 0,
                'deleted_tickets' => 0,
                'deleted_items' => 0,
                'detached_agent_requests' => 0,
            ];
        }

        $ticketIds = $this->fetchDistinctIntValuesByIds('bet_ticket_items', 'ticket_id', 'game_id', $gameIds);
        $ticketResult = $this->cleanupTicketsByIds($ticketIds);
        $this->deleteGamesByIds($gameIds);

        return [
            'matched_games' => count($gameIds),
            'deleted_games' => count($gameIds),
            'matched_tickets' => $ticketResult['matched_tickets'],
            'deleted_tickets' => $ticketResult['deleted_tickets'],
            'deleted_items' => $ticketResult['deleted_items'],
            'detached_agent_requests' => $ticketResult['detached_agent_requests'],
        ];
    }

    /**
     * @return array{cleared_pix_logs: int, cleared_agent_logs: int, cleared_total: int}
     */
    public function cleanupGatewayLogsOlderThanDays(int $days): array
    {
        $days = max(1, min(3650, $days));
        $timezone = new DateTimeZone('America/Sao_Paulo');
        $cutoff = (new DateTimeImmutable('now', $timezone))
            ->modify('-' . $days . ' days')
            ->format('Y-m-d H:i:s');

        $pdo = $this->db->pdo();
        $clearedPixLogs = 0;
        $clearedAgentLogs = 0;

        if ($this->tableExists('pix_transactions')) {
            $stmt = $pdo->prepare(
                'UPDATE pix_transactions
                 SET request_payload = NULL,
                     response_payload = NULL,
                     qr_code = NULL
                 WHERE created_at <= :cutoff
                   AND (request_payload IS NOT NULL OR response_payload IS NOT NULL OR qr_code IS NOT NULL)'
            );
            $stmt->execute(['cutoff' => $cutoff]);
            $clearedPixLogs = $stmt->rowCount();
        }

        if ($this->tableExists('agent_payment_requests')) {
            $stmt = $pdo->prepare(
                'UPDATE agent_payment_requests
                 SET request_payload = NULL,
                     response_payload = NULL,
                     qr_code = NULL
                 WHERE created_at <= :cutoff
                   AND (request_payload IS NOT NULL OR response_payload IS NOT NULL OR qr_code IS NOT NULL)'
            );
            $stmt->execute(['cutoff' => $cutoff]);
            $clearedAgentLogs = $stmt->rowCount();
        }

        return [
            'cleared_pix_logs' => $clearedPixLogs,
            'cleared_agent_logs' => $clearedAgentLogs,
            'cleared_total' => $clearedPixLogs + $clearedAgentLogs,
        ];
    }

    /**
     * @return array{deleted_leagues: int, deleted_teams: int}
     */
    public function cleanupOrphans(): array
    {
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();

        try {
            $orphanLeagueIds = array_map(
                static fn (array $row): int => (int) $row['id'],
                $pdo->query('SELECT l.id FROM leagues l LEFT JOIN games g ON g.league_id = l.id WHERE g.id IS NULL')->fetchAll()
            );

            $orphanTeamIds = array_map(
                static fn (array $row): int => (int) $row['id'],
                $pdo->query('SELECT t.id FROM teams t LEFT JOIN games gh ON gh.home_team_id = t.id LEFT JOIN games ga ON ga.away_team_id = t.id WHERE gh.id IS NULL AND ga.id IS NULL')->fetchAll()
            );

            $deletedLeagues = $this->deleteIds($pdo, 'leagues', $orphanLeagueIds);
            $deletedTeams = $this->deleteIds($pdo, 'teams', $orphanTeamIds);

            $pdo->commit();

            return [
                'deleted_leagues' => $deletedLeagues,
                'deleted_teams' => $deletedTeams,
            ];
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $exception;
        }
    }

    private function ensureCleanupSchema(): void
    {
        (new BettingLockService($this->db))->ensureSchema();
        (new AgentService($this->db))->ensureSchema();
        $this->ensureSourceColumns();
    }

    private function ensureSourceColumns(): void
    {
        $pdo = $this->db->pdo();

        foreach (['xscores_match_id', 'sofascore_match_id', 'flashscore_match_id', 'betfair_match_id', 'oddapi_match_id'] as $column) {
            $exists = $pdo->query('SHOW COLUMNS FROM games LIKE ' . $pdo->quote($column))->fetch();
            if ($exists === false) {
                $pdo->exec('ALTER TABLE games ADD COLUMN ' . $column . ' VARCHAR(64) NULL AFTER created_at');
            }
        }
    }

    /**
     * @param array{
     *   category_id?: int,
     *   status?: string,
     *   source?: string,
     *   match_from?: string,
     *   match_to?: string,
     *   without_odds?: bool,
     *   locked_only?: bool
     * } $filters
     * @return array{0: array<int, string>, 1: array<string, mixed>, 2: int}
     */
    private function buildGameFilterParts(array $filters): array
    {
        $conditions = [];
        $params = [];
        $activeFilters = 0;

        $categoryId = (int) ($filters['category_id'] ?? 0);
        if ($categoryId > 0) {
            $conditions[] = 'l.category_id = :category_id';
            $params['category_id'] = $categoryId;
            $activeFilters++;
        }

        $status = (string) ($filters['status'] ?? 'all');
        if (in_array($status, ['scheduled', 'live', 'finished'], true)) {
            $conditions[] = 'g.status = :status';
            $params['status'] = $status;
            $activeFilters++;
        }

        $source = (string) ($filters['source'] ?? 'all');
        if ($source !== 'all') {
            $sourceCondition = $this->sourceCondition($source);
            if ($sourceCondition !== null) {
                $conditions[] = $sourceCondition;
                $activeFilters++;
            }
        }

        $matchFrom = $this->normalizeDateTime((string) ($filters['match_from'] ?? ''));
        if ($matchFrom !== null) {
            $conditions[] = 'g.match_date >= :match_from';
            $params['match_from'] = $matchFrom;
            $activeFilters++;
        }

        $matchTo = $this->normalizeDateTime((string) ($filters['match_to'] ?? ''), true);
        if ($matchTo !== null) {
            $conditions[] = 'g.match_date <= :match_to';
            $params['match_to'] = $matchTo;
            $activeFilters++;
        }

        if (($filters['without_odds'] ?? false) === true) {
            $conditions[] = 'NOT EXISTS (SELECT 1 FROM odds o WHERE o.game_id = g.id)';
            $activeFilters++;
        }

        if (($filters['locked_only'] ?? false) === true) {
            $conditions[] = $this->lockedGamesCondition('g');
            $activeFilters++;
        }

        return [$conditions, $params, $activeFilters];
    }

    private function sourceCondition(string $source): ?string
    {
        return match ($source) {
            'xscore' => '(g.xscores_match_id IS NOT NULL AND g.xscores_match_id <> "")',
            'sofascore' => '(g.sofascore_match_id IS NOT NULL AND g.sofascore_match_id <> "")',
            'flashscore' => '(g.flashscore_match_id IS NOT NULL AND g.flashscore_match_id <> "")',
            'betfair' => '(g.betfair_match_id IS NOT NULL AND g.betfair_match_id <> "")',
            'oddapi' => '(g.oddapi_match_id IS NOT NULL AND g.oddapi_match_id <> "")',
            'manual' => '(COALESCE(g.xscores_match_id, "") = "" AND COALESCE(g.sofascore_match_id, "") = "" AND COALESCE(g.flashscore_match_id, "") = "" AND COALESCE(g.betfair_match_id, "") = "" AND COALESCE(g.oddapi_match_id, "") = "")',
            default => null,
        };
    }

    private function lockedGamesCondition(string $gameAlias): string
    {
        return '(COALESCE(' . $gameAlias . '.betting_locked, 0) = 1'
            . ' OR COALESCE(' . $gameAlias . '.betting_only_before_start, 0) = 1'
            . ' OR COALESCE(' . $gameAlias . '.betting_lock_after_minutes, 0) > 0)';
    }

    /**
     * @param array<int, int> $gameIds
     */
    private function deleteGamesByIds(array $gameIds): void
    {
        $gameIds = array_values(array_filter(array_unique(array_map('intval', $gameIds)), static fn (int $id): bool => $id > 0));
        if ($gameIds === []) {
            return;
        }

        $pdo = $this->db->pdo();
        $pdo->beginTransaction();

        try {
            foreach (array_chunk($gameIds, 500) as $chunk) {
                $this->deleteByIds($pdo, 'odds', 'game_id', $chunk);
                $this->deleteByIds($pdo, 'game_results', 'game_id', $chunk);
                $this->deleteByIds($pdo, 'games', 'id', $chunk);
            }

            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $exception;
        }
    }

    /**
     * @param array<int, int> $ids
     */
    private function deleteIds(PDO $pdo, string $table, array $ids): int
    {
        if ($ids === []) {
            return 0;
        }

        $deleted = 0;
        foreach (array_chunk($ids, 500) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $stmt = $pdo->prepare('DELETE FROM ' . $table . ' WHERE id IN (' . $placeholders . ')');
            $stmt->execute($chunk);
            $deleted += count($chunk);
        }

        return $deleted;
    }

    private function normalizeDateTime(string $value, bool $endOfMinute = false): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        $timezone = new DateTimeZone('America/Sao_Paulo');
        $formats = ['Y-m-d\TH:i', 'Y-m-d H:i:s', 'Y-m-d H:i', 'Y-m-d'];

        foreach ($formats as $format) {
            $date = DateTimeImmutable::createFromFormat($format, $value, $timezone);
            if ($date instanceof DateTimeImmutable) {
                if ($format === 'Y-m-d') {
                    $date = $date->setTime($endOfMinute ? 23 : 0, $endOfMinute ? 59 : 0, $endOfMinute ? 59 : 0);
                } elseif ($endOfMinute) {
                    $date = $date->setTime((int) $date->format('H'), (int) $date->format('i'), 59);
                }

                return $date->format('Y-m-d H:i:s');
            }
        }

        return null;
    }

    /**
     * @return array{
     *   matched_tickets: int,
     *   deleted_tickets: int,
     *   deleted_items: int,
     *   detached_agent_requests: int
     * }
     */
    private function emptyTicketCleanupResult(): array
    {
        return [
            'matched_tickets' => 0,
            'deleted_tickets' => 0,
            'deleted_items' => 0,
            'detached_agent_requests' => 0,
        ];
    }

    /**
     * @param array<int, int> $ticketIds
     * @return array{
     *   matched_tickets: int,
     *   deleted_tickets: int,
     *   deleted_items: int,
     *   detached_agent_requests: int
     * }
     */
    private function cleanupTicketsByIds(array $ticketIds): array
    {
        if (!$this->tableExists('bet_tickets')) {
            return $this->emptyTicketCleanupResult();
        }

        $ticketIds = array_values(array_filter(array_unique(array_map('intval', $ticketIds)), static fn (int $id): bool => $id > 0));
        if ($ticketIds === []) {
            return $this->emptyTicketCleanupResult();
        }

        $existingTicketIds = $this->fetchExistingIds('bet_tickets', $ticketIds);
        if ($existingTicketIds === []) {
            return $this->emptyTicketCleanupResult();
        }

        $pdo = $this->db->pdo();
        $pdo->beginTransaction();

        try {
            $deletedItems = $this->deleteByIds($pdo, 'bet_ticket_items', 'ticket_id', $existingTicketIds);
            $detachedAgentRequests = 0;

            if ($this->tableExists('agent_payment_requests')) {
                foreach (array_chunk($existingTicketIds, 500) as $chunk) {
                    $placeholders = implode(',', array_fill(0, count($chunk), '?'));
                    $stmt = $pdo->prepare('UPDATE agent_payment_requests SET ticket_id = NULL WHERE ticket_id IN (' . $placeholders . ')');
                    $stmt->execute($chunk);
                    $detachedAgentRequests += $stmt->rowCount();
                }
            }

            $deletedTickets = $this->deleteByIds($pdo, 'bet_tickets', 'id', $existingTicketIds);
            $pdo->commit();

            return [
                'matched_tickets' => count($existingTicketIds),
                'deleted_tickets' => $deletedTickets,
                'deleted_items' => $deletedItems,
                'detached_agent_requests' => $detachedAgentRequests,
            ];
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $exception;
        }
    }

    /**
     * @param array<int, int> $ids
     * @return array<int, int>
     */
    private function fetchExistingIds(string $table, array $ids): array
    {
        if (!$this->tableExists($table) || $ids === []) {
            return [];
        }

        $pdo = $this->db->pdo();
        $existingIds = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $stmt = $pdo->prepare('SELECT id FROM ' . $table . ' WHERE id IN (' . $placeholders . ')');
            $stmt->execute($chunk);
            foreach ($stmt->fetchAll() as $row) {
                $existingIds[] = (int) ($row['id'] ?? 0);
            }
        }

        return array_values(array_filter(array_unique($existingIds), static fn (int $id): bool => $id > 0));
    }

    /**
     * @param array<int, int> $filterIds
     * @return array<int, int>
     */
    private function fetchDistinctIntValuesByIds(string $table, string $selectColumn, string $filterColumn, array $filterIds): array
    {
        if (!$this->tableExists($table) || $filterIds === []) {
            return [];
        }

        $pdo = $this->db->pdo();
        $values = [];
        foreach (array_chunk($filterIds, 500) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $stmt = $pdo->prepare('SELECT DISTINCT ' . $selectColumn . ' FROM ' . $table . ' WHERE ' . $filterColumn . ' IN (' . $placeholders . ')');
            $stmt->execute($chunk);
            foreach ($stmt->fetchAll() as $row) {
                $values[] = (int) ($row[$selectColumn] ?? 0);
            }
        }

        return array_values(array_filter(array_unique($values), static fn (int $id): bool => $id > 0));
    }

    /**
     * @param array<int, int> $ids
     */
    private function deleteByIds(PDO $pdo, string $table, string $column, array $ids): int
    {
        if (!$this->tableExists($table) || $ids === []) {
            return 0;
        }

        $deleted = 0;
        foreach (array_chunk($ids, 500) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $stmt = $pdo->prepare('DELETE FROM ' . $table . ' WHERE ' . $column . ' IN (' . $placeholders . ')');
            $stmt->execute($chunk);
            $deleted += $stmt->rowCount();
        }

        return $deleted;
    }

    private function tableExists(string $table): bool
    {
        if (array_key_exists($table, $this->tableExistsCache)) {
            return $this->tableExistsCache[$table];
        }

        $quotedTable = $this->db->pdo()->quote($table);
        $this->tableExistsCache[$table] = $this->db->pdo()->query('SHOW TABLES LIKE ' . $quotedTable)->fetchColumn() !== false;

        return $this->tableExistsCache[$table];
    }
}
