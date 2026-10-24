# Deploying REDact on a VPS (recommended for production)

Same release zip as shared hosting (`scripts/build-release.ps1`), plus a real queue worker,
nginx, PHP-FPM and proper backups. Commands for Debian 12 / Ubuntu 24.04.

## 1. Packages

```bash
sudo apt update
sudo apt install -y nginx mariadb-server unzip certbot python3-certbot-nginx \
  php8.4-fpm php8.4-cli php8.4-mysql php8.4-mbstring php8.4-intl php8.4-gd php8.4-zip \
  php8.4-xml php8.4-curl php8.4-sodium php8.4-opcache
# (on Debian add the sury.org PHP repository first if 8.4 is not available)
```

## 2. User, folders, database

```bash
sudo adduser --system --group --home /srv/redact redact
sudo mkdir -p /srv/redact/app /srv/redact/content
sudo mysql -e "CREATE DATABASE redact CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
  CREATE USER 'redact'@'localhost' IDENTIFIED BY '<strong password>';
  GRANT ALL ON redact.* TO 'redact'@'localhost';"
```

Upload the release zip and the content pack, then:

```bash
sudo -u redact unzip -q redact-*.zip -d /srv/redact/app
sudo -u redact cp /srv/redact/app/.env.example /srv/redact/app/.env
```

Edit `.env` as in `DEPLOY-shared-hosting.md` section 4, with these differences:
`DB_HOST=127.0.0.1`, `REDACT_QUEUE_VIA_SCHEDULER=false` (a real worker runs),
`REDACT_CONTENT_PATH=/srv/redact/content`, `TRUSTED_PROXIES=127.0.0.1` only if another proxy sits in front.

```bash
cd /srv/redact/app
sudo -u redact php artisan key:generate --force
sudo -u redact php artisan migrate --force
sudo -u redact php artisan db:seed --class=RolesSeeder --force
sudo -u redact php artisan optimize
sudo chmod 640 .env
```

## 3. PHP-FPM pool

`/etc/php/8.4/fpm/pool.d/redact.conf`:

```ini
[redact]
user = redact
group = redact
listen = /run/php/redact.sock
listen.owner = www-data
listen.group = www-data
pm = dynamic
pm.max_children = 10
php_admin_value[upload_max_filesize] = 30M
php_admin_value[post_max_size] = 32M
php_admin_value[expose_php] = Off
php_admin_value[open_basedir] = /srv/redact:/tmp
```

## 4. nginx

See `deploy/vps/nginx.conf` (root = `public/`, PHP only through `index.php`, 32 MB uploads, no
dotfiles). Then `sudo certbot --nginx -d redact.example.com`.

## 5. Queue worker and scheduler

```bash
sudo cp deploy/vps/redact-queue.service /etc/systemd/system/
sudo systemctl enable --now redact-queue
echo "* * * * * redact php /srv/redact/app/artisan schedule:run >> /dev/null 2>&1" | sudo tee /etc/cron.d/redact
```

## 5a. Mail (SPF, DKIM, DMARC)

Same rules as shared hosting (`docs/DEPLOY-shared-hosting.md`, Mail): `From` and envelope sender
exactly `no-reply@cetome.com` (cetome.com DMARC, `aspf=s`), a relay listed in cetome.com's SPF,
DKIM for cetome.com. Prefer SMTP through the `no-reply@cetome.com` mailbox:

```dotenv
MAIL_MAILER=smtp
MAIL_HOST=smtp.ionos.fr
MAIL_PORT=587
MAIL_USERNAME=no-reply@cetome.com
MAIL_PASSWORD=...
MAIL_FROM_ADDRESS=no-reply@cetome.com
MAIL_FROM_NAME="cetome REDact"
REDACT_CONTACT_TO=info@cetome.com
```

A local MTA (postfix) only if the VPS address is in cetome.com's SPF record and postfix signs with
the cetome.com DKIM key; then `MAIL_MAILER=sendmail` and
`MAIL_SENDMAIL_PATH="/usr/sbin/sendmail -t -i -fno-reply@cetome.com"`. Check the headers of a
contact form message received at `REDACT_CONTACT_TO`: `spf=pass`, `dkim=pass`, `dmarc=pass`, `Return-Path: <no-reply@cetome.com>`.

## 5b. Billing (Stripe)

Optional (without keys billing is off: no plan limits on a self-hosted install, the free limits with
`REDACT_SELF_HOSTED=false`). See `docs/BILLING.md`: create
the product and its 100 EUR monthly price, activate Stripe Tax and the Customer Portal, add the
webhook endpoint `https://redact.example.com/stripe/webhook` (`php artisan cashier:webhook` creates
it with the right events once `STRIPE_SECRET` is set; it prints the signing secret), then set
`STRIPE_KEY`, `STRIPE_SECRET`, `STRIPE_WEBHOOK_SECRET`, `STRIPE_PRICE_PRODUCT_MONTHLY` in `.env`
and run `php artisan optimize`. nginx needs nothing special: the webhook is a plain POST to Laravel
(no session, no CSRF, signature checked by Cashier). Try it with test keys first
(`stripe listen --forward-to https://staging.example/stripe/webhook`).

## 6. Updates

```bash
cd /srv/redact
sudo -u redact php app/artisan down
sudo -u redact unzip -q -o redact-<new>.zip -d app
cd app && sudo -u redact php artisan migrate --force && sudo -u redact php artisan optimize
sudo systemctl restart redact-queue php8.4-fpm
sudo -u redact php artisan up
```

## 7. Backups

- Database: nightly `mysqldump --single-transaction redact | gzip` to off-site storage, keep 30 days.
- Files: `/srv/redact/app/storage/app/private` (uploaded documents) and `.env`.
- Test a restore once a quarter.

## 8. Hardening checklist

- `ufw allow OpenSSH; ufw allow 'Nginx Full'; ufw enable`
- SSH keys only, no root login, `unattended-upgrades` on.
- `APP_DEBUG=false`, `SESSION_SECURE_COOKIE=true`, `SESSION_COOKIE=__Host-redact`.
- Run `composer audit` and `npm audit` before each release (CI does it).
