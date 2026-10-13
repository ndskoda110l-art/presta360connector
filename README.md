# Presta360 Connector

Read-only PrestaShop module that lets shop managers ask about their shop in Claude through the Presta360 service.

- **Read-only by design:** only a fixed register of named `SELECT` queries; no write endpoints; read-only DB session and statement timeout.
- **Signed requests only:** HMAC-SHA256 with a per-shop secret, 5-minute timestamp window, one-time nonces.
- **No data leaves except answers:** results are aggregates or short lists returned to the request; Presta360 does not store shop data.
- **One codebase for PrestaShop 1.6.1.11 → 9.x** (PHP 5.6 → 8.5).

## Install
1. Upload the ZIP in *Modules → Upload a module* (or copy the folder to `modules/`).
2. Open the module configuration, copy the **Endpoint URL** and the **Secret key** (shown once) into the Presta360 admin.

## Endpoint
`POST index.php?fc=module&module=presta360connector&controller=api` – contract v1 (signed JSON request, named query, aggregated JSON result).

This repository is published from the Presta360 project (`module/presta360connector`); issues and pull requests are welcome here.

Queries in this version: `ping`, `orders.summary`, `orders.to_ship`, `stock.low`, `service.inbox`.

## License
Academic Free License 3.0 (AFL-3.0), see `LICENSE.md`.
