<?php

namespace Refatbd\BdCourierFraudChecker\Courier;

use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\ConnectionException;
use Refatbd\BdCourierFraudChecker\Traits\Helpers;
use Illuminate\Support\Facades\Cache;

class Redx
{
    use Helpers;

    protected string $cacheKey = 'redx_access_token';
    protected int $cacheMinutes = 50;
    protected int $timeout = 20;
    protected ?int $lastAuthStatus = null;

    public function __construct()
    {
        // Check for required environment variables
        $this->checkRequiredConfig(['redx_phone', 'redx_password']);
        $this->validateBDPhoneNumber(config("bdcourierfraudchecker.redx_phone"));
    }

    public function getAccessToken()
    {
        // Try cached token first
        $token = Cache::get($this->cacheKey);

        if ($token) {
            return $token;
        }

        // No cached token, login and get new one
        $response = Http::withHeaders([
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/137.0.0.0 Safari/537.36',
            'Accept' => 'application/json, text/plain, */*',
            'Origin' => 'https://redx.com.bd',
            'referer' => 'https://redx.com.bd/',
        ])->timeout($this->timeout)->post('https://api.redx.com.bd/v4/auth/login', [
            'phone' => '88' . $this->validateBDPhoneNumber(config("bdcourierfraudchecker.redx_phone")),
            'password' => config("bdcourierfraudchecker.redx_password"),
        ]);

        $this->lastAuthStatus = $response->status();
        if (!$response->successful()) {
            return null;
        }

        $token = $response->json('data.accessToken');
        if ($token) {
            Cache::put($this->cacheKey, $token, now()->addMinutes($this->cacheMinutes));
        }

        return $token;
    }

    public function redx(string $queryPhone)
    {
        $queryPhone = $this->validateBDPhoneNumber($queryPhone);
        try {
            return $this->lookup($queryPhone);
        } catch (ConnectionException $exception) {
            return $this->failure(null, 'network_error', 'Unable to connect to RedX.');
        }
    }

    protected function lookup(string $queryPhone)
    {
        $queryPhone = $this->validateBDPhoneNumber($queryPhone);

        $accessToken = $this->getAccessToken();
        if (!$accessToken) {
            return $this->failure($this->lastAuthStatus, 'auth_failed', 'Unable to authenticate with RedX.');
        }

        // Only an expired token earns one re-authentication; no generic request loops.
        $response = $this->getOrderData($accessToken, $queryPhone);
        if ($response->status() === 401) {
            Cache::forget($this->cacheKey);
            $accessToken = $this->getAccessToken();
            if (!$accessToken) return $this->failure($this->lastAuthStatus, 'auth_failed', 'Unable to authenticate with RedX.');
            $response = $this->getOrderData($accessToken, $queryPhone);
        }

        $json = $response->json();
        $status = $response->status();
        if (!RedxResult::accepts($status, $json)) {
            $state = in_array($status, [401, 403], true) ? 'auth_failed' : ($status === 429 ? 'throttled' : 'invalid_response');
            return $this->failure($status, $state, 'RedX did not return a valid customer lookup result.');
        }
        $data = $json['data'];
        $total = (int) $data['totalParcels'];
        $delivered = (int) $data['deliveredParcels'];
        $cancel = $total - $delivered;

        return [
            'status' => true,
            'message' => 'Successful.',
            'meta' => ['http_status' => $status, 'state' => 'connected', 'success_envelope_compatibility' => $status === 400],
            'data' => [
                'success' => $delivered,
                'cancel' => $cancel,
                'total' => $total,
                'deliveredPercentage' => $total > 0 ? round($delivered / $total * 100, 2) : null,
                'returnPercentage' => $total > 0 ? round($cancel / $total * 100, 2) : null,
                'providerReturnPercentage' => is_numeric($data['returnPercentage'] ?? null) ? (float) $data['returnPercentage'] : null,
                'customerSegment' => is_string($data['customerSegment'] ?? null) ? $data['customerSegment'] : null,
                'countsAvailable' => true,
                'showCount' => true,
            ],
        ];
    }

    protected function failure(?int $status, string $state, string $message): array
    {
        return ['status' => false, 'code' => $status, 'message' => $message,
            'meta' => ['http_status' => $status, 'state' => $state]];
    }


    protected function getOrderData($accessToken, $queryPhone)
    {
        return Http::withHeaders([
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/137.0.0.0 Safari/537.36',
            'Accept' => 'application/json, text/plain, */*',
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $accessToken,
            'Origin' => 'https://redx.com.bd',
            'referer' => 'https://redx.com.bd/',
        ])
            ->timeout($this->timeout)
            ->get("https://redx.com.bd/api/redx_se/admin/parcel/customer-success-return-rate?phoneNumber=88{$queryPhone}");
    }


}
