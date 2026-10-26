# Extension context

## What this is

**Launa for WooCommerce** is a **payment provider plugin**. It adds an `ax402` payment method so a WooCommerce store can accept **x402 / Launa** on-chain settlements. The **catalog stays USD**; merchants enable settlement tokens from Launa platform config (e.g. USDC, USDT).

It supports two buyer types on the same order lifecycle:

| Buyer | How they pay |
|---|---|
| **Human** | Checkout → store pay page → wallet (e.g. MetaMask) via `@launaio/react-paywall` |
| **Agent (legacy REST)** | `POST /wp-json/launa/v1/orders` → pay the Launa gateway URL with a buyer SDK |
| **Agent (UCP)** | `GET /.well-known/ucp` + REST `/wp-json/ucp/v1` catalog/cart/checkout/order (MCP at `/mcp`); x402 402 at `complete` (on by default). Protocol 2026-08-25. See [ucp.md](ucp.md) and [ucp-x402-binding](https://github.com/AxLabs/ucp-x402-binding). |

Hosted control plane: `https://api.launa.io`.

## What this is not

This plugin is **not** a full storefront product. It does not own:

- Product catalog UX (beyond optional local demo seed)
- Cart / checkout theme design
- Customer accounts, CRM, or a custom order inbox
- Multi-currency browsing / FX switchers

Those belong to **WooCommerce** (and optional third-party plugins). Merchants manage catalog, customers, and orders in the normal WooCommerce admin.

## Separation of concerns

```text
┌─────────────────────────────────────────────────────────┐
│  WooCommerce                                            │
│  products · cart · checkout · customers · orders        │
└───────────────────────────┬─────────────────────────────┘
                            │ payment method: launa
┌───────────────────────────▼─────────────────────────────┐
│  Launa WooCommerce plugin                               │
│  gateway settings · endpoint prep · pay page · fulfill  │
└───────────────────────────┬─────────────────────────────┘
                            │
┌───────────────────────────▼─────────────────────────────┐
│  Launa platform                                         │
│  control plane (apis/endpoints) · buyer gateway (402)   │
└─────────────────────────────────────────────────────────┘
```

| Concern | Owner |
|---|---|
| Catalog prices, customers, order list | WooCommerce |
| API key, pay-to wallet, settlement tokens, enable gateway | Plugin → **WooCommerce → Settings → Payments → Launa** |
| HTTP 402 challenge, payment verify, proxy to fulfill | Launa gateway |
| Mark order paid after verified payment | Plugin fulfill REST (if settlement already exists) or settlement reconcile |

## Currency model (v0)

- Store / catalog currency: **USD** (required). This is not a storefront multi-currency switcher.
- Settlement assets come from Launa `GET /config/platform` → `payment_tokens`. Merchants multi-select which tokens the store accepts.
- Networks are derived from those tokens (and optionally `GET /supported-networks`). Display names / public RPC / explorers come from token `extra` when present, otherwise from one ethereum-lists chain file via the GitHub Contents API — not a hard-coded chain table in the plugin. Hedera uses Mirror Node / HashScan defaults for `hedera:*`.
- Pay-to: one **EVM** address for all `eip155:*` tokens; a separate **Hedera account id** when any `hedera:*` token is enabled (field greyed out until then). Hedera shopper connect needs a WalletConnect project id. Mixed EVM+Hedera stores use one Launa API with `pay_to_addresses` for Hedera networks.
- At payment prep the plugin creates **one temporary Launa endpoint** whose `accepts[]` lists every priced settlement token. Humans pick an asset on the pay page (paywall policy selects that accept). Agents/buyers choose from the same 402 `accepts[]`.
- At payment prep, the plugin converts **USD order total → settlement amount** via an exchange-rate interface: common stablecoins (**USDC**, **USDT**, …) are **1:1**; other tokens use Launa `GET /exchange-rates?quote=usd&date=YYYY-MM-DD` (weekdays: today → yesterday if a weekday → closest previous business day; weekends skip Sat/Sun). Tokens without a resolvable rate are omitted from checkout accepts. Rates are cached 5 minutes so checkout and the pay page do not refetch.
- Staging currently hosts the live FX endpoint (`https://api.staging.launa.io`); point `LAUNA_BASE_URL` / gateway base URL at staging to exercise non-stable settlement.
- Sub-cent catalog prices are supported when decimals are raised (plugin helps when Launa is enabled).
- Registering settlement symbols as Woo store currencies / FX catalog browsing remains **out of scope**.

## Trust model (v0)

1. Checkout selects Launa → plugin creates/updates a per-order Launa endpoint priced to the total (multi-token `accepts`).
2. Fulfill URL embeds `order_key` + a one-time `fulfill_token` stored on the order.
3. Human pay page records the chosen settlement token (paywall `preferredAssets` / `preferredNetworks`), then pays the **Launa gateway URL directly** (store origin registered via control-plane CORS).
4. The gateway GETs `{upstream_base_url}{path}` (optional `upstream_auth` — e.g. ngrok skip). That hop may run **before** Launa writes the settlement. Woo fulfill ACKs HTTP 200; it calls `payment_complete()` **only** when a matching settlement already exists.
5. **Settlement reconcile** (pay-page / agent / UCP status polls, UCP complete) marks the order paid from the control-plane row. That is the usual complete path after a fulfill ACK, and also covers missing fulfill.

Detailed sequence, settlement lock, ngrok, and reconcile: [architecture.md](architecture.md).

## Where merchants look day to day

| Task | Where |
|---|---|
| Configure Launa | **WooCommerce → Settings → Payments → Launa** |
| See orders, email, totals, status | **WooCommerce → Orders** |
| Shop / checkout | Normal WooCommerce storefront |
| Local demo products | Seeded by `bin/seed-wp-env.sh` (dev only) |

## Related docs

- [Local development & seeding](local-development.md) — start wp-env, seed, tunnel, first payment
- [E2E environment](e2e.md) — ready-to-go seed + ngrok + programmatic / MetaMask pay (`npm run env:e2e`)
- [Architecture](architecture.md) — components and flows
- [UCP for agents](ucp.md) — opt-in shopping + x402 complete ([ucp-x402-binding](https://github.com/AxLabs/ucp-x402-binding))
- [Releases & versioning](../RELEASE.md) — SemVer, GitHub Releases, WordPress.org SVN (agents: read before bumping or directory publish)
- [Merchant setup](merchant-setup.md) — production-oriented checklist
- [Testing](testing.md) — unit, live control plane, E2E commands
- [AGENTS.md](../AGENTS.md) — short agent entrypoint
