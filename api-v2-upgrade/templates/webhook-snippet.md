# Webhook sender changes (per-order webhook_url + client_reference)

In the existing "queue a webhook for an order status change" method:

```php
private function v2Meta(string $kind, int $orderId): ?array
{
    try {
        return $this->db->fetchOne(
            'SELECT client_reference, webhook_url FROM api_v2_order_meta WHERE kind = ? AND order_id = ? LIMIT 1',
            [$kind, $orderId]);
    } catch (\Throwable) { return null; }            // v2 never used yet
}

public function queueOrderEvent(int $userId, string $event, int $orderId): void
{
    $meta     = $this->v2Meta('data', $orderId);
    $webhook  = /* account's saved webhook row */;
    $perOrder = $meta && !empty($meta['webhook_url']);
    if (!$perOrder && (!$webhook || $webhook['status'] !== 'active' || $webhook['url'] === '')) return;

    $payload = $this->buildOrderPayload($event, $order);
    if ($meta) $payload['order']['client_reference'] = $meta['client_reference'];

    if ($perOrder) \App\Helpers\ApiV2::ensureSecret($this->db, $userId);   // sign with the account secret
    $this->logModel->createLog($userId, $event,
        $perOrder ? $meta['webhook_url'] : $webhook['url'], json_encode($payload), $orderId, null, $perOrder);
}
```

- The delivery/retry step must look up the **secret** by user (not "active saved webhook only"),
  otherwise per-order deliveries for accounts without a saved URL never send. Mark those rows
  with `per_order = 1` so the retry worker sends them even when the saved webhook is inactive.
- Keep v1's signature header and add `X-Signature` (hex HMAC-SHA256 of the raw body).
- Re-validate `webhook_url` at send time (DNS can change): skip + log if it now resolves to a
  private address.
