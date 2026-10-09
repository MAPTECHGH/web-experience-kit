<?php
/*
 * TEMPLATE / WORKED EXAMPLE — the maptechgh.com data-order controller for API v2.
 * Copy the FLOW exactly (validate → idemBegin → client_reference → server price → dry_run →
 * one transaction: lock user, debit, create order + transaction + meta, idemFinish, commit).
 * ADAPT: PricingHelper / Order / Transaction / DuplicateOrderHelper calls, order fields,
 * reference prefix, 'source' value, status mapping in fmt().
 */
declare(strict_types=1);

namespace App\Controllers\Api\V2;

use App\Helpers\ApiV2;
use App\Helpers\PricingHelper;
use App\Helpers\DuplicateOrderHelper;
use App\Models\Order;
use App\Models\Transaction;

/**
 * Data bundle orders — v2.
 *
 *   POST /api/v2/orders            one order   (dry_run, idempotency_key, client_reference, webhook_url)
 *   POST /api/v2/orders/bulk       up to 100   (each order gets its own result; only placed ones are charged)
 *   GET  /api/v2/orders            list (status, recipient, client_reference, from, to, page, limit)
 *   GET  /api/v2/orders/{ref}      one order by our reference or your client_reference
 *
 * Orders are created exactly like v1 (status "accepted", source api / bulk_api), so the
 * queue, providers, refunds, reports and webhooks all treat them the same way.
 */
class OrdersController extends BaseV2
{
    public const MAX_BULK = 100;

