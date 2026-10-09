<?php
/*
 * TEMPLATE — app/controllers/api/V2/BaseV2.php
 * ADAPT: $this->apiUser() must return the API key row set by the site's 'api' middleware
 *        (needs user_id). minBalance(): the site's credit / negative-balance rule.
 */
declare(strict_types=1);

namespace App\Controllers\Api\V2;

use App\Core\Controller;
use App\Helpers\ApiV2;

/**
 * Base for every /api/v2 controller. The 'api' middleware has already checked the
 * API key, approval, Domain Security, IP blocks and the rate limit (same as v1).
 */
abstract class BaseV2 extends Controller
{
    protected int   $uid;
    protected array $api;

    public function __construct()
    {
        parent::__construct();
        ApiV2::requestId();
        try {
            ApiV2::ensureSchema($this->db);
        } catch (\Throwable $e) {
            error_log('[ApiV2] schema: ' . $e->getMessage());
            ApiV2::fail('SERVER_ERROR', 'The API is starting up. Please retry in a moment.', 503);
        }
        $this->api = $this->apiUser();
        $this->uid = (int)$this->api['user_id'];
        ApiV2::setUser($this->uid);
    }

    /** Fresh user row (balance, free mode, iShare MB…). */
    protected function freshUser(): array
    {
        return $this->db->fetchOne('SELECT * FROM users WHERE id = ? LIMIT 1', [$this->uid]) ?: [];
    }

    /** Lowest balance allowed (free mode lets admins allow a negative limit). */
    protected static function minBalance(array $u): float
    {
        return !empty($u['free_mode']) ? (float)($u['free_mode_limit'] ?? -50) : 0.0;
    }

    protected function pricingUser(): array
    {
        $u = $this->freshUser();
        return [
            'id'              => $this->uid,
            'price_group_id'  => $u['price_group_id'] ?? ($this->api['price_group_id'] ?? null),
            'balance'         => (float)($u['balance'] ?? 0),
            'free_mode'       => (bool)($u['free_mode'] ?? 0),
            'free_mode_limit' => (float)($u['free_mode_limit'] ?? -50),
        ];
    }
}
