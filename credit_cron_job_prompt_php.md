# Prompt: Credit Cron Job — PHP Webhook Script

## Overview

Write a PHP script that acts as a **credit usage monitor webhook receiver**. It is called by a **GoHighLevel (GHL) workflow** via HTTP POST. The script validates a trigger word from the incoming JSON body, processes billing transaction CSV data, compares usage against purchased credits, and sends an alert payload to a downstream webhook URL for any `(location_id, usage_type)` combination that has consumed 90% or more of its allocated budget.

The script must also support **CLI execution for testing purposes**, before it is exposed as a live HTTP endpoint.

---

## Dual-Mode Execution

The script runs in two modes, detected automatically at runtime:

### Mode 1: HTTP Mode (Production)

When accessed via a web server (Apache/Nginx), the script behaves as a webhook endpoint receiving POST requests from GHL. Detect this with:

```php
$isCli = (php_sapi_name() === 'cli');
```

### Mode 2: CLI Mode (Testing)

When run from the terminal (`php credit_cron_job.php`), the script simulates an incoming GHL webhook by constructing a mock payload internally, then runs the full processing logic exactly as it would in HTTP mode.

```bash
# Run in CLI mode for testing
php credit_cron_job.php

# Optionally pass a custom trigger word to test mismatch handling
php credit_cron_job.php --trigger=wrong_word

# Optionally skip sending to the downstream webhook during testing
php credit_cron_job.php --dry-run
```

**CLI mock payload** — use this hardcoded structure for local testing:

```php
$mockPayload = [
    'timestamp' => '2026-05-18 02:48:39',
    'ip'        => '127.0.0.1',
    'raw_body'  => [
        'contact_id' => 'test_contact_001',
        'full_name'  => 'Test User',
        'location'   => [
            'id'   => 'BXuCudh2EKUEmv1gC4ai',
            'name' => 'SJ360',
        ],
        'workflow' => [
            'id'   => 'c612e7d9-d649-4878-97f6-c953e7a5ecb7',
            'name' => 'Cron Job: Credit Tracking',
        ],
        'customData' => [
            'trigger_word' => 'credit_cron_job',
        ],
    ],
];
```

In CLI mode, print all output to stdout with clear `[INFO]` / `[WARN]` / `[ERROR]` labels. At the end, print the full JSON payload that would be sent to the downstream webhook, so you can inspect it without actually sending it (unless `--dry-run` is omitted).

---

## How the Script is Triggered (HTTP Mode)

A GHL workflow ("Cron Job: Credit Tracking") sends a webhook POST request on a schedule. The incoming request body is a JSON string. The trigger word is nested inside `raw_body.customData.trigger_word`.

### Incoming Request Structure

```json
{
  "timestamp": "2026-05-18 02:48:39",
  "ip": "34.135.75.202",
  "headers": { "...": "..." },
  "payload": [],
  "raw_body": {
    "contact_id": "s7nWOmcdvdQ3kqmg56sJ",
    "full_name": "",
    "phone": "+601165494950",
    "country": "MY",
    "location": {
      "name": "SJ360",
      "id": "BXuCudh2EKUEmv1gC4ai",
      "address": "C-18-2, The Link 2, Jalil Link 2, No. 5, Jalan Jalil Perkasa 1",
      "city": "Bukit Jalil",
      "state": "Wilayah Persekutuan Kuala Lumpur",
      "country": "MY",
      "postalCode": "57000"
    },
    "workflow": {
      "id": "c612e7d9-d649-4878-97f6-c953e7a5ecb7",
      "name": "Cron Job: Credit Tracking"
    },
    "customData": {
      "trigger_word": "credit_cron_job"
    }
  }
}
```

### Trigger Validation

On every incoming request (HTTP or CLI):

1. Parse the JSON body (or use the mock payload in CLI mode).
2. Navigate to `raw_body → customData → trigger_word`.
3. If the value equals `"credit_cron_job"` (case-insensitive), proceed with the cron job logic.
4. If the trigger word is missing or does not match, respond with HTTP `200` (or print in CLI):
   ```json
   { "status": "ignored", "reason": "trigger_word mismatch or missing" }
   ```
   Do **not** return a non-2xx HTTP status — GHL retries on errors.

---

## Input Files

For all CSV file handling — including column names, data types, parsing logic, credit record structure, and how the billing transactions and total credits files are read and processed — **follow exactly how `credit_widget_admin.php` handles these files**. Mirror its field names, data formatting, and any cleaning or normalisation it applies. Do not deviate from the conventions in that file.

---

## Processing Logic

### Step 1: Load and Parse Files

```
- Read both CSV files following the parsing and field conventions in credit_widget_admin.php
- Clean and validate rows the same way that file does
- Build a lookup array from the credits file keyed by [location_id][usage_type]
```

### Step 2: Group and Aggregate Transactions

```
- Group all transaction rows by (location_id, usage_type)
- Sum the amount field per group → result is total_used
```

### Step 3: Compare Against Budget

For each unique `(location_id, usage_type)` pair:

```
usage_percentage = (total_used / total_credits) * 100

if usage_percentage >= 90:
    → add to alerts array
```

### Step 4: Build the Alert JSON Payload

