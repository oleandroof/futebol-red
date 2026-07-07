<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Models\DashboardModel;
use App\Services\AdminCleanupService;
use App\Services\AdminGameSyncService;
use App\Services\AgentService;
use App\Services\BettingLockService;
use App\Services\PixGatewayService;
use Throwable;

final class AdminController extends Controller
{
    public function index(): void
    {
        $user = $this->auth->requireAdmin();
        $this->ensureGameResultsTable();
        $lockService = new BettingLockService($this->db);
        $lockService->ensureSchema();
        $agentService = new AgentService($this->db);
        $agentService->ensureSchema();
        $gatewayService = new PixGatewayService($this->db);
        $gatewayService->ensureSettings();

        $model = new DashboardModel($this->db);
        $settings = $model->settings();

        $categories = $model->categories();
        $leagues = $model->leagues();
        $games = $lockService->annotateGames($model->adminGames());
        $resultGamesToday = $model->adminResultGamesToday();
        $teams = $model->teams();
        $cleanupSummary = (new AdminCleanupService($this->db))->summary();
        $lockSummary = $lockService->summary();
        $lockSettings = $lockService->settings();
        $lockGames = $lockService->annotateGames($model->lockableGames());
        $agentSummary = $agentService->summary();
        $managers = $agentService->managers();
        $bookmakers = $agentService->bookmakers();
        $managerOptions = $agentService->activeManagers();

        $agentCashFilters = [
            'agent_id' => (int) ($_GET['agent_cash_agent_id'] ?? 0),
            'role' => (string) ($_GET['agent_cash_role'] ?? 'all'),
            'status' => (string) ($_GET['agent_cash_status'] ?? 'all'),
            'entry_type' => (string) ($_GET['agent_cash_entry_type'] ?? 'all'),
            'date_from' => trim((string) ($_GET['agent_cash_date_from'] ?? '')),
            'date_to' => trim((string) ($_GET['agent_cash_date_to'] ?? '')),
        ];
        $agentCashEntries = $agentService->adminCashEntries($agentCashFilters);

        $editCategoryId = (int) ($_GET['edit_category'] ?? 0);
        $editLeagueId = (int) ($_GET['edit_league'] ?? 0);
        $editGameId = (int) ($_GET['edit_game'] ?? 0);
        $editTeamId = (int) ($_GET['edit_team'] ?? 0);
        $editManagerId = (int) ($_GET['edit_manager'] ?? 0);
        $editBookmakerId = (int) ($_GET['edit_bookmaker'] ?? 0);
        $ticketId = (int) ($_GET['ticket'] ?? 0);
        $ticketDateFrom = trim((string) ($_GET['ticket_date_from'] ?? date('Y-m-d')));
        $ticketDateTo = trim((string) ($_GET['ticket_date_to'] ?? date('Y-m-d')));

        $editCategory = $this->findById($categories, $editCategoryId);
        $editLeague = $this->findById($leagues, $editLeagueId);
        $editGame = $editGameId > 0 ? $model->adminGameById($editGameId) : null;
        $editTeam = $this->findById($teams, $editTeamId);
        $editManager = $editManagerId > 0 ? $agentService->agentById($editManagerId) : null;
        $editBookmaker = $editBookmakerId > 0 ? $agentService->agentById($editBookmakerId) : null;
        $selectedTicket = $ticketId > 0 ? $model->adminTicketById($ticketId) : null;

        $this->view->render('admin/dashboard', [
            'user' => $user,
            'stats' => $model->stats(),
            'overviewSummary' => $model->adminOverviewSummary(),
            'overviewDaily' => $model->adminOverviewDaily(7),
            'bets' => $model->recentBets($ticketDateFrom, $ticketDateTo),
            'settlements' => $model->settlements(),
            'settings' => $settings,
            'gatewaySummary' => $gatewayService->gatewaySummary(),
            'categories' => $categories,
            'leagues' => $leagues,
            'teams' => $teams,
            'games' => $games,
            'managers' => $managers,
            'bookmakers' => $bookmakers,
            'managerOptions' => $managerOptions,
            'agentSummary' => $agentSummary,
            'agentCashEntries' => $agentCashEntries,
            'agentCashFilters' => $agentCashFilters,
            'lockGames' => $lockGames,
            'lockSummary' => $lockSummary,
            'lockSettings' => $lockSettings,
            'cleanupSummary' => $cleanupSummary,
            'resultGamesToday' => $resultGamesToday,
            'selectedTicket' => $selectedTicket,
            'ticketDateFrom' => $ticketDateFrom,
            'ticketDateTo' => $ticketDateTo,
            'editCategory' => $editCategory,
            'editLeague' => $editLeague,
            'editGame' => $editGame,
            'editTeam' => $editTeam,
            'editManager' => $editManager && ($editManager['role'] ?? '') === 'manager' ? $editManager : null,
            'editBookmaker' => $editBookmaker && ($editBookmaker['role'] ?? '') === 'bookmaker' ? $editBookmaker : null,
            'flash' => $_SESSION['flash'] ?? null,
            'theme' => in_array((string) ($settings['site_theme'] ?? 'classic'), ['classic', 'neo', 'sportsbook'], true)
                ? (string) ($settings['site_theme'] ?? 'classic')
                : 'classic',
        ]);

        unset($_SESSION['flash']);
    }

    public function saveCategory(): void
    {
        $this->auth->requireAdmin();

        $categoryId = (int) ($_POST['category_id'] ?? 0);
        $name = trim((string) ($_POST['name'] ?? ''));
        $slug = strtolower(trim((string) ($_POST['slug'] ?? '')));
        $slug = preg_replace('/[^a-z0-9\-]/', '-', $slug);
        $slug = trim((string) preg_replace('/-+/', '-', $slug), '-');
        $sortOrder = (int) ($_POST['sort_order'] ?? 0);
        $isActive = isset($_POST['is_active']) ? 1 : 0;

        if ($name === '' || $slug === '') {
            $_SESSION['flash'] = ['type' => 'error', 'message' => 'Nome e slug da categoria sao obrigatorios.'];
            $this->redirect('/admin#categorias');
        }

        if ($categoryId > 0) {
            $stmt = $this->db->pdo()->prepare('UPDATE categories SET name = :name, slug = :slug, is_active = :is_active, sort_order = :sort_order WHERE id = :id');
            $stmt->execute([
                'name' => $name,
                'slug' => $slug,
                'is_active' => $isActive,
                'sort_order' => $sortOrder,
                'id' => $categoryId,
            ]);
            $_SESSION['flash'] = ['type' => 'success', 'message' => 'Categoria atualizada com sucesso.'];
        } else {
            $stmt = $this->db->pdo()->prepare('INSERT INTO categories (name, slug, is_active, sort_order) VALUES (:name, :slug, :is_active, :sort_order)');
            $stmt->execute([
                'name' => $name,
                'slug' => $slug,
                'is_active' => $isActive,
                'sort_order' => $sortOrder,
            ]);
            $_SESSION['flash'] = ['type' => 'success', 'message' => 'Categoria salva com sucesso.'];
        }

        $this->redirect('/admin#categorias');
    }

    public function deleteCategory(): void
    {
        $this->auth->requireAdmin();
        $categoryId = (int) ($_POST['category_id'] ?? 0);

        if ($categoryId <= 0) {
            $_SESSION['flash'] = ['type' => 'error', 'message' => 'Categoria invalida.'];
            $this->redirect('/admin#categorias');
        }

        $stmtCount = $this->db->pdo()->prepare('SELECT COUNT(*) FROM leagues WHERE category_id = :id');
        $stmtCount->execute(['id' => $categoryId]);
        if ((int) $stmtCount->fetchColumn() > 0) {
            $_SESSION['flash'] = ['type' => 'error', 'message' => 'Nao e possivel deletar categoria com ligas vinculadas.'];
            $this->redirect('/admin#categorias');
        }

        try {
            $stmt = $this->db->pdo()->prepare('DELETE FROM categories WHERE id = :id');
            $stmt->execute(['id' => $categoryId]);
            $_SESSION['flash'] = ['type' => 'success', 'message' => 'Categoria removida com sucesso.'];
        } catch (Throwable $exception) {
            $_SESSION['flash'] = ['type' => 'error', 'message' => 'Erro ao deletar categoria.'];
        }

        $this->redirect('/admin#categorias');
    }

