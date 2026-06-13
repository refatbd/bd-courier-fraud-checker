<!-- ╔══════════════════════════════════════════════════════════════════════╗ -->
<!-- ║                            HEADER BANNER                               ║ -->
<!-- ╚══════════════════════════════════════════════════════════════════════╝ -->

<div align="center">

<img src="https://capsule-render.vercel.app/api?type=waving&color=0:007BFF,100:00C6FF&height=200&section=header&text=BD%20Courier%20Fraud%20Checker&fontSize=42&fontColor=ffffff&animation=fadeIn&fontAlignY=38&desc=Spot%20risky%20customers%20before%20you%20ship&descAlignY=58&descSize=18" width="100%" alt="BD Courier Fraud Checker" />

<!-- Animated typing subtitle -->
<a href="https://github.com/refatbd">
  <img src="https://readme-typing-svg.demolab.com?font=Fira+Code&weight=600&size=22&duration=3000&pause=800&color=007BFF&center=true&vCenter=true&width=600&lines=Steadfast+%E2%9C%93;Pathao+%E2%9C%93;RedX+%E2%9C%93;Carrybee+%E2%9C%93;One+API.+Four+couriers.+Zero+guesswork." alt="Typing SVG" />
</a>

<br/>

<!-- Badges -->
<p>
  <img src="https://img.shields.io/packagist/v/refatbd/bd-courier-fraud-checker?style=for-the-badge&color=007BFF&logo=packagist&logoColor=white" alt="Packagist Version" />
  <img src="https://img.shields.io/packagist/dt/refatbd/bd-courier-fraud-checker?style=for-the-badge&color=00C6FF&logo=composer&logoColor=white" alt="Downloads" />
  <img src="https://img.shields.io/badge/PHP-7.4%20--%208.3-777BB4?style=for-the-badge&logo=php&logoColor=white" alt="PHP" />
  <img src="https://img.shields.io/badge/Laravel-Ready-FF2D20?style=for-the-badge&logo=laravel&logoColor=white" alt="Laravel" />
  <img src="https://img.shields.io/packagist/l/refatbd/bd-courier-fraud-checker?style=for-the-badge&color=28a745" alt="License" />
</p>

<sub>Maintained with ❤️ by <a href="https://github.com/refatbd"><b>refatbd</b></a></sub>

</div>

<br/>

<!-- ╔══════════════════════════════════════════════════════════════════════╗ -->
<!-- ║                            INTRO                                       ║ -->
<!-- ╚══════════════════════════════════════════════════════════════════════╝ -->

> A **Laravel package** that checks a Bangladeshi phone number against the data of four major couriers — **Steadfast, Pathao, RedX, and Carrybee** — and tells you, in one call, how risky that customer is before you confirm a Cash-on-Delivery order.

<div align="center">

```
📞  01XXXXXXXXX  ─────▶  🔎  BdCourierFraudChecker  ─────▶  📊  Success rate · Fraud signals · Complaints
```

</div>

---

## 📑 Table of Contents

