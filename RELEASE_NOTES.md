Steadfast status observations and accurate handling of restricted parcel counts.

- Official API responses include sanitized `meta.http_status`, `meta.state`, `meta.retry_after_seconds` and optional numeric rate-limit headers.
- 429 means throttling; it does not establish daily quota exhaustion. No automatic retry on authentication or throttling failures.
- Connection failures return a safe message without the recipient URL or credentials. Unexpected HTTP 200 payloads are rejected.
- Restricted counts remain `null`, with `countsAvailable` and `showCount` false. A range such as `25+` is no longer converted to invented exact counts. Both explicit counts are required; independent provider ratios are preserved.
- Fraud-check budget remaining, limit and reset time remain unknown (`meta.quota` null values). Generic rate-limit headers are not fraud-budget evidence. No additional probe requests are issued.

Migration: consumers must respect `countsAvailable` / `showCount` and render null counts as unknown. Do not coerce null to zero or treat a volume range as an exact total. Existing explicit/legacy counts retain their format. Store observations per credential and distinguish provider limits from your own subscription quota.

Tests: `php -d zend.assertions=1 -d assert.exception=1 tests/run.php /path/to/laravel/vendor/autoload.php`. The runner uses fake HTTP responses only: no customer lookup or live credential is required.
