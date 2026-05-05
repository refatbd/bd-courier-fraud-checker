<?php

namespace Refatbd\BdCourierFraudChecker\Courier;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Refatbd\BdCourierFraudChecker\Traits\Helpers;

class Carrybee
{
    use Helpers;

    protected string $frontendUrl    = 'https://merchant.carrybee.com';
    protected string $apiUrl         = 'https://api-merchant.carrybee.com';
    protected string $tokenCacheKey  = 'carrybee_bearer_token';
    protected string $cookieCacheKey = 'carrybee_session_cookies';
    protected string $bizIdCacheKey  = 'carrybee_business_id';
    protected int    $cacheMinutes   = 50;

    public function __construct()
    {
        $this->checkRequiredConfig(['carrybee_phone', 'carrybee_password']);
    }

    // -------------------------------------------------------------------------
    // Public entry point
    // -------------------------------------------------------------------------

    public function carrybee(string $phoneNumber): array
    {
        $phoneNumber = $this->validateBDPhoneNumber($phoneNumber);

        // Try with cached token first, retry once with fresh login if it fails
        for ($attempt = 0; $attempt < 2; $attempt++) {

            $token      = $this->getBearerToken();
            $businessId = Cache::get($this->bizIdCacheKey);

            if (!$token || !$businessId) {
                return ['status' => false, 'message' => 'Authentication failed'];
            }

            $response = $this->fetchCustomer($token, $businessId, $phoneNumber);

            if ($response->successful()) {
                return $this->parseResponse($response->json());
            }

            // Token expired — clear and retry with a fresh login
            $this->clearCache();
        }

        return ['status' => false, 'message' => 'Failed to fetch data from Carrybee.'];
    }

    // -------------------------------------------------------------------------
    // Auth — 3-step NextAuth flow
    // Step 1: GET  /api/auth/csrf           -> csrfToken + cookies
    // Step 2: POST /api/auth/callback/login -> session cookie
    // Step 3: GET  /api/auth/session        -> Bearer token + businessId
    // -------------------------------------------------------------------------

    protected function getBearerToken(): ?string
    {
        $token = Cache::get($this->tokenCacheKey);
        if ($token) {
            return $token;
        }

        return $this->login();
    }

    protected function login(): ?string
    {
        // Step 1: GET CSRF token
        $csrfResponse = Http::withHeaders([
            'Accept'       => 'application/json',
            'Content-Type' => 'application/json',
        ])->get($this->frontendUrl . '/api/auth/csrf');

        if (!$csrfResponse->successful()) {
            return null;
        }

        $csrfToken = $csrfResponse->json('csrfToken');
        if (!$csrfToken) {
            return null;
        }

        // Collect cookies from CSRF response
        $sessionCookies = [];
        foreach ($csrfResponse->cookies()->toArray() as $cookie) {
            $sessionCookies[$cookie['Name']] = $cookie['Value'];
        }

        // Step 2: POST credentials
        $loginResponse = Http::withCookies($sessionCookies, 'merchant.carrybee.com')
            ->withHeaders([
                'Content-Type'           => 'application/x-www-form-urlencoded',
                'x-auth-return-redirect' => '1',
                'Referer'                => $this->frontendUrl . '/login',
                'Origin'                 => $this->frontendUrl,
            ])
            ->withOptions(['allow_redirects' => false])
            ->asForm()
            ->post($this->frontendUrl . '/api/auth/callback/login', [
                'phone'       => '+88' . config('bdcourierfraudchecker.carrybee_phone'),
                'password'    => config('bdcourierfraudchecker.carrybee_password'),
                'csrfToken'   => $csrfToken,
                'callbackUrl' => $this->frontendUrl . '/login',
            ]);

        // Merge new cookies (session token added here)
        foreach ($loginResponse->cookies()->toArray() as $cookie) {
            $sessionCookies[$cookie['Name']] = $cookie['Value'];
        }

        if (empty($sessionCookies['__Secure-authjs.session-token'])) {
            return null;
        }

        // Step 3: GET session → extract Bearer token & business ID
        $sessionResponse = Http::withCookies($sessionCookies, 'merchant.carrybee.com')
            ->withHeaders([
                'Accept'       => 'application/json',
                'Content-Type' => 'application/json',
            ])
            ->get($this->frontendUrl . '/api/auth/session');

        if (!$sessionResponse->successful()) {
            return null;
        }

        $session = $sessionResponse->json();

        // Bearer token is at root level: session.accessToken
        $bearerToken = $session['accessToken']
            ?? $session['access_token']
            ?? $session['user']['accessToken']
            ?? $session['user']['access_token']
            ?? null;

        // Business ID is at session.user.selectedBusinessId
        $businessId = $session['user']['selectedBusinessId']
            ?? $session['user']['businessId']
            ?? $session['user']['business_id']
            ?? $session['user']['merchantBusinesses'][0]['businessId']
            ?? null;

        if (!$bearerToken) {
            return null;
        }

        Cache::put($this->tokenCacheKey,  $bearerToken,    now()->addMinutes($this->cacheMinutes));
        Cache::put($this->cookieCacheKey, $sessionCookies, now()->addMinutes($this->cacheMinutes));

        if ($businessId) {
            Cache::put($this->bizIdCacheKey, $businessId, now()->addMinutes($this->cacheMinutes));
        }

        return $bearerToken;
    }

    // -------------------------------------------------------------------------
    // API call — GET /api/v2/businesses/{id}/customers/+880XXXXXXXXXX
    // -------------------------------------------------------------------------

    protected function fetchCustomer(string $token, string $businessId, string $phone)
    {
        $fullPhone = urlencode('+880' . $phone);

        return Http::withHeaders([
            'Accept'        => 'application/json',
            'Content-Type'  => 'application/json',
            'Authorization' => 'Bearer ' . $token,
            'Origin'        => $this->frontendUrl,
            'Referer'       => $this->frontendUrl . '/',
        ])->get($this->apiUrl . "/api/v2/businesses/{$businessId}/customers/{$fullPhone}");
    }

    // -------------------------------------------------------------------------
    // Parse response
    // -------------------------------------------------------------------------

    protected function parseResponse(array $raw): array
    {
        if (!isset($raw['data']) || ($raw['error'] ?? false) === true) {
            return [
                'status'  => false,
                'message' => $raw['message'] ?? 'Unknown error occurred.',
            ];
        }

        $data = $raw['data'];

        $total   = (int)   ($data['total_order']     ?? 0);
        $cancel  = (int)   ($data['cancelled_order'] ?? 0);
        $success = max($total - $cancel, 0);

        // Carrybee returns success_rate directly — use it when available
        $deliveredPct = isset($data['success_rate'])
            ? (float) $data['success_rate']
            : ($total > 0 ? round(($success / $total) * 100, 2) : 0);

        $returnPct = $total > 0 ? round(($cancel / $total) * 100, 2) : 0;

        return [
            'status'  => true,
            'message' => 'Successful.',
            'data'    => [
                'success'             => $success,
                'cancel'              => $cancel,
                'total'               => $total,
                'deliveredPercentage' => $deliveredPct,
                'returnPercentage'    => $returnPct,
            ],
        ];
    }

    // -------------------------------------------------------------------------
    // Clear all cached auth data
    // -------------------------------------------------------------------------

    protected function clearCache(): void
    {
        Cache::forget($this->tokenCacheKey);
        Cache::forget($this->cookieCacheKey);
        Cache::forget($this->bizIdCacheKey);
    }
}
