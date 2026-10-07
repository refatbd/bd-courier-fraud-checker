<?php

namespace Refatbd\BdCourierFraudChecker\Courier;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Refatbd\BdCourierFraudChecker\Exception\BdCourierFraudCheckerException;
use Refatbd\BdCourierFraudChecker\Traits\Helpers;

class Steadfast
{
    use Helpers;

    /**
     * @var string Official Steadfast REST API base URL.
     */
    protected string $apiBaseUrl = 'https://portal.packzy.com/api/v1';

    protected string $cacheKey = 'steedfast_cookie';
    protected int $cacheMinutes = 50;
    protected int $timeout = 20;

    public function __construct()
    {
        $hasApiKeys = !empty(config('bdcourierfraudchecker.steadfast_api_key')) && !empty(config('bdcourierfraudchecker.steadfast_secret_key'));
        $hasLegacyAuth = !empty(config('bdcourierfraudchecker.steedfast_user')) && !empty(config('bdcourierfraudchecker.steedfast_password'));

        if (!$hasApiKeys && !$hasLegacyAuth) {
            throw new BdCourierFraudCheckerException(
                "Steadfast credentials not configured. Please set either STEADFAST_API_KEY & STEADFAST_SECRET_KEY (Recommended) or STEADFAST_USER & STEADFAST_PASSWORD (Legacy)."
            );
        }
    }

    /**
     * Primary entry point: check fraud and delivery statistics for a phone number.
     * Automatically prioritizes the Recommended official REST API (STEADFAST_API_KEY & STEADFAST_SECRET_KEY)
     * if configured, falling back to the Legacy web session driver (STEADFAST_USER & STEADFAST_PASSWORD).
     *
     * @param string $phoneNumber
     * @return array
     */
    public function steadfast($phoneNumber): array
    {
        $phoneNumber = $this->validateBDPhoneNumber($phoneNumber);

        if ($this->hasApiCredentials()) {
            return $this->steadfastApi($phoneNumber);
        }

        return $this->steadfastLegacy($phoneNumber);
    }

    /**
     * Check if official Steadfast API keys are configured (Recommended mode).
     *
     * @return bool
     */
    public function hasApiCredentials(): bool
    {
        return !empty(config('bdcourierfraudchecker.steadfast_api_key')) &&
               !empty(config('bdcourierfraudchecker.steadfast_secret_key'));
    }

    /**
     * Recommended: Query Steadfast using the official REST API (GET /fraud_check/score/{phone}).
     * Fast, reliable, no scraping/cookies needed.
     * Requires STEADFAST_API_KEY and STEADFAST_SECRET_KEY.
     *
     * @param string $phoneNumber
     * @return array
     */
    public function steadfastApi(string $phoneNumber): array
    {
        $phoneNumber = $this->validateBDPhoneNumber($phoneNumber);

        $apiKey = config('bdcourierfraudchecker.steadfast_api_key');
        $secretKey = config('bdcourierfraudchecker.steadfast_secret_key');

        $response = Http::withHeaders([
            'Api-Key'      => $apiKey,
            'Secret-Key'   => $secretKey,
            'Content-Type' => 'application/json',
            'Accept'       => 'application/json',
        ])
        ->timeout($this->timeout)
        ->get("{$this->apiBaseUrl}/fraud_check/score/{$phoneNumber}");

        if ($response->status() === 429) {
            $object = $response->json();
            $err = is_array($object) ? ($object['error'] ?? $object['message'] ?? null) : null;
            return [
                'status'  => false,
                'driver'  => 'api',
                'message' => $err ?: 'Steadfast rate limit exceeded. You have reached your maximum allowed searches.',
                'code'    => 429,
            ];
        }

        if ($response->status() === 401) {
            return [
                'status'  => false,
                'driver'  => 'api',
                'message' => 'Steadfast API authentication failed. Please verify your Api-Key and Secret-Key.',
                'code'    => 401,
            ];
        }

        if ($response->successful()) {
            $object = $response->json();
            if (is_array($object)) {
                $result = $this->formatResult($object);
                $result['driver'] = 'api';
                return $result;
            }
        }

        return [
            'status'  => false,
            'driver'  => 'api',
            'message' => 'Failed to fetch data from Steadfast API.',
            'code'    => $response->status(),
        ];
    }

