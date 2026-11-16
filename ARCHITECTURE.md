# Pixel Forge — System Architecture & Code Review Guide

This document provides a comprehensive technical breakdown of the Pixel Forge image generation platform. It is structured for technical deep-dives, architectural reviews, and code review evaluations.

---

## 1. System Overview & Architecture Diagram

Pixel Forge is an image generation platform integrating with the Pixelforge provider API. It guarantees exact financial correctness through an integer nanodollar math engine, provides live feedback with Inertia Vue 3, and ensures high availability and idempotency across asynchronous webhooks and queued jobs.

```
[ Frontend: Inertia + Vue 3 ]
       │  ▲ (Live range estimate / 3s partial reload polling)
       ▼  │
[ GenerationController ] ──> [ PricingService ] (Rate snapshots & token math)
       │
       ├──> [ BalanceService ] (Pre-flight balance validation)
       │
       └──> [ CreateGenerationJob ] ──> [ PixelforgeClient ] ──> [ Pixelforge API ]
                                                                        │
[ Pixelforge Webhooks / Reconciliation ]                                │ (Async execution)
       │                                                                │
       ▼                                                                ▼
[ WebhookController ] ──> [ GenerationStateMachine ] <── [ ReconcileGenerations ]
                                   │
               ┌───────────────────┴───────────────────┐
               ▼                                       ▼
     [ Generation Model ]                     [ BalanceService ]
 (Monotonic state transition)             (Atomic debit & Ledger record)
```

---

## 2. Core Architectural Pillars

### Pillar 1: Zero-Float Nanodollar Financial Engine
*Files: `app/ValueObjects/Money.php`, `app/Services/PricingService.php`*

- **Problem**: IEEE 754 floating-point numbers cannot accurately represent base-10 decimals (e.g. `0.1` or `0.01`), leading to cumulative rounding errors in financial transactions.
- **Solution**: All monetary values are represented as integer **nanodollars** ($1.00 USD = `1,000,000,000` nanodollars) stored in `BIGINT` database columns.
- **Key Details**:
  - **Rate Parsing**: Provider rates (e.g. `"5.00"` per 1M tokens) are parsed via PHP `BCMath` string arithmetic: `bcmul($rate, "1000") = 5,000` nanodollars per token.
  - **Exact Provider Cost**: `Σ(tokens × rate_nano_per_token)` produces an exact integer nanodollar sum with zero precision loss.
  - **Markup (+25%)**: Computed via `bcmul((string)$providerCostNano, "1.25", 4)` and rounded to the nearest whole nanodollar using explicit round-half-up (`bcround($value, 0, BCMATH_ROUND_HALF_UP)`).
  - **Rate Freezing**: Pricing rates are snapshotted in `generations.rates` (JSON) at creation time. Historical settlement always uses the snapshot rates to prevent retroactive price changes from affecting past records.
  - **Failure Guarantee**: Failed generations strictly record `provider_cost_nano = 0` and `user_charge_nano = 0`.

---

### Pillar 2: Monotonic State Machine
*File: `app/Actions/GenerationStateMachine.php`*

- **Problem**: Asynchronous distributed workflows can deliver webhook events out of order, or slow HTTP responses might return after a webhook has already been processed.
- **Solution**: A centralized, forward-only state machine enforces deterministic lifecycle transitions:
  $$\text{queued} \longrightarrow \text{processing} \longrightarrow (\text{succeeded} \mid \text{failed})$$
- **Key Details**:
  - `succeeded` and `failed` are terminal states. Late delivery of a `processing` webhook after completion is discarded safely.
  - State changes, usage recording, and balance debits occur within a single database transaction using row-level locking (`lockForUpdate()`).

---

### Pillar 3: Idempotent Webhooks & Cryptographic Verification
*File: `app/Http/Controllers/WebhookController.php`*

- **Problem**: Webhook endpoints are vulnerable to replay attacks, spoofed payloads, and duplicate billing from provider retry attempts.
- **Solution**:
  - **Signature Authentication**: Extracts `Pixelforge-Signature: t=<unix>,v1=<hex>` and calculates `hash_hmac('sha256', "$timestamp.$rawBody", $secret)`. Compared with timing-safe `hash_equals()`.
  - **Replay Protection**: Rejects payloads where `abs(time() - $timestamp) > 300` seconds with `400 Bad Request`.
  - **Deduplication**: Incoming `event_id` is inserted into the `webhook_events` table under a `UNIQUE` database constraint in the same transaction as the state transition. Duplicates return `200 OK` immediately without repeating business logic.

