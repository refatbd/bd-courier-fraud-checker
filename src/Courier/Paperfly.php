<?php

namespace Refatbd\BdCourierFraudChecker\Courier;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Refatbd\BdCourierFraudChecker\Traits\Helpers;

class Paperfly
{
    use Helpers;

    protected string $loginUrl      = 'https://go-app.paperfly.com.bd/merchant/api/react/authentication/login_using_password.php';
    protected string $smartCheckUrl = 'https://go-app.paperfly.com.bd/merchant/api/react/smart-check/smart-check-v2.php';
    protected string $paperflyKey   = 'Paperfly_~La?Rj73FcLm';
    protected string $tokenCacheKey = 'paperfly_bearer_token';
    protected int    $cacheMinutes  = 50;
    protected int    $timeout       = 20;

    public function __construct()
    {
        $this->checkRequiredConfig(['paperfly_user', 'paperfly_password']);
    }

    /**
     * Check customer delivery history by phone number.
     *
     * @param string $phoneNumber
     * @return array
     */
    public function paperfly(string $phoneNumber): array
    {
        $phoneNumber = $this->validateBDPhoneNumber($phoneNumber);

        for ($attempt = 0; $attempt < 2; $attempt++) {
            $token = $this->getBearerToken();

            if (!$token) {
                return [
                    'status'  => false,
                    'message' => 'Authentication failed',
                ];
            }

            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $token,
                'paperflykey'   => $this->paperflyKey,
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json, text/plain, */*',
                'Origin'        => 'https://go.paperfly.com.bd',
                'Referer'       => 'https://go.paperfly.com.bd/',
            ])
            ->timeout($this->timeout)
            ->post($this->smartCheckUrl . '?search_text=' . $phoneNumber, [
                'search_text' => $phoneNumber,
            ]);

            if ($response->successful()) {
                $data = $response->json();
                if (is_array($data)) {
                    return $this->formatResult($data);
                }
            }

            // Stale or invalid session token — clear cache and retry
            Cache::forget($this->tokenCacheKey);
        }

        return [
            'status'  => false,
            'message' => 'Failed to fetch data from Paperfly.',
        ];
    }

    /**
     * Retrieve bearer token from cache or perform login.
     *
     * @return string|null
     */
    protected function getBearerToken(): ?string
    {
        $token = Cache::get($this->tokenCacheKey);
        if ($token) {
            return $token;
        }

        return $this->login();
    }

    /**
     * Perform login to obtain session token.
     *
     * @return string|null
     */
    protected function login(): ?string
    {
        $username = config('bdcourierfraudchecker.paperfly_user');
        $password = config('bdcourierfraudchecker.paperfly_password');

        $response = Http::withHeaders([
            'Content-Type' => 'application/json',
            'Accept'       => 'application/json, text/plain, */*',
            'Origin'       => 'https://go.paperfly.com.bd',
            'Referer'      => 'https://go.paperfly.com.bd/',
        ])
        ->timeout($this->timeout)
        ->post($this->loginUrl, [
            'username' => $username,
            'password' => $password,
        ]);

        if (!$response->successful()) {
            return null;
        }

        $data = $response->json();
        $token = $data['token'] ?? $data['user']['token'] ?? null;

        if ($token) {
            Cache::put($this->tokenCacheKey, $token, now()->addMinutes($this->cacheMinutes));
            return $token;
        }

        return null;
    }

    /**
     * Format the raw API result into a standardized response array.
     *
     * @param array $data
     * @return array
     */
    protected function formatResult(array $data): array
    {
        $smartCheck = $data['smart_check'] ?? [];

        return [
            'status'        => true,
            'customer_phone'=> $data['customer_phone'] ?? null,
            'delivered'     => (int) ($data['delivered'] ?? 0),
            'partial'       => (int) ($data['partial'] ?? 0),
            'returned'      => (int) ($data['returned'] ?? 0),
            'total'         => (int) ($data['total'] ?? 0),
            'delivery_rate' => isset($smartCheck['delivery_rate']) ? (float) $smartCheck['delivery_rate'] : 0.0,
            'return_rate'   => isset($smartCheck['return_rate']) ? (float) $smartCheck['return_rate'] : 0.0,
            'label'         => $smartCheck['label'] ?? null,
            'color'         => $smartCheck['color'] ?? null,
            'icon'          => $smartCheck['icon'] ?? null,
            'note'          => $smartCheck['note'] ?? null,
        ];
    }
}
