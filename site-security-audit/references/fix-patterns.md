# Fix patterns (PHP / PDO / MySQL)

## Verify a payment with the stored reference and amount
```php
// SECURITY: verify THIS payment's own reference, never one from the request
$reference = $payment['paystack_ref'] ?: $payment['reference'];
$res  = $paystack->verify($reference);
$d    = $res['data'] ?? [];
$paid = ((int)($d['amount'] ?? 0)) / 100;
$expected = (float)($payment['amount_settled'] ?: $payment['amount']);
if (($d['status'] ?? '') !== 'success' || ($d['reference'] ?? '') !== $reference
    || strtoupper($d['currency'] ?? 'GHS') !== 'GHS' || $paid + 0.009 < $expected) {
    // log and stop — do not credit
}
$credit = $expected;            // never metadata, never the request
```

## Once-only gate (credit / refund / claim / dispatch)
```php
$db->beginTransaction();
$n = $db->execute("UPDATE orders SET status='refunded', refunded_at=NOW()
                    WHERE id=? AND status NOT IN ('refunded','delivered')", [$id]);
if ($n < 1) { $db->rollBack(); return 'already done'; }
$u = $db->fetchOne('SELECT balance FROM users WHERE id=? FOR UPDATE', [$uid]);
// … credit, write ledger row …
$db->commit();
```
Across several entry points (button + bot + cron) also serialise with a named lock:
```php
if ((int)$db->fetchColumn('SELECT GET_LOCK(?, 15)', ['order_refund_'.$id]) !== 1) return 'busy';
try { /* re-read the row, then the gated update above */ }
finally { $db->fetchColumn('SELECT RELEASE_LOCK(?)', ['order_refund_'.$id]); }
```
One external ID credited once ever: check the ledger (`transactions.reference = txId`) inside the
locked section, ideally plus a UNIQUE key.

## Claim before sending to a provider
```php
if ($db->execute("UPDATE orders SET status='processing' WHERE id=? AND status='accepted'", [$id]) < 1) return;
$r = $provider->send($order);
if (timeoutOrUnclear($r)) { /* leave in processing for review — NOT failed, NOT refunded */ }
```

## Webhook secret
```php
$secret = (string) Setting::get('x_webhook_secret', '');
if ($secret === '' || !hash_equals($secret, (string)$incoming)) { http_response_code(401); exit; }
```
If turning this on would stop real payments when no secret is saved, instead confirm the payment
with the provider's own status API and use the status/amount from there.

## Upload
```php
if (@getimagesize($tmp) === false) return error('Not an image');
$ext = ['image/png'=>'png','image/gif'=>'gif','image/webp'=>'webp'][$mime] ?? 'jpg';
$name = bin2hex(random_bytes(10)).'.'.$ext;
```
`uploads/.htaccess`:
```
<FilesMatch "\.(php[0-9]?|phtml|phar|pht|phps|shtml|cgi|pl|py|sh)$">
    Require all denied
</FilesMatch>
<IfModule mod_php.c>
    php_flag engine off
</IfModule>
Options -Indexes -ExecCGI
```

## Web-root .htaccess additions
```
RewriteRule ^(app|config|crons|logs|vendor|database|databbase)(/|$) - [F,L]
<FilesMatch "\.(log|sql|bak|sh|zip|gz|tgz|tar|rar|7z|old|orig|swp)$">
    Require all denied
</FilesMatch>
```
Check that nothing public (assets) lives in a blocked folder first.
Cron scripts: first line after `declare`: `if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }`

## Per-account login limit (IP-independent)
```php
$fails = (int)$db->fetchColumn("SELECT COUNT(*) FROM auth_attempts
          WHERE type='login' AND identifier=? AND created_at >= NOW() - INTERVAL 15 MINUTE", [$email]);
if ($fails >= 10) { /* refuse: "try again in 15 minutes" */ }
```

## Hash PINs without locking anyone out
- `verify($pin, $stored)`: `password_verify` when `$stored` starts with `$2y$`, else `hash_equals`
  (old plain PIN).
- On a correct login with a plain PIN → save `password_hash($pin)`.
- One-time migration (guarded by a settings flag + `GET_LOCK`): widen the column to
  `VARCHAR(255)`, hash every plain value. Run it **before** any transaction (ALTER commits).
- Anything that showed or texted the stored PIN must now ask for it or say "the PIN you chose".

## Admin escalation
```php
if ($target['role'] === 'admin' && !Auth::isOwner()) deny('Only the Owner can change admin accounts');
if ($newRole === 'admin' && $target['role'] !== 'admin' && !Auth::isOwner()) $newRole = $target['role'];
```
