# Audit checklist — what to hunt for

Real findings only: every item needs a file:line, an attacker path and a fix.

## A. Money in (top-ups, payment webhooks, verify buttons)
- [ ] Verify/"I have paid" button: does it verify the **reference the browser sent** instead of
      the one stored on the payment row? (One small real payment → credits a big pending one.)
- [ ] Amount credited comes from the **stored row**, and only when the gateway's paid amount
      ≥ that amount, currency = expected, gateway reference = this payment's reference.
- [ ] Credit uses gateway **metadata** (payer can set it) instead of the stored amount?
- [ ] Webhook/callback takes a **token / reference / amount from the request** and trusts it?
- [ ] Webhook signature: checked with `hash_equals`, raw body, correct secret; what happens when
      **no secret is saved** (often "accept all")? When the **signature header is missing**?
- [ ] Hard-coded or default secrets (`'direct'`, `'maptech'`, `'secret'`, `'test'`).
- [ ] Client can choose the payment **reference** (e.g. reuse a shop order's `SHOP_` ref for a
      cheaper top-up)? Webhooks that route by reference **prefix** and skip the amount check?
- [ ] Webhook creates a payment row when none exists (attacker invents one)?
- [ ] Double credit: webhook + redirect callback + verify button + retries, concurrently. Look for
      read-check-update without `FOR UPDATE` / guarded `UPDATE … WHERE status='pending'`.
- [ ] SMS-based crediting (MoMo "payment received" SMS forwarder): secret required? sender checked?
      one Transaction ID credited once *ever* (not just once per user row)? two users claiming the
      same Transaction ID at once? guessing other people's Transaction IDs (rate limit)?

## B. Money out / buying
- [ ] Price always computed server-side from the user's price group; client price ignored.
- [ ] Bundle from another price group / disabled / other network rejected.
- [ ] Balance check and debit atomic (`FOR UPDATE` or `UPDATE … WHERE balance >= ?`).
- [ ] Zero/missing price treated as **free** (e.g. AFA price row missing → 0.00)?
- [ ] Debit and order insert in one transaction; DB auto-reconnect **inside** a transaction
      (splits it: order saved, debit lost)?
- [ ] Provider called inside an open transaction (cards/data bought, then rolled back → lost)?
- [ ] Queue/cron: order **claimed** (`accepted → processing` with affected-rows check) before
      sending; inline dispatch + cron can't both send; batch sends claim first.
- [ ] Timeout/curl error treated as "failed" → auto-refund while the provider may deliver.
- [ ] API: key lookup, IDOR on status endpoints (filter by user), idempotency key scoped per user,
      blacklist / lock-mode checks on every purchase path (incl. secondary APIs).
- [ ] Public USSD / WhatsApp / bot endpoints: who can call them, can they spend someone's balance.

## C. Refunds, shop checkout, commissions, withdrawals
- [ ] Refund decided from a stale row, credit not gated by status change → double refund
      (double click, bot retry, auto-refund + admin at once).
- [ ] Refund to wallet for orders never paid from the wallet (MoMo / shop orders).
- [ ] "Undo refund"/requeue ignores a failed claw-back.
- [ ] Shop checkout: paid amount compared with `customer_price`? (Customer can start their own
      tiny Paystack charge with the order's reference when the order was created for another
      gateway.) Same payment reference reusable?
- [ ] Shop/sub-agent prices can go **below platform cost** (AFA, bundles) while the platform pays.
- [ ] Withdrawal approve/reject: only from `pending`, once; reject refunds once.
- [ ] Payout timeout → balance given back → paid twice. Payouts with no idempotency reference.
- [ ] Absolute balance writes (`SET balance = ?`) after an unlocked read (lost updates).
- [ ] Commission credited twice (two "delivered" events) — idempotent flag under lock.
- [ ] Sub-agent: IDOR on other agents, PIN brute force, PINs stored in plain text.

## D. Auth, admin, server
- [ ] Routes changing state/money without `auth`/`admin`/`perm:` middleware.
- [ ] Admin with "manage users" can promote to admin / reset the Owner's password / disable Owner;
      "no role" = full access?
- [ ] Per-admin wallet limits enforced under a lock; admins raising their own limits.
- [ ] Login/PIN/mobile-login brute force: per-IP only (fakeable `X-Forwarded-For`) vs per-account;
      session-only counters (cleared by dropping cookies); endpoints with no limit at all.
- [ ] Disabled/demoted users keep working sessions.
- [ ] Password reset: token strength, expiry, single use, other links cancelled.
- [ ] Uploads: extension from the **file name** while only MIME/magic bytes are checked
      (`GIF89a<?php …` named `x.php` passes) → remote code execution. Uploads folder runs PHP?
- [ ] SSRF: server fetches user URLs (downloaders, webhook tests) — substring host allow-lists,
      redirects, private IPs.
- [ ] Command injection (`exec`, `shell_exec`, `system`, backticks).
- [ ] Web root exposure: `*.sql` dumps, `*.zip` backups, `logs/`, `crons/*.php` runnable from the
      web, export files with customer data, `.env` rules.
- [ ] Cron trigger endpoints protected by a secret (`hash_equals`), fail closed.
- [ ] AI assistant / bot actions: can a user make it move money or act on another account?
- [ ] Hard-coded third-party API keys in source.
- [ ] Bugs that show one user's data to another (e.g. using `execute()`'s row count as an insert id).