    public function saveLeague(): void
    {
        $this->auth->requireAdmin();

        $leagueId = (int) ($_POST['league_id'] ?? 0);
        $name = trim((string) ($_POST['name'] ?? ''));
        $countryCode = strtolower(trim((string) ($_POST['country_code'] ?? '')));
        $categoryId = (int) ($_POST['category_id'] ?? 0);

        if ($name === '' || $countryCode === '' || $categoryId <= 0) {
            $_SESSION['flash'] = ['type' => 'error', 'message' => 'Preencha categoria, nome e pais da liga.'];
            $this->redirect('/admin#ligas');
        }

        if ($leagueId > 0) {
            $stmt = $this->db->pdo()->prepare('UPDATE leagues SET category_id = :category_id, name = :name, country_code = :country_code WHERE id = :id');
            $stmt->execute([
                'category_id' => $categoryId,
                'name' => $name,
                'country_code' => $countryCode,
                'id' => $leagueId,
            ]);
            $_SESSION['flash'] = ['type' => 'success', 'message' => 'Liga atualizada com sucesso.'];
        } else {
            $stmt = $this->db->pdo()->prepare('INSERT INTO leagues (category_id, name, country_code) VALUES (:category_id, :name, :country_code)');
            $stmt->execute([
                'category_id' => $categoryId,
                'name' => $name,
                'country_code' => $countryCode,
            ]);
            $_SESSION['flash'] = ['type' => 'success', 'message' => 'Liga cadastrada com sucesso.'];
        }

        $this->redirect('/admin#ligas');
    }

    public function deleteLeague(): void
    {
        $this->auth->requireAdmin();
        $leagueId = (int) ($_POST['league_id'] ?? 0);

        if ($leagueId <= 0) {
            $_SESSION['flash'] = ['type' => 'error', 'message' => 'Liga invalida.'];
            $this->redirect('/admin#ligas');
        }

        $stmtCount = $this->db->pdo()->prepare('SELECT COUNT(*) FROM games WHERE league_id = :id');
        $stmtCount->execute(['id' => $leagueId]);
        if ((int) $stmtCount->fetchColumn() > 0) {
            $_SESSION['flash'] = ['type' => 'error', 'message' => 'Nao e possivel deletar liga com jogos vinculados.'];
            $this->redirect('/admin#ligas');
        }

        try {
            $stmt = $this->db->pdo()->prepare('DELETE FROM leagues WHERE id = :id');
            $stmt->execute(['id' => $leagueId]);
            $_SESSION['flash'] = ['type' => 'success', 'message' => 'Liga removida com sucesso.'];
        } catch (Throwable $exception) {
            $_SESSION['flash'] = ['type' => 'error', 'message' => 'Erro ao deletar liga.'];
        }

        $this->redirect('/admin#ligas');
    }

    public function saveTeam(): void
    {
        $this->auth->requireAdmin();

        $teamId = (int) ($_POST['team_id'] ?? 0);
        $name = trim((string) ($_POST['name'] ?? ''));
        $logo = trim((string) ($_POST['logo'] ?? ''));

        if ($name === '') {
            $_SESSION['flash'] = ['type' => 'error', 'message' => 'Nome do time e obrigatorio.'];
            $this->redirect('/admin#times');
        }

        if ($teamId > 0) {
            $stmt = $this->db->pdo()->prepare('UPDATE teams SET name = :name, logo = :logo WHERE id = :id');
            $stmt->execute([
                'name' => $name,
                'logo' => $logo !== '' ? $logo : null,
                'id' => $teamId,
            ]);
            $_SESSION['flash'] = ['type' => 'success', 'message' => 'Time atualizado com sucesso.'];
        } else {
            $stmt = $this->db->pdo()->prepare('INSERT INTO teams (name, logo) VALUES (:name, :logo)');
            $stmt->execute([
                'name' => $name,
                'logo' => $logo !== '' ? $logo : null,
            ]);
            $_SESSION['flash'] = ['type' => 'success', 'message' => 'Time cadastrado com sucesso.'];
        }

        $this->redirect('/admin#times');
    }

    public function deleteTeam(): void
    {
        $this->auth->requireAdmin();
        $teamId = (int) ($_POST['team_id'] ?? 0);

        if ($teamId <= 0) {
            $_SESSION['flash'] = ['type' => 'error', 'message' => 'Time invalido.'];
            $this->redirect('/admin#times');
        }

        $stmtCount = $this->db->pdo()->prepare('SELECT COUNT(*) FROM games WHERE home_team_id = :id OR away_team_id = :id');
        $stmtCount->execute(['id' => $teamId]);
        if ((int) $stmtCount->fetchColumn() > 0) {
            $_SESSION['flash'] = ['type' => 'error', 'message' => 'Nao e possivel deletar time vinculado a jogos.'];
            $this->redirect('/admin#times');
        }

        try {
            $stmt = $this->db->pdo()->prepare('DELETE FROM teams WHERE id = :id');
            $stmt->execute(['id' => $teamId]);
            $_SESSION['flash'] = ['type' => 'success', 'message' => 'Time removido com sucesso.'];
        } catch (Throwable $exception) {
            $_SESSION['flash'] = ['type' => 'error', 'message' => 'Erro ao deletar time.'];
        }

        $this->redirect('/admin#times');
    }

    public function saveGame(): void
    {
        $admin = $this->auth->requireAdmin();

        $gameId = (int) ($_POST['game_id'] ?? 0);
        $leagueId = (int) ($_POST['league_id'] ?? 0);
        $homeTeamId = (int) ($_POST['home_team_id'] ?? 0);
        $awayTeamId = (int) ($_POST['away_team_id'] ?? 0);
        $sport = trim((string) ($_POST['sport'] ?? ''));
        $matchDate = trim((string) ($_POST['match_date'] ?? ''));
        $riskLevel = trim((string) ($_POST['risk_level'] ?? 'medium'));

        if ($leagueId <= 0 || $homeTeamId <= 0 || $awayTeamId <= 0 || $homeTeamId === $awayTeamId || $sport === '' || $matchDate === '') {
            $_SESSION['flash'] = ['type' => 'error', 'message' => 'Dados invalidos para cadastrar o jogo.'];
            $this->redirect('/admin#jogos');
        }

        $pdo = $this->db->pdo();

        if ($gameId > 0) {
            $stmt = $pdo->prepare('UPDATE games SET league_id = :league_id, sport = :sport, home_team_id = :home_team_id, away_team_id = :away_team_id, match_date = :match_date, risk_level = :risk_level WHERE id = :id');
            $stmt->execute([
                'league_id' => $leagueId,
                'sport' => $sport,
                'home_team_id' => $homeTeamId,
                'away_team_id' => $awayTeamId,
                'match_date' => $matchDate,
                'risk_level' => $riskLevel,
                'id' => $gameId,
            ]);
        } else {
            $stmt = $pdo->prepare(
                'INSERT INTO games (league_id, sport, home_team_id, away_team_id, match_date, status, risk_level, betting_only_before_start, game_origin, created_by_user_id, created_at)
                 VALUES (:league_id, :sport, :home_team_id, :away_team_id, :match_date, "scheduled", :risk_level, 1, "admin", :created_by_user_id, NOW())'
            );
            $stmt->execute([
                'league_id' => $leagueId,
                'sport' => $sport,
                'home_team_id' => $homeTeamId,
                'away_team_id' => $awayTeamId,
                'match_date' => $matchDate,
                'risk_level' => $riskLevel,
                'created_by_user_id' => (int) $admin['id'],
            ]);
            $gameId = (int) $pdo->lastInsertId();
        }

        $pdo->beginTransaction();
        try {
            $this->upsertOdd($gameId, 'Resultado final', 'Casa', (float) ($_POST['odd_home'] ?? 1.50));
            $this->upsertOdd($gameId, 'Resultado final', 'Empate', (float) ($_POST['odd_draw'] ?? 3.00));
            $this->upsertOdd($gameId, 'Resultado final', 'Fora', (float) ($_POST['odd_away'] ?? 2.50));

            $pdo->prepare('DELETE FROM odds WHERE game_id = :game_id AND market_name <> :market_name')->execute([
                'game_id' => $gameId,
                'market_name' => 'Resultado final',
            ]);

            $extraMarketNames = $_POST['extra_market_name'] ?? [];
            $extraOptionNames = $_POST['extra_option_name'] ?? [];
            $extraOddValues = $_POST['extra_odd_value'] ?? [];

            if (is_array($extraMarketNames) && is_array($extraOptionNames) && is_array($extraOddValues)) {
                $total = max(count($extraMarketNames), count($extraOptionNames), count($extraOddValues));
                for ($index = 0; $index < $total; $index++) {
                    $marketName = trim((string) ($extraMarketNames[$index] ?? ''));
                    $optionName = trim((string) ($extraOptionNames[$index] ?? ''));
                    $oddValue = (float) ($extraOddValues[$index] ?? 0);

                    if ($marketName === '' || $optionName === '' || $oddValue <= 0) {
                        continue;
                    }

                    $this->upsertOdd($gameId, $marketName, $optionName, $oddValue);
                }
            }

            $this->cleanupInvalidGameResults($gameId);
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $_SESSION['flash'] = ['type' => 'error', 'message' => 'Erro ao salvar mercados e odds do jogo.'];
            $this->redirect('/admin#jogos');
        }

        $this->syncGameStatusFromResults($gameId);
        $_SESSION['flash'] = ['type' => 'success', 'message' => 'Jogo salvo com sucesso.'];
        $this->redirect('/admin#jogos');
    }

