# Launa WooCommerce Extension

WooCommerce **payment provider** plugin that accepts **x402 / Launa** on-chain settlements for humans (wallet / MetaMask) and agents (buyer SDK / CLI).

The store catalog stays in **USD**. Settlement uses the same payment tokens Launa exposes on the platform (`USDC`, `USDT`, and other enabled assets) — merchants choose which to accept; shoppers (or agents) pick one at pay time. See [docs/context.md](docs/context.md).

This is a gateway extension — not a full storefront. Catalog, cart, customers, and the order list stay in WooCommerce.

This plugin was previously **Ax402 for WooCommerce**. Ax402 was rebranded to Launa, so the plugin is now **Launa for WooCommerce**. The WordPress.org listing stays [ax402-for-woocommerce](https://wordpress.org/plugins/ax402-for-woocommerce/). Development continues in [AxLabs/launa-woocommerce-extension](https://github.com/AxLabs/launa-woocommerce-extension).

## Features (v0)

- Checkout payment method `launa`
- Multi-asset settlement from Launa platform `payment_tokens` (admin multi-select)
- Per-order gateway endpoint with multi-token `accepts[]`, priced from the USD cart total
- Human pay page: settlement picker, network switch + balance checks, `@launaio/react-paywall`
- Agent REST: products, create order, order status (same multi-accept payment URL)
- UCP for buying agents (`/.well-known/ucp`, `/wp-json/ucp/v1`) — on by default; see [docs/ucp.md](docs/ucp.md) and [ucp-x402-binding](https://github.com/AxLabs/ucp-x402-binding)
- Fulfill upstream: valid `order_key` + token ACKs HTTP 200; `payment_complete()` only with a matching Launa settlement
- `wp-env` local development + demo product seed
- Unit + live control-plane tests

## Documentation

| Doc | Contents |
|---|---|
| [**Context**](docs/context.md) | What the extension is / is not, concerns, currency model |
| [**Local development**](docs/local-development.md) | Start wp-env, `.env`, seed products, tunnel, first payment |
| [**E2E environment**](docs/e2e.md) | Ready-to-go seed + ngrok + programmatic / MetaMask pay |
| [**UCP for agents**](docs/ucp.md) | Discovery, catalog, cart, checkout, order, x402 402-at-complete ([ucp-x402-binding](https://github.com/AxLabs/ucp-x402-binding)) |
| [Architecture](docs/architecture.md) | Payment flow, settlement lock, ngrok upstream_auth, reconcile |
| [**Releases**](RELEASE.md) | SemVer, GitHub Releases, WordPress.org SVN publish |
| [Merchant setup](docs/merchant-setup.md) | Production checklist + agent buy sketch |
| [Testing](docs/testing.md) | Unit, live CP, E2E commands |

## Quick start

Full walkthrough: [docs/local-development.md](docs/local-development.md).

```bash
nvm use
cp .env.example .env
# fill LAUNA_API_KEY, LAUNA_PAY_TO_ADDRESS, LAUNA_NETWORK

npm install
npm --prefix plugin install
npm run build

npm run env:start   # WordPress + WooCommerce + plugin + auto-seed
npm run env:e2e     # re-seed + E2E readiness (set WP_BASE_URL after ngrok)
```

| | |
|---|---|
| Store | http://localhost:8888 |
| Admin | http://localhost:8888/wp-admin |
| Login | `admin` / `password` |

Configure **WooCommerce → Settings → Payments → Launa** (API key, pay-to wallet, settlement tokens). Orders appear under **WooCommerce → Orders**.

Full E2E (tunnel + pay): [docs/e2e.md](docs/e2e.md).

### Demo catalog (seeded)

| Product | Price |
|---|---|
| Launa E2E Micropay | $0.01 |
| Launa Spark Note | $0.02 |
| Launa Signal Pulse | $0.001 |
| Launa Dust Credit | $0.0003 |
| Launa Access Pass | $0.10 |
| Launa Starter Pack | $0.25 |

## Tests

```bash
npm test                 # PHP unit + integration + JS unit
npm run plugin-check     # WordPress Plugin Check (needs wp-env)
npm run test:live-cp     # needs LAUNA_API_KEY (+ PAY_TO for create/delete)
npm run test:e2e-pay     # needs public WP_BASE_URL + buyer key (see docs/e2e.md)
npm run test:e2e-ucp     # UCP agent flow (on by default after seed; LAUNA_UCP_ENABLED=no to disable)
```

See [docs/testing.md](docs/testing.md) and [docs/e2e.md](docs/e2e.md).

## Repository layout

```text
plugin/        WordPress plugin (gateway, REST, pay page, blocks)
bin/           seed-wp-env.sh, phpunit, package, version helpers
docs/          context, local-dev, architecture, merchant, testing
tests/         PHP / JS / Playwright / programmatic pay
wporg-assets/  WordPress.org Directory icon + screenshots
RELEASE.md     versioning, GitHub Releases, WordPress.org SVN
AGENTS.md      short agent entrypoint
```

## Releases

Development is **this GitHub repo**. WordPress.org SVN is updated only when we publish a directory release.

SemVer + annotated git tags + GitHub Releases (plugin zip attached), then a copy of that zip into SVN `trunk/` + `tags/X.Y.Z`. Directory: [wordpress.org/plugins/ax402-for-woocommerce](https://wordpress.org/plugins/ax402-for-woocommerce/). Full process: **[RELEASE.md](RELEASE.md)**.

```bash
bash bin/check-version.sh
bash bin/bump-version.sh 0.2.0   # then commit, tag v0.2.0, push tag
# after the GitHub Release exists: publish the zip to WordPress.org SVN (RELEASE.md)
```

## Security

Never commit API keys or private keys. Rotate any key that was shared in chat or logs.