    // ── POST /api/v2/orders ──────────────────────────────────
    public function create(): void
    {
        $b       = ApiV2::body();
        $dry     = ApiV2::boolParam($b['dry_run'] ?? false);
        $idem    = $dry ? '' : ApiV2::idemKey($b['idempotency_key'] ?? '');
        $rawBid  = trim((string)($b['bundle_id'] ?? ''));
        $to      = preg_replace('/\s+/', '', (string)($b['recipient'] ?? '')) ?? '';
        $cref    = ApiV2::cleanClientRef($b['client_reference'] ?? '');
        $hook    = trim((string)($b['webhook_url'] ?? ''));

        $fields = [];
        if ($rawBid === '') $fields['bundle_id'] = 'Required. Use an id from GET /api/v2/bundles.';
        if ($to === '') $fields['recipient'] = 'Required.';
        elseif (!ApiV2::validPhone($to)) $fields['recipient'] = 'Must be a 10-digit Ghana number like 0241234567.';
        if ($cref === null) $fields['client_reference'] = 'Up to 64 letters, numbers, _ or -.';
        if ($hook !== '' && ($p = ApiV2::webhookUrlProblem($hook))) $fields['webhook_url'] = $p;
        if ($fields) ApiV2::fail('VALIDATION_ERROR', 'Please fix the highlighted fields.', 422, ['fields' => $fields]);

        ApiV2::idemBegin($this->db, $this->uid, $idem, 'orders.create', $b);
        if ($cref !== '' && ($t = ApiV2::clientRefTaken($this->db, $this->uid, $cref))) {
            ApiV2::fail('CLIENT_REFERENCE_EXISTS', "client_reference '{$cref}' was already used.", 409, ['existing_reference' => $t['reference']]);
        }

        $pu  = $this->pricingUser();
        $chk = $this->checkLine($pu, $rawBid, $to, [], !$dry);
        if (!$chk['ok']) ApiV2::fail($chk['code'], $chk['message'], $chk['http'], $chk['extra'] ?? []);
        $price = (float)$chk['pricing']['price'];

        if ($dry) {
            $u   = $this->freshUser();
            $bal = (float)($u['balance'] ?? 0);
            $can = round($bal - $price, 2) >= self::minBalance($u);
            $data = [
                'dry_run'       => true,
                'would_succeed' => $can,
                'price'         => ApiV2::money($price),
                'currency'      => 'GHS',
                'balance'       => ApiV2::money($bal),
                'balance_after' => ApiV2::money($bal - $price),
                'network'       => ['code' => (string)$chk['pricing']['network_code'], 'name' => (string)$chk['pricing']['network_name']],
                'bundle'        => ['id' => $rawBid, 'label' => (string)$chk['pricing']['label'], 'capacity_gb' => (float)$chk['pricing']['capacity_gb']],
                'recipient'     => $to,
            ];
            if (!$can) {
                ApiV2::send(402, ApiV2::errorBody('INSUFFICIENT_BALANCE', 'Not enough balance for this order.',
                    ['required' => ApiV2::money($price), 'available' => ApiV2::money($bal)]) + ['dry_run' => true]);
            }
            ApiV2::ok($data);
        }

        $orderModel = new Order();
        $this->db->beginTransaction();
        try {
            $u = $this->db->fetchOne('SELECT * FROM users WHERE id = ? LIMIT 1 FOR UPDATE', [$this->uid]);
            if (!$u) throw new \RuntimeException('user missing');
            $before = (float)$u['balance'];
            if (round($before - $price, 2) < self::minBalance($u)) {
                $this->db->rollBack();
                ApiV2::idemRelease($this->db, $this->uid, $idem);
                ApiV2::fail('INSUFFICIENT_BALANCE', 'Not enough balance for this order.', 402,
                    ['required' => ApiV2::money($price), 'available' => ApiV2::money($before)]);
            }
            $after = round($before - $price, 2);
            $this->db->execute('UPDATE users SET balance = ? WHERE id = ?', [$after, $this->uid]);

            $pr  = $chk['pricing'];
            $ref = $orderModel->generateReference('API');
            $oid = $orderModel->create([
                'user_id'         => $this->uid,
                'bundle_id'       => $chk['bundle_id'],
                'network_id'      => (int)$pr['network_id'],
                'provider_id'     => $pr['provider_id'] ?: null,
                'recipient'       => $to,
                'capacity_gb'     => (float)$pr['capacity_gb'],
                'price'           => $price,
                'balance_before'  => $before,
                'balance_after'   => $after,
                'status'          => 'accepted',
                'reference'       => $ref,
                'idempotency_key' => null,
                'source'          => 'api',
                'accepted_at'     => date('Y-m-d H:i:s'),
            ]);
            (new Transaction())->record($this->uid, 'debit', $price, $before, $after, $ref,
                "API order {$ref} — {$pr['label']} to {$to}", $oid);
            ApiV2::saveMeta($this->db, $this->uid, 'data', $oid, $ref, $cref, $hook);

            $order = $this->loadOrders([$oid])[$oid] ?? null;
            $body  = ApiV2::okBody(['order' => $this->fmt($order), 'balance' => ApiV2::money($after), 'currency' => 'GHS']);
            ApiV2::idemFinish($this->db, $this->uid, $idem, 201, $body);
            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            ApiV2::idemRelease($this->db, $this->uid, $idem);
            error_log('[ApiV2 orders.create] ' . $e->getMessage());
            if (str_contains($e->getMessage(), 'uq_user_cref')) {
                ApiV2::fail('CLIENT_REFERENCE_EXISTS', "client_reference '{$cref}' was already used.", 409);
            }
            ApiV2::fail('SERVER_ERROR', 'The order could not be placed. Nothing was charged. Please try again.', 500);
        }

        try {
            (new \App\Services\NotificationService())->newOrder($this->freshUser(), $orderModel->findWithRelations($oid));
        } catch (\Throwable) {}

        ApiV2::send(201, $body);
    }

