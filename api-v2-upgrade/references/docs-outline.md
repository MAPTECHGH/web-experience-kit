# v2 docs page outline (docs_v2)

Put a **v1 / v2 switch** at the top of the existing API docs page (`?v=2`). v1 docs unchanged.

1. **Quick start** — base URL, `X-API-Key` header, a curl `GET /api/v2/me`, the reply.
2. **Replies** — the `ok/data/meta` and `ok/error/meta` shapes; quote `request_id` to support.
3. **Errors** — table: HTTP, code, meaning, what to do (retry? top up? fix field?).
4. **Retry safely** — `idempotency_key` rules with a "network dropped, retry with the same key"
   example; replay flag; mismatch / in-progress codes.
5. **Your own reference** — `client_reference`, lookup by it.
6. **Test without paying** — `dry_run`.
7. **Endpoints** — one card each: method + path, body fields table (name, type, required, notes),
   example request (curl + PHP), example reply, possible error codes.
8. **Bulk** — per-line results example with one failed line.
9. **Webhooks** — events, payload, per-order `webhook_url`, signature header, verification code in
   PHP / Node / Python (`hash_equals(hash_hmac('sha256', $raw, $secret), $sig)`), retry policy.
10. **Rate limits** — headers, 429 + `retry_after`.
11. **Changelog** — "v2 added on <date>; v1 keeps working".

Also: an "API v2" section in the site's API tester with dry-run requests pre-filled, and a
"Download docs" popup offering v1 or v2 PDF if the site already ships a PDF.
