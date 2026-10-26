# Merchant setup

For local wp-env and demo seeding, use [local-development.md](local-development.md).  
For what this plugin owns vs WooCommerce, see [context.md](context.md).

1. Create a Launa seller account at [launa.io](https://launa.io) and verify email.
2. Create an API key with **All** scopes (name it e.g. “Launa for WooCommerce”).
3. In WooCommerce → Settings → Payments → **Launa (x402)**:
   - The status card at the top shows whether the store is **ready** (USD catalog, API key, a settlement token, and the matching payout wallet). Ready does not depend on Enable/Disable. If something is missing, the checklist stays on screen and each failed row says how to fix it.
   - Enable the method (offers Launa at checkout only when the checklist is ready)
   - Paste **Launa API Key** (create one at [launa.io](https://launa.io): API keys → name it e.g. “Launa for WooCommerce” → All scopes → Create API Key)
   - Set **EVM pay-to** wallet (one address for all EVM networks; invalid checksums are rejected on save)
   - Enable settlement tokens from Launa platform config. If any **Hedera** token is enabled, unlock and set **Hedera pay-to** account id (`0.0.x`) plus a **WalletConnect project ID** for shopper wallets
   - Recent payments lists the last five Launa settlements, with a link to [income on Launa](https://launa.io/dashboard/income)
   - Advanced: API base URL is read-only (`LAUNA_BASE_URL` + re-seed to change). Environment seed is Base Sepolia vs production hostname onboarding
4. Ensure store currency is **USD** (catalog). Shoppers still pay in the settlement token they pick on the pay page.
5. For sub-cent catalog prices (e.g. `$0.001`), set **WooCommerce → Settings → General → Number of decimals** to at least **4** (USD defaults to 2, which displays those as `$0.00`). When Launa is enabled, the plugin raises display decimals to at least 4 and trims trailing zeros (so `$0.001` / `$0.10` render correctly).
6. For local testing with real gateway upstream, expose the shop with a public tunnel and ensure Launa API `upstream_base_url` matches that public origin (re-onboard / update API if needed). Free ngrok requires the endpoint `upstream_auth` skip header — the plugin sets it automatically when `home_url` is an ngrok host (details in [architecture.md](architecture.md)).
7. **Settlement reconcile** (off by default): when enabled, Launa settlements can complete unpaid orders if gateway fulfill is missing. Pay-page and agent status polls still confirm payment when this is off.
8. If endpoint creation fails for **Base Sepolia** (`eip155:845320402`), confirm the seller account has that payment asset enabled in Launa (or temporarily use Base mainnet for smoke tests).

## Agent buy

```http
GET /wp-json/launa/v1/products
POST /wp-json/launa/v1/orders
  { "line_items": [{ "product_id": 12, "quantity": 1 }] }
→ { "payment_url": "https://{slug}.dev.x.launa.io/wp-json/launa/v1/fulfill/...", ... }

# then
launa pay url --url "$payment_url"
GET /wp-json/launa/v1/orders/{order_key}
```

**UCP for agents** is on by default. Disable it on the Launa settings screen if you do not want `/.well-known/ucp`. Flow and 402-at-complete: [ucp.md](ucp.md). x402 binding: [ucp-x402-binding](https://github.com/AxLabs/ucp-x402-binding).
