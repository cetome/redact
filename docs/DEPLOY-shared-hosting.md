# Deploying REDact on shared hosting (IONOS)

REDact runs on ordinary shared PHP hosting: no Redis, no daemon, no Node on the server.
Everything heavy (Composer, the front-end build) happens on your PC; you upload one zip.

What you need:

| | |
|---|---|
| PHP | **8.4** (8.3 minimum) with `pdo_mysql`, `mbstring`, `intl`, `gd`, `zip`, `fileinfo`, `sodium`, `openssl`, `xml` |
| Database | MariaDB 10.4+ or MySQL 8 (one database) |
| Web server | Apache with `mod_rewrite` (IONOS default) |
| Access | SFTP and **SSH** (included in IONOS web hosting plans) |
| Cron | one job every minute (IONOS: *Hosting → Cron jobs*) |
| HTTPS | an SSL certificate on the domain (passkeys only work over HTTPS) |

Paths below use `~/` for your hosting home directory (on IONOS SSH it looks like
`/homepages/12/d123456789/htdocs`).

## 1. One-time setup

1. **PHP version.** IONOS panel → *Hosting → PHP settings* → PHP 8.4 for the domain.
   Over SSH the CLI PHP is not always the same version: use the explicit binary, e.g.
   `/usr/bin/php8.4-cli` (run `ls /usr/bin/php*` to see what exists). Below, `PHP` means that binary.
2. **Database.** Panel → *Databases* → create a MariaDB database. Note host, name, user, password.
3. **Folders.** Over SFTP create:
   - `~/redact-app/` — the application
   - `~/redact-content/` — the private content pack (cetome know-how), **outside** the web root
4. **Domain.** Panel → *Domains* → `redact.example.com` → *Change destination / Target directory* →
   `redact-app/public`. This is the important security setting: only `public/` is reachable.
   *If your plan cannot do this*, point the domain to `redact-app/` and copy
   `deploy/shared-hosting/root.htaccess` to `redact-app/.htaccess` (it forwards everything to
   `public/` and blocks the rest).
5. **HTTPS.** Panel → *SSL certificates* → assign a certificate to the domain.

## 2. Build the release on your PC

```powershell
cd "C:\path\to\redact-app"
powershell -ExecutionPolicy Bypass -File scripts\build-release.ps1
```

You get `build\redact-<version>-<commit>.zip` (no `.env`, no secrets, no content pack).

## 3. Upload

