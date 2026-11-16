# Pixelforge Image Generation Platform

A production-grade Laravel 13 + Vue 3 + Inertia application for generating images via the Pixelforge AI API with exact nanodollar money handling (+25% markup), idempotent webhook verification, automatic retries with exponential backoff, user credit balance management, and admin reconciliation.

---

## Architecture & Features

- **Exact Nanodollar Money Model**: 1 USD = 1,000,000,000 nanodollars stored as integer `BIGINT`. No floating-point math anywhere. Rate strings parsed with `BCMath`. Exact round-half-up markup calculation.
- **Robust Webhook Engine**: HMAC-SHA256 signature verification (`Pixelforge-Signature`), replay protection with 300s timestamp expiration, and deduplication via unique DB constraints.
- **Monotonic State Machine**: `GenerationStateMachine` guarantees forward-only state progression (`queued` → `processing` → `succeeded` / `failed`). Terminal states are immutable.
- **Concurrency & Balance Protection**: Atomic row-level locking ensures user balances never drop below zero and duplicate ledger entries are prevented.
- **Live UI & Inertia Polling**: Real-time cost estimation breakdown on generation form, test keyword helpers, and 3-second auto-polling for in-flight image jobs.
- **Reconciliation & Reporting**: Per-day and per-user financial ledger breakdown, plus live zero-diff reconciliation against Pixelforge's `GET /v1/usage` API.

---

## Money Model & Precision Rules

### 1. Integer Nanodollars Storage
Floating-point arithmetic introduces rounding inaccuracies (e.g., `0.1 + 0.2 !== 0.3`). In this system:
- **Base Unit**: Nanodollar ($1.00 USD = 1,000,000,000 nanodollars).
- **Database Type**: `BIGINT` column types (`provider_cost_nano`, `user_charge_nano`, `balance_nano`, `amount_nano`).
- **Rates**: API rates are returned per 1,000,000 tokens as strings (e.g. `"5.00"`). Nanodollars per token is computed via `BCMath`:
  $$\text{NanoPerToken} = \frac{\text{Rate} \times 1,000,000,000}{1,000,000} = \text{Rate} \times 1000$$
- **Provider Cost**: Exact sum of tokens multiplied by nanodollars per token:
  $$\text{ProviderCost} = \sum (\text{Tokens}_i \times \text{RateNano}_i)$$

### 2. Markup & Rounding Rule
- Users are charged provider cost plus **25% markup** (`provider_cost * 1.25`).
- While provider costs are exact whole nanodollars, multiplying by `1.25` can produce half-nanodollar fractions (e.g. `12,642,500 * 1.25 = 15,803,125.0`).
- **Explicit Rounding Rule**: Round half up to the nearest whole nanodollar (`round($nano * 1.25)` or `bcmul` with half-up rounding).
- **Failed Generations**: Cost $0.00 to the user. No charges or ledger entries are recorded for failed attempts.

### 3. Display Formatting
- Full precision display: Formats down to 9 decimal places (e.g. `$0.012642500`).
- Short display: Up to 4 to 6 decimal places with trimmed trailing zeros (e.g. `$0.0158`).

---

## Webhook Strategy & Idempotency

1. **Raw Body Verification**:
   - Webhook signature header: `Pixelforge-Signature: t=<unix_timestamp>,v1=<hex_hmac>`
   - Signature computed as `HMAC-SHA256(timestamp . "." . raw_body, webhook_secret)`
   - Verified via `hash_equals()` before JSON decoding.
   - Timestamps older than 300 seconds are rejected with `400 Bad Request`.

2. **Deduplication & Atomicity**:
   - Webhooks are recorded in `webhook_events` with a unique index on `event_id`.
   - The state transition, ledger creation, and balance deduction occur inside a single atomic `DB::transaction` with row-level `lockForUpdate()`.
   - Concurrent or duplicated webhook deliveries are discarded idempotently without duplicate charges.

3. **Handling Out-Of-Order Events**:
   - If a `generation.processing` webhook arrives after `generation.succeeded` or `generation.failed`, the state machine ignores the regression.
   - If a webhook arrives before the create HTTP request completes, it matches the generation using `metadata.generation_id`.

---

## Queue & Retry Strategy

- **Queue Driver**: Database queue driver (`php artisan queue:work`).
- **Max Retries**: 5 attempts.
- **Backoff**: Exponential backoff (`[1, 2, 4, 8, 16]` seconds) or `Retry-After` header value.
- **Error Categorization**:
  - **Terminal Errors** (No retry, immediate failure): `content_policy_violation` (400), `invalid_api_key` (401), `validation_failed` (422), `daily_limit_exceeded` (429).
  - **Retryable Errors** (Released with backoff): `rate_limit_exceeded` (429), `concurrency_limit_exceeded` (429), `overloaded` (503).

---

## Test Keywords

You can test edge cases by including these keywords in prompts:
- `[succeed]` — Forces a successful generation.
- `[fail]` — Forces a generation failure.
- `[blocked]` — Triggers a content policy violation (400).
- `[slow]` — Simulates a slow generation (60-90s).
- `[duplicate]` — Delivers every webhook twice to verify idempotency.

---

## Setup & Local Development

### 1. Requirements
- PHP 8.2+ with `bcmath`, `pdo_sqlite` extensions
- Composer
- Node.js & npm

### 2. Installation
```bash
# Clone repository
git clone https://github.com/stephenopokudamoah/pixel-forge-takehome-assessment.git
cd pixel-forge-takehome-assessment

# Install PHP dependencies
composer install

# Install JS dependencies & build frontend assets
npm install
npm run build

# Configure environment
cp .env.example .env
php artisan key:generate

# Run migrations and seed default user ($10 credits)
php artisan migrate:fresh --seed
```

### 3. Setting Up Pixelforge Secrets
In your `.env` file, configure:
```env
PIXEL_FORGE_API_KEY=your_pixelforge_api_key
PIXEL_FORGE_WEBHOOK_SECRET=your_webhook_signing_secret
```

### 4. Running Locally with Webhook Tunnel
To receive webhooks locally, expose port 8000 using Ngrok, Cloudflare Tunnel, or Expose:
```bash
# Start Laravel development server
php artisan serve

# In another terminal, run queue worker
php artisan queue:work

# In another terminal, start your tunnel
ngrok http 8000
# or: expose share http://localhost:8000
```
Then configure your webhook URL in Pixelforge dashboard to:
`https://your-tunnel-domain.ngrok-free.app/webhooks/pixelforge`

---

## Running Tests

Run the full Pest test suite covering unit math, pricing, webhooks, create jobs, and balances:
```bash
./vendor/bin/pest
```

---

## Console Commands

### Stuck Generation Reconciliation
Polls generations stuck in `queued` or `processing` for longer than 2 minutes:
```bash
php artisan generations:reconcile
```

### Provider Cost Reconciliation
Compares local recorded provider costs against Pixelforge `GET /v1/usage` and displays zero-difference confirmation:
```bash
php artisan usage:reconcile
```

---

## Open Decisions & Trade-Offs

1. **Nanodollar Units**: Selected integer nanodollars ($10^{-9}$) to match Pixelforge's 9-decimal API precision without ever converting to IEEE floating-point numbers.
2. **Round Half Up for Markup**: Documented in single source of truth `Money::withMarkup()`.
3. **Database Queue Driver**: Configured database queue driver for simplicity and zero external Redis dependencies.
4. **Asynchronous Image Downloads**: Webhook handler returns 200 immediately and dispatches `DownloadImageJob` in the background to prevent webhook timeout.