    /**
     * Legacy: Query Steadfast using web portal session cookie scraping.
     * Retained for complete backwards compatibility.
     * Uses STEADFAST_USER and STEADFAST_PASSWORD.
     *
     * @param string $phoneNumber
     * @return array
     */
    public function steadfastLegacy(string $phoneNumber): array
    {
        $phoneNumber = $this->validateBDPhoneNumber($phoneNumber);

        $loginCookiesArray = Cache::get($this->cacheKey);

        // Two passes: the first uses whatever session we have (cached or
        // freshly logged in). If that session turns out to be stale on the
        // server side, we drop it and force a fresh login on the second pass
        // instead of failing the whole request.
        for ($pass = 0; $pass < 2; $pass++) {
            if (!$loginCookiesArray) {
                $loginCookiesArray = $this->login();

                if (!$loginCookiesArray) {
                    return [
                        'status'  => false,
                        'driver'  => 'legacy',
                        'message' => "Authentication failed",
                    ];
                }
            }

            $authResponse = $this->getOrderData($loginCookiesArray, $phoneNumber);

            // Handle Steadfast account search rate limiting (HTTP 429)
            if ($authResponse->status() === 429) {
                $object = $authResponse->json();
                $err = is_array($object) ? ($object['error'] ?? null) : null;
                return [
                    'status'  => false,
                    'driver'  => 'legacy',
                    'message' => $err ?: 'Steadfast rate limit exceeded. You have reached your maximum allowed searches.',
                    'code'    => 429,
                ];
            }

            // A valid result is a successful JSON response. A stale session
            // makes Steadfast redirect to /login (HTML), which is NOT valid.
            if ($authResponse->successful() && $this->isJsonResponse($authResponse)) {
                $object = $authResponse->json();

                if (is_array($object) && !empty($object)) {
                    $result = $this->formatResult($object);
                    $result['driver'] = 'legacy';
                    return $result;
                }
            }

            // Session is stale or the response was unexpected. Drop the cached
            // cookies and force a fresh login on the next pass.
            Cache::forget($this->cacheKey);
            $loginCookiesArray = null;
        }

        return [
            'status'  => false,
            'driver'  => 'legacy',
            'message' => "Something went wrong. Try again",
        ];
    }

    /**
     * Authenticate against Steadfast web portal and return the session cookies (Legacy).
     * Caches the cookies on success. Returns null on failure.
     *
     * @return array|null
     */
    public function login(): ?array
    {
        $email = config("bdcourierfraudchecker.steedfast_user");
        $password = config("bdcourierfraudchecker.steedfast_password");

        if (empty($email) || empty($password)) {
            return null;
        }

        // First fetch the login page (for the CSRF token + initial cookies)
        $response = Http::withHeaders($this->browserHeaders())
            ->timeout($this->timeout)
            ->get('https://steadfast.com.bd/login');

        if (!$response->successful()) {
            return null;
        }

        $token = $this->extractCsrfToken($response->body());
        if (!$token) {
            return null;
        }

        $cookiesArray = $this->cookiesToArray($response->cookies());

        // Submit credentials. Do NOT follow the redirect so we can capture the
        // authenticated session cookie that Steadfast sets on the 302 response.
        $loginRequest = Http::withHeaders(array_merge($this->browserHeaders(), [
                'Referer' => 'https://steadfast.com.bd/login',
                'Origin'  => 'https://steadfast.com.bd',
            ]))
            ->withCookies($cookiesArray, 'steadfast.com.bd')
            ->asForm()
            ->timeout($this->timeout)
            ->withoutRedirecting()
            ->post('https://steadfast.com.bd/login', [
                '_token'   => $token,
                'email'    => $email,
                'password' => $password,
            ]);

        // A successful login is a 302 redirect to the dashboard. A redirect
        // back to /login (or a 200 re-render) means the credentials failed.
        $location = (string) $loginRequest->header('Location');
        if (!$loginRequest->redirect() || str_contains($location, '/login')) {
            return null;
        }

        // Merge initial cookies with the cookies set by the login response so
        // we retain the complete authenticated session.
        $loginCookiesArray = array_merge(
            $cookiesArray,
            $this->cookiesToArray($loginRequest->cookies())
        );

        if (empty($loginCookiesArray)) {
            return null;
        }

        Cache::put($this->cacheKey, $loginCookiesArray, now()->addMinutes($this->cacheMinutes));

        return $loginCookiesArray;
    }

    protected function getOrderData($loginCookiesArray, $phoneNumber)
    {
        // Ask explicitly for JSON. With these headers a stale session returns a
        // 401/redirect we can detect, rather than a 200 HTML login page.
        return Http::withHeaders(array_merge($this->browserHeaders(), [
                'Accept'           => 'application/json, text/plain, */*',
                'X-Requested-With' => 'XMLHttpRequest',
                'Referer'          => 'https://steadfast.com.bd/user/frauds/check',
            ]))
            ->withCookies($loginCookiesArray, 'steadfast.com.bd')
            ->timeout($this->timeout)
            ->get('https://steadfast.com.bd/user/frauds/check/' . $phoneNumber);
    }

