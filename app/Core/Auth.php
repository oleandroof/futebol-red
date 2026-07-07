<?php

declare(strict_types=1);

namespace App\Core;

use App\Services\AgentService;

final class Auth
{
    private string $lastError = '';

    public function __construct(private readonly Database $db)
    {
    }

    public function user(): ?array
    {
        (new AgentService($this->db))->ensureSchema();

        if (!isset($_SESSION['user_id'])) {
            return null;
        }

        $stmt = $this->db->pdo()->prepare(
            'SELECT id, name, email, cpf, role, balance, agent_balance, theme, manager_user_id, is_active, commission_rate, pix_checkout_mode, pix_key, pix_qr_code
             FROM users
             WHERE id = :id
             LIMIT 1'
        );
        $stmt->execute(['id' => $_SESSION['user_id']]);

        $user = $stmt->fetch();
        if (!$user) {
            unset($_SESSION['user_id']);
            return null;
        }

        if (in_array((string) $user['role'], ['manager', 'bookmaker'], true) && (int) ($user['is_active'] ?? 1) !== 1) {
            unset($_SESSION['user_id']);
            return null;
        }

        return $user;
    }

    public function attempt(string $email, string $password): bool
    {
        (new AgentService($this->db))->ensureSchema();
        $this->lastError = '';

        $stmt = $this->db->pdo()->prepare('SELECT * FROM users WHERE LOWER(email) = LOWER(:email) LIMIT 1');
        $stmt->execute(['email' => $email]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password'])) {
            $this->lastError = 'Credenciais invalidas.';
            return false;
        }

        if (in_array((string) ($user['role'] ?? ''), ['manager', 'bookmaker'], true) && (int) ($user['is_active'] ?? 1) !== 1) {
            $this->lastError = 'Este acesso esta temporariamente desativado pelo admin.';
            return false;
        }

        $_SESSION['user_id'] = (int) $user['id'];
        return true;
    }

    public function loginById(int $userId): void
    {
        $_SESSION['user_id'] = $userId;
    }

    public function logout(): void
    {
        unset($_SESSION['user_id']);
    }

    public function requireUser(): array
    {
        $user = $this->user();
        if ($user === null) {
            header('Location: ' . app_url('/'));
            exit;
        }

        return $user;
    }

    public function requireAdmin(): array
    {
        $user = $this->user();
        if ($user === null || $user['role'] !== 'admin') {
            header('Location: ' . app_url('/'));
            exit;
        }

        return $user;
    }

    public function requireAgent(): array
    {
        $user = $this->user();
        if ($user === null || !in_array((string) ($user['role'] ?? ''), ['manager', 'bookmaker'], true)) {
            header('Location: ' . app_url('/'));
            exit;
        }

        return $user;
    }

    public function lastError(): string
    {
        return $this->lastError;
    }
}

