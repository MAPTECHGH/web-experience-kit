# API v2 contract

Everything here is what clients rely on. Keep it identical across products.

## 1. Transport
- Base: `https://SITE/api/v2/`. JSON in, JSON out (`Content-Type: application/json; charset=utf-8`).
- Auth: same API key as v1, header `X-API-Key: <key>` (accept v1's other forms too if v1 does).
- Every reply has headers `X-Request-Id: req_<20 hex>`, `X-Api-Version: 2`, `Cache-Control: no-store`,
  and (from the middleware) `X-RateLimit-Limit`, `X-RateLimit-Remaining`, `X-RateLimit-Reset`.

## 2. Reply envelope (no exceptions)
```json
{"ok":true,"data":{...},"meta":{"request_id":"req_…","api_version":"2", "pagination":{...}}}
{"ok":false,"error":{"code":"INSUFFICIENT_BALANCE","message":"Not enough balance for this order.","required":5.0,"available":1.2},"meta":{"request_id":"req_…","api_version":"2"}}
```
- `error.code` is a stable UPPER_SNAKE string clients can switch on; `message` is for humans.
- Extra error fields sit next to code/message (`fields`, `retry_after`, `existing_reference`…).
- Money: numbers rounded to 2 dp + `"currency":"GHS"` (or the site's currency).
- Dates: ISO-8601 with timezone (`2026-10-09T11:28:00+00:00`).

## 3. Error codes (HTTP status → code)
| HTTP | code | when |
|---|---|---|
| 400 | INVALID_JSON | body not JSON |
| 401 | AUTH_REQUIRED / INVALID_API_KEY | no key / bad or inactive key |
| 402 | INSUFFICIENT_BALANCE | `required`, `available` |
| 403 | DOMAIN_NOT_ALLOWED / FORBIDDEN / ACCOUNT_DISABLED | IP/domain rules, blocks |
| 404 | NOT_FOUND / ORDER_NOT_FOUND / BUNDLE_NOT_FOUND | |
| 409 | IDEMPOTENCY_MISMATCH | same key, different request |
| 409 | IDEMPOTENCY_IN_PROGRESS | same key still running (`retry_after`) |
| 409 | CLIENT_REFERENCE_EXISTS | `existing_reference` |
| 409 | DUPLICATE_ORDER | site's own duplicate-order rule (same number+bundle within N min) |
| 422 | VALIDATION_ERROR | `fields: {name: reason}` |
| 422 | NUMBER_NOT_ALLOWED / NETWORK_LOCKED / BUNDLE_UNAVAILABLE | business rules |
| 429 | RATE_LIMITED | `retry_after` seconds |
| 500 | SERVER_ERROR | always says whether anything was charged |
| 503 | SERVICE_UNAVAILABLE | maintenance / product switched off |

## 4. Idempotency (never charge twice)
- Optional `idempotency_key` in the body (1–100 of `A-Za-z0-9_-:.`). Scoped **per account**
  (primary key `(user_id, idem_key)`) — never global, never shows another account's order.
- Request fingerprint = sha256(scope + sorted body without `idempotency_key`/`dry_run`).
- Flow: `INSERT IGNORE` row status `processing` →
  - inserted: go ahead;
  - exists + different fingerprint → 409 IDEMPOTENCY_MISMATCH;
  - exists + `done` → replay stored reply (same HTTP code) with `meta.idempotent_replay:true`;
  - exists + `processing` and older than 90 s → take over (a crash never charged, because charge
    and `done` are one transaction); else 409 IDEMPOTENCY_IN_PROGRESS.
- **`idemFinish()` runs inside the charge transaction.** Any error reply before the charge
  releases the key automatically (ApiV2::send does this), so the client can retry after topping up.
- `dry_run` requests never use the key.

## 5. client_reference
- Optional, 1–64 of `A-Za-z0-9_-`, unique per account (unique index `(user_id, client_reference)`).
- Stored in `api_v2_order_meta(kind, order_id, reference, client_reference, webhook_url)`.
- `GET /orders/{ref}` accepts our reference **or** the client_reference.
- Included in every v2 reply and webhook for that order.

## 6. Per-order webhook_url
- Optional on create. Must be `https://`, public host, no user:pass, not localhost/.local/.internal,
  not this site, and every resolved IP must pass `FILTER_FLAG_NO_PRIV_RANGE|NO_RES_RANGE` (stops SSRF).
- If set, that order's status updates go there (signed with the account's webhook secret), even if
  no URL is saved on the API page. Otherwise the saved account URL is used as in v1.
- The webhook secret exists as soon as the API key exists (create on key creation / first v2 call),
  shown on the API page with Show / Copy / Regenerate.
- Signature: `X-Signature: hex(hmac_sha256(raw_body, secret))` (keep v1's header name too).
- Retries reuse the site's existing webhook retry cron — no new cron.

## 7. dry_run
- `dry_run: true` on any money endpoint: full validation + price + `would_succeed`,
  `balance`, `balance_after`. No DB writes, no key reserved. Insufficient balance → 402 with
  `dry_run:true` so clients can test that path.

## 8. Bulk
- `POST /orders/bulk {orders:[{bundle_id, recipient, client_reference?}], webhook_url?, idempotency_key?, dry_run?}`
- Max N lines (default 100). Reply: `summary {total, placed, failed, charged}` +
  `results[] {index, ok, order | error}`. Only placed lines are charged, in one transaction.
- Duplicate numbers inside the same batch follow the site's duplicate rule per line.

## 9. Lists
- `?page=&limit=` (limit ≤ 100) → `meta.pagination {total, page, limit, total_pages, has_more}`.
- Filters: `status`, `recipient`, `client_reference`, `from`, `to` (YYYY-MM-DD).

## 10. Rate limits
- Same limiter as v1 but one bucket per v2 endpoint (normalise `/orders/{ref}` etc.).
- Item-heavy calls (number checks, bulk) also count items per minute (`api_v2_counters`).
- 429 reply in v2 shape with `retry_after` + `Retry-After` header.

## 11. Request log (optional admin page)
- Table `api_request_logs`: request_id, user_id, method, path, http_status, ok, error_code,
  ip, duration_ms, reference, idempotency_key, request body (secrets masked), response body
  (trimmed), created_at. Written from `ApiV2::send()` and a shutdown hook (crashes too).
- Admin page: paste a `req_…` id → full request/response; settings: on/off + keep N days; clear.
- Mask `api_key`, `secret`, `password`, `token`, `pin` fields before saving.

## 12. Schema (created on first v2 call, flag in settings)
- `api_v2_idempotency(user_id, idem_key PK, request_hash, status, http_code, response, created_at, updated_at)`
- `api_v2_order_meta(id, user_id, kind, order_id, reference, client_reference, webhook_url, created_at,
   UNIQUE(kind,order_id), UNIQUE(user_id,client_reference))`
- `api_v2_counters(user_id, bucket, window_start PK, used)`
- `webhook_logs.per_order TINYINT` (only if the site has a webhook log table)
