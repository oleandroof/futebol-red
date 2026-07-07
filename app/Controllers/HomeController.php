<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Models\DashboardModel;
use App\Services\AgentBettingService;
use App\Services\BettingLockService;

final class HomeController extends Controller
{
    public function index(): void
    {
        $model = new DashboardModel($this->db);
        $lockService = new BettingLockService($this->db);
        $lockService->ensureSchema();
        $lockSettings = $lockService->settings();
        $user = $this->auth->user();
        $isAgentUser = $user !== null && in_array((string) ($user['role'] ?? ''), ['manager', 'bookmaker'], true);
        $settings = $model->settings();
        $category = trim((string) ($_GET['cat'] ?? ''));
        $leagueId = max(0, (int) ($_GET['league'] ?? 0));
        $search = trim((string) ($_GET['q'] ?? ''));

        $periodInput = strtolower(trim((string) ($_GET['period'] ?? '')));
        if ($periodInput === '') {
            $period = $category !== '' ? 'all' : 'today';
        } else {
            $period = $periodInput;
        }

        if (!in_array($period, ['all', 'today', 'tomorrow', 'week', 'live'], true)) {
            $period = $category !== '' ? 'all' : 'today';
        }

        if ($search !== '') {
            $period = 'all';
        }

        $ticketCheck = $_SESSION['ticket_check'] ?? null;
        $pixCheckout = $_SESSION['pix_checkout'] ?? null;
        unset($_SESSION['ticket_check']);

        $selectedOddIds = array_values(array_unique(array_map('intval', $_SESSION['betslip'] ?? [])));
        $betSlipItemsById = $model->oddsByIds($selectedOddIds);
        $betSlipItems = [];
        $totalOdd = 1.0;

        foreach ($selectedOddIds as $oddId) {
            if (!isset($betSlipItemsById[$oddId])) {
                continue;
            }
            $item = $lockService->annotateGame($betSlipItemsById[$oddId], $lockSettings);
            $betSlipItems[] = $item;
            $totalOdd *= (float) $item['odd_value'];
        }

        if ($betSlipItems === []) {
            $totalOdd = 0;
        }

        $betSlipHasLockedItems = false;
        foreach ($betSlipItems as $item) {
            if ((int) ($item['betting_is_locked'] ?? 0) === 1) {
                $betSlipHasLockedItems = true;
                break;
            }
        }

        $myTickets = $user ? $model->userTickets((int) $user['id']) : [];
        $myOpenTickets = array_values(array_filter($myTickets, static function (array $ticket): bool {
            return ($ticket['status'] ?? '') === 'open';
        }));
        $profileStats = $user ? $model->userTicketStats((int) $user['id']) : null;
        $games = $lockService->annotateGames(
            $model->featuredGames($category !== '' ? $category : null, $period, $search !== '' ? $search : null, $leagueId > 0 ? $leagueId : null),
            $lockSettings
        );

        $this->view->render('public/home', [
            'user' => $user,
            'games' => $games,
            'categories' => $model->publicCategoriesWithCount(),
            'leagues' => $model->publicLeaguesWithCount($category !== '' ? $category : null, $period, $search !== '' ? $search : null),
            'stats' => $model->stats(),
            'flash' => $_SESSION['flash'] ?? null,
            'theme' => in_array((string) ($settings['site_theme'] ?? 'classic'), ['classic', 'neo', 'sportsbook'], true)
                ? (string) ($settings['site_theme'] ?? 'classic')
                : 'classic',
            'activeCategory' => $category,
            'activeLeagueId' => $leagueId,
            'activePeriod' => $period,
            'searchQuery' => $search,
            'betSlipItems' => $betSlipItems,
            'betSlipTotalOdd' => $totalOdd,
            'ticketCheck' => $ticketCheck,
            'pixCheckout' => $pixCheckout,
            'isAgentUser' => $isAgentUser,
            'myTickets' => $myTickets,
            'myOpenTickets' => $myOpenTickets,
            'profileStats' => $profileStats,
            'globalBettingLock' => $lockSettings['global_lock_all'],
            'betSlipHasLockedItems' => $betSlipHasLockedItems,
        ]);

        unset($_SESSION['flash']);
    }

