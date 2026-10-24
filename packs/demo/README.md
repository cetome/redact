# REDact demo pack (invented)

A small, **fully invented** content pack so that the open-source REDact engine runs end to end
without cetome's private pack. Use it to try the software, to develop the engine, and as a worked
example when you write your own pack (format: [docs/CONTENT-PACK.md](../../docs/CONTENT-PACK.md)).

> **Not for real assessments.** Nothing here comes from a standard or from a real assessment
> method: the three "standards" (DEMO-NET, DEMO-PRIV, DEMO-PAY), their requirements, decision
> trees, documentation items, assessment criteria, threats and catalogue were written for this
> demo. A product assessed with this pack is not assessed against anything. The article codes
> 3.3 d, e and f only name the articles of the RED Delegated Regulation the demo standards are
> attached to.

Use it with:

```dotenv
REDACT_CONTENT_PATH=packs/demo
```

## What it contains

| File | Content |
|---|---|
| `pack.json` | id `demo`, three invented standards on articles 3.3 d/e/f, families ACC, UPD, DAT, AI context |
| `scoping.json` | four scoping questions (network, updates, personal data, payments) and two demo restrictions at the conformity step |
| `lists.json` | assets, interfaces, software, two relations |
| `requirements.json` | ACC-1 (per asset, all three standards), UPD-1 (once for the product), DAT-1 (per personal-data asset) |
| `trees/` | three invented decision trees (ACC-1 is shared by the three standards) |
| `assessments/` | demo assessment criteria for ACC-1 and DAT-1 (justification rules, assessment cases) |
| `einfo.json`, `einfo-prefill.json`, `einfo-variants.json` | five documentation items, pre-fill from the inventory, a variants example |
| `risk.json` | five-level matrix, STRIDE labels, six demo threats |
| `catalogue.json` | a dozen everyday items to pick from, two groups, the picker ranking |
| `ai/prompts/` | neutral wording of the AI instructions |
| `rules.php`, `src/DemoRules.php` | `GenericRules` plus two demo rules (restrictions, DAT-1 only for personal data) |

## Checking it

```bash
REDACT_TEST_PACK=packs/demo php artisan test --filter=ContentPackSmoke
```

`tests/Feature/Workflow/DemoPackTest.php` runs the same walk-through on this pack in every test
run.

Licence: AGPL-3.0-only, like the engine.
