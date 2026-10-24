# REDact engine

**RED Assistant for Compliance Toolkit**: a web application that takes radio equipment through
**RED cyber** (Delegated Regulation (EU) 2022/30, articles 3.3 d, e and f of the Radio Equipment
Directive) and **EN 18031**, from the product description to the EU declaration of conformity.
This repository holds the **engine**: free software under the
[GNU Affero General Public License v3.0 only](LICENSE).

> The engine ships with an **invented demo content pack**. The demo pack contains no text of any
> standard and gives no compliance result. **Do not use it for a real conformity assessment.**
> cetome's assessment content is available on the hosted service at
> [redact.cetome.com](https://redact.cetome.com), or under a commercial licence.

## What it does

For each version of a product, REDact runs one workflow (steps can overlap; nothing forces a strict order):

1. **Product**: picture, description, models, hardware and software versions, intended use,
   product identification (with Excel import) and documents.
2. **Essential requirements**: which articles apply, which EN 18031 parts follow, and whether
   self-assessment is possible.
3. **Risk assessment**: a STRIDE threat register, an editable 5×5 matrix and residual risk.
4. **Assets & interfaces**: assets, interfaces, components and the other lists the content pack
   defines, with the mappings between them. SBOM/HBOM import (CycloneDX, SPDX, SWID, Excel) with
   known-vulnerability checks (EUVD, NVD, OSV, CISA KEV) and VEX import (CycloneDX VEX, alone or
   in the SBOM, OpenVEX, CSAF 2.0 VEX).
5. **Decision trees**: the tree is the evaluation. For each item it applies to, you answer on the
   tree itself; the last decision node, the verdict and a justification are recorded. Impossible
   outcomes are refused.
6. **E.Info**: the documentation each item needs, derived from steps 4 and 5.
7. **Assessment units**: the result and evidence of each assessment unit of the standard, recorded
   by the team or by an invited external assessor (marked independent), or performed outside
   REDact by an independent test laboratory whose report is attached.
8. **Conformity**: the RED restrictions and the conformity assessment route (self-assessment or a
   Notified Body, with the NANDO list).
9. **Review**: reviewers, internal or external (for example a Notified Body), view the evidence and
   validate each evaluation and assessment unit (agree, disagree, question), then decide on the
   version. Review never edits anything. It can
   start before the assessment units are in; only the final approval needs them. Without a
   reviewer, the owner can self-review (flagged as such).
10. **Declaration & technical file**: the EU declaration of conformity in the 23 EU languages of the
    Directive (official Annex VI wording, Word and PDF) and the technical documentation as a zip
    following Annex V.

Also included:

- **Reports**: Word and Excel exports, and the content pack's own workbook templates filled in
  (and read back) when the pack provides them.
- **AI assistance**, which is optional, with your own key or model: draft from documents, improve
  a text, check an evaluation, suggest a path, suggest scoping answers. Every AI output is marked as
  such and stays a proposal until a person accepts it. The AI never gives a verdict and never
  decides a decision-tree path.
- **Accounts without passwords**: an e-mail link and code, plus passkeys (WebAuthn).
- **Teams**: organisations with admins and members, product roles (owner, contributor, reviewer,
  external assessor), invitations, ownership transfer, and a staff console with audit logs.
- **History**: who changed what and when, for product owners, contributors and organisation admins,
  with CSV export.
- **A guest sandbox** ("try without an account"), purged automatically, and a read-only demo
  product when the content pack provides one.
- **Optional billing** with Stripe (off unless configured).
- **Internationalisation**: English and French interface, i18n-ready. Every text lives in Laravel
  language files (`lang/en`, `lang/fr`): add a language by translating them and listing it in
  `config/redact.php` (`locales`). The EU declaration of conformity already comes in the 23 EU
  languages of the Directive.

## SBOM and VEX

We publish what we ask manufacturers to publish. The [`sbom/`](sbom/) folder holds the engine's
**SBOM** (CycloneDX 1.6: every PHP and JavaScript component it ships, with versions, licences and
hashes) and its **VEX** (the known vulnerabilities affecting those components and their status,
from [OSV](https://osv.dev)). Both are standard CycloneDX files that any SBOM tool reads, and both
are regenerated for every release with `php scripts/sbom.php`.

## Open-core model

| Part | Licence | Where |
|---|---|---|
| **Engine** (this repository) | AGPL-3.0-only | here |
| **Demo content pack** (`packs/demo/`), fully invented | AGPL-3.0-only, with the engine | here |
| **cetome content pack**: scoping rules, inventory catalogue and its ranking, threat library, E.Info placement and pre-fill, workbook templates, AI prompt wording, transcriptions of the EN 18031 decision trees and assessment criteria | proprietary | only on cetome's hosted service, or under a commercial licence |
| **Hosted service** [redact.cetome.com](https://redact.cetome.com): free and paid accounts ([pricing](https://redact.cetome.com/pricing)) | Terms of Use | cetome |

The engine knows no assessment method of its own. Everything specific (questions, lists, trees,
rules) comes from a **content pack** loaded from `REDACT_CONTENT_PATH`. You can:

- run the engine with the demo pack (`REDACT_CONTENT_PATH=packs/demo`, the default in `.env.example`)
  to try the workflow end to end;
- write your own pack by following [docs/CONTENT-PACK.md](docs/CONTENT-PACK.md). Texts of standards are
  copyrighted by CEN/CENELEC and the national standards bodies, so check that you have the right to use
  any text you put in a pack.

**Commercial licences** let you run a modified engine without the AGPL obligations, integrate it in
another product, or use the cetome pack on your own servers: [redact.cetome.com/contact](https://redact.cetome.com/contact).

### AGPL in practice

If you run a modified version of the engine as a network service, AGPL section 13 requires you to
offer its users the corresponding source code of that version, for example through a link in the
interface. Running the engine unmodified, or for internal use, has no extra obligation beyond the licence.

## Requirements

- PHP 8.3 or later, with `gd`, `intl`, `sodium`, `zip` and `pdo_sqlite` (or `pdo_mysql`)
- Composer
- Node.js 22 or later (only to build the front end)
- A database, see below

### Databases

| Database | Status |
|---|---|
| **SQLite** 3.35+ | Supported. The default for a local install and the test suite; fine for a small single-server install. |
| **MariaDB** 10.4+ | Supported. Recommended in production. |
| **MySQL** 8.0+ | Supported (same driver and schema as MariaDB). |
| PostgreSQL, SQL Server | Not supported. |

Create one empty database (utf8mb4 for MariaDB and MySQL): `php artisan migrate` creates every table.

The engine runs on shared hosting without daemons: the queue and the cache use the database, and
`php artisan schedule:run` runs from cron every minute. It also runs on a VPS.

## Quick start

```sh
composer install
cp .env.example .env            # Windows: copy .env.example .env
php artisan key:generate
php artisan migrate --seed --seeder=RolesSeeder
npm ci
npm run build                   # or: npm run dev
php artisan serve               # http://localhost:8000
```

- With `MAIL_MAILER=log`, e-mails are written to `storage/logs`, including the sign-in link and code.
- Passkeys work on `http://localhost`.
- To make yourself staff: `php artisan redact:make-staff you@example.com --super`.

## Configuration

Every setting is documented in [`.env.example`](.env.example). The main ones:

- `REDACT_CONTENT_PATH`: the content pack, relative to the app folder or absolute. In production,
  keep it outside the web root.
- **AI** (optional), with **your own key or model**: Claude, ChatGPT, Gemini, Mistral or any
  OpenAI-compatible API (Ollama, vLLM, LM Studio, a gateway). Either set `REDACT_AI_PROVIDER` (`anthropic`, `openai`, `gemini`,
  `mistral`, `openai_compatible`), `REDACT_AI_MODEL` (free text, empty for the suggested default),
  `REDACT_AI_BASE_URL` (compatible APIs and gateways) and the provider's key
  (`ANTHROPIC_API_KEY`, `OPENAI_API_KEY`, `GEMINI_API_KEY`, `REDACT_AI_API_KEY`), or use the
  console's **AI settings** page (`/admin/ai`, super-admin): the key is stored encrypted, never shown
  again, and a **Test connection** button checks it. Self-hosted, the AI is not limited by plans;
  per-person quotas are `REDACT_AI_UNITS_PER_DAY` and `REDACT_AI_UNITS_PER_10_MIN`. Without
  cetome's pack the AI uses the engine's generic instructions. Each organisation can also bring
  its own key or model on its Settings tab (AI model card). See
  [docs/AI-PROVIDERS.md](docs/AI-PROVIDERS.md).
- **Known vulnerabilities**: `REDACT_VULN_CHECKS`, `REDACT_VULN_AUTO`, `REDACT_VULN_WEEKLY`, and
  `NVD_API_KEY` (optional, it raises the NVD rate limit). Set `REDACT_VULN_USER_AGENT` to your own
  site and contact. The sources are described in `docs/`.
- **Guest sandbox**: switch it off with `REDACT_GUEST=false`.

## Quality

```sh
php artisan test
vendor/bin/pint --test
vendor/bin/phpstan analyse
composer audit
npm audit --omit=dev
```

The tests use the fixture pack in `tests/Fixtures/content-pack` and an in-memory SQLite database.

## Deployment

See `docs/` for deployment notes (shared hosting and VPS). In short: `APP_ENV=production`,
`APP_DEBUG=false`, HTTPS, a cron job for `schedule:run`, and the content pack outside the web root.
The hardening checklist is in [SECURITY.md](SECURITY.md).

## Contributing

Issues and discussions are welcome. **Pull requests are not accepted yet**: they will open once the
Contributor License Agreement is final. Until then, please report bugs and ideas as issues. See
[CONTRIBUTING.md](CONTRIBUTING.md).

## Legal

- Licence: [LICENSE](LICENSE) (AGPL-3.0-only). Copyright (c) 2026 cetome.
- Third-party components: [NOTICE](NOTICE).
- Trademarks: "REDact" and "cetome", with their logos, are not covered by the licence. See [TRADEMARKS.md](TRADEMARKS.md).
- Security: [SECURITY.md](SECURITY.md).
- Content pack format: [docs/CONTENT-PACK.md](docs/CONTENT-PACK.md).