    public function addToBetSlip(): void
    {
        $user = $this->auth->user();
        if ($user === null) {
            $_SESSION['flash'] = ['type' => 'error', 'message' => 'Faca login para montar o bilhete.'];
            $this->redirect('/');
        }

        $oddId = (int) ($_POST['odd_id'] ?? 0);
        if ($oddId <= 0) {
            $_SESSION['flash'] = ['type' => 'error', 'message' => 'Odd invalida.'];
            $this->redirect('/');
        }

        $model = new DashboardModel($this->db);
        $lockService = new BettingLockService($this->db);
        $lockService->ensureSchema();
        $lockSettings = $lockService->settings();
        $odd = $model->oddsByIds([$oddId]);
        if (!isset($odd[$oddId])) {
            $_SESSION['flash'] = ['type' => 'error', 'message' => 'Odd nao encontrada.'];
            $this->redirect('/');
        }

        $selected = $lockService->annotateGame($odd[$oddId], $lockSettings);
        if ((int) ($selected['betting_is_locked'] ?? 0) === 1) {
            $_SESSION['flash'] = ['type' => 'error', 'message' => 'Este jogo esta travado para apostas. ' . (string) ($selected['betting_lock_reason'] ?? '')];
            $this->redirect($this->buildHomeTarget());
        }

        $betslip = array_values(array_unique(array_map('intval', $_SESSION['betslip'] ?? [])));

        if (in_array($oddId, $betslip, true)) {
            $_SESSION['betslip'] = array_values(array_filter($betslip, static fn (int $id): bool => $id !== $oddId));
            $this->redirect($this->buildHomeTarget('#betslip-panel'));
        }

        $existingItems = $model->oddsByIds($betslip);
        $filtered = [];

        foreach ($betslip as $selectedOddId) {
            $existing = $existingItems[$selectedOddId] ?? null;
            if ($existing === null) {
                continue;
            }

            $sameMarket = (int) $existing['game_id'] === (int) $selected['game_id']
                && (string) $existing['market_name'] === (string) $selected['market_name'];

            if ($sameMarket) {
                continue;
            }

            $filtered[] = $selectedOddId;
        }

        $filtered[] = $oddId;
        $_SESSION['betslip'] = array_values(array_unique($filtered));
        $this->redirect($this->buildHomeTarget('#betslip-panel'));
    }

    public function removeFromBetSlip(): void
    {
        $oddId = (int) ($_POST['odd_id'] ?? 0);
        $betslip = array_values(array_unique(array_map('intval', $_SESSION['betslip'] ?? [])));
        $_SESSION['betslip'] = array_values(array_filter($betslip, static fn (int $id): bool => $id !== $oddId));
        $this->redirect($this->buildHomeTarget('#betslip-panel'));
    }

    public function clearBetSlip(): void
    {
        unset($_SESSION['betslip']);
        $this->redirect($this->buildHomeTarget('#betslip-panel'));
    }

