<?php

namespace Refatbd\BdCourierFraudChecker\Courier;

/** Validate RedX's envelope before accepting its anomalous HTTP 400 success response. */
class RedxResult
{
    public static function accepts(int $status, $body): bool
    {
        if (!is_array($body) || !is_array($body['data'] ?? null)) return false;
        if (array_key_exists('isError', $body) && $body['isError'] !== false) return false;
        if (isset($body['code']) && !in_array($body['code'], [200, '200'], true)) return false;
        if ($status === 400) {
            if (($body['isError'] ?? null) !== false || !in_array($body['code'] ?? null, [200, '200'], true)) return false;
        } elseif ($status < 200 || $status >= 300) {
            return false;
        }
        $total = $body['data']['totalParcels'] ?? null;
        $delivered = $body['data']['deliveredParcels'] ?? null;
        foreach ([$total, $delivered] as $count) {
            if (!is_numeric($count) || !is_finite((float) $count) || (float) $count < 0
                || (float) $count > PHP_INT_MAX || floor((float) $count) !== (float) $count) return false;
        }
        return (float) $delivered <= (float) $total;
    }
}
