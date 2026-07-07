<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

final class GgpixService
{
    private string $baseUrl = 'https://ggpixapi.com/api/v1';

    public function documentation(): array
    {
        return [
            'name' => 'GGPix',
            'docs_url' => 'https://ggpixapi.com/docs/',
            'base_url' => $this->baseUrl,
            'auth' => 'X-API-Key',
            'endpoints' => [
                'POST /pix/in',
                'GET /transactions/:id',
            ],
        ];
    }

    public function createPixCharge(string $apiKey, array $payload): array
    {
        if ($apiKey === '') {
            throw new RuntimeException('API Key da GGPix nao configurada.');
        }

        $response = $this->request(
            'POST',
            $this->baseUrl . '/pix/in',
            $apiKey,
            $payload
        );

        return [
            'raw' => $response,
            'transaction_id' => (string) ($response['id'] ?? ''),
            'qr_code' => (string) ($response['pixCopyPaste'] ?? $response['pixCode'] ?? ''),
            'status' => strtoupper((string) ($response['status'] ?? 'PENDING')),
            'amount' => (float) ($response['amount'] ?? 0),
        ];
    }

    public function getPixStatus(string $apiKey, string $transactionId): array
    {
        if ($apiKey === '' || $transactionId === '') {
            throw new RuntimeException('Parametros invalidos para consulta de status na GGPix.');
        }

        $response = $this->request(
            'GET',
            $this->baseUrl . '/transactions/' . rawurlencode($transactionId),
            $apiKey
        );

        return [
            'raw' => $response,
            'status' => strtoupper((string) ($response['status'] ?? 'PENDING')),
            'paid_value' => (float) ($response['amount'] ?? 0),
        ];
    }

    private function request(string $method, string $url, string $apiKey, array $payload = []): array
    {
        $curl = curl_init();

        $options = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Content-Type: application/json',
                'X-API-Key: ' . $apiKey,
            ],
        ];

        if ($method === 'POST') {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_UNESCAPED_UNICODE);
        }

        curl_setopt_array($curl, $options);
        $raw = curl_exec($curl);
        $error = curl_error($curl);
        $httpCode = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);

        if ($raw === false || $error !== '') {
            throw new RuntimeException('Falha na comunicacao com GGPix: ' . $error);
        }

        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Resposta inesperada da GGPix (HTTP ' . $httpCode . '): ' . trim((string) $raw));
        }

        if ($httpCode >= 400) {
            $message = (string) ($decoded['message'] ?? $decoded['error'] ?? 'Erro desconhecido');
            throw new RuntimeException('HTTP ' . $httpCode . ': ' . $message);
        }

        return $decoded;
    }
}
