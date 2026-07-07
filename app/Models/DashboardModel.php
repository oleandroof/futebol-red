<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

final class DashboardModel
{
    private static bool $visibilitySchemaEnsured = false;

    public function __construct(private readonly Database $db)
    {
        $this->ensureGameVisibilitySchema();
    }

    public function featuredGames(?string $categorySlug = null, string $period = 'today', ?string $search = null, ?int $leagueId = null): array
    {
        [$where, $params] = $this->publicGameWhereClause($categorySlug, $period, $search, $leagueId);

        $sql = 'SELECT g.*, l.name AS league_name, l.country_code,
                       c.name AS category_name, c.slug AS category_slug,
                       hm.name AS home_name, hm.logo AS home_logo,
                       aw.name AS away_name, aw.logo AS away_logo
                FROM games g
                INNER JOIN leagues l ON l.id = g.league_id
                INNER JOIN categories c ON c.id = l.category_id
                INNER JOIN teams hm ON hm.id = g.home_team_id
                INNER JOIN teams aw ON aw.id = g.away_team_id
                ' . $where . '
                ORDER BY g.match_date ASC
                LIMIT 60';

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        $games = $stmt->fetchAll();

        $oddStmt = $this->db->pdo()->prepare('SELECT id, market_name, option_name, odd_value FROM odds WHERE game_id = :game_id ORDER BY market_name ASC, id ASC');
        foreach ($games as &$game) {
            $oddStmt->execute(['game_id' => $game['id']]);
            $odds = $oddStmt->fetchAll();
            $game['odds'] = $odds;
            $game['markets'] = $this->groupOddsByMarket($odds);
        }

        return $games;
    }