    public function saveGameVisibility(): void
    {
        $this->auth->requireAdmin();

        $gameId = (int) ($_POST['game_id'] ?? 0);
        $visible = (int) ($_POST['is_visible'] ?? 1) === 1 ? 1 : 0;

        if ($gameId <= 0) {
            $_SESSION['flash'] = ['type' => 'error', 'message' => 'Jogo invalido para alterar visibilidade.'];
            $this->redirect('/admin#jogos');
        }

        $exists = $this->db->pdo()->query('SHOW COLUMNS FROM games LIKE "is_visible"')->fetch();
        if ($exists === false) {
            $this->db->pdo()->exec('ALTER TABLE games ADD COLUMN is_visible TINYINT(1) NOT NULL DEFAULT 1 AFTER risk_level');
            $this->db->pdo()->exec('UPDATE games SET is_visible = 1 WHERE is_visible IS NULL');
        }

        $stmt = $this->db->pdo()->prepare('UPDATE games SET is_visible = :is_visible WHERE id = :id AND game_origin = "admin"');
        $stmt->execute([
            'is_visible' => $visible,
            'id' => $gameId,
        ]);

        $_SESSION['flash'] = [
            'type' => 'success',
            'message' => $visible === 1 ? 'Jogo ativado e visivel no sistema.' : 'Jogo desativado e ocultado no sistema.',
        ];
        $this->redirect('/admin#jogos');
    }

    public function saveGameMainOdds(): void
    {
        $this->auth->requireAdmin();

        $gameId = (int) ($_POST['game_id'] ?? 0);
        $oddHome = (float) ($_POST['odd_home'] ?? 0);
        $oddDraw = (float) ($_POST['odd_draw'] ?? 0);
        $oddAway = (float) ($_POST['odd_away'] ?? 0);

        if ($gameId <= 0 || $oddHome <= 1 || $oddDraw <= 1 || $oddAway <= 1) {
            $_SESSION['flash'] = ['type' => 'error', 'message' => 'Informe odds validas (maiores que 1.00).'];
            $this->redirect('/admin#jogos');
        }

        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $this->upsertOdd($gameId, 'Resultado final', 'Casa', round($oddHome, 2));
            $this->upsertOdd($gameId, 'Resultado final', 'Empate', round($oddDraw, 2));
            $this->upsertOdd($gameId, 'Resultado final', 'Fora', round($oddAway, 2));
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $_SESSION['flash'] = ['type' => 'error', 'message' => 'Erro ao salvar odds do jogo.'];
            $this->redirect('/admin#jogos');
        }

