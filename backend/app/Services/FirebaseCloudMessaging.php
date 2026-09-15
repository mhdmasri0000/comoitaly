<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class FirebaseCloudMessaging
{
    /**
     * @return array{project_id: string, client_email: string, private_key: string}
     */
    protected function credentials(): array
    {
        $path = (string) config('firebase.credentials');

        if ($path !== '' && ! is_file($path)) {
            $normalized = str_replace('\\', '/', $path);
            $basename = basename($normalized);
            $candidates = array_values(array_unique(array_filter([
                $path,
                storage_path($basename),
                storage_path($normalized),
                storage_path('app/'.$basename),
                base_path($normalized),
                base_path('storage/'.$basename),
            ])));

            foreach ($candidates as $candidate) {
                if (is_file($candidate)) {
                    $path = $candidate;
                    break;
                }
            }
        }

        if ($path === '' || ! is_file($path)) {
            throw new RuntimeException('Firebase credentials file was not found. Set FIREBASE_CREDENTIALS.');
        }

        $real = realpath($path) ?: $path;
        $storageRoot = realpath(storage_path()) ?: storage_path();
        if (! str_starts_with($real, $storageRoot) && ! str_starts_with($real, base_path())) {
            throw new RuntimeException('Firebase credentials path is not allowed.');
        }

        $json = json_decode((string) file_get_contents($real), true);
        if (! is_array($json)) {
            throw new RuntimeException('Firebase credentials file is invalid JSON.');
        }

        $projectId = (string) (config('firebase.project_id') ?: ($json['project_id'] ?? ''));
        $clientEmail = (string) ($json['client_email'] ?? '');
        $privateKey = (string) ($json['private_key'] ?? '');

        if ($projectId === '' || $clientEmail === '' || $privateKey === '') {
            throw new RuntimeException('Firebase credentials are missing required fields.');
        }

        return [
            'project_id' => $projectId,
            'client_email' => $clientEmail,
            'private_key' => $privateKey,
        ];
    }

    public function isConfigured(): bool
    {
        try {
            $this->credentials();

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function accessToken(): string
    {
        return Cache::remember('firebase_fcm_access_token', 3000, function () {
            $credentials = $this->credentials();
            $now = time();
            $header = $this->base64UrlEncode(json_encode(['alg' => 'RS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR));
            $claims = $this->base64UrlEncode(json_encode([
                'iss' => $credentials['client_email'],
                'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
                'aud' => 'https://oauth2.googleapis.com/token',
                'iat' => $now,
                'exp' => $now + 3600,
            ], JSON_THROW_ON_ERROR));

            $unsigned = $header.'.'.$claims;
            $privateKey = openssl_pkey_get_private($credentials['private_key']);
            if ($privateKey === false) {
                throw new RuntimeException('Unable to read Firebase private key.');
            }

            $signature = '';
            $ok = openssl_sign($unsigned, $signature, $privateKey, OPENSSL_ALGO_SHA256);
            if (! $ok) {
                throw new RuntimeException('Unable to sign Firebase auth JWT.');
            }

            $jwt = $unsigned.'.'.$this->base64UrlEncode($signature);

            $response = Http::asForm()
                ->timeout(20)
                ->post('https://oauth2.googleapis.com/token', [
                    'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                    'assertion' => $jwt,
                ]);

            if (! $response->successful() || empty($response->json('access_token'))) {
                Log::warning('Firebase OAuth token exchange failed', [
                    'status' => $response->status(),
                ]);
                throw new RuntimeException('Failed to obtain Firebase access token.');
            }

            return (string) $response->json('access_token');
        });
    }

    /**
     * @param  array<string, string>  $data
     * @return array{success: bool, message_id?: string, error?: string, invalid_token?: bool}
     */
    public function sendToToken(string $token, string $title, string $body, array $data = []): array
    {
        $token = trim($token);
        if ($token === '') {
            return ['success' => false, 'error' => 'Empty FCM token', 'invalid_token' => true];
        }

        $credentials = $this->credentials();
        $payload = [
            'message' => [
                'token' => $token,
                'notification' => [
                    'title' => $title,
                    'body' => $body,
                ],
                'data' => $this->stringifyData($data),
                'android' => [
                    'priority' => 'high',
                ],
                'apns' => [
                    'headers' => [
                        'apns-priority' => '10',
                    ],
                    'payload' => [
                        'aps' => [
                            'sound' => 'default',
                        ],
                    ],
                ],
            ],
        ];

        $response = Http::withToken($this->accessToken())
            ->timeout(20)
            ->post(
                "https://fcm.googleapis.com/v1/projects/{$credentials['project_id']}/messages:send",
                $payload
            );

        if ($response->successful()) {
            return [
                'success' => true,
                'message_id' => (string) ($response->json('name') ?? ''),
            ];
        }

        $errorCode = (string) data_get($response->json(), 'error.status', '');
        $errorMessage = (string) data_get($response->json(), 'error.message', 'FCM send failed');
        $invalid = in_array($errorCode, ['NOT_FOUND', 'INVALID_ARGUMENT', 'UNREGISTERED', 'PERMISSION_DENIED'], true)
            || str_contains(strtolower($errorMessage), 'not a valid fcm')
            || str_contains(strtolower($errorMessage), 'requested entity was not found')
            || str_contains(strtolower($errorMessage), 'registration token is not a valid');

        Log::info('FCM send failed', [
            'status' => $response->status(),
            'error_code' => $errorCode,
        ]);

        return [
            'success' => false,
            'error' => $errorMessage,
            'invalid_token' => $invalid,
        ];
    }

    /**
     * @param  array<int, string>  $tokens
     * @param  array<string, string>  $data
     * @return array{sent: int, failed: int, invalid_tokens: array<int, string>}
     */
    public function sendToTokens(array $tokens, string $title, string $body, array $data = []): array
    {
        $sent = 0;
        $failed = 0;
        $invalid = [];

        foreach (array_values(array_unique(array_filter(array_map('trim', $tokens)))) as $token) {
            $result = $this->sendToToken($token, $title, $body, $data);
            if ($result['success']) {
                $sent++;
                continue;
            }

            $failed++;
            if (! empty($result['invalid_token'])) {
                $invalid[] = $token;
            }
        }

        return [
            'sent' => $sent,
            'failed' => $failed,
            'invalid_tokens' => $invalid,
        ];
    }

    /**
     * @param  array<string, string>  $data
     * @return array{success: bool, error?: string}
     */
    public function sendToTopic(string $topic, string $title, string $body, array $data = []): array
    {
        $topic = trim($topic);
        if ($topic === '') {
            return ['success' => false, 'error' => 'Empty topic'];
        }

        $credentials = $this->credentials();
        $response = Http::withToken($this->accessToken())
            ->timeout(20)
            ->post(
                "https://fcm.googleapis.com/v1/projects/{$credentials['project_id']}/messages:send",
                [
                    'message' => [
                        'topic' => $topic,
                        'notification' => [
                            'title' => $title,
                            'body' => $body,
                        ],
                        'data' => $this->stringifyData($data),
                    ],
                ]
            );

        if ($response->successful()) {
            return ['success' => true];
        }

        return [
            'success' => false,
            'error' => (string) data_get($response->json(), 'error.message', 'Topic send failed'),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, string>
     */
    protected function stringifyData(array $data): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            if ($value === null) {
                continue;
            }
            $out[(string) $key] = is_scalar($value) ? (string) $value : json_encode($value, JSON_THROW_ON_ERROR);
        }

        return $out;
    }

    protected function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
