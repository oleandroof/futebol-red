<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use RuntimeException;
use Throwable;

final class AgentService
{
    private static bool $schemaEnsured = false;

    public function __construct(private readonly Database $db)
    {
    }

    public function ensureSchema(): void
    {
        if (self::$schemaEnsured) {
            return;
        }

        $pdo = $this->db->pdo();

        $pdo->exec('ALTER TABLE users MODIFY COLUMN role ENUM("admin", "user", "manager", "bookmaker") NOT NULL DEFAULT "user"');
        $this->ensureUserColumn('manager_user_id', 'INT UNSIGNED NULL AFTER role');
        $this->ensureUserColumn('is_active', 'TINYINT(1) NOT NULL DEFAULT 1 AFTER manager_user_id');
        $this->ensureUserColumn('agent_balance', 'DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER balance');
        $this->ensureUserColumn('commission_rate', 'DECIMAL(5,2) NOT NULL DEFAULT 0.00 AFTER agent_balance');
        $this->ensureUserColumn('pix_checkout_mode', 'VARCHAR(20) NOT NULL DEFAULT "gateway" AFTER commission_rate');
        $this->ensureUserColumn('pix_key', 'VARCHAR(255) NULL AFTER pix_checkout_mode');
        $this->ensureUserColumn('pix_qr_code', 'LONGTEXT NULL AFTER pix_key');

        $this->ensureUserIndex('idx_users_manager_user', 'manager_user_id');

        $this->ensureTicketColumn('sales_channel', 'VARCHAR(20) NOT NULL DEFAULT "user" AFTER status');
        $this->ensureTicketColumn('agent_user_id', 'INT UNSIGNED NULL AFTER user_id');
        $this->ensureTicketColumn('manager_user_id', 'INT UNSIGNED NULL AFTER agent_user_id');
        $this->ensureTicketColumn('payment_request_id', 'INT UNSIGNED NULL AFTER manager_user_id');
        $this->ensureTicketColumn('agent_commission_amount', 'DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER payment_request_id');
        $this->ensureTicketColumn('manager_commission_amount', 'DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER agent_commission_amount');

        $pdo->exec('CREATE TABLE IF NOT EXISTS agent_wallet_transactions (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            agent_user_id INT UNSIGNED NOT NULL,
            source_agent_user_id INT UNSIGNED NULL,
            manager_user_id INT UNSIGNED NULL,
            ticket_id INT UNSIGNED NULL,
            payment_request_id INT UNSIGNED NULL,
            entry_type VARCHAR(40) NOT NULL,
            direction ENUM("credit", "debit") NOT NULL DEFAULT "credit",
            amount DECIMAL(12,2) NOT NULL,
            status ENUM("pending", "paid", "failed") NOT NULL DEFAULT "paid",
            description VARCHAR(255) NOT NULL,
            metadata LONGTEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_agent_wallet_agent (agent_user_id),
            INDEX idx_agent_wallet_manager (manager_user_id),
            INDEX idx_agent_wallet_ticket (ticket_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        $pdo->exec('CREATE TABLE IF NOT EXISTS agent_payment_requests (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            reference_code VARCHAR(80) NOT NULL UNIQUE,
            agent_user_id INT UNSIGNED NOT NULL,
            manager_user_id INT UNSIGNED NULL,
            ticket_id INT UNSIGNED NULL,
            payment_mode VARCHAR(20) NOT NULL DEFAULT "gateway",
            gateway_provider VARCHAR(20) NULL,
            status VARCHAR(20) NOT NULL DEFAULT "pending",
            stake DECIMAL(12,2) NOT NULL,
            total_odd DECIMAL(10,2) NOT NULL,
            potential_return DECIMAL(12,2) NOT NULL,
            provider_transaction_id VARCHAR(120) NULL,
            qr_code LONGTEXT NULL,
            payment_key VARCHAR(255) NULL,
            items_payload LONGTEXT NOT NULL,
            request_payload LONGTEXT NULL,
            response_payload LONGTEXT NULL,
            paid_at DATETIME NULL,
            applied_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_agent_payment_agent (agent_user_id),
            INDEX idx_agent_payment_manager (manager_user_id),
            INDEX idx_agent_payment_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        self::$schemaEnsured = true;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function managers(): array
    {
        $this->ensureSchema();

        $sql = 'SELECT u.id, u.name, u.email, u.cpf, u.is_active, u.agent_balance, u.commission_rate,
                       (SELECT COUNT(*) FROM users b WHERE b.manager_user_id = u.id AND b.role = "bookmaker") AS bookmakers_total,
                       (SELECT COALESCE(SUM(awt.amount), 0)
                        FROM agent_wallet_transactions awt
                        WHERE awt.agent_user_id = u.id
                          AND awt.entry_type IN ("manager_commission", "self_commission")
                          AND awt.status = "paid") AS commissions_total
                FROM users u
                WHERE u.role = "manager"
                ORDER BY u.name ASC';

        return $this->db->pdo()->query($sql)->fetchAll();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function bookmakers(): array
    {
        $this->ensureSchema();

        $sql = 'SELECT u.id, u.name, u.email, u.cpf, u.is_active, u.agent_balance, u.commission_rate,
                       u.manager_user_id, m.name AS manager_name,
                       COALESCE(SUM(CASE WHEN awt.entry_type = "bookmaker_commission" AND awt.status = "paid" THEN awt.amount ELSE 0 END), 0) AS commissions_total
                FROM users u
                LEFT JOIN users m ON m.id = u.manager_user_id
                LEFT JOIN agent_wallet_transactions awt ON awt.agent_user_id = u.id
                WHERE u.role = "bookmaker"
                GROUP BY u.id, u.name, u.email, u.cpf, u.is_active, u.agent_balance, u.commission_rate, u.manager_user_id, m.name
                ORDER BY u.name ASC';

        return $this->db->pdo()->query($sql)->fetchAll();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function activeManagers(): array
    {
        $this->ensureSchema();
        return $this->db->pdo()->query('SELECT id, name FROM users WHERE role = "manager" AND is_active = 1 ORDER BY name ASC')->fetchAll();
    }

    public function agentById(int $id): ?array
    {
        $this->ensureSchema();
        if ($id <= 0) {
            return null;
        }

        $stmt = $this->db->pdo()->prepare(
            'SELECT id, name, email, cpf, role, manager_user_id, is_active, agent_balance, commission_rate, pix_checkout_mode, pix_key, pix_qr_code
             FROM users
             WHERE id = :id AND role IN ("manager", "bookmaker")
             LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function saveAgent(array $data, ?int $agentId = null): int
    {
        $this->ensureSchema();

        $role = in_array((string) ($data['role'] ?? ''), ['manager', 'bookmaker'], true) ? (string) $data['role'] : '';
        $name = trim((string) ($data['name'] ?? ''));
        $email = strtolower(trim((string) ($data['email'] ?? '')));
        $cpf = preg_replace('/\D+/', '', (string) ($data['cpf'] ?? ''));
        $password = (string) ($data['password'] ?? '');
        $managerUserId = (int) ($data['manager_user_id'] ?? 0);
        $isActive = !empty($data['is_active']) ? 1 : 0;
        $commissionRate = max(0, min(100, (float) ($data['commission_rate'] ?? 0)));
        $pixMode = in_array((string) ($data['pix_checkout_mode'] ?? ''), ['gateway', 'custom_key', 'custom_qr'], true)
            ? (string) $data['pix_checkout_mode']
            : 'gateway';
        $pixKey = trim((string) ($data['pix_key'] ?? ''));
        $pixQrCode = trim((string) ($data['pix_qr_code'] ?? ''));

        if ($role === '' || $name === '' || $email === '' || strlen($cpf) !== 11) {
            throw new RuntimeException('Preencha corretamente nome, e-mail, CPF e perfil do agente.');
        }

        if ($role === 'bookmaker' && $managerUserId <= 0) {
            throw new RuntimeException('Selecione um gerente para o cambista.');
        }

        if ($role === 'bookmaker') {
            $managerStmt = $this->db->pdo()->prepare('SELECT id FROM users WHERE id = :id AND role = "manager" LIMIT 1');
            $managerStmt->execute(['id' => $managerUserId]);
            if (!$managerStmt->fetch()) {
                throw new RuntimeException('Gerente vinculado nao encontrado.');
            }
        }

        if ($pixMode === 'custom_key' && $pixKey === '') {
            throw new RuntimeException('Informe a chave Pix personalizada do agente.');
        }

        if ($pixMode === 'custom_qr' && $pixQrCode === '') {
            throw new RuntimeException('Informe o QR Code personalizado do agente.');
        }

        if ($agentId === null && strlen($password) < 6) {
            throw new RuntimeException('A senha inicial do agente deve ter ao menos 6 caracteres.');
        }

        $pdo = $this->db->pdo();

        $check = $pdo->prepare(
            'SELECT id FROM users
             WHERE (LOWER(email) = LOWER(:email) OR cpf = :cpf)
               AND (:id = 0 OR id <> :id)
             LIMIT 1'
        );
        $check->execute([
            'email' => $email,
            'cpf' => $cpf,
            'id' => (int) $agentId,
        ]);

        if ($check->fetch()) {
            throw new RuntimeException('Ja existe outro usuario com este e-mail ou CPF.');
        }

        if ($agentId !== null && $agentId > 0) {
            $sql = 'UPDATE users
                    SET name = :name,
                        email = :email,
                        cpf = :cpf,
                        role = :role,
                        manager_user_id = :manager_user_id,
                        is_active = :is_active,
                        commission_rate = :commission_rate,
                        pix_checkout_mode = :pix_checkout_mode,
                        pix_key = :pix_key,
                        pix_qr_code = :pix_qr_code';

            $params = [
                'name' => $name,
                'email' => $email,
                'cpf' => $cpf,
                'role' => $role,
                'manager_user_id' => $role === 'bookmaker' ? $managerUserId : null,
                'is_active' => $isActive,
                'commission_rate' => $commissionRate,
                'pix_checkout_mode' => $pixMode,
                'pix_key' => $pixMode === 'custom_key' ? $pixKey : null,
                'pix_qr_code' => $pixMode === 'custom_qr' ? $pixQrCode : null,
                'id' => $agentId,
            ];

            if ($password !== '') {
                $sql .= ', password = :password';
                $params['password'] = password_hash($password, PASSWORD_DEFAULT);
            }

            $sql .= ' WHERE id = :id AND role IN ("manager", "bookmaker")';
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);

            if ($stmt->rowCount() === 0 && $this->agentById($agentId) === null) {
                throw new RuntimeException('Agente nao encontrado para atualizacao.');
            }

            return $agentId;
        }

        $stmt = $pdo->prepare(
            'INSERT INTO users
                (name, email, cpf, password, role, manager_user_id, is_active, balance, agent_balance, commission_rate, pix_checkout_mode, pix_key, pix_qr_code, theme, created_at)
             VALUES
                (:name, :email, :cpf, :password, :role, :manager_user_id, :is_active, 0, 0, :commission_rate, :pix_checkout_mode, :pix_key, :pix_qr_code, "classic", NOW())'
        );
        $stmt->execute([
            'name' => $name,
            'email' => $email,
            'cpf' => $cpf,
            'password' => password_hash($password, PASSWORD_DEFAULT),
            'role' => $role,
            'manager_user_id' => $role === 'bookmaker' ? $managerUserId : null,
            'is_active' => $isActive,
            'commission_rate' => $commissionRate,
            'pix_checkout_mode' => $pixMode,
            'pix_key' => $pixMode === 'custom_key' ? $pixKey : null,
            'pix_qr_code' => $pixMode === 'custom_qr' ? $pixQrCode : null,
        ]);

        return (int) $pdo->lastInsertId();
    }

    public function deleteAgent(int $agentId): void
    {
        $this->ensureSchema();

        $agent = $this->agentById($agentId);
        if ($agent === null) {
            throw new RuntimeException('Agente nao encontrado.');
        }

        if ((string) $agent['role'] === 'manager') {
            $childStmt = $this->db->pdo()->prepare('SELECT COUNT(*) FROM users WHERE manager_user_id = :id AND role = "bookmaker"');
            $childStmt->execute(['id' => $agentId]);
            if ((int) $childStmt->fetchColumn() > 0) {
                throw new RuntimeException('Nao e possivel excluir gerente com cambistas vinculados.');
            }
        }

        $linkedStmt = $this->db->pdo()->prepare(
            'SELECT
                (SELECT COUNT(*) FROM bet_tickets WHERE user_id = :id OR agent_user_id = :id) +
                (SELECT COUNT(*) FROM agent_wallet_transactions WHERE agent_user_id = :id OR source_agent_user_id = :id) +
                (SELECT COUNT(*) FROM agent_payment_requests WHERE agent_user_id = :id)
             AS total_links'
        );
        $linkedStmt->execute(['id' => $agentId]);

        if ((int) $linkedStmt->fetchColumn() > 0) {
            throw new RuntimeException('Este agente ja possui caixa, pagamentos ou bilhetes. Use status inativo em vez de excluir.');
        }

        $stmt = $this->db->pdo()->prepare('DELETE FROM users WHERE id = :id AND role IN ("manager", "bookmaker")');
        $stmt->execute(['id' => $agentId]);
    }

    public function adjustBalance(int $agentId, float $amount, int $performedByUserId, string $description = ''): void
    {
        $this->ensureSchema();

        if ($agentId <= 0 || abs($amount) <= 0) {
            throw new RuntimeException('Ajuste de saldo invalido.');
        }

        $agent = $this->agentById($agentId);
        if ($agent === null) {
            throw new RuntimeException('Agente nao encontrado para ajuste de saldo.');
        }

        $pdo = $this->db->pdo();
        $pdo->beginTransaction();

        try {
            $pdo->prepare('UPDATE users SET agent_balance = agent_balance + :amount WHERE id = :id')->execute([
                'amount' => $amount,
                'id' => $agentId,
            ]);

            $this->insertWalletEntry([
                'agent_user_id' => $agentId,
                'source_agent_user_id' => null,
                'manager_user_id' => (int) ($agent['role'] === 'manager' ? $agentId : ($agent['manager_user_id'] ?? 0)) ?: null,
                'ticket_id' => null,
                'payment_request_id' => null,
                'entry_type' => 'manual_adjustment',
                'direction' => $amount >= 0 ? 'credit' : 'debit',
                'amount' => abs($amount),
                'status' => 'paid',
                'description' => $description !== '' ? $description : 'Ajuste manual pelo admin #' . $performedByUserId,
                'metadata' => ['performed_by_user_id' => $performedByUserId],
            ]);

            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function summary(): array
    {
        $this->ensureSchema();
        $pdo = $this->db->pdo();

        return [
            'managers_total' => (int) $pdo->query('SELECT COUNT(*) FROM users WHERE role = "manager"')->fetchColumn(),
            'bookmakers_total' => (int) $pdo->query('SELECT COUNT(*) FROM users WHERE role = "bookmaker"')->fetchColumn(),
            'active_agents_total' => (int) $pdo->query('SELECT COUNT(*) FROM users WHERE role IN ("manager", "bookmaker") AND is_active = 1')->fetchColumn(),
            'inactive_agents_total' => (int) $pdo->query('SELECT COUNT(*) FROM users WHERE role IN ("manager", "bookmaker") AND is_active = 0')->fetchColumn(),
            'agent_balance_total' => (float) $pdo->query('SELECT COALESCE(SUM(agent_balance), 0) FROM users WHERE role IN ("manager", "bookmaker")')->fetchColumn(),
            'commissions_total' => (float) $pdo->query('SELECT COALESCE(SUM(amount), 0) FROM agent_wallet_transactions WHERE status = "paid" AND direction = "credit" AND entry_type IN ("manager_commission", "bookmaker_commission", "self_commission")')->fetchColumn(),
        ];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public function adminCashEntries(array $filters = []): array
    {
        $this->ensureSchema();

        $where = [];
        $params = [];

        $agentId = (int) ($filters['agent_id'] ?? 0);
        if ($agentId > 0) {
            $where[] = 'awt.agent_user_id = :agent_id';
            $params['agent_id'] = $agentId;
        }

        $role = (string) ($filters['role'] ?? 'all');
        if (in_array($role, ['manager', 'bookmaker'], true)) {
            $where[] = 'u.role = :role';
            $params['role'] = $role;
        }

        $status = (string) ($filters['status'] ?? 'all');
        if (in_array($status, ['pending', 'paid', 'failed'], true)) {
            $where[] = 'awt.status = :status';
            $params['status'] = $status;
        }

        $entryType = trim((string) ($filters['entry_type'] ?? ''));
        if ($entryType !== '' && $entryType !== 'all') {
            $where[] = 'awt.entry_type = :entry_type';
            $params['entry_type'] = $entryType;
        }

        $dateFrom = trim((string) ($filters['date_from'] ?? ''));
        if ($dateFrom !== '') {
            $where[] = 'DATE(awt.created_at) >= :date_from';
            $params['date_from'] = $dateFrom;
        }

        $dateTo = trim((string) ($filters['date_to'] ?? ''));
        if ($dateTo !== '') {
            $where[] = 'DATE(awt.created_at) <= :date_to';
            $params['date_to'] = $dateTo;
        }

        $sql = 'SELECT awt.*, u.name AS agent_name, u.role AS agent_role, u.is_active,
                       src.name AS source_agent_name, mgr.name AS manager_name
                FROM agent_wallet_transactions awt
                INNER JOIN users u ON u.id = awt.agent_user_id
                LEFT JOIN users src ON src.id = awt.source_agent_user_id
                LEFT JOIN users mgr ON mgr.id = awt.manager_user_id';

        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        $sql .= ' ORDER BY awt.created_at DESC LIMIT 250';
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    public function panelSummary(array $user): array
    {
        $this->ensureSchema();

        $userId = (int) ($user['id'] ?? 0);
        $role = (string) ($user['role'] ?? '');

        $summary = [
            'agent_balance' => (float) ($user['agent_balance'] ?? 0),
            'commissions_available' => (float) ($user['agent_balance'] ?? 0),
            'commission_rate' => (float) ($user['commission_rate'] ?? 0),
            'open_tickets' => 0,
            'tickets_total' => 0,
            'commissions_total' => 0.0,
            'bookmakers_total' => 0,
        ];

        $ticketWhere = $role === 'manager'
            ? '(bt.user_id = :user_id OR bt.manager_user_id = :user_id)'
            : 'bt.user_id = :user_id';

        $ticketStmt = $this->db->pdo()->prepare(
            'SELECT
                COUNT(*) AS tickets_total,
                SUM(CASE WHEN bt.status = "open" THEN 1 ELSE 0 END) AS open_tickets
             FROM bet_tickets bt
             WHERE ' . $ticketWhere
        );
        $ticketStmt->execute(['user_id' => $userId]);
        $ticketRow = $ticketStmt->fetch() ?: [];

        $summary['tickets_total'] = (int) ($ticketRow['tickets_total'] ?? 0);
        $summary['open_tickets'] = (int) ($ticketRow['open_tickets'] ?? 0);

        $commissionStmt = $this->db->pdo()->prepare(
            'SELECT COALESCE(SUM(amount), 0) FROM agent_wallet_transactions WHERE agent_user_id = :user_id AND direction = "credit" AND status = "paid"'
        );
        $commissionStmt->execute(['user_id' => $userId]);
        $summary['commissions_total'] = (float) $commissionStmt->fetchColumn();

        if ($role === 'manager') {
            $bookmakersStmt = $this->db->pdo()->prepare('SELECT COUNT(*) FROM users WHERE manager_user_id = :id AND role = "bookmaker"');
            $bookmakersStmt->execute(['id' => $userId]);
            $summary['bookmakers_total'] = (int) $bookmakersStmt->fetchColumn();
        }

        return $summary;
    }

    public function withdrawCommission(int $userId, float $amount): void
    {
        $this->ensureSchema();

        if ($userId <= 0 || $amount <= 0) {
            throw new RuntimeException('Valor de saque invalido.');
        }

        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('SELECT id, role, manager_user_id, agent_balance FROM users WHERE id = :id LIMIT 1 FOR UPDATE');
            $stmt->execute(['id' => $userId]);
            $user = $stmt->fetch();

            if (!$user || !in_array((string) ($user['role'] ?? ''), ['manager', 'bookmaker'], true)) {
                throw new RuntimeException('Apenas gerente ou cambista podem sacar comissao.');
            }

            $currentBalance = (float) ($user['agent_balance'] ?? 0);
            if ($currentBalance < $amount) {
                throw new RuntimeException('Saldo de comissao insuficiente para saque.');
            }

            $pdo->prepare('UPDATE users SET agent_balance = agent_balance - :amount WHERE id = :id')->execute([
                'amount' => $amount,
                'id' => (int) $user['id'],
            ]);

            $this->insertWalletEntry([
                'agent_user_id' => (int) $user['id'],
                'source_agent_user_id' => null,
                'manager_user_id' => (string) ($user['role'] ?? '') === 'manager'
                    ? (int) $user['id']
                    : ((int) ($user['manager_user_id'] ?? 0) ?: null),
                'ticket_id' => null,
                'payment_request_id' => null,
                'entry_type' => 'commission_withdrawal',
                'direction' => 'debit',
                'amount' => round($amount, 2),
                'status' => 'pending',
                'description' => 'Saque de comissao solicitado pelo agente',
                'metadata' => ['requested_by_user_id' => (int) $user['id']],
            ]);

            $reference = 'AGSAQ-' . date('YmdHis') . '-' . random_int(100, 999);
            $pdo->prepare(
                'INSERT INTO transactions (reference, user_id, type, amount, status, created_at)
                 VALUES (:reference, :user_id, "withdrawal", :amount, "pending", NOW())'
            )->execute([
                'reference' => $reference,
                'user_id' => (int) $user['id'],
                'amount' => round($amount, 2),
            ]);

            $pdo->prepare(
                'INSERT INTO pix_transactions (reference_code, user_id, tx_type, amount, status, provider, created_at)
                 VALUES (:reference_code, :user_id, "withdraw", :amount, "pending", "manual", NOW())'
            )->execute([
                'reference_code' => $reference,
                'user_id' => (int) $user['id'],
                'amount' => round($amount, 2),
            ]);

            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function managerBookmakers(int $managerId): array
    {
        $this->ensureSchema();
        if ($managerId <= 0) {
            return [];
        }

        $stmt = $this->db->pdo()->prepare(
            'SELECT u.id, u.name, u.email, u.is_active, u.agent_balance, u.commission_rate,
                    COALESCE(SUM(CASE WHEN awt.entry_type = "bookmaker_commission" AND awt.status = "paid" THEN awt.amount ELSE 0 END), 0) AS commissions_total
             FROM users u
             LEFT JOIN agent_wallet_transactions awt ON awt.agent_user_id = u.id
             WHERE u.role = "bookmaker" AND u.manager_user_id = :manager_id
             GROUP BY u.id, u.name, u.email, u.is_active, u.agent_balance, u.commission_rate
             ORDER BY u.name ASC'
        );
        $stmt->execute(['manager_id' => $managerId]);

        return $stmt->fetchAll();
    }

    /**
     * @param array<string, mixed> $user
     * @return array<int, array<string, mixed>>
     */
    public function panelTickets(array $user): array
    {
        $this->ensureSchema();

        $userId = (int) ($user['id'] ?? 0);
        $role = (string) ($user['role'] ?? '');
        $where = $role === 'manager'
            ? '(bt.user_id = :user_id OR bt.manager_user_id = :user_id)'
            : 'bt.user_id = :user_id';

        $stmt = $this->db->pdo()->prepare(
            'SELECT bt.id, bt.ticket_code, bt.stake, bt.total_odd, bt.potential_return, bt.status, bt.created_at,
                    bt.sales_channel, bt.agent_commission_amount, bt.manager_commission_amount,
                    u.name AS seller_name
             FROM bet_tickets bt
             INNER JOIN users u ON u.id = bt.user_id
             WHERE ' . $where . '
             ORDER BY bt.created_at DESC
             LIMIT 120'
        );
        $stmt->execute(['user_id' => $userId]);

        return $stmt->fetchAll();
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public function panelCashEntries(array $user, array $filters = []): array
    {
        $this->ensureSchema();

        $userId = (int) ($user['id'] ?? 0);
        $role = (string) ($user['role'] ?? '');
        $where = [];
        $params = ['user_id' => $userId];

        if ($role === 'manager') {
            $where[] = '(awt.agent_user_id = :user_id OR awt.manager_user_id = :user_id)';
        } else {
            $where[] = 'awt.agent_user_id = :user_id';
        }

        $status = (string) ($filters['status'] ?? 'all');
        if (in_array($status, ['pending', 'paid', 'failed'], true)) {
            $where[] = 'awt.status = :status';
            $params['status'] = $status;
        }

        $entryType = trim((string) ($filters['entry_type'] ?? ''));
        if ($entryType !== '' && $entryType !== 'all') {
            $where[] = 'awt.entry_type = :entry_type';
            $params['entry_type'] = $entryType;
        }

        $dateFrom = trim((string) ($filters['date_from'] ?? ''));
        if ($dateFrom !== '') {
            $where[] = 'DATE(awt.created_at) >= :date_from';
            $params['date_from'] = $dateFrom;
        }

        $dateTo = trim((string) ($filters['date_to'] ?? ''));
        if ($dateTo !== '') {
            $where[] = 'DATE(awt.created_at) <= :date_to';
            $params['date_to'] = $dateTo;
        }

        $stmt = $this->db->pdo()->prepare(
            'SELECT awt.*, agent.name AS agent_name, source.name AS source_agent_name
             FROM agent_wallet_transactions awt
             INNER JOIN users agent ON agent.id = awt.agent_user_id
             LEFT JOIN users source ON source.id = awt.source_agent_user_id
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY awt.created_at DESC
             LIMIT 200'
        );
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /**
     * @param array<string, mixed> $user
     * @return array<int, array<string, mixed>>
     */
    public function pendingPaymentRequests(array $user): array
    {
        $this->ensureSchema();

        $userId = (int) ($user['id'] ?? 0);
        $role = (string) ($user['role'] ?? '');
        $where = $role === 'manager'
            ? '(apr.agent_user_id = :user_id OR apr.manager_user_id = :user_id)'
            : 'apr.agent_user_id = :user_id';

        $stmt = $this->db->pdo()->prepare(
            'SELECT apr.*, u.name AS agent_name
             FROM agent_payment_requests apr
             INNER JOIN users u ON u.id = apr.agent_user_id
             WHERE ' . $where . '
             ORDER BY apr.created_at DESC
             LIMIT 80'
        );
        $stmt->execute(['user_id' => $userId]);

        return $stmt->fetchAll();
    }

    /**
     * @param array<string, mixed> $entry
     */
    public function insertWalletEntry(array $entry): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO agent_wallet_transactions
                (agent_user_id, source_agent_user_id, manager_user_id, ticket_id, payment_request_id, entry_type, direction, amount, status, description, metadata, created_at)
             VALUES
                (:agent_user_id, :source_agent_user_id, :manager_user_id, :ticket_id, :payment_request_id, :entry_type, :direction, :amount, :status, :description, :metadata, NOW())'
        )->execute([
            'agent_user_id' => (int) ($entry['agent_user_id'] ?? 0),
            'source_agent_user_id' => isset($entry['source_agent_user_id']) ? (int) $entry['source_agent_user_id'] : null,
            'manager_user_id' => isset($entry['manager_user_id']) ? (int) $entry['manager_user_id'] : null,
            'ticket_id' => isset($entry['ticket_id']) ? (int) $entry['ticket_id'] : null,
            'payment_request_id' => isset($entry['payment_request_id']) ? (int) $entry['payment_request_id'] : null,
            'entry_type' => (string) ($entry['entry_type'] ?? 'manual_adjustment'),
            'direction' => (string) ($entry['direction'] ?? 'credit'),
            'amount' => (float) ($entry['amount'] ?? 0),
            'status' => (string) ($entry['status'] ?? 'paid'),
            'description' => (string) ($entry['description'] ?? ''),
            'metadata' => isset($entry['metadata']) ? json_encode($entry['metadata'], JSON_UNESCAPED_UNICODE) : null,
        ]);
    }

    private function ensureUserColumn(string $column, string $definition): void
    {
        $exists = $this->db->pdo()->query('SHOW COLUMNS FROM users LIKE ' . $this->db->pdo()->quote($column))->fetch();
        if ($exists === false) {
            $this->db->pdo()->exec('ALTER TABLE users ADD COLUMN ' . $column . ' ' . $definition);
        }
    }

    private function ensureTicketColumn(string $column, string $definition): void
    {
        $exists = $this->db->pdo()->query('SHOW COLUMNS FROM bet_tickets LIKE ' . $this->db->pdo()->quote($column))->fetch();
        if ($exists === false) {
            $this->db->pdo()->exec('ALTER TABLE bet_tickets ADD COLUMN ' . $column . ' ' . $definition);
        }
    }

    private function ensureUserIndex(string $indexName, string $column): void
    {
        $stmt = $this->db->pdo()->prepare('SHOW INDEX FROM users WHERE Key_name = :index_name');
        $stmt->execute(['index_name' => $indexName]);
        if ($stmt->fetch() === false) {
            $this->db->pdo()->exec('ALTER TABLE users ADD INDEX ' . $indexName . ' (' . $column . ')');
        }
    }
}
