#!/usr/bin/env python3
"""
API v2 smoke test — run against a LOCAL copy of the site only (never live: it places orders).

  python3 v2_smoke_test.py            (edit CONFIG first)

Needs: Python 3.8+, no extra packages. The test account needs some balance; the bundle should
be cheap. Orders it creates are real rows in the LOCAL database.
"""
import json, threading, urllib.request, urllib.error, uuid

CONFIG = {
    "base":       "http://127.0.0.1:8102",   # local copy
    "api_key":    "PUT_TEST_KEY_HERE",
    "bundle_id":  "1",                       # a cheap bundle from GET /api/v2/bundles
    "recipient":  "0241234567",              # a number your rules allow
    "v1_paths":   ["/api/bundles"],          # v1 GET endpoints to make sure still answer like before
}

RES = []
def check(name, cond, info=""):
    RES.append((name, bool(cond)))
    print(("PASS " if cond else "FAIL ") + name + ("" if cond else "  :: " + str(info)[:300]))

def call(method, path, body=None, key=True, raw=None):
    h = {"Content-Type": "application/json"}
    if key: h["X-API-Key"] = CONFIG["api_key"]
    data = raw if raw is not None else (json.dumps(body).encode() if body is not None else None)
    r = urllib.request.Request(CONFIG["base"] + path, data=data, method=method, headers=h)
    try:
        with urllib.request.urlopen(r, timeout=60) as resp:
            return resp.status, json.loads(resp.read() or b"null"), dict(resp.headers)
    except urllib.error.HTTPError as e:
        txt = e.read()
        try: j = json.loads(txt)
        except Exception: j = {"_raw": txt[:200].decode("utf-8", "replace")}
        return e.code, j, dict(e.headers)

def envelope_ok(j): return isinstance(j, dict) and "ok" in j and "meta" in j and str(j["meta"].get("request_id", "")).startswith("req_")
def order_count(cref): 
    s, j, _ = call("GET", "/api/v2/orders?client_reference=" + cref)
    if not j.get("ok"): return -1
    d = j.get("data")
    return len(d if isinstance(d, list) else (d or {}).get("orders", []))

def main():
    s, j, h = call("GET", "/api/v2/me")
    check("me: 200 + envelope + X-Request-Id", s == 200 and envelope_ok(j) and h.get("X-Request-Id", "").startswith("req_"), (s, j))
    bal0 = (j.get("data") or {}).get("balance")
    if "X-RateLimit-Limit" not in h:
        print("NOTE no X-RateLimit-* headers (fine if no rate-limit rule exists for this endpoint yet)")

    s, j, _ = call("GET", "/api/v2/me", key=False)
    check("no key -> 401 AUTH_REQUIRED (v2 shape)", s == 401 and envelope_ok(j) and j["error"]["code"] == "AUTH_REQUIRED", j)
    s, j, _ = call("GET", "/api/v2/nope")
    check("unknown path -> 404 NOT_FOUND (v2 shape)", s == 404 and envelope_ok(j), j)
    s, j, _ = call("POST", "/api/v2/orders", raw=b"{bad json")
    check("bad JSON -> 400 INVALID_JSON", s == 400 and j.get("error", {}).get("code") == "INVALID_JSON", j)
    s, j, _ = call("POST", "/api/v2/orders", {"recipient": "12"})
    check("validation -> 422 with fields", s == 422 and "fields" in j.get("error", {}), j)

    base = {"bundle_id": CONFIG["bundle_id"], "recipient": CONFIG["recipient"]}
    s, j, _ = call("POST", "/api/v2/orders", dict(base, dry_run=True))
    check("dry_run -> price, no charge", s in (200, 402) and j.get("data", {}).get("dry_run", j.get("dry_run")) is True, j)
    s, j2, _ = call("GET", "/api/v2/me")
    check("dry_run did not change balance", j2.get("data", {}).get("balance") == bal0, (bal0, j2))

    for hook in ["http://example.com/h", "https://127.0.0.1/h", "https://localhost/h", "https://10.0.0.5/h"]:
        s, j, _ = call("POST", "/api/v2/orders", dict(base, dry_run=True, webhook_url=hook))
        check("webhook_url refused: " + hook, s == 422, j)

    # Idempotency race: 8 identical requests at once -> exactly 1 order
    key = "smoke-" + uuid.uuid4().hex[:12]; cref = "cref-" + uuid.uuid4().hex[:10]
    body = dict(base, idempotency_key=key, client_reference=cref)
    out = [None] * 8
    def go(i): out[i] = call("POST", "/api/v2/orders", body)
    ts = [threading.Thread(target=go, args=(i,)) for i in range(8)]
    [t.start() for t in ts]; [t.join() for t in ts]
    codes = sorted(o[0] for o in out)
    refs = {((o[1].get("data") or {}).get("order") or {}).get("reference") for o in out if o[1].get("ok")}
    check("8 parallel same key -> one order reference", len(refs) == 1 and order_count(cref) == 1, (codes, refs))
    check("others replayed or in-progress (no 5xx)", all(c in (200, 201, 409) for c in codes), codes)

    s, j, _ = call("POST", "/api/v2/orders", body)
    check("replay later -> same reply, idempotent_replay", j.get("meta", {}).get("idempotent_replay") is True, j)
    s, j, _ = call("POST", "/api/v2/orders", dict(body, recipient=CONFIG["recipient"][:-1] + "9"))
    check("same key, different body -> 409 IDEMPOTENCY_MISMATCH", s == 409 and j["error"]["code"] == "IDEMPOTENCY_MISMATCH", j)
    s, j, _ = call("POST", "/api/v2/orders", dict(base, client_reference=cref))
    check("client_reference reused -> 409 CLIENT_REFERENCE_EXISTS", s == 409 and j["error"]["code"] == "CLIENT_REFERENCE_EXISTS", j)
    s, j, _ = call("GET", "/api/v2/orders/" + cref)
    o = j.get("data") or {}
    o = o.get("order", o) if isinstance(o, dict) else {}
    check("GET /orders/{client_reference}", s == 200 and o.get("client_reference") == cref, j)

    # Bulk: one good, one bad -> only one charged
    s, j, _ = call("POST", "/api/v2/orders/bulk", {"orders": [dict(base, client_reference="b-" + uuid.uuid4().hex[:8]),
                                                              {"bundle_id": CONFIG["bundle_id"], "recipient": "123"}]})
    res = (j.get("data") or {}).get("results", [])
    check("bulk: per-line results, bad line not charged", len(res) == 2 and res[0].get("ok") and not res[1].get("ok"), j)

    for p in CONFIG["v1_paths"]:
        s, j, _ = call("GET", p)
        check("v1 still answers: " + p, s == 200 and not (isinstance(j, dict) and "api_version" in (j.get("meta") or {})), (s, j))

    bad = [n for n, ok in RES if not ok]
    print("\n%d passed, %d failed" % (len(RES) - len(bad), len(bad)))
    for n in bad: print(" -", n)

if __name__ == "__main__":
    main()
