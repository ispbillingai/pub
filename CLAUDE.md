# Ristorante POS — handoff notes

This folder (`F:\pub`, repo `ispbillingai/pub`) is a copy of `F:\order` (repo `ispbillingai/order`),
copied on 2026-10-01 at commit `39705be` ("Menu components: a photo for each ingredient").
Work continues here.

## What the app is
PHP + MariaDB restaurant POS ("ristorante"): admin, cashier, waiter, kitchen, guest ordering
(QR menu), fiscal printing via the Epson RT printer, card payments (Epson RT protocol 17 / Dojo),
Glovo orders, "Clienti online" (one QR for a shop with no tables:
sign-up with WhatsApp code, then ordering; `online.php`, `includes/online_order.php`, Admin > Clienti online). This repo (pub) is live at https://pub.upgradesrls.com;
the original (`order` repo) is still live at https://ristorante.upgradesrls.com.

## Project notes
Notes collected while working on this project are in [docs/claude-memory/](docs/claude-memory/)
(start from `MEMORY.md`). **Passwords have been removed from the copy in git** because this repo is
public. The full copies (with passwords) are in Claude's local memory for this folder:
`C:\Users\magom\.claude\projects\f--pub\memory\`.

## Deployment (do this after every change)
1. Edit locally, `git commit`, `git push origin main`.
2. On the PUB server (`pub.upgradesrls.com` = 217.160.131.242, IONOS Ubuntu 24.04, SSH as root;
   credentials in local memory `pub-server.md`), via plink:
   `cd /var/www/html/pub && git pull origin main && php migrate.php`
3. Wait ~3 s (opcache revalidate_freq=2), then test live: `php -l` the changed files, curl
   https://pub.upgradesrls.com, check `/var/log/apache2/pub.upgradesrls.com-error.log`.
4. Never hand-edit files on the server. `config/database.php` and `config/devices.php` are
   gitignored and server-only.

The PUB server was set up on 2026-10-01: Apache 2.4 + PHP 8.3 + MariaDB 10.11 (default collation
`utf8mb4_unicode_ci`), DB `pub` / user `pub`, vhost `/etc/apache2/sites-available/pub.conf`,
Let's Encrypt certificate via certbot (auto-renews), 2 GB swap. The DB started as a copy of the live
ristorante DB (menu, rooms, tables, staff users, settings). The device monitor (Dispositivi /
Aree di rete) was removed from this repo on 2026-10-02 (it is still in the ristorante app).
`qrencode` (apt) is installed: it draws the online orders' pay QR as a PNG for WhatsApp
(`online-qr.php`); without it the "order ready" message goes without the image.
Do not deploy this repo to `/var/www/html/ristorante`, which still pulls from `ispbillingai/order`.

## Rules
- New tables need `COLLATE utf8mb4_unicode_ci` (the server default `general_ci` breaks UNIONs).
- Users are never deleted, only disabled or enabled.
- Never change the WireGuard tunnel; remote access goes through OpenVPN.
- `crm/` (repo `bitrix`) is a different app; leave it alone.
- Local setup: copy `config/database.example.php` to `config/database.php` (and `devices.example.php`)
  and import `database_schema.sql`, then run `php migrate.php`.
