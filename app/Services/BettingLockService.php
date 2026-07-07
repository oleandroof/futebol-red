<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

final class BettingLockService
{
    private const GLOBAL_LOCK_KEY = 'betting_lock_all_games';
    private static bool $schemaEnsured = false;

    public function __construct(private readonly Database $db)
    {
    }

    public function ensureSchema(): void
    {
        if (self::$schemaEnsured) {
            return;
        }

        $this->ensureGameColumn('betting_locked', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER risk_level');
        $this->ensureGameColumn('betting_only_before_start', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER betting_locked');
        $this->ensureGameColumn('betting_lock_after_minutes', 'SMALLINT UNSIGNED NULL AFTER betting_only_before_start');
        $this->ensureSetting(self::GLOBAL_LOCK_KEY, '0');

        self::$schemaEnsured = true;
    }

    /**
     * @return array{global_lock_all: bool}
     */
    public function settings(): array
    {
        $this->ensureSchema();

        $stmt = $this->db->pdo()->prepare('SELECT setting_key, setting_value FROM settings WHERE setting_key = :key');
        $stmt->execute(['key' => self::GLOBAL_LOCK_KEY]);
        $rows = $stmt->fetchAll();

        $settings = [
            'global_lock_all' => false,
        ];

        foreach ($rows as $row) {
            if (($row['setting_key'] ?? '') === self::GLOBAL_LOCK_KEY) {
                $settings['global_lock_all'] = (string) ($row['setting_value'] ?? '0') === '1';
            }
        }

        return $settings;
    }

    public function setGlobalLock(bool $locked): void
    {
        $this->ensureSchema();
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO settings (setting_key, setting_value)
             VALUES (:setting_key, :setting_value)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
        );
        $stmt->execute([
            'setting_key' => self::GLOBAL_LOCK_KEY,
            'setting_value' => $locked ? '1' : '0',
        ]);
    }

    public function setManualGameLock(int $gameId, bool $locked): void
    {
        $this->ensureSchema();
        $stmt = $this->db->pdo()->prepare('UPDATE games SET betting_locked = :betting_locked WHERE id = :id AND game_origin = "admin"');
        $stmt->execute([
            'betting_locked' => $locked ? 1 : 0,
            'id' => $gameId,
        ]);

        if ($stmt->rowCount() === 0 && !$this->gameExists($gameId)) {
            throw new RuntimeException('Jogo nao encontrado para atualizar a trava manual.');
        }
    }

    public function configureGameWindow(int $gameId, bool $onlyBeforeStart, ?int $lockAfterMinutes): void
    {
        $this->ensureSchema();
        $minutes = $lockAfterMinutes !== null && $lockAfterMinutes > 0 ? min($lockAfterMinutes, 600) : null;

        $stmt = $this->db->pdo()->prepare(
            'UPDATE games
             SET betting_only_before_start = :betting_only_before_start,
                 betting_lock_after_minutes = :betting_lock_after_minutes
             WHERE id = :id AND game_origin = "admin"'
        );
        $stmt->execute([
            'betting_only_before_start' => $onlyBeforeStart ? 1 : 0,
            'betting_lock_after_minutes' => $minutes,
            'id' => $gameId,
        ]);

        if ($stmt->rowCount() === 0 && !$this->gameExists($gameId)) {
            throw new RuntimeException('Jogo nao encontrado para atualizar a trava por horario.');
        }
    }

    /**
     * @return array{global_unlocked: bool, cleared_games: int}
     */
    public function clearAllLocks(): array
    {
        $this->ensureSchema();
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();

        try {
            $globalEnabled = $this->settings()['global_lock_all'];

            $stmt = $pdo->prepare(
                'UPDATE games
                 SET betting_locked = 0,
                     betting_only_before_start = 0,
                     betting_lock_after_minutes = NULL
                 WHERE game_origin = "admin"
                   AND (
                        betting_locked = 1
                        OR betting_only_before_start = 1
                        OR (betting_lock_after_minutes IS NOT NULL AND betting_lock_after_minutes > 0)
                   )'
            );
            $stmt->execute();

            $this->setGlobalLock(false);
            $pdo->commit();

            return [
                'global_unlocked' => $globalEnabled,
                'cleared_games' => (int) $stmt->rowCount(),
            ];
        } catch (\Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $exception;
        }
    }

    /**
     * @param array<string, mixed> $game
     * @param array{global_lock_all: bool}|null $settings
     * @return array<string, mixed>
     */
    public function annotateGame(array $game, ?array $settings = null): array
    {
        $settings ??= $this->settings();

        [$locked, $reason, $source] = $this->lockState($game, $settings);

        $game['betting_is_locked'] = $locked ? 1 : 0;
        $game['betting_lock_reason'] = $reason;
        $game['betting_lock_source'] = $source;
        $game['betting_rule_summary'] = $this->ruleSummary($game);

        return $game;
    }

    /**
     * @param array<int, array<string, mixed>> $games
     * @param array{global_lock_all: bool}|null $settings
     * @return array<int, array<string, mixed>>
     */
    public function annotateGames(array $games, ?array $settings = null): array
    {
        $settings ??= $this->settings();

        foreach ($games as $index => $game) {
            $games[$index] = $this->annotateGame($game, $settings);
        }

        return $games;
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @return array<int, array<string, mixed>>
     */
    public function lockedSelections(array $items): array
    {
        $settings = $this->settings();
        $locked = [];

        foreach ($items as $item) {
            $annotated = $this->annotateGame($item, $settings);
            if ((int) ($annotated['betting_is_locked'] ?? 0) === 1) {
                $locked[] = $annotated;
            }
        }

        return $locked;
    }

    /**
     * @return array{
     *   global_lock_all: bool,
     *   manual_locked_games: int,
     *   prestart_games: int,
     *   timed_games: int,
     *   currently_locked_games: int
     * }
     */
    public function summary(): array
    {
        $settings = $this->settings();
        $pdo = $this->db->pdo();

        $summary = [
            'global_lock_all' => $settings['global_lock_all'],
            'manual_locked_games' => (int) $pdo->query('SELECT COUNT(*) FROM games WHERE game_origin = "admin" AND status <> "finished" AND betting_locked = 1')->fetchColumn(),
            'prestart_games' => (int) $pdo->query('SELECT COUNT(*) FROM games WHERE game_origin = "admin" AND status <> "finished" AND betting_only_before_start = 1')->fetchColumn(),
            'timed_games' => (int) $pdo->query('SELECT COUNT(*) FROM games WHERE game_origin = "admin" AND status <> "finished" AND betting_lock_after_minutes IS NOT NULL AND betting_lock_after_minutes > 0')->fetchColumn(),
            'currently_locked_games' => 0,
        ];

        $games = $pdo->query(
            'SELECT id, match_date, status, betting_locked, betting_only_before_start, betting_lock_after_minutes
             FROM games
             WHERE game_origin = "admin" AND status <> "finished"
             ORDER BY match_date ASC'
        )->fetchAll();

        foreach ($this->annotateGames($games, $settings) as $game) {
            if ((int) ($game['betting_is_locked'] ?? 0) === 1) {
                $summary['currently_locked_games']++;
            }
        }

        return $summary;
    }

    /**
     * @param array<string, mixed> $game
     * @param array{global_lock_all: bool} $settings
     * @return array{0: bool, 1: string, 2: string}
     */
    private function lockState(array $game, array $settings): array
    {
        if ($settings['global_lock_all']) {
            return [true, 'Mercado travado globalmente pelo admin.', 'global'];
        }

        if ((int) ($game['betting_locked'] ?? 0) === 1) {
            return [true, 'Mercado travado manualmente pelo admin.', 'manual'];
        }

        $matchDate = $this->matchDate((string) ($game['match_date'] ?? ''));
        if ($matchDate === null) {
            return [false, '', ''];
        }

        $now = new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo'));

        $status = strtolower(trim((string) ($game['status'] ?? 'scheduled')));
        if ($status === 'finished') {
            return [true, 'Partida finalizada. Apostas encerradas.', 'finished'];
        }

        // Cinturao de seguranca: se o horario ja passou e o jogo ainda aparece como "scheduled",
        // nao permite apostar depois do kickoff (evita apostas tardias por status/atualizacao falha).
        if ($status === 'scheduled' && $now >= $matchDate) {
            return [true, 'Apostas encerradas no inicio da partida.', 'kickoff'];
        }

        if ((int) ($game['betting_only_before_start'] ?? 0) === 1 && $now >= $matchDate) {
            return [true, 'Apostas encerradas no inicio da partida.', 'kickoff'];
        }

        $lockAfterMinutes = (int) ($game['betting_lock_after_minutes'] ?? 0);
        if ($lockAfterMinutes > 0) {
            $deadline = $matchDate->modify('+' . $lockAfterMinutes . ' minutes');
            if ($deadline instanceof DateTimeImmutable && $now >= $deadline) {
                return [true, 'Apostas encerradas apos ' . $lockAfterMinutes . ' min de jogo.', 'minutes'];
            }
        }

        return [false, '', ''];
    }

    /**
     * @param array<string, mixed> $game
     */
    private function ruleSummary(array $game): string
    {
        $parts = [];

        if ((int) ($game['betting_locked'] ?? 0) === 1) {
            $parts[] = 'travado manual';
        }

        if ((int) ($game['betting_only_before_start'] ?? 0) === 1) {
            $parts[] = 'so ate iniciar';
        }

        $lockAfterMinutes = (int) ($game['betting_lock_after_minutes'] ?? 0);
        if ($lockAfterMinutes > 0) {
            $parts[] = 'trava apos ' . $lockAfterMinutes . ' min';
        }

        return $parts === [] ? 'sem trava especial' : implode(' | ', $parts);
    }

    private function ensureGameColumn(string $column, string $definition): void
    {
        $exists = $this->db->pdo()->query('SHOW COLUMNS FROM games LIKE ' . $this->db->pdo()->quote($column))->fetch();
        if ($exists === false) {
            $this->db->pdo()->exec('ALTER TABLE games ADD COLUMN ' . $column . ' ' . $definition);
        }
    }

    private function ensureSetting(string $key, string $value): void
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO settings (setting_key, setting_value)
             VALUES (:setting_key, :setting_value)
             ON DUPLICATE KEY UPDATE setting_value = setting_value'
        );
        $stmt->execute([
            'setting_key' => $key,
            'setting_value' => $value,
        ]);
    }

    private function gameExists(int $gameId): bool
    {
        $stmt = $this->db->pdo()->prepare('SELECT id FROM games WHERE id = :id AND game_origin = "admin" LIMIT 1');
        $stmt->execute(['id' => $gameId]);
        return $stmt->fetch() !== false;
    }

    private function matchDate(string $value): ?DateTimeImmutable
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        $timezone = new DateTimeZone('America/Sao_Paulo');
        foreach (['Y-m-d H:i:s', 'Y-m-d H:i'] as $format) {
            $date = DateTimeImmutable::createFromFormat($format, $value, $timezone);
            if ($date instanceof DateTimeImmutable) {
                return $date;
            }
        }

        try {
            return new DateTimeImmutable($value, $timezone);
        } catch (\Throwable) {
            return null;
        }
    }
}
