---
name: site-security-audit
description: Full security audit of a website's code (built for PHP data-bundle / payment sites) — finds money-losing bugs and security holes, shows a plain-English report, waits for the go-ahead, then fixes them in a separate folder with tests. Use for "audit my site", "check my site for security issues", "security review", or "find and fix vulnerabilities".
---

# Site Security Audit

Two phases, always in this order:

1. **AUDIT** — read the code, find real problems, prove each one, show a report. **Change nothing.**
2. **FIX** — only after the user says go ahead ("fix them", "go ahead", "apply"). Fix in a new
   folder, test every fix, deliver with an install note.

Never skip from 1 to 2 on your own. If the user only says "audit", stop after the report and ask.

## Ground rules (both phases)

- **Never open secrets:** `.env`, config files with passwords/keys, database dumps (`*.sql`),
  logs, `vendor/`, user uploads, exports. Note that they exist (and whether the web can reach
  them), but don't read their contents. If a check needs a value from config, say so and ask.
- **Never touch the live site or live files.** Read from a copy; write fixes to a new folder
  (`_security_fixes`, then `_security_fixes2`, … for later rounds). Never overwrite what the
  user uploads.
- **Never type passwords into live sites, never place real orders, never move real money** to
  "test" something. Testing happens on a local copy with fake payment servers (see phase 2).
- **Keep each file's line endings** (Windows CRLF stays CRLF). Use `scripts/patch_keep_eol.py`.
- Speak plainly. The owner may not be a security expert: say what an attacker would do and
  what it would cost, not jargon.

## Phase 1 — AUDIT

### 1. Map the site (5–10 min, no deep reading yet)
- Find the entry point, router, route list and middleware (auth / admin / CSRF / API key).
- Is the **project root the web root**? (index.php + .htaccess at the top, real files served
  directly.) If so, every file in the folder may be downloadable or runnable — check
  `.htaccess` rules against what's actually there (db dumps, `*.zip` backups, logs, `crons/`,
  uploads, exports).
- List the **money paths**: wallet top-up (each gateway), payment webhooks/callbacks, buying
  (web, bulk, API v1/v2, USSD, WhatsApp, bots), refunds, shop/storefront checkout, commissions,
  withdrawals/payouts, referrals, sub-agents, admin credit/debit, cron jobs that send orders.

### 2. Review in parallel areas
If you can start sub-agents, split the work (each reads its files fully, line by line):
- **A. Money in** — top-ups and every payment webhook/callback/verify button.
- **B. Money out / buying** — every purchase path and the order queue/cron.
- **C. Refunds, shop checkout, commissions, withdrawals, sub-agents, payouts.**
- **D. Auth & admin** — routes/middleware, login & reset, sessions, admin roles/permissions,
  uploads, SSRF/command injection, AI/bot actions, cron endpoints, exposed files.

Give each area the checklist in `references/checklist.md` and these instructions: quote exact
`file:line`, trace where every input comes from, give a concrete attack, a fix, and a list of
what was checked and found SAFE. Without sub-agents, do the four areas yourself in that order.

### 3. Verify every finding yourself
Sub-agent findings are leads, not facts. For each Critical/High item, open the code and confirm:
- the input really is attacker-controlled (follow it from the request),
- no other check stops it (middleware, a lock, a unique key, an earlier `if`),
- the route is reachable by that kind of user.
Drop anything you can't back with a real scenario. Mark items that depend on a setting you
can't see as **"if …"** (e.g. "if no Moolre secret is saved").

### 4. Report (in the chat, plain language)
Group by severity, most dangerous first:
- **Critical** — anyone (or any customer) can take money, take over the server, or bypass login.
- **Serious** — needs a condition, an insider, or two requests at once (double credit/refund).
- **Medium / Low** — limited impact, hardening.

