<?php

namespace Refatbd\BdCourierFraudChecker\Courier;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Refatbd\BdCourierFraudChecker\Traits\Helpers;

class Steadfast
{
    use Helpers;

    protected string $cacheKey = 'steedfast_cookie';
    protected int $cacheMinutes = 50;
    protected int $timeout = 20;

    public function __construct()
    {
        // Check for required environment variables
        $this->checkRequiredConfig(['steedfast_user', 'steedfast_password']);
    }


    public function steadfast($phoneNumber)
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
                        'status' => false,
                        'message' => "Authentication failed",
                    ];
                }
            }

            $authResponse = $this->getOrderData($loginCookiesArray, $phoneNumber);

            // A valid result is a successful JSON response. A stale session
            // makes Steadfast redirect to /login (HTML), which is NOT valid.
            if ($authResponse->successful() && $this->isJsonResponse($authResponse)) {
                $object = $authResponse->json();

                if (is_array($object) && !empty($object)) {
                    return $this->formatResult($object);
                }
            }

            // Session is stale or the response was unexpected. Drop the cached
            // cookies and force a fresh login on the next pass.
            Cache::forget($this->cacheKey);
            $loginCookiesArray = null;
        }

        return [
            'status' => false,
            'message' => "Something went wrong. Try again",
        ];
    }


    /**
     * Authenticate against Steadfast and return the session cookies.
     * Caches the cookies on success. Returns null on failure.
     */
    private function login(): ?array
    {
        $email = config("bdcourierfraudchecker.steedfast_user");
        $password = config("bdcourierfraudchecker.steedfast_password");

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
                'Origin' => 'https://steadfast.com.bd',
            ]))
            ->withCookies($cookiesArray, 'steadfast.com.bd')
            ->asForm()
            ->timeout($this->timeout)
            ->withoutRedirecting()
            ->post('https://steadfast.com.bd/login', [
                '_token' => $token,
                'email' => $email,
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


    private function getOrderData($loginCookiesArray, $phoneNumber)
    {
        // Ask explicitly for JSON. With these headers a stale session returns a
        // 401/redirect we can detect, rather than a 200 HTML login page.
        return Http::withHeaders(array_merge($this->browserHeaders(), [
                'Accept' => 'application/json, text/plain, */*',
                'X-Requested-With' => 'XMLHttpRequest',
                'Referer' => 'https://steadfast.com.bd/user/frauds/check',
            ]))
            ->withCookies($loginCookiesArray, 'steadfast.com.bd')
            ->timeout($this->timeout)
            ->get('https://steadfast.com.bd/user/frauds/check/' . $phoneNumber);
    }


    private function formatResult(array $object): array
    {
        // Safe extraction of data with fallback to 0
        $success = isset($object['total_delivered']) ? (int)$object['total_delivered'] : 0;
        $cancel = isset($object['total_cancelled']) ? (int)$object['total_cancelled'] : 0;
        $total = $success + $cancel;

        // Calculate percentages
        $deliveredPercentage = $total > 0 ? round(($success / $total) * 100, 2) : 0;
        $returnPercentage = $total > 0 ? round(($cancel / $total) * 100, 2) : 0;

        // Extract complaints/fraud reports against this number
        $frauds = [];
        if (!empty($object['frauds']) && is_array($object['frauds'])) {
            foreach ($object['frauds'] as $fraud) {
                $createdAt = $fraud['created_at'] ?? null;

                $frauds[] = [
                    'name' => $fraud['name'] ?? null,
                    'phone' => $fraud['phone'] ?? null,
                    'details' => $fraud['details'] ?? null,
                    'image' => $fraud['image'] ?? null,
                    'consignment_id' => $fraud['consignment_id'] ?? null,
                    'created_at' => $createdAt,
                    'created_at_human' => $createdAt ? Carbon::parse($createdAt)->diffForHumans() : null,
                ];
            }
        }

        $data = [
            'success' => $success,
            'cancel' => $cancel,
            'total' => $total,
            'deliveredPercentage' => $deliveredPercentage,
            'returnPercentage' => $returnPercentage,
            'fraudReportCount' => count($frauds),
            'frauds' => $frauds,
        ];

        return [
            'status' => true,
            'message' => "Successful.",
            'data' => $data,
        ];
    }


    /**
     * Default browser-like headers so the request is not blocked or served a
     * different response by Steadfast's front-end / WAF.
     */
    private function browserHeaders(): array
    {
        return [
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/137.0.0.0 Safari/537.36',
            'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language' => 'en-US,en;q=0.9',
        ];
    }


    private function extractCsrfToken(string $html): ?string
    {
        // Tolerant of attribute ordering / meta-tag fallback.
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


    private function cookiesToArray($cookieJar): array
    {
        $array = [];
        foreach ($cookieJar->toArray() as $cookie) {
            $array[$cookie['Name']] = $cookie['Value'];
        }

        return $array;
    }


    private function isJsonResponse($response): bool
    {
        $contentType = (string) $response->header('Content-Type');
        if (str_contains(strtolower($contentType), 'json')) {
            return true;
        }

        // Fallback: an HTML body decodes to null, valid JSON to an array.
        return is_array($response->json());
    }


}