    public function confirmBetSlip(): void
    {
        $user = $this->auth->requireUser();
        $stake = (float) ($_POST['stake'] ?? 0);

        if ($stake <= 0) {
            $_SESSION['flash'] = ['type' => 'error', 'message' => 'Informe um valor de aposta valido.'];
            $this->redirect($this->withCacheBust($this->buildHomeTarget('#betslip-panel')));
        }

        $oddIds = array_values(array_unique(array_map('intval', $_SESSION['betslip'] ?? [])));
        if ($oddIds === []) {
            $_SESSION['flash'] = ['type' => 'error', 'message' => 'Selecione ao menos uma odd para apostar.'];
            $this->redirect($this->withCacheBust($this->buildHomeTarget('#betslip-panel')));
        }

        $model = new DashboardModel($this->db);
        $lockService = new BettingLockService($this->db);
        $lockService->ensureSchema();
        $lockSettings = $lockService->settings();
        $itemsById = $model->oddsByIds($oddIds);
        $items = [];
        $totalOdd = 1.0;

        foreach ($oddIds as $oddId) {
            if (!isset($itemsById[$oddId])) {
                continue;
            }
            $item = $lockService->annotateGame($itemsById[$oddId], $lockSettings);
            $items[] = $item;
            $totalOdd *= (float) $item['odd_value'];
        }

        if ($items === []) {
            $_SESSION['flash'] = ['type' => 'error', 'message' => 'Nao foi possivel validar as odds selecionadas.'];
            $this->redirect($this->withCacheBust($this->buildHomeTarget('#betslip-panel')));
        }

        $lockedItems = array_values(array_filter($items, static function (array $item): bool {
            return (int) ($item['betting_is_locked'] ?? 0) === 1;
        }));

        if ($lockedItems !== []) {
            $lockedIds = array_map(static fn (array $item): int => (int) $item['odd_id'], $lockedItems);
            $_SESSION['betslip'] = array_values(array_filter($oddIds, static fn (int $id): bool => !in_array($id, $lockedIds, true)));
            $_SESSION['flash'] = [
                'type' => 'error',
                'message' => 'Alguns jogos foram travados e sairam do bilhete: ' . $this->lockedGamesSummary($lockedItems) . '.',
            ];
            $this->redirect($this->withCacheBust($this->buildHomeTarget('#betslip-panel')));
        }

        if (in_array((string) ($user['role'] ?? ''), ['manager', 'bookmaker'], true)) {
            try {
                $checkout = (new AgentBettingService($this->db))->createPaymentRequest($user, $items, $stake, $totalOdd);
                $_SESSION['pix_checkout'] = $checkout;

                if (!empty($checkout['issued']) && !empty($checkout['ticket_code'])) {
                    unset($_SESSION['betslip']);
                    $_SESSION['ticket_check'] = $model->findTicketByCode((string) $checkout['ticket_code']);
                    $_SESSION['flash'] = ['type' => 'success', 'message' => 'Bilhete emitido com sucesso. Codigo: ' . $checkout['ticket_code']];
                } else {
                    $_SESSION['flash'] = [
                        'type' => 'success',
                        'message' => (($checkout['payment_mode'] ?? 'gateway') === 'gateway')
                            ? 'Pix do bilhete gerado. Pague e confirme para emitir o jogo.'
                            : 'Pagamento personalizado preparado. Confirme o recebimento para emitir o bilhete.',
                    ];
                }
            } catch (\Throwable $exception) {
                $_SESSION['flash'] = [
                    'type' => 'error',
                    'message' => 'Erro ao preparar o bilhete do agente: ' . mb_substr((string) $exception->getMessage(), 0, 220),
                ];
            }

            $this->redirect($this->withCacheBust($this->buildHomeTarget('#betslip-panel')));
        }

        $potentialReturn = $stake * $totalOdd;

        if ((float) $user['balance'] < $stake) {
            $_SESSION['flash'] = ['type' => 'error', 'message' => 'Saldo insuficiente para confirmar o bilhete.'];
            $this->redirect($this->withCacheBust($this->buildHomeTarget('#betslip-panel')));
        }

        $ticketCode = 'BT' . date('YmdHis') . random_int(10, 99);
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();

        $pdo->prepare('UPDATE users SET balance = balance - :stake WHERE id = :id')->execute([
            'stake' => $stake,
            'id' => $user['id'],
        ]);

        $pdo->prepare('INSERT INTO bet_tickets (ticket_code, user_id, stake, total_odd, potential_return, status, created_at) VALUES (:ticket_code, :user_id, :stake, :total_odd, :potential_return, "open", NOW())')->execute([
            'ticket_code' => $ticketCode,
            'user_id' => $user['id'],
            'stake' => $stake,
            'total_odd' => $totalOdd,
            'potential_return' => $potentialReturn,
        ]);

        $ticketId = (int) $pdo->lastInsertId();

        $itemInsert = $pdo->prepare('INSERT INTO bet_ticket_items (ticket_id, game_id, odd_id, market_name, option_name, odd_value) VALUES (:ticket_id, :game_id, :odd_id, :market_name, :option_name, :odd_value)');
        foreach ($items as $item) {
            $itemInsert->execute([
                'ticket_id' => $ticketId,
                'game_id' => $item['game_id'],
                'odd_id' => $item['odd_id'],
                'market_name' => $item['market_name'],
                'option_name' => $item['option_name'],
                'odd_value' => $item['odd_value'],
            ]);
        }

        $pdo->prepare('INSERT INTO transactions (reference, user_id, type, amount, status, created_at) VALUES (:reference, :user_id, "bet_loss", :amount, "paid", NOW())')->execute([
            'reference' => $ticketCode,
            'user_id' => $user['id'],
            'amount' => $stake,
        ]);

        $pdo->commit();

        unset($_SESSION['betslip']);

        $_SESSION['ticket_check'] = $model->findTicketByCode($ticketCode);
        $_SESSION['flash'] = ['type' => 'success', 'message' => 'Bilhete confirmado com sucesso. Codigo: ' . $ticketCode];
        $this->redirect($this->withCacheBust($this->buildHomeTarget('#betslip-panel')));
    }


