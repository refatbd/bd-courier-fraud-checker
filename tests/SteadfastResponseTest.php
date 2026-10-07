<?php

namespace Refatbd\BdCourierFraudChecker\Tests;

use ReflectionClass;
use Refatbd\BdCourierFraudChecker\Courier\Steadfast;

class SteadfastResponseTest
{
    public static function run(): void
    {
        echo "Running Steadfast tests...\n";

        self::testMethodVisibilities();
        self::testLegacyFormatResult();
        self::testModern2026FormatResult();
        self::testZeroHistoryFormatResult();

        echo "All Steadfast tests passed successfully!\n";
    }

    protected static function testMethodVisibilities(): void
    {
        $ref = new ReflectionClass(Steadfast::class);

        // Verify login() is public
        $loginMethod = $ref->getMethod('login');
        assert($loginMethod->isPublic(), 'Steadfast::login() must be public');

        // Verify internal helpers are protected (NOT private)
        $protectedMethods = [
            'getOrderData',
            'formatResult',
            'browserHeaders',
            'extractCsrfToken',
            'cookiesToArray',
            'isJsonResponse',
        ];

        foreach ($protectedMethods as $methodName) {
            $method = $ref->getMethod($methodName);
            assert($method->isProtected(), "Steadfast::{$methodName}() must be protected");
            assert(!$method->isPrivate(), "Steadfast::{$methodName}() must not be private");
        }

        echo "  [x] Method visibilities verified (login is public, helpers are protected)\n";
    }

    protected static function testLegacyFormatResult(): void
    {
        $ref = new ReflectionClass(Steadfast::class);
        $method = $ref->getMethod('formatResult');
        $method->setAccessible(true);

        // Instantiate Steadfast without running constructor requiring config
        $instance = $ref->newInstanceWithoutConstructor();

        $legacyPayload = [
            'total_delivered' => 15,
            'total_cancelled' => 5,
            'frauds' => [],
        ];

        $result = $method->invoke($instance, $legacyPayload);

        assert($result['status'] === true);
        assert($result['data']['success'] === 15);
        assert($result['data']['cancel'] === 5);
        assert($result['data']['total'] === 20);
        assert($result['data']['deliveredPercentage'] == 75.0);
        assert($result['data']['returnPercentage'] == 25.0);

        echo "  [x] Legacy schema parsing verified\n";
    }

    protected static function testModern2026FormatResult(): void
    {
        $ref = new ReflectionClass(Steadfast::class);
        $method = $ref->getMethod('formatResult');
        $method->setAccessible(true);

        $instance = $ref->newInstanceWithoutConstructor();

        $modernPayload = [
            'delivered_count' => null,
            'cancelled_count' => null,
            'delivery_ratio' => 80.0,
            'cancellation_ratio' => 20.0,
            'volume_range' => '10+',
            'volume_band' => 'high',
            'frauds' => [
                [
                    'name' => 'Test Customer',
                    'phone' => '01700000000',
                    'details' => 'Delivery refused',
                    'created_at' => '2026-01-01 12:00:00',
                ]
            ],
            'fraud_reports' => 1,
        ];

        $result = $method->invoke($instance, $modernPayload);

        assert($result['status'] === true);
        assert($result['data']['total'] === 10, 'Expected total 10 parsed from 10+');
        assert($result['data']['success'] === 8, 'Expected 8 successes (80% of 10)');
        assert($result['data']['cancel'] === 2, 'Expected 2 cancels (20% of 10)');
        assert($result['data']['deliveredPercentage'] == 80.0);
        assert($result['data']['returnPercentage'] == 20.0);
        assert($result['data']['volume_range'] === '10+');
        assert($result['data']['volume_band'] === 'high');
        assert($result['data']['fraudReportCount'] === 1);
        assert(count($result['data']['frauds']) === 1);

        echo "  [x] Modern 2026 schema parsing verified\n";
    }

    protected static function testZeroHistoryFormatResult(): void
    {
        $ref = new ReflectionClass(Steadfast::class);
        $method = $ref->getMethod('formatResult');
        $method->setAccessible(true);

        $instance = $ref->newInstanceWithoutConstructor();

        $emptyPayload = [
            'delivered_count' => null,
            'cancelled_count' => null,
            'delivery_ratio' => null,
            'cancellation_ratio' => null,
            'volume_range' => null,
            'volume_band' => 'none',
            'frauds' => [],
        ];

        $result = $method->invoke($instance, $emptyPayload);

        assert($result['status'] === true);
        assert($result['data']['total'] === 0);
        assert($result['data']['success'] === 0);
        assert($result['data']['cancel'] === 0);
        assert($result['data']['deliveredPercentage'] == 0.0);
        assert($result['data']['returnPercentage'] == 0.0);
        assert($result['data']['fraudReportCount'] === 0);

        echo "  [x] Zero history schema parsing verified\n";
    }
}
