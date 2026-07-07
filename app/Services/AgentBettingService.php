<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use RuntimeException;
use Throwable;

final class AgentBettingService
{
    public function __construct(private readonly Database $db)
    {
    }

    /**
     * @param array<string, mixed> $user
     * @param array<int, array<string, mixed>> $items
     * @return array<string, mixed>
     */
    public function createPaymentRequest(array $user, array $items, float $stake, float $totalOdd): array
    {
        $this->assertAgentUser($user);
        $this->ensureServices();

        if ($stake <= 0 || $totalOdd <= 0 || $items === []) {
            throw new RuntimeException('Nao foi possivel gerar a cobranca do bilhete do agente.');
        }

        $agentId = (int) $user['id'];
        $managerId = (string) $user['role'] === 'manager' ? $agentId : (int) ($user['manager_user_id'] ?? 0);
        $paymentMode = (string) ($user['pix_checkout_mode'] ?? 'gateway');
        $reference = 'AGP-' . date('YmdHis') . '-' . random_int(100, 999);
        $potentialReturn = round($stake * $totalOdd, 2);
        $payloadItems = array_map(static function (array $item): array {
            return [
                'game_id' => (int) ($item['game_id'] ?? 0),
                'odd_id' => (int) ($item['odd_id'] ?? 0),
                'market_name' => (string) ($item['market_name'] ?? ''),
                'option_name' => (string) ($item['option_name'] ?? ''),
                'odd_value' => (float) ($item['odd_value'] ?? 0),
                'home_name' => (string) ($item['home_name'] ?? ''),
                'away_name' => (string) ($item['away_name'] ?? ''),
                'match_date' => (string) ($item['match_date'] ?? ''),
            ];
        }, $items);

        $pdo = $this->db->pdo();
        $agentService = new AgentService($this->db);
        $gatewayService = new PixGatewayService($this->db);
        $gatewaySettings = $gatewayService->settings();

        $requestData = [
            'reference_code' => $reference,
            'agent_user_id' => $agentId,
            'manager_user_id' => $managerId > 0 ? $managerId : null,
            'ticket_id' => null,
            'payment_mode' => $paymentMode,
            'gateway_provider' => null,
            'status' => 'pending',
            'stake' => $stake,
            'total_odd' => $totalOdd,
            'potential_return' => $potentialReturn,
            'provider_transaction_id' => null,
            'qr_code' => null,
            'payment_key' => null,
            'items_payload' => json_encode($payloadItems, JSON_UNESCAPED_UNICODE),
            'request_payload' => null,
            'response_payload' => null,
        ];

        if ($paymentMode === 'gateway') {
            $provider = $gatewayService->defaultProvider($gatewaySettings);
            $charge = $gatewayService->createCharge($provider, [
                'amount' => $stake,
                'payer_name' => (string) ($user['name'] ?? ''),
                'payer_document' => (string) ($user['cpf'] ?? ''),
                'payer_email' => (string) ($user['email'] ?? ''),
                'description' => 'Bilhete de agente ' . $reference,
                'reference' => $reference,
                'webhook_url' => $gatewayService->webhookUrlFor($provider, $gatewaySettings),
                'metadata' => [
                    'context' => 'agent_ticket',
                    'reference_code' => $reference,
                    'agent_user_id' => $agentId,
                ],
            ], $gatewaySettings);

            $requestData['gateway_provider'] = $provider;
            $requestData['provider_transaction_id'] = $charge['transaction_id'];
            $requestData['qr_code'] = $charge['qr_code'];
            $requestData['status'] = $charge['status'];
            $requestData['request_payload'] = json_encode([
                'provider' => $provider,
                'stake' => $stake,
                'reference' => $reference,
            ], JSON_UNESCAPED_UNICODE);
            $requestData['response_payload'] = json_encode($charge['raw'], JSON_UNESCAPED_UNICODE);
        } elseif ($paymentMode === 'custom_key') {
            $pixKey = trim((string) ($user['pix_key'] ?? ''));
            if ($pixKey === '') {
                throw new RuntimeException('Este agente nao possui chave Pix personalizada configurada.');
            }

            $requestData['payment_key'] = $pixKey;
            $requestData['request_payload'] = json_encode(['mode' => 'custom_key'], JSON_UNESCAPED_UNICODE);
        } elseif ($paymentMode === 'custom_qr') {
            $pixQrCode = trim((string) ($user['pix_qr_code'] ?? ''));
            if ($pixQrCode === '') {
                throw new RuntimeException('Este agente nao possui QR Code personalizado configurado.');
            }

            $requestData['qr_code'] = $pixQrCode;
            $requestData['request_payload'] = json_encode(['mode' => 'custom_qr'], JSON_UNESCAPED_UNICODE);
        }

        $pdo->prepare(
            'INSERT INTO agent_payment_requests
                (reference_code, agent_user_id, manager_user_id, ticket_id, payment_mode, gateway_provider, status, stake, total_odd, potential_return, provider_transaction_id, qr_code, payment_key, items_payload, request_payload, response_payload, created_at)
             VALUES
                (:reference_code, :agent_user_id, :manager_user_id, :ticket_id, :payment_mode, :gateway_provider, :status, :stake, :total_odd, :potential_return, :provider_transaction_id, :qr_code, :payment_key, :items_payload, :request_payload, :response_payload, NOW())'
        )->execute($requestData);

        if ($requestData['status'] === 'paid') {
            $issued = $this->finalizePaidRequestByReference($reference);
            return array_merge($issued, [
                'context' => 'agent_sale',
                'reference' => $reference,
                'amount' => $stake,
                'status' => 'paid',
                'payment_mode' => $paymentMode,
                'provider' => $requestData['gateway_provider'],
            ]);
        }

        return [
            'context' => 'agent_sale',
            'reference' => $reference,
            'amount' => $stake,
            'status' => (string) $requestData['status'],
            'payment_mode' => $paymentMode,
            'provider' => $requestData['gateway_provider'],
            'transaction_id' => $requestData['provider_transaction_id'],
            'qr_code' => $requestData['qr_code'],
            'payment_key' => $requestData['payment_key'],
            'ticket_code' => null,
            'issued' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function syncPaymentRequestStatus(int $userId, string $referenceCode): array
    {
        $request = $this->loadOwnedRequest($userId, $referenceCode);
        if (!empty($request['applied_at']) || (string) ($request['status'] ?? '') === 'issued') {
            return $this->finalizePaidRequestByReference($referenceCode);
        }

        if ((string) $request['payment_mode'] !== 'gateway') {
            throw new RuntimeException('Este pedido usa pagamento manual/customizado.');
        }

        $provider = (string) ($request['gateway_provider'] ?? '');
        $transactionId = (string) ($request['provider_transaction_id'] ?? '');
        if ($provider === '' || $transactionId === '') {
            throw new RuntimeException('Pedido sem gateway/transacao vinculada.');
        }

        $gatewayService = new PixGatewayService($this->db);
        $statusResponse = $gatewayService->getChargeStatus($provider, $transactionId);
        $status = (string) ($statusResponse['status'] ?? 'pending');

        $this->db->pdo()->prepare(
            'UPDATE agent_payment_requests
             SET status = :status,
                 response_payload = :response_payload,
                 paid_at = CASE WHEN :status = "paid" AND paid_at IS NULL THEN NOW() ELSE paid_at END
             WHERE id = :id'
        )->execute([
            'status' => $status,
            'response_payload' => json_encode($statusResponse['raw'], JSON_UNESCAPED_UNICODE),
            'id' => (int) $request['id'],
        ]);

        if ($status === 'paid') {
            return $this->finalizePaidRequestByReference($referenceCode);
        }

        return [
            'issued' => false,
            'status' => $status,
            'reference' => $referenceCode,
            'ticket_code' => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function confirmCustomPayment(int $userId, string $referenceCode): array
    {
        $request = $this->loadOwnedRequest($userId, $referenceCode);
        if ((string) ($request['payment_mode'] ?? '') === 'gateway') {
            throw new RuntimeException('Este pedido depende de confirmacao do gateway Pix.');
        }

        $this->db->pdo()->prepare(
            'UPDATE agent_payment_requests
             SET status = "paid",
                 paid_at = CASE WHEN paid_at IS NULL THEN NOW() ELSE paid_at END
             WHERE id = :id'
        )->execute([
            'id' => (int) $request['id'],
        ]);

        return $this->finalizePaidRequestByReference($referenceCode);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>|null
     */
    public function applyProviderWebhook(string $provider, string $transactionId, string $status, array $payload = []): ?array
    {
        $this->ensureServices();

        if ($transactionId === '') {
            return null;
        }

        $normalized = (new PixGatewayService($this->db))->normalizeStatus($provider, $status);
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, reference_code, applied_at, ticket_id, status
             FROM agent_payment_requests
             WHERE gateway_provider = :provider AND provider_transaction_id = :transaction_id
             LIMIT 1'
        );
        $stmt->execute([
            'provider' => $provider,
            'transaction_id' => $transactionId,
        ]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }

        if (!empty($row['applied_at']) || (string) ($row['status'] ?? '') === 'issued') {
            return $this->finalizePaidRequestByReference((string) $row['reference_code']);
        }

        $this->db->pdo()->prepare(
            'UPDATE agent_payment_requests
             SET status = :status,
                 response_payload = :response_payload,
                 paid_at = CASE WHEN :status = "paid" AND paid_at IS NULL THEN NOW() ELSE paid_at END
             WHERE id = :id'
        )->execute([
            'status' => $normalized,
            'response_payload' => json_encode($payload, JSON_UNESCAPED_UNICODE),
            'id' => (int) $row['id'],
        ]);

        if ($normalized === 'paid') {
            return $this->finalizePaidRequestByReference((string) $row['reference_code']);
        }

        return [
            'issued' => false,
            'status' => $normalized,
            'reference' => (string) $row['reference_code'],
            'ticket_code' => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function finalizePaidRequestByReference(string $referenceCode): array
    {
        $this->ensureServices();

        $pdo = $this->db->pdo();
        $pdo->beginTransaction();

        try {
            $stmt = $pdo->prepare('SELECT * FROM agent_payment_requests WHERE reference_code = :reference_code LIMIT 1 FOR UPDATE');
            $stmt->execute(['reference_code' => $referenceCode]);
            $request = $stmt->fetch();

            if (!$request) {
                throw new RuntimeException('Pedido Pix do agente nao encontrado.');
            }

            if (!empty($request['applied_at']) && (int) ($request['ticket_id'] ?? 0) > 0) {
                $ticket = $this->loadTicketById((int) $request['ticket_id']);
                $pdo->commit();

                return [
                    'issued' => true,
                    'status' => (string) ($request['status'] ?? 'paid'),
                    'reference' => $referenceCode,
                    'ticket_code' => (string) ($ticket['ticket_code'] ?? ''),
                    'ticket_id' => (int) ($ticket['id'] ?? 0),
                ];
            }

            if ((string) ($request['status'] ?? '') !== 'paid') {
                throw new RuntimeException('O pagamento do bilhete ainda nao foi confirmado.');
            }

            $agent = $this->loadAgentById((int) $request['agent_user_id']);
            if ($agent === null) {
                throw new RuntimeException('Agente do pedido nao encontrado.');
            }

            $manager = null;
            if ((int) ($request['manager_user_id'] ?? 0) > 0) {
                $manager = $this->loadAgentById((int) $request['manager_user_id']);
            }

            $items = json_decode((string) ($request['items_payload'] ?? '[]'), true);
            if (!is_array($items) || $items === []) {
                throw new RuntimeException('Itens do pedido nao encontrados.');
            }

            $stake = (float) ($request['stake'] ?? 0);
            $totalOdd = (float) ($request['total_odd'] ?? 0);
            $potentialReturn = (float) ($request['potential_return'] ?? 0);
            $salesChannel = (string) $agent['role'];

            $agentCommission = 0.0;
            $managerCommission = 0.0;
            $entryType = null;

            if ($salesChannel === 'bookmaker') {
                $agentCommission = round($stake * ((float) ($agent['commission_rate'] ?? 0) / 100), 2);
                $managerCommission = $manager ? round($stake * ((float) ($manager['commission_rate'] ?? 0) / 100), 2) : 0.0;
                $entryType = 'bookmaker_commission';
            } elseif ($salesChannel === 'manager') {
                $managerCommission = round($stake * ((float) ($agent['commission_rate'] ?? 0) / 100), 2);
                $entryType = 'self_commission';
            }

            $ticketCode = 'BT' . date('YmdHis') . random_int(10, 99);
            $pdo->prepare(
                'INSERT INTO bet_tickets
                    (ticket_code, user_id, agent_user_id, manager_user_id, payment_request_id, stake, total_odd, potential_return, status, sales_channel, agent_commission_amount, manager_commission_amount, created_at)
                 VALUES
                    (:ticket_code, :user_id, :agent_user_id, :manager_user_id, :payment_request_id, :stake, :total_odd, :potential_return, "open", :sales_channel, :agent_commission_amount, :manager_commission_amount, NOW())'
            )->execute([
                'ticket_code' => $ticketCode,
                'user_id' => (int) $agent['id'],
                'agent_user_id' => (int) $agent['id'],
                'manager_user_id' => $manager ? (int) $manager['id'] : ((string) $salesChannel === 'manager' ? (int) $agent['id'] : null),
                'payment_request_id' => (int) $request['id'],
                'stake' => $stake,
                'total_odd' => $totalOdd,
                'potential_return' => $potentialReturn,
                'sales_channel' => $salesChannel,
                'agent_commission_amount' => $agentCommission,
                'manager_commission_amount' => $managerCommission,
            ]);
            $ticketId = (int) $pdo->lastInsertId();

            $itemInsert = $pdo->prepare(
                'INSERT INTO bet_ticket_items (ticket_id, game_id, odd_id, market_name, option_name, odd_value)
                 VALUES (:ticket_id, :game_id, :odd_id, :market_name, :option_name, :odd_value)'
            );

            foreach ($items as $item) {
                $itemInsert->execute([
                    'ticket_id' => $ticketId,
                    'game_id' => (int) ($item['game_id'] ?? 0),
                    'odd_id' => (int) ($item['odd_id'] ?? 0),
                    'market_name' => (string) ($item['market_name'] ?? ''),
                    'option_name' => (string) ($item['option_name'] ?? ''),
                    'odd_value' => (float) ($item['odd_value'] ?? 0),
                ]);
            }

            $agentService = new AgentService($this->db);

            if ($agentCommission > 0 && $salesChannel === 'bookmaker') {
                $pdo->prepare('UPDATE users SET agent_balance = agent_balance + :amount WHERE id = :id')->execute([
                    'amount' => $agentCommission,
                    'id' => (int) $agent['id'],
                ]);

                $agentService->insertWalletEntry([
                    'agent_user_id' => (int) $agent['id'],
                    'source_agent_user_id' => (int) $agent['id'],
                    'manager_user_id' => $manager ? (int) $manager['id'] : null,
                    'ticket_id' => $ticketId,
                    'payment_request_id' => (int) $request['id'],
                    'entry_type' => 'bookmaker_commission',
                    'direction' => 'credit',
                    'amount' => $agentCommission,
                    'status' => 'paid',
                    'description' => 'Comissao do cambista no bilhete ' . $ticketCode,
                    'metadata' => ['reference_code' => $referenceCode],
                ]);
            }

            if ($managerCommission > 0) {
                $managerReceiverId = $manager ? (int) $manager['id'] : (int) $agent['id'];
                $pdo->prepare('UPDATE users SET agent_balance = agent_balance + :amount WHERE id = :id')->execute([
                    'amount' => $managerCommission,
                    'id' => $managerReceiverId,
                ]);

                $agentService->insertWalletEntry([
                    'agent_user_id' => $managerReceiverId,
                    'source_agent_user_id' => (int) $agent['id'],
                    'manager_user_id' => $managerReceiverId,
                    'ticket_id' => $ticketId,
                    'payment_request_id' => (int) $request['id'],
                    'entry_type' => $entryType === 'self_commission' ? 'self_commission' : 'manager_commission',
                    'direction' => 'credit',
                    'amount' => $managerCommission,
                    'status' => 'paid',
                    'description' => $entryType === 'self_commission'
                        ? 'Comissao do gerente no proprio bilhete ' . $ticketCode
                        : 'Comissao do gerente no bilhete ' . $ticketCode,
                    'metadata' => ['reference_code' => $referenceCode],
                ]);
            }

            $pdo->prepare(
                'UPDATE agent_payment_requests
                 SET ticket_id = :ticket_id,
                     applied_at = NOW(),
                     status = "issued"
                 WHERE id = :id'
            )->execute([
                'ticket_id' => $ticketId,
                'id' => (int) $request['id'],
            ]);

            $pdo->commit();

            return [
                'issued' => true,
                'status' => 'issued',
                'reference' => $referenceCode,
                'ticket_code' => $ticketCode,
                'ticket_id' => $ticketId,
            ];
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function loadOwnedRequest(int $userId, string $referenceCode): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT apr.*, u.role AS agent_role
             FROM agent_payment_requests apr
             INNER JOIN users u ON u.id = apr.agent_user_id
             WHERE apr.reference_code = :reference_code
               AND (apr.agent_user_id = :user_id OR apr.manager_user_id = :user_id)
             LIMIT 1'
        );
        $stmt->execute([
            'reference_code' => $referenceCode,
            'user_id' => $userId,
        ]);
        $request = $stmt->fetch();

        if (!$request) {
            throw new RuntimeException('Pedido do agente nao encontrado.');
        }

        return $request;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function loadTicketById(int $ticketId): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT id, ticket_code FROM bet_tickets WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $ticketId]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function loadAgentById(int $userId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, role, commission_rate, manager_user_id, is_active
             FROM users
             WHERE id = :id AND role IN ("manager", "bookmaker")
             LIMIT 1'
        );
        $stmt->execute(['id' => $userId]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /**
     * @param array<string, mixed> $user
     */
    private function assertAgentUser(array $user): void
    {
        $role = (string) ($user['role'] ?? '');
        if (!in_array($role, ['manager', 'bookmaker'], true)) {
            throw new RuntimeException('Somente gerentes e cambistas podem usar este fluxo.');
        }

        if ((int) ($user['is_active'] ?? 1) !== 1) {
            throw new RuntimeException('Este agente esta inativo no momento.');
        }
    }

    private function ensureServices(): void
    {
        (new AgentService($this->db))->ensureSchema();
        (new PixGatewayService($this->db))->ensureSettings();
    }
}