    private function buildHomeTarget(string $anchor = ''): string
    {
        $params = [];
        $category = trim((string) ($_POST['cat'] ?? ''));
        $period = strtolower(trim((string) ($_POST['period'] ?? '')));
        $leagueId = max(0, (int) ($_POST['league'] ?? 0));
        $search = trim((string) ($_POST['q'] ?? ''));

        if ($category !== '') {
            $params['cat'] = $category;
        }

        if ($period !== '' && in_array($period, ['all', 'today', 'tomorrow', 'week', 'live'], true)) {
            $params['period'] = $period;
        }

        if ($search !== '') {
            $params['q'] = $search;
        }

        if ($leagueId > 0) {
            $params['league'] = $leagueId;
        }

        $target = '/';
        if ($params !== []) {
            $target .= '?' . http_build_query($params);
        }

        return $target . $anchor;
    }

    private function withCacheBust(string $target): string
    {
        $anchor = '';
        $anchorPos = strpos($target, '#');
        if ($anchorPos !== false) {
            $anchor = substr($target, $anchorPos);
            $target = substr($target, 0, $anchorPos);
        }

        $target .= (str_contains($target, '?') ? '&' : '?') . 'v=' . time();
        return $target . $anchor;
    }

    public function checkTicket(): void
    {
        $code = strtoupper(trim((string) ($_POST['ticket_code'] ?? '')));
        if ($code === '') {
            $_SESSION['flash'] = ['type' => 'error', 'message' => 'Informe o codigo do bilhete.'];
            $this->redirect('/');
        }

        $model = new DashboardModel($this->db);
        $ticket = $model->findTicketByCode($code);

        if ($ticket === null) {
            $_SESSION['flash'] = ['type' => 'error', 'message' => 'Bilhete nao encontrado.'];
            $this->redirect('/');
        }

        $_SESSION['ticket_check'] = $ticket;
        $this->redirect('/');
    }

    /**
     * @param array<int, array<string, mixed>> $items
     */
    private function lockedGamesSummary(array $items): string
    {
        $labels = [];

        foreach (array_slice($items, 0, 3) as $item) {
            $labels[] = trim((string) ($item['home_name'] ?? '') . ' x ' . (string) ($item['away_name'] ?? ''));
        }

        $summary = implode(', ', array_filter($labels));
        if (count($items) > 3) {
            $summary .= ' +' . (count($items) - 3);
        }

        return $summary !== '' ? $summary : 'jogos indisponiveis';
    }
}
