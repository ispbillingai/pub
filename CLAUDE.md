# Ristorante POS — handoff notes

This folder (`F:\pub`, repo `ispbillingai/pub`) is a copy of `F:\order` (repo `ispbillingai/order`),
copied on 2026-10-01 at commit `39705be` ("Menu components: a photo for each ingredient").
Work continues here.

## What the app is
PHP + MariaDB restaurant POS ("ristorante"): admin, cashier, waiter, kitchen, guest ordering
(QR menu), fiscal printing via the Epson RT printer, card payments (Epson RT protocol 17 / Dojo),
Glovo orders, device monitor (MikroTik). Live at https://ristorante.upgradesrls.com.

## Project notes
Notes collected while working on this project are in [docs/claude-memory/](docs/claude-memory/)
(start from `MEMORY.md`). **Passwords have been removed from the copy in git** because this repo is
public. The full copies (with passwords) are in Claude's local memory for this folder:
`C:\Users\magom\.claude\projects\f--pub\memory\`.

## Deployment (do this after every change)
1. Edit locally, `git commit`, `git push origin main`.
2. On the server (`crm.upgradesrls.com`, IONOS Ubuntu, SSH as root — credentials in local memory
   `panificio-azzurro-network.md`), via plink:
   `cd /var/www/html/ristorante && git pull origin main && php migrate.php`
3. Wait ~3 s (opcache revalidate_freq=2), then test live: `php -l` the changed files, curl
   https://ristorante.upgradesrls.com, check `/var/log/apache2/ristorante.upgradesrls.com-error.log`.
4. Never hand-edit files on the server. `config/database.php`, `config/devices.php` and
   `config/config.php` are gitignored and server-only.

**Important:** on 2026-10-01 the server checkout `/var/www/html/ristorante` still pulls from
`ispbillingai/order`, not this repo. Before deploying from here, either point the server's
`origin` at `ispbillingai/pub` or keep pushing to `order` too.

## Rules
- New tables need `COLLATE utf8mb4_unicode_ci` (the server default `general_ci` breaks UNIONs).
- Users are never deleted, only disabled or enabled.
- Never change the WireGuard tunnel; remote access goes through OpenVPN.
- `crm/` (repo `bitrix`) is a different app; leave it alone.
- Local setup: copy `config/database.example.php` to `config/database.php` (and `devices.example.php`)
  and import `database_schema.sql`, then run `php migrate.php`.