    // ── POST /api/v2/orders/bulk ─────────────────────────────
    public function bulk(): void
    {
        $b      = ApiV2::body();
        $dry    = ApiV2::boolParam($b['dry_run'] ?? false);
        $idem   = $dry ? '' : ApiV2::idemKey($b['idempotency_key'] ?? '');
        $lines  = $b['orders'] ?? null;
        $defHook = trim((string)($b['webhook_url'] ?? ''));

        if (!is_array($lines) || !$lines || array_keys($lines) !== range(0, count($lines) - 1)) {
            ApiV2::fail('VALIDATION_ERROR', 'orders must be a non-empty array.', 422, ['fields' => ['orders' => 'Required array.']]);
        }
        if (count($lines) > self::MAX_BULK) {
            ApiV2::fail('VALIDATION_ERROR', 'At most ' . self::MAX_BULK . ' orders per request.', 422, ['fields' => ['orders' => 'Too many.']]);
        }
        if ($defHook !== '' && ($p = ApiV2::webhookUrlProblem($defHook))) {
            ApiV2::fail('VALIDATION_ERROR', $p, 422, ['fields' => ['webhook_url' => $p]]);
        }

        ApiV2::idemBegin($this->db, $this->uid, $idem, 'orders.bulk', $b);
        $pu       = $this->pricingUser();
        $results  = [];
        $good     = [];
        $seenCref = [];
        $staged   = [];   // recipient => [['capacity_gb'=>…], …] for the duplicate check

        foreach ($lines as $i => $ln) {
            if (!is_array($ln)) { $results[$i] = $this->itemErr($i, 'VALIDATION_ERROR', 'Each order must be an object.'); continue; }
            $rawBid = trim((string)($ln['bundle_id'] ?? ''));
            $to     = preg_replace('/\s+/', '', (string)($ln['recipient'] ?? '')) ?? '';
            $cref   = ApiV2::cleanClientRef($ln['client_reference'] ?? '');
            $hook   = trim((string)($ln['webhook_url'] ?? $defHook));

            if ($rawBid === '') { $results[$i] = $this->itemErr($i, 'VALIDATION_ERROR', 'bundle_id is required.', $to); continue; }
            if (!ApiV2::validPhone($to)) { $results[$i] = $this->itemErr($i, 'VALIDATION_ERROR', 'recipient must be a 10-digit Ghana number like 0241234567.', $to); continue; }
            if ($cref === null) { $results[$i] = $this->itemErr($i, 'VALIDATION_ERROR', 'client_reference: up to 64 letters, numbers, _ or -.', $to); continue; }
            if ($hook !== '' && $hook !== $defHook && ($p = ApiV2::webhookUrlProblem($hook))) { $results[$i] = $this->itemErr($i, 'VALIDATION_ERROR', $p, $to); continue; }
            if ($cref !== '') {
                if (isset($seenCref[$cref])) { $results[$i] = $this->itemErr($i, 'CLIENT_REFERENCE_EXISTS', "client_reference '{$cref}' appears twice in this request.", $to); continue; }
                if ($t = ApiV2::clientRefTaken($this->db, $this->uid, $cref)) {
                    $results[$i] = $this->itemErr($i, 'CLIENT_REFERENCE_EXISTS', "client_reference '{$cref}' was already used.", $to, ['existing_reference' => $t['reference']]);
                    continue;
                }
            }

            $chk = $this->checkLine($pu, $rawBid, $to, $staged[$to] ?? [], !$dry);
            if (!$chk['ok']) { $results[$i] = $this->itemErr($i, $chk['code'], $chk['message'], $to); continue; }

            if ($cref !== '') $seenCref[$cref] = true;
            $staged[$to][] = ['capacity_gb' => (float)$chk['pricing']['capacity_gb']];
            $good[$i] = ['bid' => $rawBid, 'bundle_id' => $chk['bundle_id'], 'to' => $to, 'cref' => $cref, 'hook' => $hook,
                         'pricing' => $chk['pricing'], 'price' => (float)$chk['pricing']['price']];
            $results[$i] = null;
        }

        if ($dry) {
            $u   = $this->freshUser();
            $bal = (float)($u['balance'] ?? 0);
            $run = $bal;
            foreach ($good as $i => $g) {
                if (round($run - $g['price'], 2) < self::minBalance($u)) {
                    $results[$i] = $this->itemErr($i, 'INSUFFICIENT_BALANCE', 'Not enough balance left for this order.', $g['to']);
                    continue;
                }
                $run = round($run - $g['price'], 2);
                $results[$i] = ['index' => $i, 'ok' => true, 'recipient' => $g['to'], 'price' => ApiV2::money($g['price']),
                                'bundle' => ['id' => $g['bid'], 'label' => (string)$g['pricing']['label']]];
            }
            ksort($results);
            $okN = count(array_filter($results, fn($r) => !empty($r['ok'])));
            ApiV2::ok(['dry_run' => true, 'summary' => ['total' => count($lines), 'would_place' => $okN, 'would_fail' => count($lines) - $okN,
                       'total_price' => ApiV2::money($bal - $run), 'balance' => ApiV2::money($bal), 'balance_after' => ApiV2::money($run), 'currency' => 'GHS'],
                       'results' => array_values($results)]);
        }

        if (!$good) {
            ksort($results);
            ApiV2::send(422, ApiV2::errorBody('NO_ORDERS_PLACED', 'None of the orders could be placed. Nothing was charged.', ['results' => array_values($results)]));
        }

        $orderModel = new Order();
        $tx         = new Transaction();
        $placedIds  = [];
        $batch      = 'BULK_API_' . strtoupper(bin2hex(random_bytes(5)));
        $this->db->beginTransaction();
        try {
            $u = $this->db->fetchOne('SELECT * FROM users WHERE id = ? LIMIT 1 FOR UPDATE', [$this->uid]);
            if (!$u) throw new \RuntimeException('user missing');
            $start = (float)$u['balance'];
            $run   = $start;
            $min   = self::minBalance($u);
            $now   = date('Y-m-d H:i:s');

            foreach ($good as $i => $g) {
                if (round($run - $g['price'], 2) < $min) {
                    $results[$i] = $this->itemErr($i, 'INSUFFICIENT_BALANCE', 'Not enough balance left for this order.', $g['to']);
                    continue;
                }
                $before = $run;
                $run    = round($run - $g['price'], 2);
                $pr     = $g['pricing'];
                $ref    = $batch . '_' . ($i + 1);
                $oid    = $orderModel->create([
                    'user_id' => $this->uid, 'bundle_id' => $g['bundle_id'], 'network_id' => (int)$pr['network_id'],
                    'provider_id' => $pr['provider_id'] ?: null, 'recipient' => $g['to'], 'capacity_gb' => (float)$pr['capacity_gb'],
                    'price' => $g['price'], 'balance_before' => $before, 'balance_after' => $run, 'status' => 'accepted',
                    'reference' => $ref, 'idempotency_key' => null, 'source' => 'bulk_api', 'accepted_at' => $now,
                ]);
                $tx->record($this->uid, 'debit', $g['price'], $before, $run, $ref,
                    "Bulk API order {$ref} — {$pr['label']} to {$g['to']}", $oid);
                ApiV2::saveMeta($this->db, $this->uid, 'data', $oid, $ref, $g['cref'], $g['hook']);
                $placedIds[$i] = $oid;
            }

            if (!$placedIds) {
                $this->db->rollBack();
                ApiV2::idemRelease($this->db, $this->uid, $idem);
                ksort($results);
                ApiV2::send(402, ApiV2::errorBody('INSUFFICIENT_BALANCE', 'Not enough balance for any of the orders. Nothing was charged.',
                    ['available' => ApiV2::money($start), 'results' => array_values($results)]));
            }

            $this->db->execute('UPDATE users SET balance = ? WHERE id = ?', [$run, $this->uid]);

            $orders = $this->loadOrders(array_values($placedIds));
            foreach ($placedIds as $i => $oid) {
                $results[$i] = ['index' => $i, 'ok' => true, 'order' => $this->fmt($orders[$oid] ?? null)];
            }
            ksort($results);
            $charged = round($start - $run, 2);
            $body = ApiV2::okBody([
                'batch_id' => $batch,
                'summary'  => ['total' => count($lines), 'placed' => count($placedIds), 'failed' => count($lines) - count($placedIds),
                               'total_charged' => ApiV2::money($charged), 'balance' => ApiV2::money($run), 'currency' => 'GHS'],
                'results'  => array_values($results),
            ]);
            ApiV2::idemFinish($this->db, $this->uid, $idem, 201, $body);
            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            ApiV2::idemRelease($this->db, $this->uid, $idem);
            error_log('[ApiV2 orders.bulk] ' . $e->getMessage());
            ApiV2::fail('SERVER_ERROR', 'The bulk order could not be placed. Nothing was charged. Please try again.', 500);
        }

        ApiV2::send(201, $body);
    }