```json
{
  "triggered_at": "2026-05-18T02:48:39Z",
  "triggered_by": {
    "workflow_id": "c612e7d9-d649-4878-97f6-c953e7a5ecb7",
    "workflow_name": "Cron Job: Credit Tracking",
    "contact_id": "s7nWOmcdvdQ3kqmg56sJ",
    "location_id": "BXuCudh2EKUEmv1gC4ai",
    "location_name": "SJ360"
  },
  "alerts": [
    {
      "location_id": "BXuCudh2EKUEmv1gC4ai",
      "usage_type": "SMS",
      "total_credits": 1000,
      "total_used": 920,
      "usage_percentage": 92.0,
      "threshold_percent": 90
    }
  ],
  "total_alerts": 1
}
```

**Field notes:**
- `triggered_at`: UTC timestamp in ISO 8601 — use `gmdate('Y-m-d\TH:i:s\Z')`
- `triggered_by`: Extract `workflow.id`, `workflow.name`, `contact_id`, and `location.id` + `location.name` from `raw_body`
- `usage_percentage`: Rounded to 2 decimal places — use `round($pct, 2)`
- `total_alerts`: Count of flagged entries
- If **no alerts**, still POST the payload with `"alerts": []` and `"total_alerts": 0`

### Step 5: POST to Downstream Webhook

Use PHP's `curl` to POST the JSON payload:

```php
$ch = curl_init(ALERT_WEBHOOK_URL);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
```

- HTTP 2xx → log success
- HTTP non-2xx or curl error → log status code and response body; do not crash
- In `--dry-run` CLI mode: skip the curl call entirely, just print the payload

### Step 6: Return Response to GHL (HTTP Mode Only)

```php
header('Content-Type: application/json');
http_response_code(200);
echo json_encode([
    'status'       => 'ok',
    'total_alerts' => $totalAlerts,
    'triggered_at' => gmdate('Y-m-d\TH:i:s\Z'),
]);
exit;
```

Always return HTTP 200 — GHL retries on non-2xx responses.

---

## Configuration

Define as constants at the top of the script (or load from a `.env` file using a simple parser):

```php
define('TRANSACTIONS_CSV',  __DIR__ . '/transactions.csv');
define('TOTAL_CREDITS_CSV', __DIR__ . '/total_credits.csv');
define('ALERT_WEBHOOK_URL', 'https://your-custom-api-endpoint.com/credit-alerts');
define('THRESHOLD_PERCENT', 90);
```

---

## Error Handling

| Scenario | Behaviour |
|---|---|
| Missing or unreadable CSV file | Log error; in HTTP mode return `{ "status": "error", "reason": "..." }` with HTTP 200 |
| Row with non-numeric amount or total_credits | Skip row, log warning with row data |
| `(location_id, usage_type)` in transactions but not in credits file | Skip, log warning |
| `total_credits` is zero or null | Skip, log warning to avoid division by zero |
| curl POST fails or times out | Log error with status code and response; do not crash |
| Incoming body is not valid JSON (HTTP mode) | Return HTTP 400 |
| Unexpected exception | Catch with try/catch, log message, return HTTP 200 to GHL |

---

## Logging

Log to stdout (both CLI and HTTP mode via `error_log` or direct echo in CLI) using this format:

```
[INFO]  Mode: CLI (dry-run)
[INFO]  trigger_word: "credit_cron_job" — MATCH, proceeding
[INFO]  Loaded 1,240 transaction rows from transactions.csv
[INFO]  Loaded 85 credit budget entries from total_credits.csv
[INFO]  Grouped into 73 unique (location_id, usage_type) pairs
[WARN]  No budget entry found for (LOC999, WhatsApp) — skipping
[INFO]  Found 4 entries at or above 90% threshold
[INFO]  [DRY RUN] Skipping webhook POST — payload preview:
        { ... }
[INFO]  Done.
```

In HTTP mode, log using `error_log()` so output goes to the server error log, not the HTTP response body.

---

## Dependencies

No external libraries required. Use only PHP built-ins:

- `fopen` / `fgetcsv` — CSV parsing
- `curl` — HTTP POST to downstream webhook
- `json_encode` / `json_decode` — JSON handling
- `gmdate` — UTC timestamps
- `php_sapi_name` — detect CLI vs HTTP mode
- `getopt` — parse CLI flags (`--dry-run`, `--trigger=`)

Minimum PHP version: **7.4+**

---

The script can be a **single self-contained file** if preferred, with all logic in `credit_cron_job.php`.

---

## Summary of Requirements

| # | Requirement |
|---|-------------|
| 1 | Detect runtime mode: CLI (testing) or HTTP (production) using `php_sapi_name()` |
| 2 | In CLI mode, use a hardcoded mock payload; support `--dry-run` and `--trigger=` flags |
| 3 | In HTTP mode, expose as a webhook endpoint receiving GHL POST requests |
| 4 | Parse `raw_body → customData → trigger_word`; proceed only if it equals `"credit_cron_job"` |
| 5 | Extract `workflow`, `contact_id`, and `location` metadata from `raw_body` for the alert payload |
| 6 | Read and parse both CSV files following conventions in `credit_widget_admin.php` |
| 7 | Group transactions by `(location_id, usage_type)` and sum the amount field |
| 8 | Compare total usage against total credits; flag entries ≥ 90% |
| 9 | Build a structured JSON alert payload including `triggered_by` metadata |
| 10 | POST the alert payload via curl to `ALERT_WEBHOOK_URL` (skip in `--dry-run` mode) |
| 11 | Always return HTTP 200 to GHL to prevent workflow retries |
| 12 | Handle all error cases gracefully without crashing |
| 13 | Log each processing step clearly; use stdout in CLI mode, `error_log()` in HTTP mode |
