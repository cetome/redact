# Contributing to the REDact engine

Thank you for helping. This guide says what we accept, how to set up the project and how to send a change.

## Before you start

- **Pull requests are not accepted yet.** They will open once the Contributor License Agreement
  (CLA) is published: every contribution will then need to be covered by it before we can merge.
  The CLA will let cetome keep the engine under AGPL-3.0-only and also offer it under commercial
  licences; you will keep the copyright in your work. Until then, issues and discussions are welcome.
- **Security issues** never go in a public issue: see [SECURITY.md](SECURITY.md).
- For a larger change, open an issue first so we can agree on the approach.

## What is in scope

| Welcome | Not accepted |
|---|---|
| Engine code: workflow, UI, accessibility, performance, security, reports, imports, AI plumbing, translations | Any text, table or figure of a standard (EN 18031 or any other CEN, CENELEC, ETSI, ISO or IEC publication). Standards are copyrighted by their publishers |
| The content pack mechanism and its documentation ([docs/CONTENT-PACK.md](docs/CONTENT-PACK.md)) | Content of cetome's private pack, or anything derived from it: rules, decision-tree transcriptions, catalogue, threat library, E.Info placement, workbook templates, prompt wording |
| Improvements to the demo pack (`packs/demo/`), if every item is invented and clearly marked as sample content | Assessment methods presented as authoritative, or "compliance" logic hard-coded in the engine. Method belongs in a pack |
| Tests, bug reports, documentation | Code you do not have the right to license under the CLA |

## Development setup

Requirements: PHP 8.3+ (with `gd`, `intl`, `sodium`, `zip`, `pdo_sqlite`), Composer, Node.js 22+.

```sh
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed --seeder=RolesSeeder
npm ci
npm run dev            # or: npm run build
php artisan serve
```

The app uses the content pack named by `REDACT_CONTENT_PATH`, which is the demo pack (`packs/demo`)
by default. With `MAIL_MAILER=log`, sign-in links and codes are written to `storage/logs`.

## Conventions

**Content pack**

- Read the pack only through `App\Content\ContentPack` and its `rules()`
  (`App\Content\Contracts\PackRules`).
- Never hard-code questions, requirement ids, tree wording or verdict rules in the engine.
- Never send the pack wholesale to the browser. Controllers expose only what a page needs.

**Authorisation**

- `App\Support\ProductAccess` is the single source of truth.
- Refuse with **404**, never 403, so that existence never leaks.

**AI**

- AI output goes to the AI channel (`ai_suggestions`) and never straight into a human field. A
  person accepts or rejects it.
- AI output is always marked with its origin badge.
- The AI never gives a verdict and never decides a decision-tree path.

**Front end and CSP**

- Strict Content-Security-Policy: no inline `<script>`, no `style=""` attributes, no `on*=` handlers.
- JavaScript goes in `resources/js/modules/<feature>.js` and registers Alpine components with
  `Alpine.data(...)` on `alpine:init`.
- The Alpine CSP build evaluates only simple property paths and method calls, so put the logic in
  component methods and getters.

**i18n**

- Every visible string goes through `__('file.key')`, with keys in both `lang/en/<area>.php` and
  `lang/fr/<area>.php`.
- Content-pack strings go through `App\Content\Text::of()`.
- House style: British English, no semicolons in UI copy, a tooltip never repeats its label.

**Security**

- Validate every input.
- Whitelist values that come from the content pack.
- Rate-limit anything that sends e-mail or calls the AI.
- Keep uploaded files on the private disk only, served through an authorised download.

**Tests**

- Feature tests use the fixture pack in `tests/Fixtures/content-pack` and in-memory SQLite.
- Add a test with every behaviour change.

## Before you open a pull request

```sh
php artisan test
vendor/bin/pint --test        # or vendor/bin/pint to fix the style
vendor/bin/phpstan analyse    # level 5, no new ignores
```

- Keep each pull request to one topic, with a clear description of what changes and why.
- Write commit messages in the imperative mood ("Add …", "Fix …"), with a short first line.
- Update the documentation and `CHANGELOG.md` when behaviour changes.
- A maintainer reviews every pull request. We may ask for changes, or decline a change that does
  not fit the engine's scope.

## Code of conduct

We follow the [Contributor Covenant](https://www.contributor-covenant.org/version/2/1/code_of_conduct/),
version 2.1. Be respectful and assume good faith. Report unacceptable behaviour through
the [contact form](https://redact.cetome.com/contact).