    public function publicLeaguesWithCount(?string $categorySlug = null, string $period = 'today', ?string $search = null): array
    {
        [$where, $params] = $this->publicGameWhereClause($categorySlug, $period, $search, null);

        $sql = 'SELECT l.id, l.name, l.country_code, l.category_id, c.name AS category_name, c.slug AS category_slug,
                       COUNT(g.id) AS total_games
                FROM games g
                INNER JOIN leagues l ON l.id = g.league_id
                INNER JOIN categories c ON c.id = l.category_id
                INNER JOIN teams hm ON hm.id = g.home_team_id
                INNER JOIN teams aw ON aw.id = g.away_team_id
                ' . $where . '
                GROUP BY l.id, l.name, l.country_code, l.category_id, c.name, c.slug
                ORDER BY c.sort_order ASC, l.name ASC';

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    public function categories(): array
    {
        return $this->db->pdo()->query('SELECT id, name, slug, is_active, sort_order FROM categories ORDER BY sort_order ASC, name ASC')->fetchAll();
    }

    public function publicCategoriesWithCount(): array
    {
        $sql = 'SELECT c.id, c.name, c.slug, COUNT(g.id) AS total_games
                FROM categories c
                LEFT JOIN leagues l ON l.category_id = c.id
                LEFT JOIN games g ON g.league_id = l.id
                    AND g.game_origin = "admin"
                    AND g.status <> "finished"
                    AND g.is_visible = 1
                    AND EXISTS (SELECT 1 FROM odds o WHERE o.game_id = g.id)
                WHERE c.is_active = 1
                GROUP BY c.id, c.name, c.slug
                ORDER BY c.sort_order ASC, c.name ASC';
        return $this->db->pdo()->query($sql)->fetchAll();
    }

    public function leagues(): array
    {
        $sql = 'SELECT l.id, l.name, l.country_code, l.category_id, c.name AS category_name, c.slug AS category_slug
                FROM leagues l
                INNER JOIN categories c ON c.id = l.category_id
                ORDER BY c.sort_order ASC, l.name ASC';
        return $this->db->pdo()->query($sql)->fetchAll();
    }

    public function teams(): array
    {
        return $this->db->pdo()->query('SELECT id, name, logo FROM teams ORDER BY name ASC')->fetchAll();
    }

    public function adminGames(): array
    {
        $sql = 'SELECT g.id, g.sport, g.match_date, g.status, g.risk_level,
                       g.is_visible,
                       g.betting_locked, g.betting_only_before_start, g.betting_lock_after_minutes,
                       g.league_id, g.home_team_id, g.away_team_id,
                       l.name AS league_name,
                       hm.name AS home_name,
                       aw.name AS away_name
                FROM games g
                INNER JOIN leagues l ON l.id = g.league_id
                INNER JOIN teams hm ON hm.id = g.home_team_id
                INNER JOIN teams aw ON aw.id = g.away_team_id
                WHERE g.game_origin = "admin"
                ORDER BY g.match_date DESC
                LIMIT 100';

        $games = $this->db->pdo()->query($sql)->fetchAll();
        $oddStmt = $this->db->pdo()->prepare('SELECT id, market_name, option_name, odd_value FROM odds WHERE game_id = :game_id ORDER BY market_name ASC, id ASC');
        $resultStmt = $this->db->pdo()->prepare('SELECT market_name, result_option FROM game_results WHERE game_id = :game_id');

        foreach ($games as &$game) {
            $oddStmt->execute(['game_id' => (int) $game['id']]);
            $odds = $oddStmt->fetchAll();
            $markets = $this->groupOddsByMarket($odds);

            $resultStmt->execute(['game_id' => (int) $game['id']]);
            $results = [];
            foreach ($resultStmt->fetchAll() as $row) {
                $results[(string) $row['market_name']] = (string) $row['result_option'];
            }

            foreach ($markets as &$market) {
                $market['result_option'] = $results[$market['market_name']] ?? '';
            }

            $game['markets'] = $markets;
        }

        return $games;
    }

    public function adminResultGamesToday(): array
    {
        $todayStart = (new \DateTimeImmutable('today'))->format('Y-m-d H:i:s');
        $tomorrowStart = (new \DateTimeImmutable('tomorrow'))->format('Y-m-d H:i:s');

        $sql = 'SELECT g.id, g.sport, g.match_date, g.status, g.risk_level,
                       l.name AS league_name,
                       hm.name AS home_name,
                       aw.name AS away_name
                FROM games g
                INNER JOIN leagues l ON l.id = g.league_id
                INNER JOIN teams hm ON hm.id = g.home_team_id
                INNER JOIN teams aw ON aw.id = g.away_team_id
                WHERE g.game_origin = "admin"
                  AND g.match_date >= :today_start
                  AND g.match_date < :tomorrow_start
                ORDER BY g.match_date ASC';

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([
            'today_start' => $todayStart,
            'tomorrow_start' => $tomorrowStart,
        ]);
        $games = $stmt->fetchAll();

        $oddStmt = $this->db->pdo()->prepare('SELECT id, market_name, option_name, odd_value FROM odds WHERE game_id = :game_id ORDER BY market_name ASC, id ASC');
        $resultStmt = $this->db->pdo()->prepare('SELECT market_name, result_option FROM game_results WHERE game_id = :game_id');

        foreach ($games as &$game) {
            $oddStmt->execute(['game_id' => (int) $game['id']]);
            $odds = $oddStmt->fetchAll();
            $markets = $this->groupOddsByMarket($odds);

            $resultStmt->execute(['game_id' => (int) $game['id']]);
            $results = [];
            foreach ($resultStmt->fetchAll() as $row) {
                $results[(string) $row['market_name']] = (string) $row['result_option'];
            }

            foreach ($markets as &$market) {
                $market['result_option'] = $results[$market['market_name']] ?? '';
            }

            $game['markets'] = $markets;
        }

        return $games;
    }

    public function lockableGames(int $limit = 300): array
    {
        $limit = max(1, min(1000, $limit));
        $sql = 'SELECT g.id, g.match_date, g.status,
                       g.betting_locked, g.betting_only_before_start, g.betting_lock_after_minutes,
                       l.name AS league_name,
                       hm.name AS home_name,
                       aw.name AS away_name
                FROM games g
                INNER JOIN leagues l ON l.id = g.league_id
                INNER JOIN teams hm ON hm.id = g.home_team_id
                INNER JOIN teams aw ON aw.id = g.away_team_id
                WHERE g.game_origin = "admin" AND g.status <> "finished"
                ORDER BY g.match_date ASC
                LIMIT ' . $limit;

        return $this->db->pdo()->query($sql)->fetchAll();
    }

    public function adminGameById(int $gameId): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM games WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $gameId]);
        $game = $stmt->fetch();
        if (!$game) {
            return null;
        }

        $oddsStmt = $this->db->pdo()->prepare('SELECT market_name, option_name, odd_value FROM odds WHERE game_id = :game_id ORDER BY market_name ASC, id ASC');
        $oddsStmt->execute(['game_id' => $gameId]);
        $odds = $oddsStmt->fetchAll();

        $baseMarket = 'Resultado final';
        $map = ['Casa' => null, 'Empate' => null, 'Fora' => null];
        $customOdds = [];

        foreach ($odds as $odd) {
            $marketName = (string) $odd['market_name'];
            $optionName = (string) $odd['option_name'];
            $oddValue = (float) $odd['odd_value'];

            if ($marketName === $baseMarket && array_key_exists($optionName, $map)) {
                $map[$optionName] = $oddValue;
                continue;
            }

            $customOdds[] = [
                'market_name' => $marketName,
                'option_name' => $optionName,
                'odd_value' => $oddValue,
            ];
        }

        $game['odd_home'] = $map['Casa'] ?? 1.50;
        $game['odd_draw'] = $map['Empate'] ?? 3.00;
        $game['odd_away'] = $map['Fora'] ?? 2.50;
        $game['custom_odds'] = $customOdds;

        return $game;
    }

    public function oddsByIds(array $oddIds): array
    {
        if ($oddIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($oddIds), '?'));
        $sql = 'SELECT o.id AS odd_id, o.market_name, o.option_name, o.odd_value,
                       g.id AS game_id, g.match_date, g.status,
                       g.betting_locked, g.betting_only_before_start, g.betting_lock_after_minutes,
                       l.name AS league_name,
                       hm.name AS home_name,
                       aw.name AS away_name
                FROM odds o
                INNER JOIN games g ON g.id = o.game_id
                INNER JOIN leagues l ON l.id = g.league_id
                INNER JOIN teams hm ON hm.id = g.home_team_id
                INNER JOIN teams aw ON aw.id = g.away_team_id
                WHERE o.id IN (' . $placeholders . ') AND g.game_origin = "admin" AND g.is_visible = 1';

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($oddIds);

        $rows = $stmt->fetchAll();
        $result = [];
        foreach ($rows as $row) {
            $result[(int) $row['odd_id']] = $row;
        }

        return $result;
    }

    public function stats(): array
    {
        $pdo = $this->db->pdo();

        return [
            'games' => (int) $pdo->query('SELECT COUNT(*) FROM games WHERE game_origin = "admin"')->fetchColumn(),
            'open_bets' => (int) $pdo->query("SELECT COUNT(*) FROM bet_tickets WHERE status = 'open'")->fetchColumn(),
            'won_bets' => (int) $pdo->query("SELECT COUNT(*) FROM bet_tickets WHERE status = 'won'")->fetchColumn(),
            'revenue' => (float) $pdo->query('SELECT COALESCE(SUM(amount), 0) FROM transactions WHERE type IN ("deposit", "bet_loss")')->fetchColumn(),
            'payouts' => (float) $pdo->query('SELECT COALESCE(SUM(amount), 0) FROM transactions WHERE type IN ("withdrawal", "bet_win")')->fetchColumn(),
            'risk_exposure' => (float) $pdo->query('SELECT COALESCE(SUM(potential_return), 0) FROM bet_tickets WHERE status = "open"')->fetchColumn(),
        ];
    }

    public function adminOverviewSummary(): array
    {
        $pdo = $this->db->pdo();

        $ticketSummary = $pdo->query('SELECT COUNT(*) AS tickets_total,
                       COALESCE(SUM(CASE WHEN status = "open" THEN 1 ELSE 0 END), 0) AS tickets_open,
                       COALESCE(SUM(CASE WHEN status = "won" THEN 1 ELSE 0 END), 0) AS tickets_won,
                       COALESCE(SUM(CASE WHEN status = "lost" THEN 1 ELSE 0 END), 0) AS tickets_lost,
                       COALESCE(SUM(CASE WHEN status = "cancelled" THEN 1 ELSE 0 END), 0) AS tickets_cancelled,
                       COALESCE(SUM(CASE WHEN DATE(created_at) = CURDATE() THEN 1 ELSE 0 END), 0) AS tickets_today,
                       COALESCE(SUM(stake), 0) AS stakes_total,
                       COALESCE(SUM(CASE WHEN status = "open" THEN stake ELSE 0 END), 0) AS stakes_open,
                       COALESCE(AVG(stake), 0) AS avg_stake,
                       COALESCE(AVG(total_odd), 0) AS avg_odd
                FROM bet_tickets')->fetch() ?: [];

        $gameSummary = $pdo->query('SELECT COUNT(*) AS games_total,
                       COALESCE(SUM(CASE WHEN is_visible = 1 THEN 1 ELSE 0 END), 0) AS games_visible,
                       COALESCE(SUM(CASE WHEN is_visible = 0 THEN 1 ELSE 0 END), 0) AS games_hidden,
                       COALESCE(SUM(CASE WHEN status = "live" THEN 1 ELSE 0 END), 0) AS games_live,
                       COALESCE(SUM(CASE WHEN status = "finished" THEN 1 ELSE 0 END), 0) AS games_finished,
                       COALESCE(SUM(CASE WHEN betting_locked = 1 THEN 1 ELSE 0 END), 0) AS games_manual_locked
                FROM games
                WHERE game_origin = "admin"')->fetch() ?: [];

        return [
            'tickets_total' => (int) ($ticketSummary['tickets_total'] ?? 0),
            'tickets_open' => (int) ($ticketSummary['tickets_open'] ?? 0),
            'tickets_won' => (int) ($ticketSummary['tickets_won'] ?? 0),
            'tickets_lost' => (int) ($ticketSummary['tickets_lost'] ?? 0),
            'tickets_cancelled' => (int) ($ticketSummary['tickets_cancelled'] ?? 0),
            'tickets_today' => (int) ($ticketSummary['tickets_today'] ?? 0),
            'stakes_total' => (float) ($ticketSummary['stakes_total'] ?? 0),
            'stakes_open' => (float) ($ticketSummary['stakes_open'] ?? 0),
            'avg_stake' => (float) ($ticketSummary['avg_stake'] ?? 0),
            'avg_odd' => (float) ($ticketSummary['avg_odd'] ?? 0),
            'games_total' => (int) ($gameSummary['games_total'] ?? 0),
            'games_visible' => (int) ($gameSummary['games_visible'] ?? 0),
            'games_hidden' => (int) ($gameSummary['games_hidden'] ?? 0),
            'games_live' => (int) ($gameSummary['games_live'] ?? 0),
            'games_finished' => (int) ($gameSummary['games_finished'] ?? 0),
            'games_manual_locked' => (int) ($gameSummary['games_manual_locked'] ?? 0),
        ];
    }

    public function adminOverviewDaily(int $days = 7): array
    {
        $days = max(3, min(30, $days));
        $timezone = new \DateTimeZone('America/Sao_Paulo');
        $today = new \DateTimeImmutable('today', $timezone);
        $start = $today->modify('-' . ($days - 1) . ' days');

        $daily = [];
        for ($index = 0; $index < $days; $index++) {
            $date = $start->modify('+' . $index . ' days');
            $key = $date->format('Y-m-d');
            $daily[$key] = [
                'date' => $key,
                'label' => $date->format('d/m'),
                'weekday' => $date->format('D'),
                'tickets' => 0,
                'stakes' => 0.0,
                'inflow' => 0.0,
                'outflow' => 0.0,
                'balance' => 0.0,
            ];
        }

        $ticketStmt = $this->db->pdo()->prepare('SELECT DATE(created_at) AS day_key,
                       COUNT(*) AS tickets,
                       COALESCE(SUM(stake), 0) AS stakes
                FROM bet_tickets
                WHERE created_at >= :start_date
                GROUP BY DATE(created_at)');
        $ticketStmt->execute(['start_date' => $start->format('Y-m-d 00:00:00')]);
        foreach ($ticketStmt->fetchAll() as $row) {
            $key = (string) ($row['day_key'] ?? '');
            if (!isset($daily[$key])) {
                continue;
            }

            $daily[$key]['tickets'] = (int) ($row['tickets'] ?? 0);
            $daily[$key]['stakes'] = (float) ($row['stakes'] ?? 0);
        }

        $transactionStmt = $this->db->pdo()->prepare('SELECT DATE(created_at) AS day_key,
                       COALESCE(SUM(CASE WHEN type IN ("deposit", "bet_loss") THEN amount ELSE 0 END), 0) AS inflow,
                       COALESCE(SUM(CASE WHEN type IN ("withdrawal", "bet_win") THEN amount ELSE 0 END), 0) AS outflow
                FROM transactions
                WHERE created_at >= :start_date
                GROUP BY DATE(created_at)');
        $transactionStmt->execute(['start_date' => $start->format('Y-m-d 00:00:00')]);
        foreach ($transactionStmt->fetchAll() as $row) {
            $key = (string) ($row['day_key'] ?? '');
            if (!isset($daily[$key])) {
                continue;
            }

            $daily[$key]['inflow'] = (float) ($row['inflow'] ?? 0);
            $daily[$key]['outflow'] = (float) ($row['outflow'] ?? 0);
        }

        foreach ($daily as &$entry) {
            $entry['balance'] = (float) $entry['inflow'] - (float) $entry['outflow'];
        }
        unset($entry);

        return array_values($daily);
    }

    public function recentBets(?string $dateFrom = null, ?string $dateTo = null): array
    {
        $where = [];
        $params = [];

        if ($dateFrom !== null && $dateFrom !== '') {
            $where[] = 'DATE(bt.created_at) >= :date_from';
            $params['date_from'] = $dateFrom;
        }

        if ($dateTo !== null && $dateTo !== '') {
            $where[] = 'DATE(bt.created_at) <= :date_to';
            $params['date_to'] = $dateTo;
        }

        $sql = 'SELECT bt.id, bt.ticket_code, bt.status, bt.stake, bt.total_odd, bt.potential_return,
                       u.name AS user_name, bt.created_at
                FROM bet_tickets bt
                INNER JOIN users u ON u.id = bt.user_id';

        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        $sql .= ' ORDER BY bt.created_at DESC LIMIT 100';

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    public function settlements(): array
    {
        return $this->db->pdo()->query('SELECT id, reference, type, amount, status, created_at FROM transactions ORDER BY created_at DESC LIMIT 12')->fetchAll();
    }

    public function settings(): array
    {
        $rows = $this->db->pdo()->query('SELECT setting_key, setting_value FROM settings')->fetchAll();
        $settings = [];
        foreach ($rows as $row) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }

        return $settings;
    }

    public function userTickets(int $userId): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT id, ticket_code, stake, total_odd, potential_return, status, created_at FROM bet_tickets WHERE user_id = :user_id ORDER BY created_at DESC LIMIT 12');
        $stmt->execute(['user_id' => $userId]);
        $tickets = $stmt->fetchAll();

        $itemsStmt = $this->db->pdo()->prepare('SELECT bti.ticket_id, bti.market_name, bti.option_name, bti.odd_value, g.match_date, hm.name AS home_name, aw.name AS away_name
                FROM bet_ticket_items bti
                INNER JOIN games g ON g.id = bti.game_id
                INNER JOIN teams hm ON hm.id = g.home_team_id
                INNER JOIN teams aw ON aw.id = g.away_team_id
                WHERE bti.ticket_id = :ticket_id');

        foreach ($tickets as &$ticket) {
            $itemsStmt->execute(['ticket_id' => $ticket['id']]);
            $ticket['items'] = $itemsStmt->fetchAll();
        }

        return $tickets;
    }

    public function userTicketStats(int $userId): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT status, COUNT(*) AS total FROM bet_tickets WHERE user_id = :user_id GROUP BY status');
        $stmt->execute(['user_id' => $userId]);

        $stats = [
            'total' => 0,
            'won' => 0,
            'lost' => 0,
            'open' => 0,
            'cancelled' => 0,
        ];

        foreach ($stmt->fetchAll() as $row) {
            $status = (string) ($row['status'] ?? '');
            $count = (int) ($row['total'] ?? 0);
            $stats['total'] += $count;

            if (array_key_exists($status, $stats)) {
                $stats[$status] += $count;
            }
        }

        return $stats;
    }

    public function adminTicketById(int $ticketId): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT bt.*, u.name AS user_name, u.email AS user_email, u.cpf AS user_cpf
                FROM bet_tickets bt
                INNER JOIN users u ON u.id = bt.user_id
                WHERE bt.id = :ticket_id
                LIMIT 1');
        $stmt->execute(['ticket_id' => $ticketId]);
        $ticket = $stmt->fetch();
        if (!$ticket) {
            return null;
        }

        $itemsStmt = $this->db->pdo()->prepare('SELECT bti.market_name, bti.option_name, bti.odd_value,
                       g.match_date, g.status AS game_status,
                       hm.name AS home_name, aw.name AS away_name,
                       gr.result_option
                FROM bet_ticket_items bti
                INNER JOIN games g ON g.id = bti.game_id
                INNER JOIN teams hm ON hm.id = g.home_team_id
                INNER JOIN teams aw ON aw.id = g.away_team_id
                LEFT JOIN game_results gr ON gr.game_id = bti.game_id AND gr.market_name = bti.market_name
                WHERE bti.ticket_id = :ticket_id
                ORDER BY g.match_date ASC, bti.market_name ASC');
        $itemsStmt->execute(['ticket_id' => $ticketId]);
        $ticket['items'] = $itemsStmt->fetchAll();

        return $ticket;
    }

    public function findTicketByCode(string $code): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT bt.*, u.name AS user_name FROM bet_tickets bt INNER JOIN users u ON u.id = bt.user_id WHERE bt.ticket_code = :ticket_code LIMIT 1');
        $stmt->execute(['ticket_code' => $code]);
        $ticket = $stmt->fetch();
        if (!$ticket) {
            return null;
        }

        $itemsStmt = $this->db->pdo()->prepare('SELECT bti.*, hm.name AS home_name, aw.name AS away_name
                FROM bet_ticket_items bti
                INNER JOIN games g ON g.id = bti.game_id
                INNER JOIN teams hm ON hm.id = g.home_team_id
                INNER JOIN teams aw ON aw.id = g.away_team_id
                WHERE bti.ticket_id = :ticket_id');
        $itemsStmt->execute(['ticket_id' => $ticket['id']]);
        $ticket['items'] = $itemsStmt->fetchAll();

        return $ticket;
    }

    /**
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function publicGameWhereClause(?string $categorySlug, string $period, ?string $search, ?int $leagueId): array
    {
        $where = 'WHERE g.game_origin = "admin"
                  AND g.status <> "finished"
                  AND g.is_visible = 1
                  AND EXISTS (SELECT 1 FROM odds o WHERE o.game_id = g.id)';
        $params = [];

        if ($categorySlug !== null && $categorySlug !== '') {
            $where .= ' AND c.slug = :slug';
            $params['slug'] = $categorySlug;
        }

        if ($leagueId !== null && $leagueId > 0) {
            $where .= ' AND g.league_id = :league_id';
            $params['league_id'] = $leagueId;
        }

        if ($search !== null && $search !== '') {
            $where .= " AND (
                LOWER(l.name) LIKE :search
                OR LOWER(hm.name) LIKE :search
                OR LOWER(aw.name) LIKE :search
                OR DATE_FORMAT(g.match_date, '%d/%m %H:%i') LIKE :search
                OR DATE_FORMAT(g.match_date, '%H:%i') LIKE :search
            )";
            $params['search'] = '%' . mb_strtolower($search) . '%';
        }

        $todayStart = new \DateTimeImmutable('today');
        $tomorrowStart = $todayStart->modify('+1 day');
        $afterTomorrowStart = $todayStart->modify('+2 days');
        $weekEnd = $todayStart->modify('+7 days');

        if ($period === 'all') {
            return [$where, $params];
        }

        if ($period === 'tomorrow') {
            $where .= ' AND g.match_date >= :start_date AND g.match_date < :end_date';
            $params['start_date'] = $tomorrowStart->format('Y-m-d H:i:s');
            $params['end_date'] = $afterTomorrowStart->format('Y-m-d H:i:s');
            return [$where, $params];
        }

        if ($period === 'week') {
            $where .= ' AND g.match_date >= :start_date AND g.match_date < :end_date';
            $params['start_date'] = $todayStart->format('Y-m-d H:i:s');
            $params['end_date'] = $weekEnd->format('Y-m-d H:i:s');
            return [$where, $params];
        }

        if ($period === 'live') {
            $where .= ' AND g.status = :status_live';
            $params['status_live'] = 'live';
            return [$where, $params];
        }

        $where .= ' AND g.match_date >= :start_date AND g.match_date < :end_date';
        $params['start_date'] = $todayStart->format('Y-m-d H:i:s');
        $params['end_date'] = $tomorrowStart->format('Y-m-d H:i:s');

        return [$where, $params];
    }

    private function groupOddsByMarket(array $odds): array
    {
        $markets = [];
        foreach ($odds as $odd) {
            $marketName = (string) $odd['market_name'];
            if (!isset($markets[$marketName])) {
                $markets[$marketName] = [
                    'market_name' => $marketName,
                    'options' => [],
                ];
            }
            $markets[$marketName]['options'][] = $odd;
        }

        return array_values($markets);
    }

    private function ensureGameVisibilitySchema(): void
    {
        if (self::$visibilitySchemaEnsured) {
            return;
        }

        $pdo = $this->db->pdo();
        $exists = $pdo->query('SHOW COLUMNS FROM games LIKE "is_visible"')->fetch();
        if ($exists === false) {
            $pdo->exec('ALTER TABLE games ADD COLUMN is_visible TINYINT(1) NOT NULL DEFAULT 1 AFTER risk_level');
            $pdo->exec('UPDATE games SET is_visible = 1 WHERE is_visible IS NULL');
        }

        self::$visibilitySchemaEnsured = true;
    }
}
