# Changelog

All notable changes to `bd-courier-fraud-checker` are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.4.0] - 2026-10-07

Modernized Steadfast Courier integration to support their 2026 response schema, added graceful upstream rate-limit handling, and opened method visibility across all courier drivers to support custom adapters, credential testing, and clean subclassing.

### Added

- **Steadfast 2026 Response Schema Support (`Steadfast.php`):**
  - Added support for Steadfast's modern delivery intelligence response keys: `delivery_ratio`, `cancellation_ratio`, `volume_range` (e.g. `"10+"`, `"1-5"`), and `volume_band`.
  - Added proportional order estimation logic from `volume_range` and percentage metrics when explicit raw counts are hidden by Steadfast.
  - Added `volume_range`, `volume_band`, `delivery_ratio`, `cancellation_ratio`, `countsAvailable`, and `showCount` to the response `data` payload.
- **Steadfast Rate-Limit Handling (HTTP 429):**
  - Added explicit handling for Steadfast's per-account search quota limits. Returning a structured `429` error response with upstream error detail instead of falling back to a generic connection failure.
- **Public Authentication & Connection Verification Helpers:**
  - Made `login()` in `Steadfast`, `Carrybee`, and `Paperfly` public (`public function login()`) to allow direct credential validation and connection testing from service providers or admin consoles.
  - Made token retrieval helpers (`getAccessToken()` in `Pathao` and `Redx`, `getBearerToken()` in `Carrybee` and `Paperfly`) public to permit programmatic token inspection and health checks.

### Changed

- **Extensibility & Method Visibility Hardening (All Couriers):**
  - Converted internal helper methods across all courier classes (`getOrderData`, `formatResult`, `browserHeaders`, `extractCsrfToken`, `cookiesToArray`, `isJsonResponse`, `extractCount`, `mapRiskLevel`) from `private` to `protected`.
  - Enables clean object-oriented subclassing and enterprise adapter extension without encountering PHP fatal visibility errors.

### Fixed

- **Fatal Error `Call to private method Refatbd\BdCourierFraudChecker\Courier\Steadfast::login()`:**
  - Resolved fatal scope collision when custom adapters (such as `SteadfastAdapter`) or external callers invoked `login()` on `Steadfast`.
- **Steadfast Misleading Zeroes / 0% Success Rate:**
  - Fixed issue where Steadfast queries returned `0` delivered, `0` cancelled, and `0%` success rate due to Steadfast deprecating `total_delivered` and `total_cancelled` in their live API. The driver now gracefully parses both legacy counts and modern ratio metrics.

## [1.3.0] - 2026-08-03

Added Paperfly Courier support via Smart Check V2 API.

### Added

- **Paperfly Courier Driver (`Paperfly.php`):** Added integration for Paperfly's merchant Smart Check V2 API (`https://go-app.paperfly.com.bd/merchant/api/react/smart-check/smart-check-v2.php`).
- **Session Auth & Caching:** Added automated merchant login with 50-minute Bearer token caching (`paperfly_bearer_token`) and automatic in-call token refresh.
- **Smart Check Response Data:** Normalizes delivered, partial, returned, total orders, delivery rate, return rate, smart check rating label, color, icon, and note.
- **Configuration:** Added `PAPERFLY_USER` and `PAPERFLY_PASSWORD` configuration keys.

## [1.2.0] - 2026-06-14

Reliability hardening across all four couriers, plus a Pathao response-shape
change to reflect their move from delivery counts to a rating-based model.
All four integrations were verified live against the real courier APIs.

### Added

- **Connection hardening (all couriers):** browser-like request headers
  (`User-Agent`, `Accept`, `Accept-Language`, `Referer`/`Origin`) so requests
  are not intermittently blocked or served different responses by the couriers'
  front-ends / WAFs.
- **Request timeouts (all couriers):** every HTTP call now has a 20s timeout so
  a hung connection fails fast instead of blocking the request.
- **In-call re-authentication (all couriers):** when a cached session/token is
  stale, the courier now drops it and re-authenticates **within the same call**
  and retries, instead of failing and forcing the next call to recover. This
  fixes the intermittent "connection not working" failures.
- **Pathao `riskLevel`:** a coarse `low` / `medium` / `high` risk level derived
  from Pathao's `customer_rating`, so the rating is directly actionable.
- **Pathao `showCount` / `countsAvailable`:** new response flags that tell you
  whether Pathao exposed numeric delivery counts for the account.

### Changed

- **Pathao response shape (potentially breaking).** Pathao migrated the
  customer-success endpoint to a rating-based model; most accounts now receive
  `show_count: false` with **no numeric delivery counts** — only a
  `customer_rating`. To avoid reporting fabricated zeros as real data:
  - When counts are **not** exposed, `success`, `cancel`, `total`,
    `deliveredPercentage`, and `returnPercentage` are now `null` (previously
    `0`), and `countsAvailable` is `false`.
  - When counts **are** exposed (accounts entitled to numeric data, or a future
    Pathao change), the full numeric breakdown is returned as before, with
    `countsAvailable` set to `true`.
  - **Action required:** if your code does arithmetic on Pathao's
    `success`/`total`/etc., guard on `countsAvailable` (or a null check) first.
- **Pathao count detection** now probes both the legacy `data.customer.*` and
  the newer `data.*` locations with multiple key names, so any account that
  *is* entitled to numeric counts reliably receives them.
- **Steadfast login** now captures the authenticated session cookie correctly
  (does not follow the login redirect, verifies the redirect target, and merges
  the pre- and post-login cookies) and validates that the fraud-check response
  is genuinely JSON before parsing it.

### Fixed

- **Steadfast intermittent failures:** a stale cached session previously caused
  the call to return *"Something went wrong. Try again"* (the unauthenticated
  redirect to the login page was mistaken for a successful response). The
  request now re-authenticates and retries in-call.
- **Pathao misleading zeros:** rating-only responses were reported as
  `"Successful."` with `0/0/0` counts, indistinguishable from a customer with
  no orders. They now clearly report `countsAvailable: false`.
- **RedX / Pathao stale token:** a `401` on the data fetch previously returned a
  "please retry" error (RedX) or reused the dead token (Pathao). Both now
  re-authenticate in-call.

### Security

- Confirmed no credentials are stored anywhere in the package — all courier
  credentials are read exclusively from `config()` / `.env`.

## [1.1.2] - Prior release

- Steadfast, Pathao, RedX, and Carrybee courier checks with delivery stats,
  fraud signals, auth caching (~50 min), and BD phone validation.

[1.3.0]: #130---2026-08-03
[1.2.0]: #120---2026-06-14
[1.1.2]: #112---prior-release
