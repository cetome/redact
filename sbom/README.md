# SBOM and VEX of the REDact engine

We ask manufacturers to know what their products contain and whether known vulnerabilities affect
them. We do the same for REDact.

| File | What it is |
|---|---|
| [`redact-engine.cdx.json`](redact-engine.cdx.json) | **SBOM**, CycloneDX 1.6 JSON: every component the engine ships, with versions, purls, licences, hashes and the dependency graph. |
| [`redact-engine.vex.cdx.json`](redact-engine.vex.cdx.json) | **VEX**, CycloneDX 1.6 JSON: the known vulnerabilities that affect those components and their status. An empty list means none was known on the date in the file. |

**What is listed**: the production PHP packages (`composer.lock`, without development packages) and
the JavaScript libraries bundled into the front end (`@alpinejs/csp`, `@laravel/passkeys`, `altcha`,
`chart.js` and what they pull in). Build tools (Vite, Tailwind CSS) are not shipped, so they are not
listed.

**How the VEX is made**: every component is looked up by purl in [OSV](https://osv.dev), a free
public database covering Packagist and npm. Each vulnerability found is recorded as "in triage"
until a person writes the analysis (in `vex-analysis.json`). Nothing is marked "not affected"
without a written justification.

Both files are regenerated for every release:

```sh
php scripts/sbom.php            # SBOM + VEX
php scripts/sbom.php --offline  # SBOM only
```
