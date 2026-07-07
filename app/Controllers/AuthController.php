<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Services\AgentBettingService;
use App\Services\AgentService;
use App\Services\PixGatewayService;
use RuntimeException;

final class AuthController extends Controller
{
    public function login(): void
    {
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        $password = (string) ($_POST['password'] ?? '');

        if (!$this->auth->attempt($email, $password)) {
            $_SESSION['flash'] = ['type' => 'error', 'message' => $this->auth->lastError() !== '' ? $this->auth->lastError() : 'Credenciais invalidas.'];
            $this->redirect('/');
        }

        $user = $this->auth->user();
        $_SESSION['flash'] = ['type' => 'success', 'message' => 'Login realizado com sucesso.'];
        if ($user && in_array((string) ($user['role'] ?? ''), ['manager', 'bookmaker'], true)) {
            $this->redirect('/agent?auth=' . time());
        }

        $this->redirect('/?auth=' . time());
    }

    public function register(): void
    {
        $name = trim((string) ($_POST['name'] ?? ''));
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        $cpf = preg_replace('/\D+/', '', (string) ($_POST['cpf'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');

        if ($name === '' || $email === '' || $password === '' || $cpf === '') {
            $_SESSION['flash'] = ['type' => 'error', 'message' => 'Preencha todos os campos do cadastro.'];
            $this->redirect('/');
        }

        if (strlen($cpf) !== 11) {
            $_SESSION['flash'] = ['type' => 'error', 'message' => 'CPF invalido. Informe 11 digitos.'];
            $this->redirect('/');
        }

        $stmt = $this->db->pdo()->prepare('SELECT id FROM users WHERE email = :email OR cpf = :cpf LIMIT 1');
        $stmt->execute(['email' => $email, 'cpf' => $cpf]);
        if ($stmt->fetch()) {
            $_SESSION['flash'] = ['type' => 'error', 'message' => 'E-mail ou CPF ja cadastrado.'];
            $this->redirect('/');
        }

        $insert = $this->db->pdo()->prepare('INSERT INTO users (name, email, cpf, password, role, balance, theme, created_at) VALUES (:name, :email, :cpf, :password, "user", 0, "classic", NOW())');
        $insert->execute([
            'name' => $name,
            'email' => $email,
            'cpf' => $cpf,
            'password' => password_hash($password, PASSWORD_DEFAULT),
        ]);

        $this->auth->loginById((int) $this->db->pdo()->lastInsertId());
        $_SESSION['flash'] = ['type' => 'success', 'message' => 'Cadastro concluido. Bem-vindo.'];
        $this->redirect('/?auth=' . time());
    }

    public function createDepositPix(): void
    {
        $user = $this->auth->requireUser();
        $amount = (float) ($_POST['amount'] ?? 0);

        if ($amount <= 0) {
            $_SESSION['flash'] = ['type' => 'error', 'message' => 'Valor de deposito invalido.'];
            $this->redirect('/');
        }

        $gatewayService = new PixGatewayService($this->db);

        try {
            $settings = $gatewayService->settings();
            $provider = $gatewayService->defaultProvider($settings);
            $webhookUrl = $gatewayService->webhookUrlFor($provider, $settings);
        } catch (RuntimeException $exception) {
            $_SESSION['flash'] = ['type' => 'error', 'message' => 'Gateway Pix indisponivel: ' . $exception->getMessage()];
            $this->redirect('/');
        }

        $pdo = $this->db->pdo();
        $reference = 'PIXDEP-' . date('YmdHis') . '-' . random_int(100, 999);

        $pdo->prepare('INSERT INTO pix_transactions (reference_code, user_id, tx_type, amount, status, provider, created_at) VALUES (:reference_code, :user_id, "deposit", :amount, "pending", :provider, NOW())')->execute([
            'reference_code' => $reference,
            'user_id' => $user['id'],
            'amount' => $amount,
            'provider' => $provider,
        ]);

        $pixId = (int) $pdo->lastInsertId();

        try {
            $charge = $gatewayService->createCharge($provider, [
                'amount' => $amount,
                'payer_name' => (string) $user['name'],
                'payer_document' => (string) $user['cpf'],
                'payer_email' => (string) ($user['email'] ?? ''),
                'description' => 'Deposito via Pix - ' . $reference,
                'reference' => $reference,
                'webhook_url' => $webhookUrl,
                'metadata' => [
                    'context' => 'wallet_deposit',
                    'reference_code' => $reference,
                    'user_id' => (int) $user['id'],
                ],
            ], $settings);

            if ($charge['transaction_id'] === '' || $charge['qr_code'] === '') {
                throw new RuntimeException('Resposta do gateway Pix sem identificador/QRCode.');
            }

            $status = (string) $charge['status'];

            $pdo->prepare('UPDATE pix_transactions SET provider_transaction_id = :provider_transaction_id, qr_code = :qr_code, status = :status, request_payload = :request_payload, response_payload = :response_payload WHERE id = :id')->execute([
                'provider_transaction_id' => $charge['transaction_id'],
                'qr_code' => $charge['qr_code'],
                'status' => $status,
                'request_payload' => json_encode(['amount' => $amount, 'reference' => $reference, 'webhook' => $webhookUrl, 'provider' => $provider], JSON_UNESCAPED_UNICODE),
                'response_payload' => json_encode($charge['raw'], JSON_UNESCAPED_UNICODE),
                'id' => $pixId,
            ]);

            $_SESSION['pix_checkout'] = [
                'context' => 'deposit',
                'reference' => $reference,
                'transaction_id' => $charge['transaction_id'],
                'amount' => $amount,
                'qr_code' => $charge['qr_code'],
                'status' => $status,
                'provider' => $provider,
            ];

            $_SESSION['flash'] = ['type' => 'success', 'message' => 'Pix gerado. Pague e clique em Atualizar status.'];
        } catch (RuntimeException $exception) {
            $pdo->prepare('UPDATE pix_transactions SET status = "failed", response_payload = :response_payload WHERE id = :id')->execute([
                'response_payload' => json_encode(['error' => $exception->getMessage()], JSON_UNESCAPED_UNICODE),
                'id' => $pixId,
            ]);
            $_SESSION['flash'] = ['type' => 'error', 'message' => 'Falha ao gerar Pix: ' . $exception->getMessage()];
        }

        $this->redirect('/');
    }

    public function syncDepositStatus(): void
    {
        $user = $this->auth->requireUser();
        $transactionId = trim((string) ($_POST['transaction_id'] ?? ''));

        if ($transactionId === '') {
            $_SESSION['flash'] = ['type' => 'error', 'message' => 'Transacao Pix nao informada.'];
            $this->redirect('/');
        }

        $stmt = $this->db->pdo()->prepare(
            'SELECT id, provider, provider_transaction_id
             FROM pix_transactions
             WHERE provider_transaction_id = :provider_transaction_id AND user_id = :user_id
             LIMIT 1'
        );
        $stmt->execute([
            'provider_transaction_id' => $transactionId,
            'user_id' => (int) $user['id'],
        ]);
        $transaction = $stmt->fetch();

        if (!$transaction) {
            $_SESSION['flash'] = ['type' => 'error', 'message' => 'Transacao Pix nao encontrada para este usuario.'];
            $this->redirect('/');
        }

        $gatewayService = new PixGatewayService($this->db);

        try {
            $statusResponse = $gatewayService->getChargeStatus((string) $transaction['provider'], $transactionId);
            $status = (string) $statusResponse['status'];

            $stmt = $this->db->pdo()->prepare('UPDATE pix_transactions SET status = :status, response_payload = :response_payload WHERE provider_transaction_id = :provider_transaction_id AND provider = :provider AND user_id = :user_id');
            $stmt->execute([
                'status' => $status,
                'response_payload' => json_encode($statusResponse['raw'], JSON_UNESCAPED_UNICODE),
                'provider_transaction_id' => $transactionId,
                'provider' => (string) $transaction['provider'],
                'user_id' => $user['id'],
            ]);

            if ($status === 'paid') {
                $this->applyApprovedDepositByProviderTransaction($transactionId, (string) $transaction['provider']);
                $_SESSION['flash'] = ['type' => 'success', 'message' => 'Pagamento confirmado e saldo creditado.'];
            } else {
                $_SESSION['flash'] = ['type' => 'success', 'message' => 'Status atual do Pix: ' . strtoupper($status) . '.'];
            }
        } catch (RuntimeException $exception) {
            $_SESSION['flash'] = ['type' => 'error', 'message' => 'Erro ao consultar status do Pix.'];
        }

        $this->redirect('/');
    }

    public function ecompagWebhook(): void
    {
        $this->handlePixWebhook('ecompag');
    }

    public function ggpixWebhook(): void
    {
        $this->handlePixWebhook('ggpix');
    }

    public function withdraw(): void
    {
        $user = $this->auth->requireUser();
        $amount = (float) ($_POST['amount'] ?? 0);

        if ($amount <= 0) {
            $_SESSION['flash'] = ['type' => 'error', 'message' => 'Valor de saque invalido.'];
            $this->redirect('/');
        }

        if ((float) $user['balance'] < $amount) {
            $_SESSION['flash'] = ['type' => 'error', 'message' => 'Saldo insuficiente para saque.'];
            $this->redirect('/');
        }

        $pdo = $this->db->pdo();
        $pdo->beginTransaction();

        $pdo->prepare('UPDATE users SET balance = balance - :amount WHERE id = :id')->execute([
            'amount' => $amount,
            'id' => $user['id'],
        ]);

        $reference = 'SAQ-' . date('YmdHis') . '-' . random_int(100, 999);
        $pdo->prepare('INSERT INTO transactions (reference, user_id, type, amount, status, created_at) VALUES (:reference, :user_id, "withdrawal", :amount, "pending", NOW())')->execute([
            'reference' => $reference,
            'user_id' => $user['id'],
            'amount' => $amount,
        ]);

        $pdo->prepare('INSERT INTO pix_transactions (reference_code, user_id, tx_type, amount, status, provider, created_at) VALUES (:reference_code, :user_id, "withdraw", :amount, "pending", "manual", NOW())')->execute([
            'reference_code' => $reference,
            'user_id' => $user['id'],
            'amount' => $amount,
        ]);

        $pdo->commit();

        $_SESSION['flash'] = ['type' => 'success', 'message' => 'Saque registrado como pendente para analise.'];
        $this->redirect('/');
    }

    public function changePassword(): void
    {
        $user = $this->auth->requireUser();

        $currentPassword = (string) ($_POST['current_password'] ?? '');
        $newPassword = (string) ($_POST['new_password'] ?? '');
        $confirmPassword = (string) ($_POST['confirm_password'] ?? '');

        if ($currentPassword === '' || $newPassword === '' || $confirmPassword === '') {
            $_SESSION['flash'] = ['type' => 'error', 'message' => 'Preencha todos os campos para alterar a senha.'];
            $this->redirect('/');
        }

        if (strlen($newPassword) < 6) {
            $_SESSION['flash'] = ['type' => 'error', 'message' => 'A nova senha deve ter no minimo 6 caracteres.'];
            $this->redirect('/');
        }

        if ($newPassword !== $confirmPassword) {
            $_SESSION['flash'] = ['type' => 'error', 'message' => 'Confirmacao de senha nao confere.'];
            $this->redirect('/');
        }

        $stmt = $this->db->pdo()->prepare('SELECT password FROM users WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => (int) $user['id']]);
        $row = $stmt->fetch();

        if (!$row || !password_verify($currentPassword, (string) $row['password'])) {
            $_SESSION['flash'] = ['type' => 'error', 'message' => 'Senha atual invalida.'];
            $this->redirect('/');
        }

        $this->db->pdo()->prepare('UPDATE users SET password = :password WHERE id = :id')->execute([
            'password' => password_hash($newPassword, PASSWORD_DEFAULT),
            'id' => (int) $user['id'],
        ]);

        $_SESSION['flash'] = ['type' => 'success', 'message' => 'Senha alterada com sucesso.'];
        $this->redirect('/');
    }

    public function logout(): void
    {
        $this->auth->logout();
        unset($_SESSION['betslip'], $_SESSION['pix_checkout']);
        $_SESSION['flash'] = ['type' => 'success', 'message' => 'Sessao encerrada.'];
        $this->redirect('/');
    }

    private function handlePixWebhook(string $provider): void
    {
        $rawInput = file_get_contents('php://input') ?: '';
        $payload = json_decode($rawInput, true);
        if (!is_array($payload)) {
            $payload = $_POST;
        }

        $transactionId = (string) ($payload['transactionId'] ?? $payload['idTransaction'] ?? $payload['transaction_id'] ?? $payload['txid'] ?? '');
        if ($transactionId === '') {
            http_response_code(400);
            echo 'missing transaction';
            return;
        }

        $status = (new PixGatewayService($this->db))->normalizeStatus($provider, (string) ($payload['status'] ?? $payload['situacao'] ?? 'pending'));

        $stmt = $this->db->pdo()->prepare('UPDATE pix_transactions SET status = :status, response_payload = :response_payload WHERE provider = :provider AND provider_transaction_id = :provider_transaction_id');
        $stmt->execute([
            'status' => $status,
            'response_payload' => json_encode($payload, JSON_UNESCAPED_UNICODE),
            'provider' => $provider,
            'provider_transaction_id' => $transactionId,
        ]);

        if ($status === 'paid') {
            $this->applyApprovedDepositByProviderTransaction($transactionId, $provider);
        }

        try {
            (new AgentBettingService($this->db))->applyProviderWebhook($provider, $transactionId, (string) ($payload['status'] ?? $payload['situacao'] ?? 'pending'), $payload);
        } catch (\Throwable) {
        }

        echo 'ok';
    }

    private function settings(): array
    {
        (new AgentService($this->db))->ensureSchema();
        (new PixGatewayService($this->db))->ensureSettings();

        $rows = $this->db->pdo()->query('SELECT setting_key, setting_value FROM settings')->fetchAll();
        $settings = [];
        foreach ($rows as $row) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }

        return $settings;
    }

    private function applyApprovedDepositByProviderTransaction(string $providerTransactionId, ?string $provider = null): void
    {
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();

        $sql = 'SELECT * FROM pix_transactions WHERE provider_transaction_id = :provider_transaction_id AND tx_type = "deposit"';
        if ($provider !== null && $provider !== '') {
            $sql .= ' AND provider = :provider';
        }
        $sql .= ' LIMIT 1 FOR UPDATE';

        $stmt = $pdo->prepare($sql);
        $params = ['provider_transaction_id' => $providerTransactionId];
        if ($provider !== null && $provider !== '') {
            $params['provider'] = $provider;
        }
        $stmt->execute($params);
        $row = $stmt->fetch();

        if (!$row) {
            $pdo->rollBack();
            return;
        }

        if (!empty($row['applied_at'])) {
            $pdo->rollBack();
            return;
        }

        if ($row['status'] !== 'paid') {
            $pdo->rollBack();
            return;
        }

        $pdo->prepare('UPDATE users SET balance = balance + :amount WHERE id = :id')->execute([
            'amount' => (float) $row['amount'],
            'id' => (int) $row['user_id'],
        ]);

        $pdo->prepare('INSERT INTO transactions (reference, user_id, type, amount, status, created_at) VALUES (:reference, :user_id, "deposit", :amount, "paid", NOW())')->execute([
            'reference' => $row['reference_code'],
            'user_id' => (int) $row['user_id'],
            'amount' => (float) $row['amount'],
        ]);

        $pdo->prepare('UPDATE pix_transactions SET applied_at = NOW() WHERE id = :id')->execute([
            'id' => (int) $row['id'],
        ]);

        $pdo->commit();
    }
}