1. Upload the zip to `~/` and unzip it into `~/redact-app/` (SSH: `unzip -q redact-*.zip -d redact-app`).
   *First time only.* For updates see section 6.
   **Then set the permissions** (a zip built on Windows unpacks world-writable, and IONOS answers
   a world-writable PHP file or folder with Apache's "500 Internal Server Error"):

   ```bash
   cd ~/redact-app
   find . -type d -exec chmod 755 {} +
   find . -type f -exec chmod 644 {} +
   chmod -R u+rwX storage bootstrap/cache
   chmod 600 .env    # once .env exists
   ```
2. Upload the content pack (the `redact-content` repository, without its `.git` folder) to `~/redact-content/`.

## 4. Configure (first time)

```bash
cd ~/redact-app
cp .env.example .env
PHP artisan key:generate --force
```

Edit `.env` (SFTP or `nano .env`) — at least:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://redact.example.com
LOG_LEVEL=warning

DB_CONNECTION=mysql
DB_HOST=db5000xxxxxx.hosting-data.io
DB_DATABASE=dbs123456
DB_USERNAME=dbu123456
DB_PASSWORD=...

SESSION_SECURE_COOKIE=true
SESSION_COOKIE=__Host-redact
REDACT_QUEUE_VIA_SCHEDULER=true
REDACT_CONTENT_PATH=/homepages/12/d123456789/htdocs/redact-content

MAIL_MAILER=smtp
MAIL_HOST=smtp.ionos.fr
MAIL_PORT=587
MAIL_USERNAME=no-reply@cetome.com
MAIL_PASSWORD=...
MAIL_FROM_ADDRESS=no-reply@cetome.com
MAIL_FROM_NAME="cetome REDact"
REDACT_CONTACT_TO=info@cetome.com  # contact form messages (default)

PASSKEYS_USER_HANDLE_SECRET=<64 random characters, never change it>
ALTCHA_HMAC_KEY=<64 random characters>
GEMINI_API_KEY=...
NVD_API_KEY=...                  # optional, see below
```

Generate random values with `PHP -r "echo bin2hex(random_bytes(32)), PHP_EOL;"`.

Then:

```bash
PHP artisan migrate --force
PHP artisan db:seed --class=RolesSeeder --force
PHP artisan storage:link        # harmless if not needed
PHP artisan optimize            # caches config, routes, views
chmod -R u+rwX storage bootstrap/cache
```

### Mail (SPF, DKIM, DMARC)

cetome.com's DMARC record asks for strict SPF alignment (`aspf=s`, quarantine): every REDact mail
(sign-in codes, invitations, the contact form) is `From: no-reply@cetome.com`
and must leave with the **envelope sender** (`Return-Path`, SMTP `MAIL FROM`) exactly
`no-reply@cetome.com`, from a server listed in cetome.com's SPF record, signed with DKIM for
cetome.com. Otherwise the message fails DMARC and is quarantined or dropped, for some recipients
and not others (cetome.com's `inc/forms.php` found it that way). A visitor's address is only ever
in `Reply-To`, never in `From`.

- **smtp (recommended, the settings above)**: Symfony Mailer uses the From address as `MAIL FROM`,
  and `App\Listeners\Mail\AlignEnvelopeSender` pins `Return-Path` to `MAIL_FROM_ADDRESS` on every
  message. Log in as the `no-reply@cetome.com` mailbox (an IONOS relay refuses or rewrites another
  sender). Port 587 uses STARTTLS (`MAIL_SCHEME` empty); for 465 set `MAIL_SCHEME=smtps`. Check
  that DKIM signing is on for cetome.com in the IONOS panel.
- **sendmail (the host's local mailer, like PHP `mail()`)**: give the envelope sender explicitly,
  as cetome.com does with `mail(..., '-fno-reply@cetome.com')`; without it the host uses its own
  domain:

  ```dotenv
  MAIL_MAILER=sendmail
  MAIL_SENDMAIL_PATH="/usr/sbin/sendmail -t -i -fno-reply@cetome.com"
  ```

  (With `-t`, Symfony would add `-f` itself; writing it keeps it right whatever the transport does.)
- **Check** once deployed: send a message from `/contact` and, in the copy that reaches `REDACT_CONTACT_TO`, read
  the headers of what arrives: `Return-Path: <no-reply@cetome.com>`, and in
  `Authentication-Results` `spf=pass smtp.mailfrom=cetome.com`, `dkim=pass header.d=cetome.com`,
  `dmarc=pass`. The message to `REDACT_CONTACT_TO` must show the visitor in `Reply-To` only.

### Billing (Stripe)

Optional: without the Stripe keys billing is off and the free limits apply ("Talk to us").
To sell additional products, follow `docs/BILLING.md` (product and price, Stripe Tax, Customer
Portal), then set `STRIPE_KEY`, `STRIPE_SECRET`, `STRIPE_PRICE_PRODUCT_MONTHLY` and, after creating
the webhook endpoint `https://redact.example.com/stripe/webhook` in the Stripe dashboard,
`STRIPE_WEBHOOK_SECRET`. Run `PHP artisan optimize` again after changing `.env`. Use test keys
(`pk_test_`, `sk_test_`) on a staging copy first. The webhook route is outside the session and CSRF
middleware on purpose: Cashier checks the `Stripe-Signature` header. No Stripe.js is loaded
(Checkout and the portal are redirects), so the Content Security Policy is unchanged.

## 5. Cron (required)

IONOS panel → *Cron jobs* → new job, **every minute**:

```
/usr/bin/php8.4-cli /homepages/12/d123456789/htdocs/redact-app/artisan schedule:run
```

**If the panel only offers an HTTP GET cron** (some IONOS plans, at most once a day): set a key in
`.env`, `REDACT_CRON_KEY=<40+ random letters and digits>` (run `PHP artisan optimize` after), then
have an external every-minute cron call `https://redact.example.com/cron/<that key>`, for example the
free cron-job.org. Keep the daily IONOS HTTP job on the same address as a backup. Any other key, or
no key in `.env`, answers 404.

This one line runs the background work: AI drafts, e-mails and vulnerability checks (queue),
retention pruning, clean-up of uploaded bills of materials,
purge of unconfirmed accounts.

## 6. Your first cetome staff account

1. Open `https://redact.example.com/register`, create your account, confirm the e-mail.
2. Add a passkey from the welcome page (the staff console requires one).
3. Over SSH: `PHP artisan redact:make-staff you@cetome.com --super`
4. The console is at `/admin`.

## 7. Updating

**One command:** upload the new zip next to `redact-app/`, then from that folder run
`sh redact-app/deploy/shared-hosting/update.sh redact-<new>.zip` (maintenance mode, unzip,
permissions, caches, migrations, queue restart, back online). The steps it runs:

```bash
cd ~
PHP redact-app/artisan down --render="errors::503"
unzip -q -o redact-<new>.zip -d redact-app       # overwrites code, keeps .env and storage/
cd redact-app
find . -type d -exec chmod 755 {} + ; find . -type f -exec chmod 644 {} + ; chmod -R u+rwX storage bootstrap/cache ; chmod 600 .env
PHP artisan migrate --force
PHP artisan optimize:clear && PHP artisan optimize
PHP artisan queue:restart
PHP artisan up
```

Removed files from older releases stay behind with `unzip -o`; that is harmless. For a perfectly
clean tree, unzip into `redact-app-new/`, copy `.env` and `storage/` across, then swap the folder names.

**Content pack updates**: replace `~/redact-content/` and run `PHP artisan cache:clear`. Each new
product version records the pack version it was assessed with.

## 8. Checks after deployment

- `https://redact.example.com/up` answers 200.
- `https://redact.example.com/.env` and `/../storage` answer 403/404.
- Response headers contain `Content-Security-Policy`, `Strict-Transport-Security`, `X-Frame-Options: DENY`.
- Sign-up e-mail arrives (check the spam folder and the DMARC alignment of `no-reply@cetome.com`).
- A message from `/contact` reaches `REDACT_CONTACT_TO` (nothing is mailed to the sender), with
  `spf=pass`, `dkim=pass`, `dmarc=pass` (Mail, above); `/admin/contact` lists it as sent.
- The cron works: a message sent from `/contact` goes from *queued* to *sent* in `/admin/contact`
  within a minute or two (the queue is drained by the cron).

## Limits of shared hosting (and when to move to the VPS)

- Background jobs run at most once a minute, so an AI draft from documents starts within ~60 s.
- PHP max execution time (often 60–90 s) caps very long AI calls; the queue retries them.
- Known-vulnerability checks call `euvdservices.enisa.europa.eu`, `services.nvd.nist.gov`, `api.osv.dev`
  and `www.cisa.gov` over HTTPS (only component identifiers are sent). A check runs in slices of
  35 s from the cron, so a long component list takes a few minutes. Without `NVD_API_KEY` the NVD
  allows 5 requests per 30 s: request a free key (https://nvd.nist.gov/developers/request-an-api-key)
  and set it in `.env`. `REDACT_VULN_CHECKS=false` switches every external call off.
  `REDACT_VULN_WEEKLY=true` re-checks every version in work (draft or in review) each Monday at
  04:00; the results page then shows what is new since last week. Set an NVD key first. Details and
  terms of use: `docs/VULNERABILITY-SOURCES.md`.
- Outbound HTTPS to the AI provider must be allowed (IONOS's outbound IP was once refused by
  Microsoft for the old stats dashboard). If AI calls fail with network errors, use the VPS.
- Uploaded documents count against the hosting quota.

The same release zip also runs on the VPS: see `DEPLOY-vps.md`.
