# Typical v2 endpoints (data-reseller site)

Map each to what the site actually sells; drop what it doesn't.

| Method | Path | Notes |
|---|---|---|
| GET | /api/v2/me | account name, balance, credit limit, webhook set-up (url saved?, secret exists) |
| GET | /api/v2/networks | code, name, active, lock mode |
| GET | /api/v2/bundles?network=mtn | id, network, label, capacity_gb, validity, **price for this account** |
| POST | /api/v2/numbers/check | up to 30 numbers → verified / unverified / blacklisted / open (if the site has number checks) |
| POST | /api/v2/orders | one data order (dry_run, idempotency_key, client_reference, webhook_url) |
| POST | /api/v2/orders/bulk | up to 100, one result each |
| GET | /api/v2/orders | list + filters + pagination |
| GET | /api/v2/orders/{ref} | by reference or client_reference |
| GET | /api/v2/transactions | wallet statement (type, reference, from, to) |
| POST | /api/v2/afa | AFA / SIM registration (same money rules) |
| GET | /api/v2/afa, /afa/{ref} | |
| GET/POST | /api/v2/result-cards, /quote, /purchase, /orders, /orders/{ref} | if sold |
| GET/POST | /api/v2/ishare/packages, /balance, /buy, /send, /transfers, /transfers/{ref} | if sold |

## Order object (keep the same keys everywhere)
```json
{
  "reference": "API-20261009-AB12CD",
  "client_reference": "inv-1001",
  "status": "processing",            // accepted | processing | delivered | failed | refunded
  "network": {"code": "mtn", "name": "MTN"},
  "bundle": {"id": "12", "label": "5GB", "capacity_gb": 5},
  "recipient": "0241234567",
  "price": 25.5, "currency": "GHS",
  "created_at": "2026-10-09T11:28:00+00:00",
  "updated_at": "2026-10-09T11:29:10+00:00",
  "delivered_at": null,
  "failure_reason": null
}
```
Status names: map the site's internal statuses to this short public list once (one function)
and use it in lists, single reads and webhooks.

## Webhook payload
```json
{"event":"order.delivered","order":{…order object…},"sent_at":"2026-10-09T11:29:10+00:00"}
```
Events: `order.processing`, `order.delivered`, `order.failed`, `order.refunded`
(+ `afa.*`, `result_card.*` where sold).