    protected function formatResult(array $object): array
    {
        $deliveredCount = $object['delivered_count'] ?? $object['total_delivered'] ?? null;
        $cancelledCount = $object['cancelled_count'] ?? $object['total_cancelled'] ?? null;

        $deliveryRatio = isset($object['delivery_ratio']) && $object['delivery_ratio'] !== null ? (float) $object['delivery_ratio'] : null;
        $cancellationRatio = isset($object['cancellation_ratio']) && $object['cancellation_ratio'] !== null ? (float) $object['cancellation_ratio'] : null;
        $volumeRange = $object['volume_range'] ?? null;
        $volumeBand = $object['volume_band'] ?? null;

        $hasExplicitCounts = ($deliveredCount !== null || $cancelledCount !== null);

        if ($hasExplicitCounts) {
            $success = (int) ($deliveredCount ?? 0);
            $cancel = (int) ($cancelledCount ?? 0);
            $total = $success + $cancel;
            $deliveredPercentage = $total > 0 ? round(($success / $total) * 100, 2) : ($deliveryRatio ?? 0.0);
            $returnPercentage = $total > 0 ? round(($cancel / $total) * 100, 2) : ($cancellationRatio ?? 0.0);
        } else {
            // New Steadfast schema: extract base order volume from volume_range (e.g. "10+" -> 10, "1-5" -> 5)
            $parsedVolume = 0;
            if ($volumeRange !== null) {
                if (preg_match('/(\d+)\s*\+/', (string) $volumeRange, $m)) {
                    $parsedVolume = (int) $m[1];
                } elseif (preg_match('/(\d+)\s*-\s*(\d+)/', (string) $volumeRange, $m)) {
                    $parsedVolume = (int) $m[2];
                } elseif (is_numeric($volumeRange)) {
                    $parsedVolume = (int) $volumeRange;
                }
            } elseif ($deliveryRatio !== null || ($volumeBand && $volumeBand !== 'none')) {
                $parsedVolume = 1;
            }

            if ($deliveryRatio !== null || $cancellationRatio !== null || $parsedVolume > 0) {
                $deliveredPercentage = $deliveryRatio !== null ? round($deliveryRatio, 2) : 0.0;
                $returnPercentage = $cancellationRatio !== null ? round($cancellationRatio, 2) : ($deliveryRatio !== null ? max(0.0, round(100.0 - $deliveryRatio, 2)) : 0.0);

                $total = $parsedVolume;
                $success = (int) round(($total * $deliveredPercentage) / 100);
                $cancel = (int) round(($total * $returnPercentage) / 100);
            } else {
                // No order history with Steadfast
                $success = 0;
                $cancel = 0;
                $total = 0;
                $deliveredPercentage = 0.0;
                $returnPercentage = 0.0;
            }
        }

        // Extract complaints/fraud reports against this number
        $frauds = [];
        if (!empty($object['frauds']) && is_array($object['frauds'])) {
            foreach ($object['frauds'] as $fraud) {
                $createdAt = $fraud['created_at'] ?? null;

                $frauds[] = [
                    'name'             => $fraud['name'] ?? null,
                    'phone'            => $fraud['phone'] ?? null,
                    'details'          => $fraud['details'] ?? null,
                    'image'            => $fraud['image'] ?? null,
                    'consignment_id'   => $fraud['consignment_id'] ?? null,
                    'created_at'       => $createdAt,
                    'created_at_human' => $createdAt ? Carbon::parse($createdAt)->diffForHumans() : null,
                ];
            }
        }

        $fraudReportCount = isset($object['total_reports'])
            ? (int) $object['total_reports']
            : (isset($object['fraud_reports']) ? (int) $object['fraud_reports'] : count($frauds));

        $fraudCategories = $object['fraud_categories'] ?? [];

        $data = [
            'success'             => $success,
            'cancel'              => $cancel,
            'total'               => $total,
            'deliveredPercentage' => $deliveredPercentage,
            'returnPercentage'    => $returnPercentage,
            'fraudReportCount'    => $fraudReportCount,
            'frauds'              => $frauds,
            'fraud_categories'    => $fraudCategories,
            'volume_range'        => $volumeRange,
            'volume_band'         => $volumeBand,
            'delivery_ratio'      => $deliveryRatio,
            'cancellation_ratio'  => $cancellationRatio,
            'countsAvailable'     => $hasExplicitCounts || $total > 0,
            'showCount'           => true,
        ];

        return [
            'status'  => true,
            'message' => "Successful.",
            'data'    => $data,
        ];
    }

    protected function browserHeaders(): array
    {
        return [
            'User-Agent'      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/137.0.0.0 Safari/537.36',
            'Accept'          => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language' => 'en-US,en;q=0.9',
        ];
    }

    protected function extractCsrfToken(string $html): ?string
    {
        $patterns = [
            '/name="_token"\s+value="([^"]+)"/',
            '/value="([^"]+)"\s+name="_token"/',
            '/<meta name="csrf-token" content="([^"]+)"/',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $html, $matches)) {
                return $matches[1] ?? null;
            }
        }

        return null;
    }

    protected function cookiesToArray($cookieJar): array
    {
        $array = [];
        foreach ($cookieJar->toArray() as $cookie) {
            $array[$cookie['Name']] = $cookie['Value'];
        }

        return $array;
    }

    protected function isJsonResponse($response): bool
    {
        $contentType = (string) $response->header('Content-Type');
        if (str_contains(strtolower($contentType), 'json')) {
            return true;
        }

        return is_array($response->json());
    }
}
