# End-to-end (E2E) testing environment

This is the **ready-to-go** path for full Launa payment tests against local WooCommerce: seed the shop, expose it publicly, run human or agent pay flows.

Product context: [context.md](context.md). Day-to-day local setup: [local-development.md](local-development.md).

---

## What “ready” means

| Check | Expected |
|---|---|
| `wp-env` running | http://localhost:8888 responds |
| Stack versions | WordPress **7.1.3**, WooCommerce **11.1.0** (pinned in [`.wp-env.json`](../.wp-env.json); refresh with `npm run env:update`) |
| Seed applied | Site title **Launa**, Launa demo catalog only, Launa enabled, coming-soon **off** |
| Gateway | `is_available=yes` (API key + EVM and/or Hedera pay-to from settings / `.env`) |
| Public origin | `WP_BASE_URL` / `WP_HOME` = HTTPS tunnel (required for live settle) |
| Launa upstream | Store API `upstream_base_url` matches that same HTTPS origin |
| Buyer funds | Wallet in `LAUNA_EVM_PRIVATE_KEY` has USDC on the configured network |

The seed alone is enough for **browsing / checkout UI**. A **public tunnel** is required for Launa to call fulfill after payment.

**Support vs E2E target:** E2E runs on the newest WooCommerce we pin (currently 11.1). Merchants on older WooCommerce remain supported down to **8.0** (`WC requires at least`), as long as we do not rely on APIs newer than that floor. When raising the E2E pin, smoke the pay flow here before bumping `WC tested up to` in the plugin header / readme.

---

## One-time prerequisites

```bash
nvm use
cp .env.example .env
# Fill at least:
#   LAUNA_API_KEY
#   LAUNA_PAY_TO_ADDRESS
#   LAUNA_NETWORK=mainnet   # or sepolia if your seller account supports it
# For programmatic pay also:
#   LAUNA_EVM_PRIVATE_KEY
# After ngrok is up:
#   WP_BASE_URL=https://your-host.ngrok-free.dev

npm install
npm --prefix plugin install
npm run build
```

---

## Start + seed (ready catalog)

```bash
npm run env:start          # WordPress + WooCommerce 11 + afterStart seed
# After changing the WC pin in .wp-env.json (or to refresh downloads):
npm run env:update
# or if already started:
npm run env:e2e            # re-seed + readiness check (+ tunnel sync if WP_BASE_URL set)
```

`npm run env:e2e` runs [`bin/prepare-e2e.sh`](../bin/prepare-e2e.sh), which:

1. Runs [`bin/seed-wp-env.sh`](../bin/seed-wp-env.sh) (idempotent)
2. If `WP_BASE_URL` is set: updates `WP_HOME` / `WP_SITEURL` and Launa `upstream_base_url`
3. Prints readiness (`is_available`, product count, coming-soon, hosts)

### Demo catalog (seeded)

| SKU | Name | Price |
|---|---|---|
| `launa-micropay` | Launa E2E Micropay | $0.01 |
| `launa-spark` | Launa Spark Note | $0.02 |
| `launa-signal` | Launa Signal Pulse | $0.001 |
| `launa-dust` | Launa Dust Credit | $0.0003 |
| `launa-pass` | Launa Access Pass | $0.10 |
| `launa-pack` | Launa Starter Pack | $0.25 |

Images: [`plugin/assets/demo-products/`](../plugin/assets/demo-products/).  
Admin: http://localhost:8888/wp-admin — `admin` / `password`.

---

## Public tunnel (required for live settle)

Launa’s gateway must reach your fulfill URL. Use the **same** HTTPS host in:

- ngrok (or Cloudflare Tunnel)
- WordPress `WP_HOME` / `WP_SITEURL`
- Launa API `upstream_base_url`
- `.env` → `WP_BASE_URL`

### Example (ephemeral ngrok)

```bash
# terminal A — shop
npm run env:start

# terminal B — tunnel
ngrok http 8888
# copy https://….ngrok-free.dev
```

```bash
# terminal A — put URL in .env then:
# WP_BASE_URL=https://….ngrok-free.dev
npm run env:e2e
```

### Example (reserved / sticky host)

If you already registered a host with Launa (e.g. previous E2E):

