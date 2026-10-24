# Security policy

## Reporting a vulnerability

E-mail **security@cetome.com** with:

- a description of the problem;
- the affected component, file or URL;
- steps to reproduce;
- the impact you expect.

Please **do not open a public issue** for a vulnerability.

We will:

- acknowledge your report within **2 business days**;
- give you a first assessment and a fix plan within **30 days**;
- keep you informed until the fix is released and deployed, and credit you if you wish.

## Scope

| What | Who handles it |
|---|---|
| **The engine code in this repository** | cetome. Report it as above. Fixes are released here, and a security advisory is published once a fixed version is available. |
| **The hosted service [redact.cetome.com](https://redact.cetome.com)** | cetome. Report it as above. The safe-harbour terms below apply. Machine-readable contact: `https://redact.cetome.com/.well-known/security.txt` (RFC 9116). |
| **Self-hosted instances** | Their operators. cetome does not run them and cannot access them. Report a problem in the engine itself to us. Report a problem in someone's deployment to its operator. |

### Safe harbour (hosted service)

We will not take legal action against research done in good faith, provided that you:

- only test against your own account and data;
- do not access or change other people's data;
- do not degrade the service (no load or denial-of-service testing, no spam);
- give us reasonable time to fix before any disclosure.

**Out of scope:**

- missing best-practice headers without a demonstrated impact;
- self-XSS;
- clickjacking on pages without sensitive actions;
- rate limits on non-sensitive endpoints;
- attacks that need a compromised device or browser;
- social engineering of cetome staff.

## Supported versions

Security fixes go to the **latest release** and to the `main` branch. Please upgrade before
reporting, and check that the problem still exists there.

## Hardening checklist for self-hosters

- `APP_ENV=production` and **`APP_DEBUG=false`**.
- `LOG_LEVEL=warning` or higher.
- **HTTPS only**. Set `SESSION_SECURE_COOKIE=true` and `SESSION_COOKIE=__Host-redact` (this needs
  HTTPS, path `/` and no cookie domain). Enable HSTS at your web server.
- `APP_URL` must be your real HTTPS origin: it is also the passkey relying party.
- Serve only `public/` as the web root. Keep the **content pack outside the web root**, readable
  by the PHP user only.
- **Secrets**: generate `APP_KEY` (`php artisan key:generate`), `PASSKEYS_USER_HANDLE_SECRET` and
  `ALTCHA_HMAC_KEY` (random, 64 characters) once and keep them stable. Never commit `.env`, and keep it out of
  the web root with permissions limited to the PHP user. Keep AI and NVD API keys in `.env`, or
  enter the AI key in the console's AI settings, where it is stored encrypted with `APP_KEY` and
  never shown again (rotating `APP_KEY` means entering it again).
- **Behind a reverse proxy**: set `TRUSTED_PROXIES` to the proxy addresses only.
- **Cron**: run `php artisan schedule:run` every minute. It purges guest sandboxes and old logs.
  On shared hosting, also set `REDACT_QUEUE_VIA_SCHEDULER=true` so the scheduler drains the queue.
- **Mail**: use an SMTP account that passes your domain's SPF, DKIM and DMARC.
- **Backups**: back up the database and `storage/app` (the uploaded documents) regularly and
  encrypted, and test restores.
- **Dependencies**: run `composer audit` and `npm audit --omit=dev` regularly, and update with
  `composer update` and `npm update`. Rebuild front-end assets after updating.
- **Content Security Policy**: keep `CSP_ENABLED=true`.
- **Optional features**: turn off what you do not use, for example `REDACT_GUEST=false`,
  `REDACT_AI_ENABLED=false` or `REDACT_VULN_CHECKS=false`.

## How the engine protects data (summary)

- **Accounts**: passwordless (e-mail link plus browser-bound code), and passkeys (WebAuthn). The
  staff console requires a user-verified passkey session.
- **Sessions**: database-backed, with an absolute lifetime, revocable, and re-checked on every request.
- **Authorisation**: one access resolver per product. Refusals are answered with a 404.
- **Browser hardening**: strict Content-Security-Policy with nonces and no inline code, and
  `frame-ancestors 'none'`.
- **Uploaded documents**: stored on a private disk and served only through an authorised download.
- **AI**: output never enters an assessment unless a person accepts it. Every AI call is
  quota-limited and logged.
- **Audit**: a full audit trail of changes and security events. Usage analytics store IP
  addresses as keyed hashes.
