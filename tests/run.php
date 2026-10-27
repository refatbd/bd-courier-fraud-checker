<?php

// Run with assertions enabled; dependency autoloader may be supplied by a Laravel host.
require $argv[1] ?? dirname(__DIR__).'/vendor/autoload.php';
if ((int) ini_get('zend.assertions') !== 1 || !ini_get('assert.exception')) {
    throw new RuntimeException('Use php -d zend.assertions=1 -d assert.exception=1 tests/run.php [autoload.php]');
}
spl_autoload_register(static function ($class) {
    $prefix = 'Refatbd\\BdCourierFraudChecker\\';
    if (strpos($class, $prefix) === 0) {
        $path = dirname(__DIR__).'/src/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
        if (is_file($path)) require $path;
    }
}, true, true);

use Illuminate\Container\Container;
use Illuminate\Config\Repository;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;
use Refatbd\BdCourierFraudChecker\Courier\Steadfast;

$container = new Container;
Container::setInstance($container);
$container->instance('config', new Repository(['bdcourierfraudchecker' => [
    'steadfast_api_key' => 'test-api-key', 'steadfast_secret_key' => 'test-secret',
]]));
Facade::setFacadeApplication($container);
$container->instance('validator', new \Illuminate\Validation\Factory(
    new \Illuminate\Translation\Translator(new \Illuminate\Translation\ArrayLoader, 'en'), $container
));
require __DIR__.'/SteadfastResponseTest.php';
\Refatbd\BdCourierFraudChecker\Tests\SteadfastResponseTest::run();

$cases = [
    [200, ['volume_range' => '25+', 'delivery_ratio' => 92, 'cancellation_ratio' => 7], [], 'connected'],
    [401, ['message' => 'sensitive upstream message'], [], 'auth_failed'],
    [403, [], [], 'forbidden'],
    [429, ['message' => 'sensitive upstream message'], ['Retry-After' => '60', 'X-RateLimit-Remaining' => '0'], 'throttled'],
    [429, [], ['Retry-After' => gmdate('D, d M Y H:i:s', time()+120).' GMT'], 'throttled'],
    [500, [], [], 'unavailable'],
    [200, ['message' => 'invalid'], [], 'invalid_response'],
    [200, ['status' => 401, 'volume_range' => '25+'], [], 'invalid_response'],
];
foreach ($cases as [$code, $body, $headers, $state]) {
    $container->instance(Factory::class, new Factory);
    Facade::clearResolvedInstance(Factory::class);
    Http::preventStrayRequests();
    Http::fake(['portal.packzy.com/*' => Http::response($body, $code, $headers)]);
    $result = (new Steadfast)->steadfastApi('01700000000');
    assert($result['meta']['state'] === $state);
    assert($result['meta']['http_status'] === $code);
    assert($result['meta']['quota']['remaining'] === null);
    assert($result['status'] === ($state === 'connected'));
    assert(strpos(json_encode($result), 'test-secret') === false);
    assert(strpos(json_encode($result), 'sensitive upstream') === false);
    if ($code === 429) {
        assert($result['meta']['retry_after_seconds'] >= 59);
        assert($result['meta']['retry_after_seconds'] <= 120);
    }
    Http::assertSentCount(1); // Auth and throttling never trigger automatic retries.
}
$container->instance(Factory::class, new Factory);
Facade::clearResolvedInstance(Factory::class);
Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('https://private/01700000000?secret=test-secret'));
$result = (new Steadfast)->steadfastApi('01700000000');
assert($result['meta']['state'] === 'network_error');
assert(strpos(json_encode($result), '01700000000') === false);

$ref = new ReflectionClass(Steadfast::class);
$format = $ref->getMethod('formatResult');
$format->setAccessible(true);
$partial = $format->invoke($ref->newInstanceWithoutConstructor(), ['delivered_count'=>5, 'cancelled_count'=>null, 'delivery_ratio'=>92]);
assert($partial['data']['countsAvailable'] === false);
assert($partial['data']['total'] === null);
assert($partial['data']['returnPercentage'] === null); // Never derive 100 - floored ratio.
$exact = $format->invoke($ref->newInstanceWithoutConstructor(), ['delivered_count'=>92, 'cancelled_count'=>7, 'delivery_ratio'=>92, 'cancellation_ratio'=>7]);
assert($exact['data']['total'] === 99);
assert($exact['data']['deliveredPercentage'] === 92.0);
echo "HTTP metadata, retry policy, redaction and count integrity tests passed.\n";
require __DIR__.'/RedxResponseTest.php';