    // ── GET /api/v2/orders ───────────────────────────────────
    public function list(): void
    {
        [$page, $limit, $offset] = ApiV2::paging();
        [$from, $to] = ApiV2::dateRange();

        $where  = ['o.user_id = ?'];
        $params = [$this->uid];
        $status = strtolower(trim((string)($_GET['status'] ?? '')));
        if ($status !== '') {
            if (!in_array($status, DuplicateOrderHelper::ALL_STATUSES, true)) {
                ApiV2::fail('VALIDATION_ERROR', 'Unknown status.', 422, ['fields' => ['status' => implode(' | ', DuplicateOrderHelper::ALL_STATUSES)]]);
            }
            $where[] = 'o.status = ?'; $params[] = $status;
        }
        $rcp = preg_replace('/\s+/', '', (string)($_GET['recipient'] ?? '')) ?? '';
        if ($rcp !== '') { $where[] = 'o.recipient = ?'; $params[] = $rcp; }
        $cref = trim((string)($_GET['client_reference'] ?? ''));
        if ($cref !== '') {
            $where[] = "o.id IN (SELECT order_id FROM api_v2_order_meta WHERE user_id = ? AND kind = 'data' AND client_reference = ?)";
            array_push($params, $this->uid, $cref);
        }
        if ($from) { $where[] = 'o.created_at >= ?'; $params[] = $from; }
        if ($to)   { $where[] = 'o.created_at <= ?'; $params[] = $to; }

        $w     = implode(' AND ', $where);
        $total = (int)$this->db->fetchColumn("SELECT COUNT(*) FROM orders o WHERE {$w}", $params);
        $ids   = array_map(fn($r) => (int)$r['id'], $this->db->fetchAll(
            "SELECT o.id FROM orders o WHERE {$w} ORDER BY o.id DESC LIMIT ? OFFSET ?", [...$params, $limit, $offset]
        ));
        $rows  = $this->loadOrders($ids);
        ApiV2::ok(array_map(fn($id) => $this->fmt($rows[$id] ?? null), $ids), 200, ApiV2::pageMeta($total, $page, $limit));
    }

