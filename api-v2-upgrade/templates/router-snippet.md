# Router / middleware changes for v2

Keep every v1 branch exactly as it is. Add a `$v2` flag and answer in the v2 format first.

```php
private function middlewareApi(string $uri): void
{
    $v2 = str_starts_with($uri, '/api/v2/');

    $apiKey = $_SERVER['HTTP_X_API_KEY'] ?? '';
    if ($apiKey === '') {
        if ($v2) \App\Helpers\ApiV2::fail('AUTH_REQUIRED', 'API key required. Send it in the X-API-Key header.', 401);
        // … v1 reply unchanged …
    }

    $row = /* same key + user lookup as v1 */;
    if (!$row /* inactive key, disabled user, API not approved */) {
        if ($v2) \App\Helpers\ApiV2::fail('INVALID_API_KEY', 'Invalid or inactive API key.', 401);
        // … v1 reply unchanged …
    }
    $_SERVER['API_USER'] = $row;   // what BaseV2::apiUser() reads

    // every other v1 guard (allowed domains/IPs, blocked IPs, account disabled…):
    //   if ($v2) \App\Helpers\ApiV2::fail('<CODE>', '<message>', 403);   then the v1 reply

    // Rate limit: same limiter, one bucket per v2 endpoint, headers on every reply
    $endpoint = $this->normaliseEndpoint($uri);
    $limiter  = new RateLimit();
    if ($v2) {
        $res = $limiter->check((int)$row['user_id'], $endpoint);        // non-exiting variant
        $st  = $limiter->status((int)$row['user_id'], $endpoint);       // ['limit','remaining','reset']
        if ($st) {
            header('X-RateLimit-Limit: ' . $st['limit']);
            header('X-RateLimit-Remaining: ' . $st['remaining']);
            header('X-RateLimit-Reset: ' . $st['reset']);
        }
        if (!$res['allowed']) {
            $ra = (int)($res['retry_after'] ?? 60);
            header('Retry-After: ' . $ra);
            \App\Helpers\ApiV2::fail('RATE_LIMITED', "Too many requests. Please retry after {$ra} seconds.", 429, ['retry_after' => $ra]);
        }
        return;
    }
    $limiter->enforce((int)$row['user_id'], $endpoint);              // v1 unchanged
}
```

If the limiter only has an `enforce()` that echoes + exits, add `check()` (returns
`['allowed'=>bool,'retry_after'=>int]`) and `status()` beside it — don't change `enforce()`.

## Buckets — add BEFORE the v1 patterns (first match wins)
```php
'#^/api/v2/orders/bulk$#'   => '/api/v2/orders/bulk',
'#^/api/v2/orders/[^/]+$#'  => '/api/v2/orders/{ref}',
'#^/api/v2/afa/[^/]+$#'     => '/api/v2/afa/{ref}',
```

## 404
```php
if (str_starts_with($_SERVER['REQUEST_URI'] ?? '', '/api/v2/')) {
    \App\Helpers\ApiV2::fail('NOT_FOUND', 'Endpoint not found. Check the method and path in the v2 docs.', 404);
}
```

## Routes
Same middleware as v1 (`['api']`). Specific paths before `{ref}` paths:
```php
['POST', '/api/v2/orders/bulk',   \App\Controllers\Api\V2\OrdersController::class, 'bulk',   ['api']],
['POST', '/api/v2/orders',        \App\Controllers\Api\V2\OrdersController::class, 'create', ['api']],
['GET',  '/api/v2/orders',        \App\Controllers\Api\V2\OrdersController::class, 'list',   ['api']],
['GET',  '/api/v2/orders/{slug}', \App\Controllers\Api\V2\OrdersController::class, 'show',   ['api']],
```
Check what placeholder names the router supports (`{id}` may be digits-only; references need a
slug-like placeholder).

## CSRF
API routes must stay outside the site's CSRF check (as v1 is). Confirm `/api/v2/...` is covered
by the same exemption.