For each: one sentence of *what's wrong*, one of *how someone abuses it* (with an amount if it's
money), and the file. Then:
- **Checked and safe** — short list, so the owner knows what was covered.
- **Do right now on the server** — things that don't need code (delete a db dump, add an
  `.htaccess` deny to uploads, check a folder for `.php` files).
- **Verdict** and the question: *"Say 'fix them' and I'll fix everything in a new
  `_security_fixes` folder, most urgent first, each tested."*

Then **stop and wait.**

## Phase 2 — FIX (only after the go-ahead)

### 1. Set up
- Copy the current files (what's live now — ask the user to refresh the copy if unsure).
- If a previous fix folder exists, build on top of it and say so ("upload X first, then this").
- Use `scripts/patch_keep_eol.py` for every edit: exact-match find/replace, one match only,
  keeps CRLF. Every fix gets a short `// SECURITY:` comment saying what it stops.

### 2. Fix rules (see `references/fix-patterns.md` for code)
- **Payments:** credit only what *this* payment row was created for; verify with the gateway
  using the reference/token **stored when the payment was created**, never one from the request;
  check reference, currency and that the amount paid covers it.
- **Webhooks:** no secret saved = reject (or confirm with the provider's status API). Compare
  with `hash_equals`. Never log the expected signature.
- **Once-only actions** (credit, refund, claim, withdrawal reject, order dispatch): make the
  status change the gate — `UPDATE … SET status='done' WHERE id=? AND status='pending'` and only
  continue when 1 row changed; or a `SELECT … FOR UPDATE` inside a transaction; or `GET_LOCK`.
- **A timeout is not a failure** — when a provider/payout doesn't answer, leave it for review;
  never auto-refund (that pays twice).
- **Uploads:** extension from the detected image type, never from the file name; plus
  `uploads/.htaccess` that refuses to run scripts.
- **Admin:** only the Owner changes admin accounts / creates admins; every money route has a
  `perm:` check; per-admin limits checked under a lock.
- **Logins/PINs:** limit failures per *account* (not only per IP — `X-Forwarded-For` is
  fakeable); hash PINs/passwords (`password_hash`), converting old plain ones on first use so
  nobody is locked out.
- **Don't break working things:** if a fix needs a secret the owner must set (webhook secret,
  app secret), list it in the install note *before* upload, and prefer a safe fallback (e.g.
  confirm with the provider's API) over switching a payment method off.

### 3. Test every fix (prove it)
Build a local copy: PHP built-in server + MariaDB/MySQL with a minimal schema (only the tables
the tested code touches; add columns as errors show). For payment gateways run
`scripts/mock_gateways.py` (fake Paystack / ExpressPay / Moolre over HTTPS with a local CA —
see the script header). Then for each Critical/Serious item:
- run the attack on the **original** code → it should succeed (proves the bug is real),
- run it on the **fixed** code → it must fail,
- run the **normal** flow on the fixed code → it must still work,
- for "twice" bugs, fire 5–8 requests in parallel and check the money moved once.
Also: `php -l` every changed file, and check line endings are unchanged.
If a test crashes inside the user's code, look closer — it may be another real bug (report it).

### 4. Deliver
- Folder `_security_fixes/` with only the changed files in their real paths + `INSTALL.txt`:
  upload order, **"before you upload" checklist** (secrets to set, files to delete on the
  server), what changed in plain words, what was tested, and what was NOT fixed and why.
- Final message: what's fixed, the must-do checklist, anything still open. Keep it short.

## Report template (phase 1)

```
I went through <areas>. Short version: <N> problems that can lose money today, <M> serious, …

## Critical — fix first
1. **<plain title>.** <what's wrong>. <how it's abused, with GH₵ amounts>. (`file.php`)
…
## Serious
…
## Medium / Low
…
## Checked and safe
- …
## Do right now on the server
- …
**Verdict:** ⛔ … Say **"fix them"** and I'll fix everything in a new `_security_fixes` folder.
```
