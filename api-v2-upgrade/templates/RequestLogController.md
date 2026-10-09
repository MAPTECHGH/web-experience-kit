# Optional: Admin → API Request Logs

Reference implementation (maptechgh.com): `GET /admin/api-requests`, `GET /admin/api-requests/{id}`,
`POST /admin/api-requests/settings`, `POST /admin/api-requests/clear` (type DELETE to confirm).

- Search box: exact `req_…` id jumps straight to the details; otherwise search reference,
  idempotency key, IP, path, user email/name/phone, error code.
- List: time, request id, user, `METHOD path`, result badge (`201 OK` / `402 INSUFFICIENT_BALANCE`), IP, ms.
- Details: request headers (API key shown as a hint only), request body (secrets masked),
  exact response body, related requests (same reference / idempotency key = retries),
  webhooks sent for that order.
- Settings: recording on/off, keep 7/14/30/60/90 days or forever (old rows deleted 1-in-200 calls).
- Stat boxes must use the admin theme variables (e.g. `var(--surface)`, `var(--text)`) — not
  hard-coded white backgrounds — or they turn white-on-white in dark themes.
- Tell clients in the docs: "quote the `request_id` from `meta.request_id` / `X-Request-Id`
  when you contact support".