    // ── GET /api/v2/orders/{ref} ─────────────────────────────
    public function show(string $slug): void
    {
        $id = ApiV2::findOrderId($this->db, $this->uid, 'data', $slug, 'orders');
        if (!$id) ApiV2::fail('ORDER_NOT_FOUND', 'No order with that reference or client_reference on this account.', 404);
        $row = $this->loadOrders([$id])[$id] ?? null;
        ApiV2::ok($this->fmt($row));
    }

    // ── Shared checks (same rules and order as v1) ───────────
    /** @return array{ok:bool,code?:string,message?:string,http?:int,extra?:array,pricing?:array,bundle_id?:int} */
    private function checkLine(array $pu, string $rawBid, string $to, array $batchCandidates, bool $logLocked): array
    {
        $bundleId = \App\Models\Bundle::resolveApiBundleId($rawBid);
        if (!$bundleId) {
            return ['ok' => false, 'code' => 'BUNDLE_NOT_FOUND', 'http' => 404,
                    'message' => "bundle_id '{$rawBid}' was not recognised. Use an id from GET /api/v2/bundles."];
        }
        $pricing = PricingHelper::forBundle($pu, $bundleId);
        if (!$pricing) {
            return ['ok' => false, 'code' => 'BUNDLE_NOT_FOUND', 'http' => 404, 'message' => 'This bundle is not available for your account.'];
        }
        $netId = (int)$pricing['network_id'];

        if ($this->db->fetchOne('SELECT id FROM number_blacklist WHERE phone = ? AND network_id = ? LIMIT 1', [$to, $netId])) {
            return ['ok' => false, 'code' => 'NUMBER_BLACKLISTED', 'http' => 403, 'message' => 'This number is blacklisted on ' . $pricing['network_name'] . '.'];
        }
        $net = $this->db->fetchOne('SELECT id, lock_mode, verify_source, external_network_id FROM networks WHERE id = ? LIMIT 1', [$netId]);
        if ($net && $net['lock_mode']
            && !\App\Helpers\NumberVerificationHelper::isVerifiedForOrder($this->db, $net, $to)) {
            if ($logLocked) {
                try { (new \App\Models\LockedOrderAttempt())->logAttempt($to, $netId, 'api'); } catch (\Throwable) {}
            }
            return ['ok' => false, 'code' => 'NUMBER_NOT_VERIFIED', 'http' => 403,
                    'message' => $pricing['network_name'] . ' only accepts verified numbers right now.'];
        }
        $dup = DuplicateOrderHelper::evaluate($to, (float)$pricing['capacity_gb'], $netId, $batchCandidates);
        if (!empty($dup['blocked'])) {
            return ['ok' => false, 'code' => 'DUPLICATE_ORDER', 'http' => 409, 'message' => (string)$dup['message']];
        }
        return ['ok' => true, 'pricing' => $pricing, 'bundle_id' => $bundleId];
    }

