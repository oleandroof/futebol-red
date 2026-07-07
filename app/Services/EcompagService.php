<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

final class EcompagService
{
    private string $baseUrl = 'https://api.ecompag.com/v2/';

    public function documentation(): array
    {
        return [
            'base_url' => $this->baseUrl,
            'endpoints' => [
                'POST pix/qrcode.php (Gerar Pix)',
                'GET pix/status.php?client_id=XXX&client_secret=XXX&transaction_id=YYY (Consultar status)',
                'Webhook (urlnoty) para confirmacao automatica com transactionId',
            ],
            'notes' => 'Saldo do usuario deve ser creditado apenas apos status confirmado como pago.',
        ];
    }

    public function createPixCharge(string $clientId, string $clientSecret, array $payload): array
    {
        if ($clientId === '' || $clientSecret === '') {
            throw new RuntimeException('Client ID e Secret Key da Ecompag nao configurados.');
        }

        $url = $this->baseUrl . 'pix/qrcode.php';
        $response = $this->request('POST', $url, array_merge([
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
        ], $payload));

        return [
            'raw' => $response,
            'transaction_id' => (string) ($response['transactionId'] ?? $response['idTransaction'] ?? $response['id'] ?? $response['txid'] ?? ''),
            'qr_code' => (string) ($response['qrcode'] ?? $response['qrCode'] ?? $response['pixCopiaECola'] ?? $response['pix'] ?? $response['code'] ?? ''),
            'status' => strtoupper((string) ($response['status'] ?? 'PENDING')),
        ];
    }

    public function getPixStatus(string $clientId, string $clientSecret, string $transactionId): array
    {
        if ($clientId === '' || $clientSecret === '' || $transactionId === '') {
            throw new RuntimeException('Parametros invalidos para consulta de status.');
        }

        $url = $this->baseUrl . 'pix/status.php?' . http_build_query([
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'transaction_id' => $transactionId,
        ]);
        $response = $this->request('GET', $url);
        $transaction = is_array($response['transaction'] ?? null) ? $response['transaction'] : [];

        return [
            'raw' => $response,
            'status' => strtoupper((string) ($transaction['status'] ?? $response['status'] ?? $response['situacao'] ?? 'PENDING')),
            'paid_value' => (float) ($transaction['amount'] ?? $response['valor'] ?? $response['amount'] ?? 0),
        ];
    }

    private function request(string $method, string $url, array $payload = []): array
    {
        $ch = curl_init();

        $options = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Content-Type: application/x-www-form-urlencoded',
            ],
        ];

        if ($method === 'POST') {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = http_build_query($payload);
        }

        curl_setopt_array($ch, $options);
        $raw = curl_exec($ch);
        $error = curl_error($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($raw === false || $error !== '') {
            throw new RuntimeException('Falha na comunicacao com Ecompag: ' . $error);
        }

        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Resposta inesperada da Ecompag (HTTP ' . $httpCode . '): ' . trim((string) $raw));
        }

        if ($httpCode >= 400) {
            $message = (string) ($decoded['message'] ?? 'Erro desconhecido');
            throw new RuntimeException('HTTP ' . $httpCode . ': ' . $message);
        }

        return $decoded;
    }
}