---

### Pillar 4: Resilient Job Pipeline & Adaptive Backoff
*Files: `app/Jobs/CreateGenerationJob.php`, `app/Http/Client/PixelforgeClient.php`*

- **Problem**: Network latency, concurrency caps (3 concurrent), rate limits (60 req/min), and temporary provider 503 errors must not drop user requests.
- **Solution**:
  - **Client-Side UUID Idempotency**: The local generation UUID is passed as both the `Idempotency-Key` HTTP header and `metadata.generation_id`. Retries never create duplicate generations on Pixelforge.
  - **Error Classification**:
    - *Terminal Failures* (`400 content_policy_violation`, `401 invalid_api_key`, `422 validation_failed`, `429 daily_limit_exceeded`): Fail immediately, write user-friendly error messages, and avoid queue waste.
    - *Transient Failures* (`429 rate_limit_exceeded`, `429 concurrency_limit_exceeded`, `503 overloaded`): Re-released to the database queue with exponential backoff (`1s`, `2s`, `4s`, `8s`, `16s`) honoring the `Retry-After` header, capped at 5 attempts.

---

### Pillar 5: Concurrency-Safe Ledger & Balances
*Files: `app/Services/BalanceService.php`, `app/Models/UserBalance.php`, `app/Models/UserLedger.php`*

- **Problem**: Concurrent generation requests could overdraw a user's credit balance.
- **Solution**:
  - **Pre-Flight Validation**: Blocks generation requests upfront if `user_balance < estimated_max_charge`.
  - **Atomic Conditional Updates**: Debits are executed using atomic SQL queries and row locks:
    ```sql
    UPDATE user_balances 
    SET balance_nano = balance_nano - :charge 
    WHERE user_id = :userId AND balance_nano >= :charge;
    ```
  - **Double-Entry Auditing**: `user_ledger` enforces a `UNIQUE(generation_id)` constraint, preventing duplicate ledger entries. All balance movements log to `credit_transactions`.

---

### Pillar 6: Dual Reconciliation Engine
*Files: `app/Console/Commands/ReconcileGenerations.php`, `app/Console/Commands/ReconcileUsage.php`, `app/Http/Controllers/AdminReportController.php`*

- **Problem**: Pixelforge webhooks are not guaranteed. Additionally, operators require proof that local accounting matches upstream billing.
- **Solution**:
  - **In-Flight Active Polling**: When viewing `/history`, active generations (`queued`/`processing`) query `GET /v1/generations/{id}` and transition to completion immediately.
  - **Scheduled Sweeper**: `ReconcileGenerations` runs periodically to resolve any generations stuck beyond 2 minutes.
  - **UTC Day Bucketing & Usage Audit**: Aggregates local provider costs by UTC completion timestamp (`completed_at`) and compares them in real-time against Pixelforge's `GET /v1/usage` API, proving a `$0.000000000` discrepancy.

---

## 3. Database Schema Overview

```
generations
  ├── id (UUID, Primary Key)
  ├── user_id (FK → users.id)
  ├── provider_id (String, Nullable, Indexed)
  ├── prompt (Text)
  ├── size, quality (Strings)
  ├── reference_images (JSON, Nullable)
  ├── status (Enum: queued, processing, succeeded, failed)
  ├── rates (JSON, Snapshot at creation)
  ├── usage (JSON, Nullable, Returned on success)
  ├── image_url (String, Nullable)
  ├── provider_cost_nano (BIGINT, Default 0)
  ├── user_charge_nano (BIGINT, Default 0)
  ├── idempotency_key (String, Unique)
  └── completed_at (Timestamp, Nullable)

webhook_events
  ├── id (BIGINT, Auto-increment)
  ├── event_id (String, Unique)
  ├── generation_id (UUID, Nullable)
  ├── event_type (String)
  ├── payload (JSON)
  └── processed_at (Timestamp)

user_balances
  ├── id (BIGINT, Auto-increment)
  ├── user_id (FK → users.id, Unique)
  └── balance_nano (BIGINT, Default 10_000_000_000 = $10.00)

user_ledger
  ├── id (BIGINT, Auto-increment)
  ├── user_id (FK → users.id)
  ├── generation_id (UUID, Unique)
  ├── amount_nano (BIGINT)
  └── type (Enum: credit, debit)

credit_transactions
  ├── id (BIGINT, Auto-increment)
  ├── user_id (FK → users.id)
  ├── amount_nano (BIGINT)
  ├── type (Enum: purchase, charge, refund)
  └── reference (String, Nullable)
```

