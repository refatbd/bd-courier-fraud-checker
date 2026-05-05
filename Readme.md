# BD Courier Fraud Checker

A Laravel package to detect fraudulent customers using Bangladeshi courier data (Steadfast, Pathao, Redx).

> Maintained by [refatbd](https://github.com/refatbd)

---

## Installation

```bash
composer require refatbd/bd-courier-fraud-checker
```

## Publish Config

```bash
php artisan vendor:publish --tag=bdcourierfraudchecker-config
```

## Environment Variables

Add these to your `.env` file:

```env
# Steadfast
STEADFAST_USER=your_email@example.com
STEADFAST_PASSWORD=your_password

# Pathao
PATHAO_USER=your_email@example.com
PATHAO_PASSWORD=your_password

# Redx
REDX_PHONE=01XXXXXXXXX
REDX_PASSWORD=your_password
```

## Usage

```php
use Refatbd\BdCourierFraudChecker\Facade\BdCourierFraudChecker;

$result = BdCourierFraudChecker::check('01XXXXXXXXX');
```

### Response Format

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
        ],
    ],
    'pathao' => [ ... ],
    'redx'   => [ ... ],
]
```

## Supported Couriers

| Courier    | Status |
|------------|--------|
| Steadfast  | ✅ |
| Pathao     | ✅ |
| Redx       | ✅ |

> More couriers can be added easily — see [Adding a New Courier](#adding-a-new-courier).

## Adding a New Courier

1. Create a new class in `src/Courier/YourCourier.php`:

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

2. Add credentials to `config/bdcourierfraudchecker.php`:

```php
"your_courier_user"     => env("YOUR_COURIER_USER", ""),
"your_courier_password" => env("YOUR_COURIER_PASSWORD", ""),
```

3. Inject it into `CourierCheckerService` and add to the `check()` return array.

4. Bind it in `BdCourierFraudCheckerServiceProvider`.

## License

MIT