        $_SESSION['flash'] = ['type' => 'success', 'message' => 'Odds do jogo atualizadas com sucesso.'];
        $this->redirect('/admin#jogos');
    }

    public function saveGlobalLock(): void
    {
        $this->auth->requireAdmin();

        $lockService = new BettingLockService($this->db);
        $lockService->ensureSchema();
        $enabled = (int) ($_POST['global_lock'] ?? 0) === 1;

        try {
            $lockService->setGlobalLock($enabled);
            $_SESSION['flash'] = [
                'type' => 'success',
                'message' => $enabled ? 'Todos os jogos foram travados para apostas.' : 'A trava global foi removida.',
            ];
        } catch (Throwable $exception) {
            $_SESSION['flash'] = [
                'type' => 'error',
                'message' => 'Erro ao atualizar a trava global: ' . mb_substr((string) $exception->getMessage(), 0, 220),
            ];
        }

        $this->redirect('/admin#trava');
    }

    public function saveManualGameLock(): void
    {
        $this->auth->requireAdmin();

        $gameId = (int) ($_POST['game_id'] ?? 0);
        $manualLock = (int) ($_POST['manual_lock'] ?? 0) === 1;

        if ($gameId <= 0) {
            $_SESSION['flash'] = ['type' => 'error', 'message' => 'Selecione um jogo valido para a trava manual.'];
            $this->redirect('/admin#trava');
        }

        $lockService = new BettingLockService($this->db);
        $lockService->ensureSchema();

        try {
            $lockService->setManualGameLock($gameId, $manualLock);
            $_SESSION['flash'] = [
                'type' => 'success',
                'message' => $manualLock ? 'Trava manual ativada para o jogo selecionado.' : 'Trava manual removida do jogo selecionado.',
            ];
        } catch (Throwable $exception) {
            $_SESSION['flash'] = [
                'type' => 'error',
                'message' => 'Erro ao salvar a trava manual: ' . mb_substr((string) $exception->getMessage(), 0, 220),
            ];
        }

        $this->redirect('/admin#trava');
    }

    public function saveGameLockWindow(): void
    {
        $this->auth->requireAdmin();

        $gameId = (int) ($_POST['game_id'] ?? 0);
        $onlyBeforeStart = isset($_POST['only_before_start']);
        $lockAfterMinutes = (int) ($_POST['lock_after_minutes'] ?? 0);

        if ($gameId <= 0) {
            $_SESSION['flash'] = ['type' => 'error', 'message' => 'Selecione um jogo valido para a trava por horario.'];
            $this->redirect('/admin#trava');
        }

        $lockService = new BettingLockService($this->db);
        $lockService->ensureSchema();

        try {
            $lockService->configureGameWindow($gameId, $onlyBeforeStart, $lockAfterMinutes > 0 ? $lockAfterMinutes : null);
            $_SESSION['flash'] = [
                'type' => 'success',
                'message' => 'Janela de aposta do jogo atualizada com sucesso.',
            ];
        } catch (Throwable $exception) {
            $_SESSION['flash'] = [
                'type' => 'error',
                'message' => 'Erro ao salvar a trava por horario: ' . mb_substr((string) $exception->getMessage(), 0, 220),
            ];
        }

        $this->redirect('/admin#trava');
    }

    public function clearAllLocks(): void
    {
        $this->auth->requireAdmin();

        $lockService = new BettingLockService($this->db);
        $lockService->ensureSchema();

        try {
            $result = $lockService->clearAllLocks();
            $_SESSION['flash'] = [
                'type' => 'success',
                'message' => 'Todas as travas foram removidas. Trava global desligada: '
                    . ($result['global_unlocked'] ? 'sim' : 'nao')
                    . '. Jogos com regras limpas: ' . $result['cleared_games'] . '.',
            ];
        } catch (Throwable $exception) {
            $_SESSION['flash'] = [
                'type' => 'error',
                'message' => 'Erro ao destravar todos os jogos: ' . mb_substr((string) $exception->getMessage(), 0, 220),
            ];
        }

        $this->redirect('/admin#trava');
    }

    public function saveGameResult(): void
    {
        $this->auth->requireAdmin();
        $this->ensureGameResultsTable();
        $lockService = new BettingLockService($this->db);
        $lockService->ensureSchema();

        $gameId = (int) ($_POST['game_id'] ?? 0);
        $marketName = trim((string) ($_POST['market_name'] ?? ''));
        $resultOption = trim((string) ($_POST['result_option'] ?? ''));

        if ($gameId <= 0 || $marketName === '' || $resultOption === '') {
            $_SESSION['flash'] = ['type' => 'error', 'message' => 'Informe jogo, mercado e resultado.'];
            $this->redirect('/admin#jogos');
        }

        if (!$this->isValidResultForGame($gameId, $marketName, $resultOption)) {
            $_SESSION['flash'] = ['type' => 'error', 'message' => 'Resultado invalido para este mercado.'];
            $this->redirect('/admin#jogos');
        }

        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $pdo->prepare('INSERT INTO game_results (game_id, market_name, result_option, settled_at) VALUES (:game_id, :market_name, :result_option, NOW()) ON DUPLICATE KEY UPDATE result_option = VALUES(result_option), settled_at = NOW()')->execute([
                'game_id' => $gameId,
                'market_name' => $marketName,
                'result_option' => $resultOption,
            ]);

            $this->lockGameForManualResult($gameId);
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $_SESSION['flash'] = [
                'type' => 'error',
                'message' => 'Erro ao salvar o resultado do mercado: ' . mb_substr((string) $exception->getMessage(), 0, 220),
            ];
            $this->redirect('/admin#jogos');
        }

        $this->syncGameStatusFromResults($gameId);
        $this->settleOpenTickets();

        $_SESSION['flash'] = ['type' => 'success', 'message' => 'Resultado do mercado salvo e bilhetes atualizados.'];
        $this->redirect('/admin#jogos');
    }

    public function saveGameResultsBulk(): void
    {
        $this->auth->requireAdmin();
        $this->ensureGameResultsTable();
        $lockService = new BettingLockService($this->db);
        $lockService->ensureSchema();

        $gameId = (int) ($_POST['game_id'] ?? 0);
        $marketNames = $_POST['result_market_name'] ?? [];
        $resultValues = $_POST['result_option_value'] ?? [];

        if ($gameId <= 0 || !is_array($marketNames) || !is_array($resultValues)) {
            $_SESSION['flash'] = ['type' => 'error', 'message' => 'Dados invalidos para salvar os resultados do jogo.'];
            $this->redirect('/admin#jogos');
        }

        $validOptions = $this->marketOptionsByGame($gameId);
        if ($validOptions === []) {
            $_SESSION['flash'] = ['type' => 'error', 'message' => 'Esse jogo nao possui mercados ativos para liquidacao manual.'];
            $this->redirect('/admin#jogos');
        }

        $submitted = [];
        $total = max(count($marketNames), count($resultValues));
        for ($index = 0; $index < $total; $index++) {
            $marketName = trim((string) ($marketNames[$index] ?? ''));
            if ($marketName === '' || !isset($validOptions[$marketName])) {
                continue;
            }

            $submitted[$marketName] = trim((string) ($resultValues[$index] ?? ''));
        }

        if ($submitted === []) {
            $_SESSION['flash'] = ['type' => 'error', 'message' => 'Nenhum mercado valido foi enviado para salvar.'];
            $this->redirect('/admin#jogos');
        }

        $pdo = $this->db->pdo();
        $upsertStmt = $pdo->prepare(
            'INSERT INTO game_results (game_id, market_name, result_option, settled_at)
             VALUES (:game_id, :market_name, :result_option, NOW())
             ON DUPLICATE KEY UPDATE result_option = VALUES(result_option), settled_at = NOW()'
        );
        $deleteStmt = $pdo->prepare('DELETE FROM game_results WHERE game_id = :game_id AND market_name = :market_name');

        $savedCount = 0;
        $clearedCount = 0;

        $pdo->beginTransaction();
        try {
            foreach ($submitted as $marketName => $resultOption) {
                if ($resultOption === '') {
                    $deleteStmt->execute([
                        'game_id' => $gameId,
                        'market_name' => $marketName,
                    ]);
                    if ($deleteStmt->rowCount() > 0) {
                        $clearedCount++;
                    }
                    continue;
                }

                if (!isset($validOptions[$marketName][$resultOption])) {
                    throw new \RuntimeException('Resultado invalido para o mercado "' . $marketName . '".');
                }

                $upsertStmt->execute([
                    'game_id' => $gameId,
                    'market_name' => $marketName,
                    'result_option' => $resultOption,
                ]);
                $savedCount++;
            }

            $this->cleanupInvalidGameResults($gameId);
            $this->lockGameForManualResult($gameId);
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $_SESSION['flash'] = [
                'type' => 'error',
                'message' => 'Erro ao salvar os resultados do jogo: ' . mb_substr((string) $exception->getMessage(), 0, 220),
            ];
            $this->redirect('/admin#jogos');
        }

        $this->syncGameStatusFromResults($gameId);
        $this->settleOpenTickets();

        $_SESSION['flash'] = [
            'type' => 'success',
            'message' => 'Resultados atualizados. Mercados salvos: ' . $savedCount . '. Mercados limpos: ' . $clearedCount . '.',
        ];
        $this->redirect('/admin#jogos');
    }

    public function deleteGame(): void
    {
        $this->auth->requireAdmin();
        $gameId = (int) ($_POST['game_id'] ?? 0);

        if ($gameId <= 0) {
            $_SESSION['flash'] = ['type' => 'error', 'message' => 'Jogo invalido.'];
            $this->redirect('/admin#jogos');
        }

        $stmtCount = $this->db->pdo()->prepare('SELECT COUNT(*) FROM bet_ticket_items WHERE game_id = :id');
        $stmtCount->execute(['id' => $gameId]);
        if ((int) $stmtCount->fetchColumn() > 0) {
            $_SESSION['flash'] = ['type' => 'error', 'message' => 'Nao e possivel deletar jogo com bilhetes vinculados.'];
            $this->redirect('/admin#jogos');
        }

        try {
            $pdo = $this->db->pdo();
            $pdo->beginTransaction();
            $pdo->prepare('DELETE FROM game_results WHERE game_id = :id')->execute(['id' => $gameId]);
            $pdo->prepare('DELETE FROM odds WHERE game_id = :id')->execute(['id' => $gameId]);
            $pdo->prepare('DELETE FROM games WHERE id = :id')->execute(['id' => $gameId]);
            $pdo->commit();
            $_SESSION['flash'] = ['type' => 'success', 'message' => 'Jogo removido com sucesso.'];
        } catch (Throwable $exception) {
            if ($this->db->pdo()->inTransaction()) {
                $this->db->pdo()->rollBack();
            }
            $_SESSION['flash'] = ['type' => 'error', 'message' => 'Erro ao deletar jogo.'];
        }

        $this->redirect('/admin#jogos');
    }

    public function saveSettings(): void
    {
        $this->auth->requireAdmin();
        (new PixGatewayService($this->db))->ensureSettings();

        $siteTheme = (string) ($_POST['site_theme'] ?? 'classic');
        if (!in_array($siteTheme, ['classic', 'neo', 'sportsbook'], true)) {
            $siteTheme = 'classic';
        }
        $ecompagEnabled = isset($_POST['pix_gateway_ecompag_enabled']);
        $ggpixEnabled = isset($_POST['pix_gateway_ggpix_enabled']);

        if (!$ecompagEnabled && !$ggpixEnabled) {
            $_SESSION['flash'] = ['type' => 'error', 'message' => 'Deixe pelo menos um gateway Pix ativo.'];
            $this->redirect('/admin#configuracoes');
        }

        $fields = [
            'site_theme' => $siteTheme,
            'pix_gateway_default' => in_array((string) ($_POST['pix_gateway_default'] ?? 'ecompag'), ['ecompag', 'ggpix'], true) ? (string) $_POST['pix_gateway_default'] : 'ecompag',
            'pix_gateway_ecompag_enabled' => $ecompagEnabled ? '1' : '0',
            'pix_gateway_ggpix_enabled' => $ggpixEnabled ? '1' : '0',
            'ecompag_client_id' => trim((string) ($_POST['ecompag_client_id'] ?? '')),
            'ecompag_client_secret' => trim((string) ($_POST['ecompag_client_secret'] ?? '')),
            'ecompag_webhook_url' => trim((string) ($_POST['ecompag_webhook_url'] ?? '')),
            'ggpix_api_key' => trim((string) ($_POST['ggpix_api_key'] ?? '')),
            'ggpix_webhook_url' => trim((string) ($_POST['ggpix_webhook_url'] ?? '')),
            'the_odds_api_key' => trim((string) ($_POST['the_odds_api_key'] ?? '')),
            'the_odds_api_regions' => trim((string) ($_POST['the_odds_api_regions'] ?? '')),
            'the_odds_api_bookmakers' => trim((string) ($_POST['the_odds_api_bookmakers'] ?? '')),
            'the_odds_api_sport_keys' => trim((string) ($_POST['the_odds_api_sport_keys'] ?? '')),
            'risk_limit' => trim((string) ($_POST['risk_limit'] ?? '')),
        ];

        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO settings (setting_key, setting_value)
             VALUES (:setting_key, :setting_value)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
        );

        foreach ($fields as $key => $value) {
            $stmt->execute([
                'setting_key' => $key,
                'setting_value' => $value,
            ]);
        }

        $_SESSION['flash'] = ['type' => 'success', 'message' => 'Configuracoes atualizadas.'];
        $this->redirect('/admin#configuracoes');
    }

    public function saveSyncSettings(): void
    {
        $this->auth->requireAdmin();

        $visibility = (string) ($_POST['sync_import_visibility'] ?? 'visible');
        $visibility = in_array($visibility, ['visible', 'hidden'], true) ? $visibility : 'visible';

        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO settings (setting_key, setting_value)
             VALUES (:setting_key, :setting_value)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
        );

        $stmt->execute([
            'setting_key' => 'sync_import_visibility',
            'setting_value' => $visibility,
        ]);

        $_SESSION['flash'] = [
            'type' => 'success',
            'message' => $visibility === 'hidden'
                ? 'Visibilidade dos syncs atualizada: jogos sincronizados entram ocultos no sistema.'
                : 'Visibilidade dos syncs atualizada: jogos sincronizados entram visiveis no sistema.',
        ];

        $this->redirect('/admin#api');
    }

    public function saveManager(): void
    {
        $this->auth->requireAdmin();
        $agentService = new AgentService($this->db);

        try {
            $managerId = (int) ($_POST['agent_id'] ?? 0);
            $agentService->saveAgent([
                'role' => 'manager',
                'name' => (string) ($_POST['name'] ?? ''),
                'email' => (string) ($_POST['email'] ?? ''),
                'cpf' => (string) ($_POST['cpf'] ?? ''),
                'password' => (string) ($_POST['password'] ?? ''),
                'is_active' => isset($_POST['is_active']),
                'commission_rate' => (float) ($_POST['commission_rate'] ?? 0),
                'pix_checkout_mode' => (string) ($_POST['pix_checkout_mode'] ?? 'gateway'),
                'pix_key' => (string) ($_POST['pix_key'] ?? ''),
                'pix_qr_code' => (string) ($_POST['pix_qr_code'] ?? ''),
            ], $managerId > 0 ? $managerId : null);

            $_SESSION['flash'] = ['type' => 'success', 'message' => $managerId > 0 ? 'Gerente atualizado com sucesso.' : 'Gerente criado com sucesso.'];
        } catch (Throwable $exception) {
            $_SESSION['flash'] = ['type' => 'error', 'message' => 'Erro ao salvar gerente: ' . mb_substr((string) $exception->getMessage(), 0, 220)];
        }

        $this->redirect('/admin#gerentes');
    }

    public function saveBookmaker(): void
    {
        $this->auth->requireAdmin();
        $agentService = new AgentService($this->db);

        try {
            $bookmakerId = (int) ($_POST['agent_id'] ?? 0);
            $agentService->saveAgent([
                'role' => 'bookmaker',
                'name' => (string) ($_POST['name'] ?? ''),
                'email' => (string) ($_POST['email'] ?? ''),
                'cpf' => (string) ($_POST['cpf'] ?? ''),
                'password' => (string) ($_POST['password'] ?? ''),
                'manager_user_id' => (int) ($_POST['manager_user_id'] ?? 0),
                'is_active' => isset($_POST['is_active']),
                'commission_rate' => (float) ($_POST['commission_rate'] ?? 0),
                'pix_checkout_mode' => (string) ($_POST['pix_checkout_mode'] ?? 'gateway'),
                'pix_key' => (string) ($_POST['pix_key'] ?? ''),
                'pix_qr_code' => (string) ($_POST['pix_qr_code'] ?? ''),
            ], $bookmakerId > 0 ? $bookmakerId : null);

            $_SESSION['flash'] = ['type' => 'success', 'message' => $bookmakerId > 0 ? 'Cambista atualizado com sucesso.' : 'Cambista criado com sucesso.'];
        } catch (Throwable $exception) {
            $_SESSION['flash'] = ['type' => 'error', 'message' => 'Erro ao salvar cambista: ' . mb_substr((string) $exception->getMessage(), 0, 220)];
        }

        $this->redirect('/admin#cambistas');
    }

    public function deleteAgent(): void
    {
        $this->auth->requireAdmin();
        $agentId = (int) ($_POST['agent_id'] ?? 0);

        try {
            (new AgentService($this->db))->deleteAgent($agentId);
            $_SESSION['flash'] = ['type' => 'success', 'message' => 'Agente removido com sucesso.'];
        } catch (Throwable $exception) {
            $_SESSION['flash'] = ['type' => 'error', 'message' => 'Erro ao remover agente: ' . mb_substr((string) $exception->getMessage(), 0, 220)];
        }

        $section = ((string) ($_POST['agent_role'] ?? '')) === 'manager' ? 'gerentes' : 'cambistas';
        $this->redirect('/admin#' . $section);
    }

    public function adjustAgentBalance(): void
    {
        $admin = $this->auth->requireAdmin();
        $agentId = (int) ($_POST['agent_id'] ?? 0);
        $amount = (float) ($_POST['amount'] ?? 0);
        $description = trim((string) ($_POST['description'] ?? ''));

        try {
            (new AgentService($this->db))->adjustBalance($agentId, $amount, (int) $admin['id'], $description);
            $_SESSION['flash'] = ['type' => 'success', 'message' => 'Saldo do agente ajustado com sucesso.'];
        } catch (Throwable $exception) {
            $_SESSION['flash'] = ['type' => 'error', 'message' => 'Erro ao ajustar saldo do agente: ' . mb_substr((string) $exception->getMessage(), 0, 220)];
        }

        $this->redirect('/admin#caixa-agentes');
    }

    public function syncGamesFromXscores(): void
    {
        $admin = $this->auth->requireAdmin();
        $limit = $this->syncLimit();
        $filters = $this->syncOptions();
        $syncService = new AdminGameSyncService($this->db);

        try {
            $stats = $syncService->syncXscores($limit, (int) $admin['id'], $filters);
            $_SESSION['flash'] = [
                'type' => 'success',
                'message' => $this->syncMessage('Sincronizacao Xscore', $stats, $filters),
            ];
        } catch (Throwable $exception) {
            $_SESSION['flash'] = [
                'type' => 'error',
                'message' => 'Erro ao sincronizar Xscore: ' . mb_substr((string) $exception->getMessage(), 0, 220),
            ];
        }

        $this->redirect('/admin#jogos');
    }

    public function syncGamesFromSofascore(): void
    {
        $admin = $this->auth->requireAdmin();
        $limit = $this->syncLimit();
        $filters = $this->syncOptions();
        $syncService = new AdminGameSyncService($this->db);

        try {
            $stats = $syncService->syncSofascore($limit, (int) $admin['id'], $filters);
            $_SESSION['flash'] = [
                'type' => 'success',
                'message' => $this->syncMessage('Sincronizacao SofaScore', $stats, $filters),
            ];
        } catch (Throwable $exception) {
            $_SESSION['flash'] = [
                'type' => 'error',
                'message' => 'Erro ao sincronizar SofaScore: ' . mb_substr((string) $exception->getMessage(), 0, 220),
            ];
        }

        $this->redirect('/admin#jogos');
    }

    public function syncGamesFromFlashscore(): void
    {
        $admin = $this->auth->requireAdmin();
        $limit = $this->syncLimit();
        $filters = $this->syncOptions();
        $syncService = new AdminGameSyncService($this->db);

        try {
            $stats = $syncService->syncFlashscore($limit, (int) $admin['id'], $filters);
            $_SESSION['flash'] = [
                'type' => 'success',
                'message' => $this->syncMessage('Sincronizacao Flashscore', $stats, $filters),
            ];
        } catch (Throwable $exception) {
            $_SESSION['flash'] = [
                'type' => 'error',
                'message' => 'Erro ao sincronizar Flashscore: ' . mb_substr((string) $exception->getMessage(), 0, 220),
            ];
        }

        $this->redirect('/admin#jogos');
    }

    public function syncGamesFromBetfair(): void
    {
        $admin = $this->auth->requireAdmin();
        $limit = $this->syncLimit();
        $filters = $this->syncOptions();
        $syncService = new AdminGameSyncService($this->db);

        try {
            $stats = $syncService->syncBetfair($limit, (int) $admin['id'], $filters);
            $_SESSION['flash'] = [
                'type' => 'success',
                'message' => $this->syncMessage('Sincronizacao Betfair', $stats, $filters),
            ];
        } catch (Throwable $exception) {
            $_SESSION['flash'] = [
                'type' => 'error',
                'message' => 'Erro ao sincronizar Betfair: ' . mb_substr((string) $exception->getMessage(), 0, 220),
            ];
        }

        $this->redirect('/admin#jogos');
    }

    public function syncGamesFromOddApi(): void
    {
        $admin = $this->auth->requireAdmin();
        $limit = $this->syncLimit();
        $filters = $this->syncOptions();
        $syncService = new AdminGameSyncService($this->db);

        try {
            $stats = $syncService->syncOddApi($limit, (int) $admin['id'], $filters);
            $_SESSION['flash'] = [
                'type' => 'success',
                'message' => $this->syncMessage('Sincronizacao OddAPI', $stats, $filters),
            ];
        } catch (Throwable $exception) {
            $_SESSION['flash'] = [
                'type' => 'error',
                'message' => 'Erro ao sincronizar OddAPI: ' . mb_substr((string) $exception->getMessage(), 0, 220),
            ];
        }

        $this->redirect('/admin#jogos');
    }

    public function cleanupGames(): void
    {
        $this->auth->requireAdmin();

        $cleanupService = new AdminCleanupService($this->db);
        $preset = trim((string) ($_POST['cleanup_preset'] ?? 'mass'));
        $limit = $this->cleanupLimit();

        $filters = [
            'category_id' => (int) ($_POST['category_id'] ?? 0),
            'status' => $this->cleanupStatus((string) ($_POST['status'] ?? 'all')),
            'source' => $this->cleanupSource((string) ($_POST['source'] ?? 'all')),
            'match_from' => (string) ($_POST['match_from'] ?? ''),
            'match_to' => (string) ($_POST['match_to'] ?? ''),
        ];

        try {
            $result = $cleanupService->cleanupGames($filters, $limit, $preset === 'all_safe');
            $_SESSION['flash'] = [
                'type' => 'success',
                'message' => $this->cleanupMessage($this->cleanupPresetLabel($preset), $result),
            ];
        } catch (Throwable $exception) {
            $_SESSION['flash'] = [
                'type' => 'error',
                'message' => 'Erro na limpeza de jogos: ' . mb_substr((string) $exception->getMessage(), 0, 220),
            ];
        }

        $this->redirect('/admin#limpeza');
    }

    public function cleanupMaintenance(): void
    {
        $this->auth->requireAdmin();

        $cleanupService = new AdminCleanupService($this->db);
        $action = trim((string) ($_POST['cleanup_action'] ?? ''));

        try {
            if ($action === 'orphans') {
                $result = $cleanupService->cleanupOrphans();
                $_SESSION['flash'] = [
                    'type' => 'success',
                    'message' => 'Limpeza estrutural concluida. Ligas removidas: ' . $result['deleted_leagues'] . '. Times removidos: ' . $result['deleted_teams'] . '.',
                ];
            } elseif ($action === 'finished_old') {
                $days = max(1, min(3650, (int) ($_POST['days'] ?? 30)));
                $result = $cleanupService->cleanupFinishedOlderThanDays($days, $this->cleanupLimit());
                $_SESSION['flash'] = [
                    'type' => 'success',
                    'message' => $this->cleanupMessage('Limpeza de jogos finalizados antigos', $result),
                ];
            } elseif ($action === 'without_odds') {
                $result = $cleanupService->cleanupGamesWithoutOdds($this->cleanupLimit());
                $_SESSION['flash'] = [
                    'type' => 'success',
                    'message' => $this->cleanupMessage('Limpeza de jogos sem odds', $result),
                ];
            } elseif ($action === 'locked_games') {
                $result = $cleanupService->cleanupLockedGames($this->cleanupLimit());
                $_SESSION['flash'] = [
                    'type' => 'success',
                    'message' => $this->cleanupMessage('Limpeza de jogos travados', $result),
                ];
            } elseif ($action === 'tickets_settled_old') {
                $days = max(1, min(3650, (int) ($_POST['days'] ?? 30)));
                $result = $cleanupService->cleanupSettledTicketsOlderThanDays($days, $this->cleanupLimit());
                $_SESSION['flash'] = [
                    'type' => 'success',
                    'message' => $this->ticketCleanupMessage('Limpeza de bilhetes finalizados antigos', $result),
                ];
            } elseif ($action === 'tickets_all') {
                $result = $cleanupService->cleanupAllTickets($this->cleanupLimit());
                $_SESSION['flash'] = [
                    'type' => 'success',
                    'message' => $this->ticketCleanupMessage('Limpeza total de bilhetes', $result),
                ];
            } elseif ($action === 'games_with_tickets') {
                $result = $cleanupService->cleanupGamesWithLinkedTickets($this->cleanupLimit());
                $_SESSION['flash'] = [
                    'type' => 'success',
                    'message' => $this->gamesWithTicketsCleanupMessage($result),
                ];
            } elseif ($action === 'gateway_logs') {
                $days = max(1, min(3650, (int) ($_POST['days'] ?? 30)));
                $result = $cleanupService->cleanupGatewayLogsOlderThanDays($days);
                $_SESSION['flash'] = [
                    'type' => 'success',
                    'message' => $this->gatewayLogsCleanupMessage($result),
                ];
            } else {
                throw new \RuntimeException('Acao de limpeza invalida.');
            }
        } catch (Throwable $exception) {
            $_SESSION['flash'] = [
                'type' => 'error',
                'message' => 'Erro na limpeza: ' . mb_substr((string) $exception->getMessage(), 0, 220),
            ];
        }

        $this->redirect('/admin#limpeza');
    }

    private function syncLimit(): int
    {
        $limit = (int) ($_POST['limit'] ?? 200);
        return max(1, min(500, $limit));
    }

    /**
     * @return array{status_scope: string, league_terms: array<int, string>}
     */
    private function syncOptions(): array
    {
        return [
            'status_scope' => $this->syncStatusScope((string) ($_POST['status_scope'] ?? 'scheduled')),
            'league_terms' => $this->syncLeagueTerms((string) ($_POST['league_filters'] ?? '')),
        ];
    }

    private function syncStatusScope(string $statusScope): string
    {
        return in_array($statusScope, ['scheduled', 'live', 'all'], true) ? $statusScope : 'scheduled';
    }

    /**
     * @return array<int, string>
     */
    private function syncLeagueTerms(string $value): array
    {
        $parts = preg_split('/[\r\n,;]+/u', $value) ?: [];
        $terms = [];

        foreach ($parts as $part) {
            $part = trim((string) $part);
            if ($part !== '') {
                $terms[$part] = true;
            }
        }

        return array_keys($terms);
    }

    /**
     * @param array{created: int, updated: int, real_odds: int, source_total: int, matched_total: int, imported_total: int} $stats
     * @param array{status_scope: string, league_terms: array<int, string>} $filters
     */
    private function syncMessage(string $label, array $stats, array $filters): string
    {
        $message = $label . ' concluida'
            . '. Na fonte: ' . $stats['source_total']
            . '. Apos filtros: ' . $stats['matched_total'];

        if ($stats['imported_total'] !== $stats['matched_total']) {
            $message .= '. Processados nesta execucao: ' . $stats['imported_total'];
        }

        $message .= '. Criados: ' . $stats['created']
            . '. Atualizados: ' . $stats['updated']
            . '. Jogos com odds reais: ' . $stats['real_odds']
            . '. Status: ' . $this->syncStatusLabel($filters['status_scope'])
            . '. Trava padrao aplicada: ate o inicio da partida.';

        if ($filters['league_terms'] !== []) {
            $message .= ' Ligas filtradas: ' . $this->syncLeagueSummary($filters['league_terms']) . '.';
        }

        return $message;
    }

    private function syncStatusLabel(string $statusScope): string
    {
        return match ($statusScope) {
            'live' => 'somente ao vivo',
            'all' => 'agendados e ao vivo',
            default => 'somente nao iniciados',
        };
    }

    /**
     * @param array<int, string> $leagueTerms
     */
    private function syncLeagueSummary(array $leagueTerms): string
    {
        $leagueTerms = array_values($leagueTerms);
        $preview = array_slice($leagueTerms, 0, 3);
        $summary = implode(', ', $preview);

        if (count($leagueTerms) > 3) {
            $summary .= ' +' . (count($leagueTerms) - 3);
        }

        return $summary;
    }

    private function cleanupLimit(): int
    {
        $limit = (int) ($_POST['limit'] ?? 1000);
        return max(1, min(10000, $limit));
    }

    private function cleanupStatus(string $status): string
    {
        return in_array($status, ['all', 'scheduled', 'live', 'finished'], true) ? $status : 'all';
    }

    private function cleanupSource(string $source): string
    {
        return in_array($source, ['all', 'manual', 'xscore', 'sofascore', 'flashscore', 'betfair', 'oddapi'], true) ? $source : 'all';
    }

    /**
     * @param array{matched: int, deleted: int, skipped_linked: int} $result
     */
    private function cleanupMessage(string $label, array $result): string
    {
        return $label
            . '. Encontrados: ' . $result['matched']
            . '. Removidos: ' . $result['deleted']
            . '. Protegidos por bilhetes vinculados: ' . $result['skipped_linked'] . '.';
    }

    private function cleanupPresetLabel(string $preset): string
    {
        return match ($preset) {
            'all_safe' => 'Limpeza geral de jogos',
            'category' => 'Limpeza por categoria',
            'schedule' => 'Limpeza por horario',
            default => 'Limpeza em massa',
        };
    }

    /**
     * @param array{matched_tickets: int, deleted_tickets: int, deleted_items: int, detached_agent_requests: int} $result
     */
    private function ticketCleanupMessage(string $label, array $result): string
    {
        return $label
            . '. Bilhetes encontrados: ' . $result['matched_tickets']
            . '. Bilhetes removidos: ' . $result['deleted_tickets']
            . '. Itens removidos: ' . $result['deleted_items']
            . '. Requests de agente desvinculadas: ' . $result['detached_agent_requests']
            . '. Os saldos atuais nao foram recalculados.';
    }

    /**
     * @param array{
     *   matched_games: int,
     *   deleted_games: int,
     *   matched_tickets: int,
     *   deleted_tickets: int,
     *   deleted_items: int,
     *   detached_agent_requests: int
     * } $result
     */
    private function gamesWithTicketsCleanupMessage(array $result): string
    {
        return 'Limpeza profunda de jogos com bilhetes'
            . '. Jogos encontrados: ' . $result['matched_games']
            . '. Jogos removidos: ' . $result['deleted_games']
            . '. Bilhetes removidos: ' . $result['deleted_tickets'] . '/' . $result['matched_tickets']
            . '. Itens removidos: ' . $result['deleted_items']
            . '. Requests de agente desvinculadas: ' . $result['detached_agent_requests']
            . '. Os saldos atuais nao foram recalculados.';
    }

    /**
     * @param array{cleared_pix_logs: int, cleared_agent_logs: int, cleared_total: int} $result
     */
    private function gatewayLogsCleanupMessage(array $result): string
    {
        return 'Limpeza de logs de gateway concluida'
            . '. Logs Pix limpos: ' . $result['cleared_pix_logs']
            . '. Logs de requests de agente limpos: ' . $result['cleared_agent_logs']
            . '. Total de registros limpos: ' . $result['cleared_total'] . '.';
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

    private function isValidResultForGame(int $gameId, string $marketName, string $resultOption): bool
    {
        $stmt = $this->db->pdo()->prepare('SELECT COUNT(*) FROM odds WHERE game_id = :game_id AND market_name = :market_name AND option_name = :option_name');
        $stmt->execute([
            'game_id' => $gameId,
            'market_name' => $marketName,
            'option_name' => $resultOption,
        ]);

        return (int) $stmt->fetchColumn() > 0;
    }

    private function marketOptionsByGame(int $gameId): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT market_name, option_name FROM odds WHERE game_id = :game_id ORDER BY market_name ASC, option_name ASC');
        $stmt->execute(['game_id' => $gameId]);

        $result = [];
        foreach ($stmt->fetchAll() as $row) {
            $marketName = trim((string) ($row['market_name'] ?? ''));
            $optionName = trim((string) ($row['option_name'] ?? ''));

            if ($marketName === '' || $optionName === '') {
                continue;
            }

            if (!isset($result[$marketName])) {
                $result[$marketName] = [];
            }

            $result[$marketName][$optionName] = true;
        }

        return $result;
    }

    private function cleanupInvalidGameResults(int $gameId): void
    {
        $validOptions = $this->marketOptionsByGame($gameId);
        $stmt = $this->db->pdo()->prepare('SELECT market_name, result_option FROM game_results WHERE game_id = :game_id');
        $stmt->execute(['game_id' => $gameId]);

        $deleteStmt = $this->db->pdo()->prepare('DELETE FROM game_results WHERE game_id = :game_id AND market_name = :market_name');
        foreach ($stmt->fetchAll() as $row) {
            $marketName = trim((string) ($row['market_name'] ?? ''));
            $resultOption = trim((string) ($row['result_option'] ?? ''));

            if ($marketName === '' || $resultOption === '') {
                $deleteStmt->execute([
                    'game_id' => $gameId,
                    'market_name' => $marketName,
                ]);
                continue;
            }

            if (!isset($validOptions[$marketName][$resultOption])) {
                $deleteStmt->execute([
                    'game_id' => $gameId,
                    'market_name' => $marketName,
                ]);
            }
        }
    }

    private function lockGameForManualResult(int $gameId): void
    {
        $stmt = $this->db->pdo()->prepare('UPDATE games SET betting_locked = 1 WHERE id = :id');
        $stmt->execute(['id' => $gameId]);

        if ($stmt->rowCount() > 0) {
            return;
        }

        $existsStmt = $this->db->pdo()->prepare('SELECT COUNT(*) FROM games WHERE id = :id');
        $existsStmt->execute(['id' => $gameId]);
        if ((int) $existsStmt->fetchColumn() <= 0) {
            throw new \RuntimeException('Jogo nao encontrado para travar as apostas apos o resultado manual.');
        }
    }

    private function syncGameStatusFromResults(int $gameId): void
    {
        $pdo = $this->db->pdo();
        $marketStmt = $pdo->prepare('SELECT COUNT(DISTINCT market_name) FROM odds WHERE game_id = :game_id');
        $marketStmt->execute(['game_id' => $gameId]);
        $marketCount = (int) $marketStmt->fetchColumn();

        if ($marketCount <= 0) {
            return;
        }

        $settledStmt = $pdo->prepare('SELECT COUNT(DISTINCT market_name) FROM game_results WHERE game_id = :game_id');
        $settledStmt->execute(['game_id' => $gameId]);
        $settledCount = (int) $settledStmt->fetchColumn();

        if ($settledCount <= 0) {
            $statusStmt = $pdo->prepare('SELECT match_date FROM games WHERE id = :id LIMIT 1');
            $statusStmt->execute(['id' => $gameId]);
            $matchDate = (string) ($statusStmt->fetchColumn() ?: '');
            $fallbackStatus = ($matchDate !== '' && strtotime($matchDate) <= time()) ? 'live' : 'scheduled';
            $pdo->prepare('UPDATE games SET status = :status WHERE id = :id')->execute([
                'status' => $fallbackStatus,
                'id' => $gameId,
            ]);
            return;
        }

        $status = $settledCount < $marketCount ? 'live' : 'finished';
        $pdo->prepare('UPDATE games SET status = :status WHERE id = :id')->execute([
            'status' => $status,
            'id' => $gameId,
        ]);
    }

    private function settleOpenTickets(): void
    {
        $pdo = $this->db->pdo();
        $agentService = new AgentService($this->db);
        $agentService->ensureSchema();
        $tickets = $pdo->query('SELECT id FROM bet_tickets WHERE status <> "cancelled"')->fetchAll();

        foreach ($tickets as $ticket) {
            $ticketId = (int) ($ticket['id'] ?? 0);
            if ($ticketId <= 0) {
                continue;
            }

            $pdo->beginTransaction();
            try {
                $lock = $pdo->prepare('SELECT id, status, user_id, agent_user_id, manager_user_id, sales_channel, potential_return, ticket_code FROM bet_tickets WHERE id = :id FOR UPDATE');
                $lock->execute(['id' => $ticketId]);
                $locked = $lock->fetch();

                if (!$locked) {
                    $pdo->rollBack();
                    continue;
                }

                $itemsStmt = $pdo->prepare('SELECT bti.option_name, bti.market_name, bti.game_id, gr.result_option
                    FROM bet_ticket_items bti
                    LEFT JOIN game_results gr ON gr.game_id = bti.game_id AND gr.market_name = bti.market_name
                    WHERE bti.ticket_id = :ticket_id');
                $itemsStmt->execute(['ticket_id' => $ticketId]);
                $items = $itemsStmt->fetchAll();

                if ($items === []) {
                    $pdo->rollBack();
                    continue;
                }

                $desiredStatus = 'won';
                foreach ($items as $item) {
                    $result = trim((string) ($item['result_option'] ?? ''));
                    if ($result === '') {
                        $desiredStatus = 'open';
                        break;
                    }

                    if ((string) $item['option_name'] !== $result) {
                        $desiredStatus = 'lost';
                        break;
                    }
                }

                $currentStatus = (string) $locked['status'];
                $reference = (string) $locked['ticket_code'] . '-WIN';
                $winTransactionStmt = $pdo->prepare('SELECT id, amount FROM transactions WHERE reference = :reference AND type = "bet_win" LIMIT 1 FOR UPDATE');
                $winTransactionStmt->execute(['reference' => $reference]);
                $winTransaction = $winTransactionStmt->fetch();
                $isAgentTicket = in_array((string) ($locked['sales_channel'] ?? ''), ['manager', 'bookmaker'], true);
                $agentWinStmt = $pdo->prepare('SELECT id, amount FROM agent_wallet_transactions WHERE ticket_id = :ticket_id AND entry_type = "ticket_win" LIMIT 1 FOR UPDATE');
                $agentWinStmt->execute(['ticket_id' => $ticketId]);
                $agentWin = $agentWinStmt->fetch();

                if ($currentStatus === 'won' && $desiredStatus !== 'won') {
                    if ($isAgentTicket && $agentWin) {
                        $paidAmount = (float) $agentWin['amount'];
                        $receiverId = (int) ($locked['agent_user_id'] ?? $locked['user_id']);
                        $pdo->prepare('UPDATE users SET agent_balance = GREATEST(agent_balance - :amount, 0) WHERE id = :id')->execute([
                            'amount' => $paidAmount,
                            'id' => $receiverId,
                        ]);
                        $pdo->prepare('DELETE FROM agent_wallet_transactions WHERE id = :id')->execute([
                            'id' => (int) $agentWin['id'],
                        ]);
                    } elseif ($winTransaction) {
                        $paidAmount = (float) $winTransaction['amount'];
                        $pdo->prepare('UPDATE users SET balance = GREATEST(balance - :amount, 0) WHERE id = :id')->execute([
                            'amount' => $paidAmount,
                            'id' => (int) $locked['user_id'],
                        ]);
                        $pdo->prepare('DELETE FROM transactions WHERE id = :id')->execute([
                            'id' => (int) $winTransaction['id'],
                        ]);
                    }
                }

                if ($currentStatus !== 'won' && $desiredStatus === 'won' && $isAgentTicket && !$agentWin) {
                    $amount = (float) $locked['potential_return'];
                    $receiverId = (int) ($locked['agent_user_id'] ?? $locked['user_id']);
                    $pdo->prepare('UPDATE users SET agent_balance = agent_balance + :amount WHERE id = :id')->execute([
                        'amount' => $amount,
                        'id' => $receiverId,
                    ]);
                    $agentService->insertWalletEntry([
                        'agent_user_id' => $receiverId,
                        'source_agent_user_id' => (int) ($locked['agent_user_id'] ?? $receiverId),
                        'manager_user_id' => (int) ($locked['manager_user_id'] ?? 0) ?: null,
                        'ticket_id' => $ticketId,
                        'payment_request_id' => null,
                        'entry_type' => 'ticket_win',
                        'direction' => 'credit',
                        'amount' => $amount,
                        'status' => 'paid',
                        'description' => 'Pagamento de bilhete ganho ' . $locked['ticket_code'],
                        'metadata' => ['ticket_code' => $locked['ticket_code']],
                    ]);
                }

                if ($currentStatus !== 'won' && $desiredStatus === 'won' && !$isAgentTicket && !$winTransaction) {
                    $amount = (float) $locked['potential_return'];
                    $pdo->prepare('UPDATE users SET balance = balance + :amount WHERE id = :id')->execute([
                        'amount' => $amount,
                        'id' => (int) $locked['user_id'],
                    ]);
                    $pdo->prepare('INSERT INTO transactions (reference, user_id, type, amount, status, created_at) VALUES (:reference, :user_id, "bet_win", :amount, "paid", NOW())')->execute([
                        'reference' => $reference,
                        'user_id' => (int) $locked['user_id'],
                        'amount' => $amount,
                    ]);
                }

                if ($currentStatus !== $desiredStatus) {
                    $pdo->prepare('UPDATE bet_tickets SET status = :status WHERE id = :id')->execute([
                        'status' => $desiredStatus,
                        'id' => $ticketId,
                    ]);
                }

                $pdo->commit();
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
            }
        }
    }
    private function upsertOdd(int $gameId, string $marketName, string $optionName, float $value): void
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
                'odd_value' => $value,
                'id' => (int) $row['id'],
            ]);
            return;
        }

        $pdo->prepare('INSERT INTO odds (game_id, market_name, option_name, odd_value) VALUES (:game_id, :market_name, :option_name, :odd_value)')->execute([
            'game_id' => $gameId,
            'market_name' => $marketName,
            'option_name' => $optionName,
            'odd_value' => $value,
        ]);
    }

    private function findById(array $rows, int $id): ?array
    {
        foreach ($rows as $row) {
            if ((int) ($row['id'] ?? 0) === $id) {
                return $row;
            }
        }

        return null;
    }
}