```bash
ngrok http 8888 --url=your-host.ngrok-free.dev
# WP_BASE_URL=https://your-host.ngrok-free.dev
npm run env:e2e
```

Verify:

```bash
curl -sS -o /dev/null -w '%{http_code}\n' \
  -H 'ngrok-skip-browser-warning: 1' \
  "$WP_BASE_URL/"
# expect 200 (store public; coming soon must be off)
```

---

## Run E2E payments

### A) Programmatic agent pay

```bash
# .env must include WP_BASE_URL + LAUNA_EVM_PRIVATE_KEY (+ seller key/address already seeded)
set -a && source .env && set +a
npm run test:e2e-pay
```

Flow: `POST /wp-json/launa/v1/products|orders` → buyer SDK pays gateway URL → order `paid`.

Optional: `E2E_PRODUCT_ID=<id>` to pin a SKU (otherwise first catalog product).

### A2) UCP agent pay

```bash
# .env: WP_BASE_URL, LAUNA_EVM_PRIVATE_KEY (UCP is on after seed unless LAUNA_UCP_ENABLED=no)
npm run env:e2e
set -a && source .env && set +a
npm run test:e2e-ucp
# physical SKU + shipping:
# E2E_UCP_PHYSICAL=1 npm run test:e2e-ucp
```

Flow: discover → catalog search → create cart → create checkout from `cart_id` → MCP `complete_checkout` (PaymentRequired on `structuredContent`) → buyer pays `resource.url` (Launa gateway) → MCP `complete_checkout` again (reconcile, no signature) → GET session `completed` → GET order. Set `E2E_UCP_TRANSPORT=rest` for the same hop over REST complete. Details: [ucp.md](ucp.md). Binding: [ucp-x402-binding](https://github.com/AxLabs/ucp-x402-binding).

Shopify `ucp` CLI smoke (discover → cart → checkout → complete **without** paying):

```bash
# needs `ucp` on PATH, WP_BASE_URL=https://… (UCP on after seed unless LAUNA_UCP_ENABLED=no)
npm run test:e2e-ucp-cli
# E2E_UCP_PHYSICAL=1 npm run test:e2e-ucp-cli
```

### B) Human / MetaMask

1. Open `$WP_BASE_URL` (or localhost if only testing UI)
2. Add a demo product → Checkout → **Pay with Launa**
3. Pay page → connect wallet on the network matching `LAUNA_NETWORK`
4. Confirm USDC → order **Processing/Completed** under **WooCommerce → Orders**

### C) Playwright pay-page shell

```bash
export E2E_ORDER_KEY=wc_order_...   # from a pending Launa order
npm run test:e2e-ui
```

Wallet connect/sign stays a **manual** checklist (see [testing.md](testing.md)).

---

## Stop

```bash
npm run env:stop
# stop ngrok in its terminal (Ctrl+C)
```

---

## Troubleshooting

| Symptom | Fix |
|---|---|
| `is_available=no` | Fill `LAUNA_API_KEY` + `LAUNA_PAY_TO_ADDRESS`, `npm run env:e2e` |
| Coming soon / “Pardon our dust” | Seed turns it off; re-run `npm run env:e2e` |
| No payment methods | Same as `is_available=no` |
| Fulfill never completes | Tunnel down, or `WP_HOME` / Launa upstream mismatch — re-run `npm run env:e2e` with `WP_BASE_URL`. Free ngrok: endpoints must include `upstream_auth` `ngrok-skip-browser-warning` (plugin sets this when the store host contains `ngrok`; place a new order or re-lock settlement after updating). Hedera: fulfill ACKs 200 before USDC; Woo marks paid from the settlement row (order note: reconcile or fulfill upstream). |
| Order paid but note says reconcile | Normal when Launa GETs fulfill before writing the ledger. Also happens if fulfill never reached the shop (tunnel). |
| ZCHF (or FX token) amount looks like USD atomics | Gateway 402 should preserve each accept’s atomic amount. If an old order still has per-token endpoints, re-place the order so prep writes one multi-accept endpoint. |
| Sepolia endpoint errors | Seller missing Sepolia USDC asset → try `LAUNA_NETWORK=mainnet` |
| `test:e2e-pay` missing env | Set `WP_BASE_URL` and `LAUNA_EVM_PRIVATE_KEY` |

Never commit `.env` or private keys. Rotate anything that was shared.
