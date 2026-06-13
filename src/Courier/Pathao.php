<?php

namespace Refatbd\BdCourierFraudChecker\Courier;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Refatbd\BdCourierFraudChecker\Traits\Helpers;

class Pathao
{
    use Helpers;

    protected string $cacheKey = 'pathao_access_token';
    protected int $cacheMinutes = 50;
    protected int $timeout = 20;

    public function __construct()
    {
        //Check required environment variables
        $this->checkRequiredConfig(['pathao_user', 'pathao_password']);
    }

    protected function getAccessToken()
    {
        // Try cached token first
        $token = Cache::get($this->cacheKey);
        if ($token) {
            return $token;
        }

        // No cached token, login and get new one
        $response = Http::withHeaders($this->browserHeaders())
            ->timeout($this->timeout)
            ->post('https://merchant.pathao.com/api/v1/login', [
                'username' => config("bdcourierfraudchecker.pathao_user"),
                'password' => config("bdcourierfraudchecker.pathao_password"),
            ]);

        // Check if the response is not success
        if (!$response->successful()) {
            return null;
        }

        $token = $response->json('access_token');
        $token = $token ? trim($token) : null;
        if ($token) {
            Cache::put($this->cacheKey, $token, now()->addMinutes($this->cacheMinutes));
        }

        return $token;
    }

    public function pathao($phone)
    {
        $phone = $this->validateBDPhoneNumber($phone);

        $maxRetries = 2;
        $attempt = 0;
        $accessToken = null;
        while ($attempt < $maxRetries) {
            $accessToken = $this->getAccessToken();

            if ($accessToken) {
                break;
            }
            Cache::forget($this->cacheKey);
            $attempt++;
        }

        if ($accessToken) {

            $maxRetries = 2;
            $attempt = 0;
            $response = null;
            while ($attempt < $maxRetries) {
                $response = $this->getOrderData($accessToken, $phone);

                if ($response->successful()) {
                    break;
                }

                // Token expired/invalid — drop it and re-authenticate so we
                // recover within this same call instead of failing.
                if ($response->status() === 401) {
                    Cache::forget($this->cacheKey);
                    $accessToken = $this->getAccessToken();
                    if (!$accessToken) {
                        break;
                    }
                }

                $attempt++;
            }

            if ($response && $response->successful()) {
                $object = $response->json();
                $payload = isset($object['data']) && is_array($object['data']) ? $object['data'] : [];
                $customer = isset($payload['customer']) && is_array($payload['customer']) ? $payload['customer'] : [];

                // Pathao's own fraud signal (e.g. "good_customer", "fraud_customer")
                $customerRating = $payload['customer_rating'] ?? ($customer['rating'] ?? null);
                $riskLevel = $this->mapRiskLevel($customerRating);

                // Pathao explicitly tells us whether counts are exposed for this
                // account. Counts may live under data.customer.* (v1) or directly
                // under data.* (v2), so we probe both with several key names.
                $showCount = $payload['show_count'] ?? null;

                $success = $this->extractCount([$customer, $payload], [
                    'successful_delivery', 'successful_deliveries', 'success', 'delivered', 'total_delivered',
                ]);
                $total = $this->extractCount([$customer, $payload], [
                    'total_delivery', 'total_deliveries', 'total', 'total_parcel', 'total_order',
                ]);

                // Counts are usable only when Pathao did not flag them off AND we
                // actually found at least one numeric value. Merchants entitled to
                // numeric data (show_count = true) keep the full count breakdown.
                $hasCounts = $showCount !== false && ($success !== null || $total !== null);

                if (!$hasCounts) {
                    // No raw counts available — surface the rating instead of
                    // fabricating 0/0, so the caller can tell them apart.
                    return [
                        'status' => true,
                        'message' => 'Successful.',
                        'data' => [
                            'success' => null,
                            'cancel' => null,
                            'total' => null,
                            'deliveredPercentage' => null,
                            'returnPercentage' => null,
                            'customerRating' => $customerRating,
                            'riskLevel' => $riskLevel,
                            'showCount' => (bool) $showCount,
                            'countsAvailable' => false,
                        ],
                    ];
                }

                $success = (int) ($success ?? 0);
                $total = (int) ($total ?? 0);
                $cancel = max($total - $success, 0);

                // Calculate percentages
                $deliveredPercentage = $total > 0 ? round(($success / $total) * 100, 2) : 0;
                $returnPercentage = $total > 0 ? round(($cancel / $total) * 100, 2) : 0;

                $data = [
                    'success' => $success,
                    'cancel' => $cancel,
                    'total' => $total,
                    'deliveredPercentage' => $deliveredPercentage,
                    'returnPercentage' => $returnPercentage,
                    'customerRating' => $customerRating,
                    'riskLevel' => $riskLevel,
                    'showCount' => true,
                    'countsAvailable' => true,
                ];

                return [
                    'status' => true,
                    'message' => 'Successful.',
                    'data' => $data,
                ];
            } else {
                // Get the full response body as JSON
                $error = ($response) ? $response->json() : [];

                // Optionally get a specific message
                $message = $error['message'] ?? 'Unknown error occurred.';

                return [
                    'status' => false,
                    'message' => $message,
                ];
            }

        }

        return [
            'status' => false,
            'message' => "Authentication failed",
        ];
    }


    private function getOrderData($accessToken, $phone)
    {
        return Http::withHeaders(array_merge($this->browserHeaders(), [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json, text/plain, */*',
            'Authorization' => 'Bearer ' . $accessToken,
            'Origin' => 'https://merchant.pathao.com',
            'Referer' => 'https://merchant.pathao.com/',
        ]))
            ->timeout($this->timeout)
            ->post('https://merchant.pathao.com/api/v1/user/success', [
                'phone' => $phone,
            ]);
    }

    /**
     * Find the first numeric value among the given key names across the given
     * source arrays. Returns null when none of the keys hold a numeric value,
     * which lets the caller tell "no count exposed" apart from a real 0.
     *
     * @param array[] $sources
     * @param string[] $keys
     * @return int|null
     */
    private function extractCount(array $sources, array $keys): ?int
    {
        foreach ($sources as $source) {
            if (!is_array($source)) {
                continue;
            }
            foreach ($keys as $key) {
                if (isset($source[$key]) && is_numeric($source[$key])) {
                    return (int) $source[$key];
                }
            }
        }

        return null;
    }

    /**
     * Map Pathao's qualitative customer rating to a coarse risk level.
     * Pathao replaced numeric delivery counts with these rating tiers, so the
     * rating is now the primary fraud signal for this courier.
     *
     * @param string|null $rating
     * @return string|null  'low' | 'medium' | 'high' | null
     */
    private function mapRiskLevel(?string $rating): ?string
    {
        if (!$rating) {
            return null;
        }

        switch (strtolower($rating)) {
            case 'excellent_customer':
            case 'good_customer':
                return 'low';

            case 'regular_customer':
            case 'new_customer':
                return 'medium';

            case 'fraud_customer':
                return 'high';

            default:
                return null;
        }
    }

    /**
     * Default browser-like headers so the request is not blocked or served a
     * different response by Pathao's front-end / WAF.
     */
    private function browserHeaders(): array
    {
        return [
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/137.0.0.0 Safari/537.36',
            'Accept' => 'application/json, text/plain, */*',
            'Accept-Language' => 'en-US,en;q=0.9',
        ];
    }


}
