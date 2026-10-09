---
name: api-v2-upgrade
description: Add a safe "version 2" of a website's reseller/client API (built for PHP data-bundle sites) next to the old one — one reply format, request IDs, never-charge-twice retry keys, dry runs, client references, per-order webhooks, bulk orders, request logs and v2 docs — without breaking v1. Use for "create API v2", "upgrade our API", "make our API like maptechgh's".
---

# API v2 Upgrade

Builds `/api/v2/...` **beside** the site's existing API. v1 keeps working byte-for-byte; v2 uses the
**same API keys, middleware, rate limits and IP/domain rules**, and creates orders in the **same
tables** so queues, providers, refunds, reports and webhooks keep working unchanged.

Proven on maptechgh.com (PHP MVC, MariaDB, wallet balance, Paystack/Moolre, provider queue):
8 identical requests at the same instant → 1 order, 1 charge.

Three phases, always in this order. Never jump ahead without the user's go-ahead.

1. **SURVEY** — read the current API and show what v2 will offer. Change nothing.
2. **BUILD** — after "go ahead": write v2 in a new folder, test it, write docs.
3. **DELIVER** — install note, docs page/PDF, tester section, what the clients get.

## Ground rules

- **v1 must not change behaviour.** Only touch v1 files to fix a real security bug, and say so
  in the install note (example: retry keys that returned *another account's* order).
- **Never open secrets:** `.env`, config with passwords/keys, DB dumps, logs, uploads, vendor.
- **Never overwrite live files.** Write to a new folder in the site copy (e.g. `_api_v2`, then
  `_api_v2_fix1`…). Keep each file's line endings (CRLF stays CRLF).
- **Never call real providers or move real money** to test. Test on a local copy with a local DB
  and fake gateways/providers.
- **Additive DB changes only**, created on first use (`CREATE TABLE IF NOT EXISTS`, add a column
  only after checking `information_schema`). No migrations for the owner to run.
- Explain in plain words. The owner sells data, not software.

## Phase 1 — SURVEY (read-only)

1. Find the v1 API: routes file (`/api/...`), its controllers, the API-key middleware, rate limiter,
   webhook sender, docs page, API settings page (key/secret/webhook URL), the order/AFA/etc. models
   and how a purchase charges the wallet (look for `balance`, `FOR UPDATE`, transactions table).
2. List every v1 endpoint with: method, path, inputs, output shape, error shape, money moved?
3. Note problems v2 will fix (typical): different reply shapes per endpoint, no request id,
   retry keys global not per-account, no dry run, webhook only to one saved URL, bulk is
   all-or-nothing, no way to find an order by the client's own id, no rate-limit headers.
4. Show the owner a short table: **v1 today → v2 endpoint → what's better**, plus the list of new
   tables. Ask: which products to include (data, AFA, airtime, result cards, iShare…), max bulk
   size (default 100), and whether to add the admin "API Request Logs" page. Wait for go-ahead.

## Phase 2 — BUILD

Read `references/design.md` first (the contract), then copy and adapt the templates.
(If this skill was installed without its folders, get them from
`github.com/MAPTECHGH/web-experience-kit/tree/main/api-v2-upgrade` — `templates/`, `references/`, `scripts/`.)

| Template | Becomes | Adapt |
|---|---|---|
| `templates/ApiV2.php` | `app/helpers/ApiV2.php` | namespaces, `Database`/`Setting` classes, webhook table names, phone rule |
| `templates/BaseV2.php` | `app/controllers/api/V2/BaseV2.php` | how the middleware exposes the API user; balance/credit rules |
| `templates/OrdersController.php` | one controller per product | pricing call, order model/fields, reference prefix, `source` value |
| `templates/router-snippet.md` | middleware + 404 changes | v2 errors in v2 format, rate-limit headers, per-endpoint buckets |
| `templates/webhook-snippet.md` | webhook sender | per-order `webhook_url`, `client_reference` in payload |
| `templates/RequestLogController.md` | optional admin page | search by `req_…` id, settings, clear |

Rules that must hold in every money endpoint (copy the create() flow exactly):

1. Validate everything → 422 `VALIDATION_ERROR` with `fields` — **before** anything is reserved.
2. `ApiV2::idemBegin()` (reserve the key; replays/mismatch/in-progress exit here).
3. `client_reference` uniqueness check (409).
4. Price from the **server's** pricing code (never trust a price sent by the client).
5. `dry_run` → return what *would* happen (price, balance_after, would_succeed). No writes.
6. One DB transaction: lock user row `FOR UPDATE` → check balance → debit → create order(s) +
   transaction rows + `api_v2_order_meta` → `ApiV2::idemFinish()` → commit. **The key is marked
   done in the same transaction as the charge** — this is what makes double charges impossible.
7. On any error before commit: rollback + `idemRelease()` and say "Nothing was charged".
8. Notifications/queue pokes **after** commit, wrapped in try/catch.

Bulk: validate every line, return one result per line (`index`, `ok`, `order` or `error`), charge
only the lines placed, all inside one transaction. Never fail the whole batch for one bad line.

Then: routes (`/api/v2/...` in the same `api` middleware group), router changes, webhook change,
docs page (`docs_v2`) with a v1/v2 switch, API tester section (dry runs pre-filled), optional
PDF, optional admin request-log page.

### Test (local copy, never live)

Use `scripts/v2_smoke_test.py` against the local copy (PHP built-in server + local MariaDB).
It checks: reply envelope + `X-Request-Id`; 401/404/422 in v2 shape; dry run charges nothing;
**8 parallel identical orders with one key → 1 order, 1 charge**; same key + different body → 409;
`client_reference` duplicate → 409 and lookup by it works; private/`http://`/own-site
`webhook_url` refused; bulk with bad lines charges only good lines; v1 still answers as before.
Edit the `CONFIG` block at the top (base URL, API key, a cheap bundle id, a test number).
Every test must pass before delivery. Also diff v1 replies before/after for 3–4 v1 calls.

## Phase 3 — DELIVER

- `INSTALL.txt` in the folder: files (new/changed), upload order, "nothing to run in the
  database", what clients get, any v1 security fix called out clearly.
- Docs page: base URL, auth header, reply shape, error-code table, every endpoint with request +
  response examples, idempotency + webhook signature verification examples (PHP, Node, Python).
- Tell the owner in 5–8 lines what changed and the one thing to test first (a `dry_run` order
  from the API tester).

See `references/design.md` for the full contract, `references/endpoints.md` for the typical
endpoint set of a data-reseller site, and `references/docs-outline.md` for the docs page.