---

## 4. Key Questions & Strategic Answers (Code Reviews & Interviews)

### Q1: Why use integer nanodollars instead of `DECIMAL(12, 4)` in MySQL or native PHP floats?
> **Answer**: Native floats use IEEE 754 binary floating-point representation, which cannot represent base-10 fractions (like 0.1) exactly, leading to compounding rounding errors. While `DECIMAL` works in SQL, casting values into PHP variables often inadvertently turns them into floats. Representing all currency as integer nanodollars (`1 USD = 1,000,000,000`) guarantees that all operations are exact integer arithmetic. Fractional calculations (such as the 25% markup) use `BCMath` with explicit round-half-up rules.

### Q2: What happens if a webhook arrives before the `POST /v1/generations` HTTP request completes?
> **Answer**: The application writes the local `generations` row to the database *before* making the HTTP request to Pixelforge. The local UUID is passed in `metadata.generation_id`. If Pixelforge emits a webhook immediately, the webhook handler matches the record using `metadata.generation_id`. If the webhook transitions the status to `processing` or `succeeded`, the subsequent return of `CreateGenerationJob` gracefully checks the current state and records the `provider_id` without regressing the status.

### Q3: How do you guarantee a user is never double-charged under concurrent duplicate webhooks?
> **Answer**: Idempotency is enforced at three distinct layers:
> 1. **Unique Database Constraint**: `webhook_events.event_id` is unique.
> 2. **Row Locking**: The state machine acquires an exclusive lock (`lockForUpdate()`) on the generation row inside the transaction.
> 3. **Ledger Uniqueness**: `user_ledger.generation_id` has a unique constraint, and `BalanceService::debit()` checks if a ledger record already exists for the generation before debiting credits.

### Q4: Why snapshot pricing rates at generation creation rather than calculating actuals using live rates?
> **Answer**: API pricing tables can change over time. If rates change between when a generation is queued and when it completes (or when historical audit reports are run), calculating actual costs from live rates would retroactively corrupt financial history. Freezing rates in `generations.rates` ensures deterministic, immutable pricing records.

### Q5: What happens if Pixelforge drops a webhook or fails to deliver it?
> **Answer**: Webhooks are an optimization, not a single point of failure. The application implements active reconciliation:
> 1. **History Page Auto-Reconciliation**: Whenever `/history` is viewed or auto-polled, any active generations (`queued`/`processing`) query `GET /v1/generations/{id}` and transition immediately if finished.
> 2. **Scheduled CLI Command**: `ReconcileGenerations` runs on schedule to poll and resolve any stuck generations.

### Q6: How does the frontend maintain real-time status and balance updates without WebSockets?
> **Answer**: The frontend uses Inertia partial reloads. While active generations exist on `/history`, a 3-second interval triggers `router.reload({ only: ['generations', 'auth'], preserveScroll: true })`. Laravel executes only the generation query and balance check, returning a minimal JSON diff without full page re-renders.

---

## 5. Technical Summary Reference

| Category | Specification | Implementation Detail |
| :--- | :--- | :--- |
| **Money Scale** | Integer Nanodollars | `1 USD = 1,000,000,000 nanodollars`, stored as `BIGINT` |
| **Markup Rule** | +25% Round Half Up | `bcmul($nano, '1.25', 4)` → `bcround($val, 0, BCMATH_ROUND_HALF_UP)` |
| **Webhook Auth** | HMAC-SHA256 | `Pixelforge-Signature: t=...,v1=...` via `hash_equals()` |
| **Replay Window** | 300 Seconds | Rejects requests where `abs(now - timestamp) > 300` |
| **Deduplication** | Unique Constraints | `webhook_events.event_id` & `user_ledger.generation_id` |
| **State Machine** | Monotonic Forward-Only | `queued` → `processing` → `succeeded` \| `failed` |
| **Retry Strategy** | Capped Exponential | 5 attempts (`1s, 2s, 4s, 8s, 16s`) honoring `Retry-After` |
| **Reconciliation** | UTC Date Bucketing | Aligns local `completed_at` sums against `GET /v1/usage` |