    private function itemErr(int $i, string $code, string $msg, string $to = '', array $extra = []): array
    {
        return ['index' => $i, 'ok' => false, 'recipient' => $to !== '' ? $to : null, 'error' => ['code' => $code, 'message' => $msg] + $extra];
    }

    /** @return array<int,array> */
    private function loadOrders(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if (!$ids) return [];
        $in   = implode(',', array_fill(0, count($ids), '?'));
        $rows = $this->db->fetchAll(
            "SELECT o.*, n.name AS network_name, n.code AS network_code, b.label AS bundle_label, b.api_code
               FROM orders o
               JOIN networks n ON n.id = o.network_id
               JOIN bundles  b ON b.id = o.bundle_id
              WHERE o.id IN ($in) AND o.user_id = ?",
            [...$ids, $this->uid]
        );
        $meta = ApiV2::metaFor($this->db, 'data', $ids);
        $out  = [];
        foreach ($rows as $r) { $r['_meta'] = $meta[(int)$r['id']] ?? null; $out[(int)$r['id']] = $r; }
        return $out;
    }

    private function fmt(?array $o): ?array
    {
        if (!$o) return null;
        return [
            'reference'        => (string)$o['reference'],
            'client_reference' => $o['_meta']['client_reference'] ?? null,
            'type'             => 'data',
            'status'           => (string)$o['status'],
            'recipient'        => (string)$o['recipient'],
            'network'          => ['code' => (string)$o['network_code'], 'name' => (string)$o['network_name']],
            'bundle'           => [
                'id'          => !empty($o['api_code']) ? (string)$o['api_code'] : (string)(int)$o['bundle_id'],
                'label'       => (string)$o['bundle_label'],
                'capacity_gb' => (float)$o['capacity_gb'],
            ],
            'price'            => ApiV2::money((float)$o['price']),
            'currency'         => 'GHS',
            'source'           => (string)$o['source'],
            'webhook_url'      => $o['_meta']['webhook_url'] ?? null,
            'created_at'       => ApiV2::iso($o['created_at'] ?? null),
            'timeline'         => [
                'accepted_at'   => ApiV2::iso($o['accepted_at']   ?? null),
                'placed_at'     => ApiV2::iso($o['placed_at']     ?? null),
                'processing_at' => ApiV2::iso($o['processing_at'] ?? null),
                'delivered_at'  => ApiV2::iso($o['delivered_at']  ?? null),
                'failed_at'     => ApiV2::iso($o['failed_at']     ?? null),
                'refunded_at'   => ApiV2::iso($o['refunded_at']   ?? null),
            ],
        ];
    }
}