- [✨ Features](#-features)
- [📦 Installation](#-installation)
- [⚙️ Configuration](#️-configuration)
- [🚀 Usage](#-usage)
- [🧾 Response Format](#-response-format)
- [🚚 Supported Couriers](#-supported-couriers)
- [🧠 How the Fraud Signal Works](#-how-the-fraud-signal-works)
- [➕ Adding a New Courier](#-adding-a-new-courier)
- [❓ FAQ](#-faq)
- [📝 Changelog](#-changelog)
- [📄 License](#-license)

---

## ✨ Features

|   | Feature | Description |
|---|---------|-------------|
| 🔁 | **One call, four couriers** | A single `check()` queries Steadfast, Pathao, RedX & Carrybee. |
| 📊 | **Delivery success rate** | Delivered / cancelled / total + auto-calculated percentages. |
| 🚨 | **Detailed complaints** | Steadfast returns the full complaint list — name, details, date & image. |
| 🏷️ | **Fraud labels** | Pathao rating, RedX segment, Carrybee complaint count. |
| ⚡ | **Smart caching** | Auth tokens/cookies cached for ~50 min — fewer logins, faster checks. |
| 🛡️ | **Resilient** | In-call re-auth on expired sessions, request timeouts, browser-like headers, graceful failures, BD phone validation. |
| 🧩 | **Extensible** | Drop in a new courier class and wire it up in minutes. |

---

## 📦 Installation

```bash
composer require refatbd/bd-courier-fraud-checker
```

Publish the config file:

```bash
php artisan vendor:publish --tag=bdcourierfraudchecker-config
```

---

## ⚙️ Configuration

Add your courier merchant credentials to your `.env` file:

```env
# 🟦 Steadfast
STEADFAST_USER=your_email@example.com
STEADFAST_PASSWORD=your_password

# 🟥 Pathao
PATHAO_USER=your_email@example.com
PATHAO_PASSWORD=your_password

# 🟧 RedX
REDX_PHONE=01XXXXXXXXX
REDX_PASSWORD=your_password

# 🟨 Carrybee
CARRYBEE_PHONE=01XXXXXXXXX
CARRYBEE_PASSWORD=your_password
```

> 💡 You only need to configure the couriers you actually use. A courier with missing credentials simply returns `status => false` instead of breaking the whole check.

---

## 🚀 Usage

```php
use Refatbd\BdCourierFraudChecker\Facade\BdCourierFraudChecker;

$result = BdCourierFraudChecker::check('01XXXXXXXXX');
```

That's it — `$result` is an array keyed by courier. Loop over it, render it, or feed it into your own risk score.

---

## 🧾 Response Format

```php
[
    'steadfast' => [
        'status' => true,
        'message' => 'Successful.',
        'data' => [
            'success'             => 45,
            'cancel'              => 5,
            'total'               => 50,
            'deliveredPercentage' => 90.0,
            'returnPercentage'    => 10.0,
            'fraudReportCount'    => 1,
            'frauds'              => [
                [
                    'name'             => 'Saiyan Ahammd Santo',
                    'phone'            => '01893048178',
                    'details'          => 'পার্সেল রিসিভ করেনা।',
                    'image'            => null,
                    'consignment_id'   => 124581452,
                    'created_at'       => '2025-02-11T14:43:02.000000Z',
                    'created_at_human' => '1 year ago',
                ],
            ],
        ],
    ],
    'pathao' => [
        'status'  => true,
        'message' => 'Successful.',
        'data'    => [
            // Pathao has moved to a rating-based model — most accounts no longer
            // receive numeric counts (showCount: false). See the note below.
            'success'             => null,
            'cancel'              => null,
            'total'               => null,
            'deliveredPercentage' => null,
            'returnPercentage'    => null,
            'customerRating'      => 'excellent_customer', // Pathao's own label
            'riskLevel'           => 'low',                // derived: low | medium | high
            'showCount'           => false,                // did Pathao expose counts?
            'countsAvailable'     => false,                // numeric data usable?
        ],
    ],
    'redx' => [
        'status'  => true,
        'message' => 'Successful.',
        'data'    => [
            'success'             => 30,
            'cancel'              => 5,
            'total'               => 35,
            'deliveredPercentage' => 85.71,
            'returnPercentage'    => 14.29,
            'customerSegment'     => 'Normal Customer', // RedX's own rating label
        ],
    ],
    'carrybee' => [
        'status'  => true,
        'message' => 'Successful.',
        'data'    => [
            'success'             => 18,
            'cancel'              => 2,
            'total'               => 20,
            'deliveredPercentage' => 90.0,
            'returnPercentage'    => 10.0,
            'fraudCount'          => 0, // Carrybee's own complaint counter
        ],
    ],
]
```

> ⚠️ **Always check `status` before reading `data`.** When a courier fails (auth error, no data, etc.) it returns `['status' => false, 'message' => '...']` with **no** `data` key.

> 🟥 **Pathao counts may be `null`.** Pathao migrated to a rating-based model, so most merchant accounts receive **no numeric delivery counts** — `showCount` and `countsAvailable` are `false`, and the count fields are `null` (not `0`, to avoid implying a customer with zero orders). The package **still returns the full numeric breakdown** for any account that *is* entitled to counts (`countsAvailable: true`). **Guard on `countsAvailable` before doing math on Pathao counts.** Steadfast, RedX, and Carrybee continue to return real numeric counts.

---

## 🚚 Supported Couriers

<div align="center">

| Courier | Status | Delivery Stats | Fraud Signal |
|:-------:|:------:|:--------------:|:-------------|
| **Steadfast** | ✅ | ✅ | ✅ Full complaint list — `frauds[]` (name · details · date · image) |
| **Pathao** | ✅ | ⚠️ Rating-based¹ | 🏷️ Rating + risk — `customerRating`, `riskLevel` |
| **RedX** | ✅ | ✅ | 🏷️ Segment label — `customerSegment` |
| **Carrybee** | ✅ | ✅ | 🔢 Complaint count — `fraudCount` |

<sub>¹ Pathao moved to a rating-based model — most accounts get no numeric counts (`countsAvailable: false`). Numeric counts are still returned for entitled accounts.</sub>

</div>

> More couriers can be added easily — see [Adding a New Courier](#-adding-a-new-courier).

---

## 🧠 How the Fraud Signal Works

Each courier exposes risk differently. The package normalizes the **delivery stats** for all of them, and surfaces each courier's **native fraud signal** on top:

| Courier | Field | Example values | Meaning |
|---------|-------|----------------|---------|
| Steadfast | `frauds[]` + `fraudReportCount` | complaint objects | Real merchant-submitted complaints with text, date & image |
| Pathao | `customerRating` + `riskLevel` | `excellent_customer` → `low`, `fraud_customer` → `high` | Pathao's internal rating, mapped to a coarse risk level |
| RedX | `customerSegment` | `Normal Customer`, `High Return Customer` | RedX's internal customer tier |
| Carrybee | `fraudCount` | `0`, `3`, … | How many complaints Carrybee holds for the number |

> 🔎 **Only Steadfast** returns the full **who / what / when** complaint text. The others give a single label or count — useful as a quick red flag, but without the details.

**Pathao `customerRating` → `riskLevel` mapping:**

| `customerRating` | `riskLevel` |
|------------------|:-----------:|
| `excellent_customer`, `good_customer` | `low` |
| `regular_customer`, `new_customer` | `medium` |
| `fraud_customer` | `high` |
| unknown / missing | `null` |

---

## ➕ Adding a New Courier

<details>
<summary><b>Click to expand the step-by-step guide</b></summary>

<br/>

**1.** Create a new class in `src/Courier/YourCourier.php`:

```php
<?php

namespace Refatbd\BdCourierFraudChecker\Courier;

use Refatbd\BdCourierFraudChecker\Traits\Helpers;

class YourCourier
{
    use Helpers;

    public function __construct()
    {
        $this->checkRequiredConfig(['your_courier_user', 'your_courier_password']);
    }

    public function check($phoneNumber)
    {
        $phoneNumber = $this->validateBDPhoneNumber($phoneNumber);
        // Add your API logic here
        return [
            'status'  => true,
            'message' => 'Successful.',
            'data'    => [],
        ];
    }
}
```

**2.** Add credentials to `config/bdcourierfraudchecker.php`:

```php
"your_courier_user"     => env("YOUR_COURIER_USER", ""),
"your_courier_password" => env("YOUR_COURIER_PASSWORD", ""),
```

**3.** Inject it into `CourierCheckerService` and add it to the `check()` return array.

**4.** Bind it in `BdCourierFraudCheckerServiceProvider`.

</details>

---

## ❓ FAQ

<details>
<summary><b>Do I need an account with every courier?</b></summary>
<br/>
No. Configure only the couriers you use. Unconfigured couriers return <code>status => false</code> and are skipped — the rest still work.
</details>

<details>
<summary><b>Why are Pathao's delivery counts <code>null</code>?</b></summary>
<br/>
Pathao migrated the customer-success endpoint to a <b>rating-based model</b>. Most merchant accounts now receive <code>show_count: false</code> with <b>no numeric delivery counts at all</b> — only a <code>customer_rating</code>. The package returns <code>null</code> for the count fields (and <code>countsAvailable: false</code>) rather than fake <code>0</code>s, so you can tell "Pathao gave us no counts" apart from "a customer with zero orders". The <code>customerRating</code> / <code>riskLevel</code> is your reliable Pathao signal. If your account <i>is</i> entitled to numeric counts, the package returns them automatically with <code>countsAvailable: true</code> — no code change needed. This is a Pathao server-side policy and can't be toggled from the API.
</details>

<details>
<summary><b>Is the data cached?</b></summary>
<br/>
Only the <b>auth tokens / session cookies</b> are cached (~50 minutes) to avoid logging in on every request. The fraud lookup itself runs fresh on every call, so results are always current.
</details>

<details>
<summary><b>What phone formats are accepted?</b></summary>
<br/>
Any valid Bangladeshi mobile number — with or without the <code>+88</code>/<code>88</code> prefix. The package normalizes and validates it (<code>01[3-9]XXXXXXXX</code>) for you.
</details>

---

## 📝 Changelog

See [CHANGELOG.md](CHANGELOG.md) for the full version history.

**Latest — `v1.2.0`:** connection hardening across all four couriers (browser-like headers, request timeouts, in-call re-authentication on stale sessions) and Pathao's rating-based response shape (`riskLevel`, `showCount`, `countsAvailable`; counts are `null` when Pathao doesn't expose them). ⚠️ Guard on `countsAvailable` before doing math on Pathao counts.

---

## 📄 License

Released under the **MIT License** — free to use, modify, and distribute.

<div align="center">

<img src="https://capsule-render.vercel.app/api?type=waving&color=0:00C6FF,100:007BFF&height=120&section=footer&text=Ship%20smarter.%20Get%20paid.&fontSize=20&fontColor=ffffff&animation=twinkling&fontAlignY=70" width="100%" alt="footer" />

<sub>⭐ If this package saved you from a fraud order, consider starring the repo!</sub>

</div>
