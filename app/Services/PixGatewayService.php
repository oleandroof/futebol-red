<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use RuntimeException;

final class PixGatewayService
{
    private const DEFAULT_PROVIDER = 'ecompag';
    private const PROVIDERS = ['ecompag', 'ggpix'];

    public function __construct(private readonly Database $db)
    {
    }

    public function ensureSettings(): void
    {
        $defaults = [
            'pix_gateway_default' => self::DEFAULT_PROVIDER,
            'pix_gateway_ecompag_enabled' => '1',
            'pix_gateway_ggpix_enabled' => '0',
            'ggpix_api_key' => '',
            'ggpix_webhook_url' => '',
        ];

        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO settings (setting_key, setting_value)
             VALUES (:setting_key, :setting_value)
             ON DUPLICATE KEY UPDATE setting_value = setting_value'
        );

        foreach ($defaults as $key => $value) {
            $stmt->execute([
                'setting_key' => $key,
                'setting_value' => $value,
            ]);
        }
    }

    public function settings(): array
    {
        $this->ensureSettings();

        $rows = $this->db->pdo()->query('SELECT setting_key, setting_value FROM settings')->fetchAll();
        $settings = [];
        foreach ($rows as $row) {
            $settings[(string) $row['setting_key']] = (string) $row['setting_value'];
        }

        return $settings;
    }

    public function gatewaySummary(): array
    {
        $settings = $this->settings();
        $default = $this->defaultProvider($settings);

        return [
            'default_provider' => $default,
            'active_providers' => $this->activeProviders($settings),
            'providers' => [
                [
                    'provider' => 'ecompag',
                    'label' => 'Ecompag',
                    'enabled' => $this->isProviderEnabled('ecompag', $settings),
                    'is_default' => $default === 'ecompag',
                    'docs' => (new EcompagService())->documentation(),
                ],
                [
                    'provider' => 'ggpix',
                    'label' => 'GGPix',
                    'enabled' => $this->isProviderEnabled('ggpix', $settings),
                    'is_default' => $default === 'ggpix',
                    'docs' => (new GgpixService())->documentation(),
                ],
            ],
        ];
    }

    /**
     * @param array<string, string>|null $settings
     * @return array<int, string>
     */
    public function activeProviders(?array $settings = null): array
    {
        $settings ??= $this->settings();
        $active = [];

        foreach (self::PROVIDERS as $provider) {
            if ($this->isProviderEnabled($provider, $settings)) {
                $active[] = $provider;
            }
        }

        return $active;
    }

    /**
     * @param array<string, string>|null $settings
     */
    public function defaultProvider(?array $settings = null): string
    {
        $settings ??= $this->settings();
        $configured = strtolower(trim((string) ($settings['pix_gateway_default'] ?? self::DEFAULT_PROVIDER)));
        $active = $this->activeProviders($settings);

        if (in_array($configured, $active, true)) {
            return $configured;
        }

        if ($active !== []) {
            return $active[0];
        }

        throw new RuntimeException('Nenhum gateway Pix esta ativo no admin.');
    }

    /**
     * @param array<string, string>|null $settings
     */
    public function isProviderEnabled(string $provider, ?array $settings = null): bool
    {
        $settings ??= $this->settings();
        $provider = strtolower(trim($provider));

        return match ($provider) {
            'ggpix' => ($settings['pix_gateway_ggpix_enabled'] ?? '0') === '1',
            default => ($settings['pix_gateway_ecompag_enabled'] ?? '1') === '1',
        };
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, string>|null $settings
     * @return array{provider: string, raw: array<string, mixed>, transaction_id: string, qr_code: string, status: string}
     */
    public function createCharge(string $provider, array $data, ?array $settings = null): array
    {
        $settings ??= $this->settings();
        $provider = strtolower(trim($provider));

        if (!$this->isProviderEnabled($provider, $settings)) {
            throw new RuntimeException('O gateway Pix selecionado nao esta ativo.');
        }

        if ($provider === 'ggpix') {
            $charge = (new GgpixService())->createPixCharge((string) ($settings['ggpix_api_key'] ?? ''), [
                'amountCents' => (int) round(((float) ($data['amount'] ?? 0)) * 100),
                'description' => (string) ($data['description'] ?? ''),
                'payerName' => (string) ($data['payer_name'] ?? ''),
                'payerDocument' => (string) ($data['payer_document'] ?? ''),
                'externalId' => (string) ($data['reference'] ?? ''),
                'webhookUrl' => (string) ($data['webhook_url'] ?? ''),
                'payerEmail' => (string) ($data['payer_email'] ?? ''),
                'payerPhone' => (string) ($data['payer_phone'] ?? ''),
                'metadata' => is_array($data['metadata'] ?? null) ? $data['metadata'] : null,
            ]);

            return [
                'provider' => 'ggpix',
                'raw' => $charge['raw'],
                'transaction_id' => $charge['transaction_id'],
                'qr_code' => $charge['qr_code'],
                'status' => $this->normalizeStatus('ggpix', (string) $charge['status']),
            ];
        }

        $charge = (new EcompagService())->createPixCharge(
            (string) ($settings['ecompag_client_id'] ?? ''),
            (string) ($settings['ecompag_client_secret'] ?? ''),
            [
                'valor' => number_format((float) ($data['amount'] ?? 0), 2, '.', ''),
                'nome' => (string) ($data['payer_name'] ?? ''),
                'cpf' => (string) ($data['payer_document'] ?? ''),
                'descricao' => (string) ($data['description'] ?? ''),
                'urlnoty' => (string) ($data['webhook_url'] ?? ''),
            ]
        );

        return [
            'provider' => 'ecompag',
            'raw' => $charge['raw'],
            'transaction_id' => $charge['transaction_id'],
            'qr_code' => $charge['qr_code'],
            'status' => $this->normalizeStatus('ecompag', (string) $charge['status']),
        ];
    }

    /**
     * @param array<string, string>|null $settings
     * @return array{provider: string, raw: array<string, mixed>, status: string, paid_value: float}
     */
    public function getChargeStatus(string $provider, string $transactionId, ?array $settings = null): array
    {
        $settings ??= $this->settings();
        $provider = strtolower(trim($provider));

        if ($provider === 'ggpix') {
            $response = (new GgpixService())->getPixStatus((string) ($settings['ggpix_api_key'] ?? ''), $transactionId);

            return [
                'provider' => 'ggpix',
                'raw' => $response['raw'],
                'status' => $this->normalizeStatus('ggpix', (string) $response['status']),
                'paid_value' => (float) ($response['paid_value'] ?? 0),
            ];
        }

        $response = (new EcompagService())->getPixStatus(
            (string) ($settings['ecompag_client_id'] ?? ''),
            (string) ($settings['ecompag_client_secret'] ?? ''),
            $transactionId
        );

        return [
            'provider' => 'ecompag',
            'raw' => $response['raw'],
            'status' => $this->normalizeStatus('ecompag', (string) $response['status']),
            'paid_value' => (float) ($response['paid_value'] ?? 0),
        ];
    }

    /**
     * @param array<string, string>|null $settings
     */
    public function webhookUrlFor(string $provider, ?array $settings = null): string
    {
        $settings ??= $this->settings();
        $provider = strtolower(trim($provider));

        return match ($provider) {
            'ggpix' => (string) (($settings['ggpix_webhook_url'] ?? '') !== '' ? $settings['ggpix_webhook_url'] : app_absolute_url('/webhook/ggpix')),
            default => (string) (($settings['ecompag_webhook_url'] ?? '') !== '' ? $settings['ecompag_webhook_url'] : app_absolute_url('/webhook/ecompag')),
        };
    }

    public function normalizeStatus(string $provider, string $status): string
    {
        $provider = strtolower(trim($provider));
        $status = strtoupper(trim($status));

        if ($provider === 'ggpix') {
            return match ($status) {
                'COMPLETE' => 'paid',
                'FAILED', 'CANCELED', 'CANCELLED' => 'failed',
                default => 'pending',
            };
        }

        $normalized = strtolower($status);
        if (in_array($normalized, ['paid', 'aprovado', 'approved', 'success', 'concluido'], true)) {
            return 'paid';
        }

        if (in_array($normalized, ['failed', 'erro', 'canceled', 'cancelled', 'rejected'], true)) {
            return 'failed';
        }

        return 'pending';
    }
}
