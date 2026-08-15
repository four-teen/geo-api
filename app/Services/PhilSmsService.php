<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;

class PhilSmsService
{
    public function send(string $phoneNumber, string $message): array
    {
        $endpoint = trim((string) config('services.philsms.endpoint'));
        $token = trim((string) config('services.philsms.token'));
        $senderId = trim((string) config('services.philsms.sender_id'));

        if ($endpoint === '' || $token === '' || $senderId === '') {
            throw new RuntimeException('SMS service is not fully configured.');
        }

        if (!filter_var($endpoint, FILTER_VALIDATE_URL)) {
            throw new RuntimeException('SMS service endpoint is invalid.');
        }

        $recipient = $this->normalizePhilippineMobileNumber($phoneNumber);
        $timeout = max(1, min(60, (int) config('services.philsms.timeout', 15)));

        try {
            $response = Http::acceptJson()
                ->asJson()
                ->withToken($token)
                ->timeout($timeout)
                ->post($endpoint, [
                    'recipient' => $recipient,
                    'sender_id' => $senderId,
                    'type' => 'plain',
                    'message' => $message,
                ]);
        } catch (ConnectionException $exception) {
            Log::warning('PhilSMS connection failed.', [
                'recipient' => $recipient,
                'error' => $exception->getMessage(),
            ]);

            throw new RuntimeException('The SMS provider could not be reached. Please try again.', 0, $exception);
        }

        $body = $response->json();
        if (!$response->successful() || !is_array($body) || strtolower((string) ($body['status'] ?? '')) === 'error') {
            $providerMessage = is_array($body) ? trim((string) ($body['message'] ?? '')) : '';

            Log::warning('PhilSMS rejected an outbound message.', [
                'recipient' => $recipient,
                'http_status' => $response->status(),
                'provider_message' => $providerMessage,
            ]);

            throw new RuntimeException($providerMessage !== ''
                ? $providerMessage
                : 'The SMS provider rejected the message. Please try again.');
        }

        return [
            'recipient' => $recipient,
            'status' => (string) ($body['status'] ?? 'success'),
            'uid' => $this->extractMessageUid($body),
        ];
    }

    public function normalizePhilippineMobileNumber(string $phoneNumber): string
    {
        $digits = preg_replace('/\D+/', '', trim($phoneNumber)) ?? '';

        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }

        if (preg_match('/^09\d{9}$/', $digits)) {
            $digits = '63' . substr($digits, 1);
        } elseif (preg_match('/^9\d{9}$/', $digits)) {
            $digits = '63' . $digits;
        }

        if (!preg_match('/^639\d{9}$/', $digits)) {
            throw new InvalidArgumentException('Enter a valid Philippine mobile number, such as 09171234567.');
        }

        return $digits;
    }

    private function extractMessageUid(array $body): ?string
    {
        $data = is_array($body['data'] ?? null) ? $body['data'] : [];
        $uid = $body['uid'] ?? $body['id'] ?? $data['uid'] ?? $data['id'] ?? null;
        $normalized = trim((string) $uid);

        return $normalized !== '' ? $normalized : null;
    }
}
