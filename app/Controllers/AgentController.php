<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Services\AgentBettingService;
use App\Services\AgentService;

final class AgentController extends Controller
{
    public function index(): void
    {
        $user = $this->auth->requireAgent();
        $agentService = new AgentService($this->db);
        $agentService->ensureSchema();

        $cashFilters = [
            'entry_type' => trim((string) ($_GET['cash_entry_type'] ?? 'all')),
            'status' => trim((string) ($_GET['cash_status'] ?? 'all')),
            'date_from' => trim((string) ($_GET['cash_date_from'] ?? '')),
            'date_to' => trim((string) ($_GET['cash_date_to'] ?? '')),
        ];

        $this->view->render('agent/dashboard', [
            'user' => $user,
            'summary' => $agentService->panelSummary($user),
            'cashEntries' => $agentService->panelCashEntries($user, $cashFilters),
            'pendingPayments' => $agentService->pendingPaymentRequests($user),
            'tickets' => $agentService->panelTickets($user),
            'bookmakers' => (string) ($user['role'] ?? '') === 'manager' ? $agentService->managerBookmakers((int) $user['id']) : [],
            'cashFilters' => $cashFilters,
            'flash' => $_SESSION['flash'] ?? null,
            'theme' => in_array((string) ($this->settings()['site_theme'] ?? 'classic'), ['classic', 'neo', 'sportsbook'], true)
                ? (string) ($this->settings()['site_theme'] ?? 'classic')
                : 'classic',
        ]);

        unset($_SESSION['flash']);
    }

    public function syncPaymentStatus(): void
    {
        $user = $this->auth->requireAgent();
        $referenceCode = trim((string) ($_POST['reference_code'] ?? ''));

        if ($referenceCode === '') {
            $_SESSION['flash'] = ['type' => 'error', 'message' => 'Pedido Pix do agente nao informado.'];
            $this->redirect('/agent#pagamentos');
        }

        try {
            $result = (new AgentBettingService($this->db))->syncPaymentRequestStatus((int) $user['id'], $referenceCode);
            if (!empty($result['issued']) && !empty($result['ticket_code'])) {
                unset($_SESSION['betslip']);
                $_SESSION['pix_checkout'] = [
                    'context' => 'agent_sale',
                    'reference' => $referenceCode,
                    'status' => 'issued',
                    'issued' => true,
                    'ticket_code' => (string) $result['ticket_code'],
                ];
                $_SESSION['flash'] = ['type' => 'success', 'message' => 'Bilhete emitido com sucesso. Codigo: ' . $result['ticket_code']];
            } else {
                $_SESSION['flash'] = ['type' => 'success', 'message' => 'Status atual do pedido: ' . strtoupper((string) ($result['status'] ?? 'pending')) . '.'];
            }
        } catch (\Throwable $exception) {
            $_SESSION['flash'] = ['type' => 'error', 'message' => 'Erro ao atualizar o pagamento: ' . mb_substr((string) $exception->getMessage(), 0, 220)];
        }

        $this->redirect($this->returnTo());
    }

    public function confirmCustomPayment(): void
    {
        $user = $this->auth->requireAgent();
        $referenceCode = trim((string) ($_POST['reference_code'] ?? ''));

        if ($referenceCode === '') {
            $_SESSION['flash'] = ['type' => 'error', 'message' => 'Pedido personalizado nao informado.'];
            $this->redirect('/agent#pagamentos');
        }

        try {
            $result = (new AgentBettingService($this->db))->confirmCustomPayment((int) $user['id'], $referenceCode);
            unset($_SESSION['betslip']);
            $_SESSION['pix_checkout'] = [
                'context' => 'agent_sale',
                'reference' => $referenceCode,
                'status' => 'issued',
                'issued' => true,
                'ticket_code' => (string) ($result['ticket_code'] ?? ''),
            ];
            $_SESSION['flash'] = ['type' => 'success', 'message' => 'Bilhete emitido com sucesso. Codigo: ' . ($result['ticket_code'] ?? '')];
        } catch (\Throwable $exception) {
            $_SESSION['flash'] = ['type' => 'error', 'message' => 'Erro ao confirmar o recebimento: ' . mb_substr((string) $exception->getMessage(), 0, 220)];
        }

        $this->redirect($this->returnTo());
    }

    public function withdrawCommission(): void
    {
        $user = $this->auth->requireAgent();
        $amount = (float) ($_POST['amount'] ?? 0);

        if ($amount <= 0) {
            $_SESSION['flash'] = ['type' => 'error', 'message' => 'Valor de saque invalido.'];
            $this->redirect('/agent#visao-geral');
        }

        try {
            $agentService = new AgentService($this->db);
            $agentService->ensureSchema();
            $agentService->withdrawCommission((int) $user['id'], round($amount, 2));
            $_SESSION['flash'] = ['type' => 'success', 'message' => 'Saque de comissao solicitado com sucesso.'];
        } catch (\Throwable $exception) {
            $_SESSION['flash'] = ['type' => 'error', 'message' => mb_substr((string) $exception->getMessage(), 0, 220)];
        }

        $this->redirect('/agent#visao-geral');
    }

    private function returnTo(): string
    {
        $returnTo = trim((string) ($_POST['return_to'] ?? '/agent#pagamentos'));
        if ($returnTo === '' || !str_starts_with($returnTo, '/')) {
            return '/agent#pagamentos';
        }

        return $returnTo;
    }

    private function settings(): array
    {
        $rows = $this->db->pdo()->query('SELECT setting_key, setting_value FROM settings')->fetchAll();
        $settings = [];
        foreach ($rows as $row) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }

        return $settings;
    }
}
