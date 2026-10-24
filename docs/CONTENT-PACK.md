# Content pack format

REDact is an **engine**. Everything that belongs to an assessment method lives in a separate
**content pack**: the scoping questionnaire, the inventory model, the requirements and their
decision trees, the documentation items (E.Info) and the rules that place them, the threat
library, the inventory catalogue and the wording of the AI instructions. The engine reads a pack
at run time from `REDACT_CONTENT_PATH` and never hard-codes its content.

This document describes every file and every key the engine reads, so that anyone can write a
pack.

> **Only invented packs belong in this repository.** It ships `packs/demo/` (the worked example
> used by self-hosted installs) and `tests/Fixtures/content-pack/` (used by the automated tests).
> Both are invented from scratch. Never commit a real method, text copied from a standard
> (requirements, decision trees, assessment criteria, notes), or a commercial pack to this
> repository, its tests, fixtures, comments or issues. Standards are copyrighted: a pack that
> transcribes one must stay private to whoever holds the right to use it.

Contents:

1. [Loading a pack](#1-loading-a-pack)
2. [Layout](#2-layout)
3. [Translatable text](#3-translatable-text)
4. [pack.json](#4-packjson)
5. [scoping.json](#5-scopingjson)
6. [lists.json](#6-listsjson)
7. [requirements.json](#7-requirementsjson)
8. [trees/](#8-trees)
9. [einfo.json, einfo-prefill.json, einfo-variants.json](#9-einfo)
10. [assessments/](#10-assessments)
11. [risk.json](#11-riskjson)
12. [catalogue.json](#12-cataloguejson)
13. [ai/prompts/](#13-aiprompts)
14. [templates/](#14-templates)
15. [rules.php and the PackRules contract](#15-rulesphp-and-the-packrules-contract)
16. [Caching and deployment](#16-caching-and-deployment)
17. [Security](#17-security)
18. [Validating a pack](#18-validating-a-pack)

---

## 1. Loading a pack

```dotenv
REDACT_CONTENT_PATH=packs/demo          # relative to the application root, or absolute
```

`App\Providers\AppServiceProvider` binds `App\Content\ContentPack` as a singleton for that path.
There is no fallback: without `pack.json` at that path the application refuses to start, so it
can never show placeholder content by accident. The tests set the path to
`tests/Fixtures/content-pack` in `phpunit.xml`.

All engine code reads the pack through `App\Content\ContentPack` and its `rules()`
(`App\Content\Contracts\PackRules`). Controllers expose only what a page needs: the pack is never
sent to the browser wholesale.

Each product version stores the pack reference (`<id>@<version>`, e.g. `demo@1.0.0`) for
traceability.

## 2. Layout

| Path | Required | Read by |
|---|---|---|
| `pack.json` | yes | `ContentPack` (id, version, standards, articles, families, AI context) |
| `scoping.json` | yes | `App\Assessment\ScopingForm`, the scoping and conformity pages, the rules |
| `risk.json` | yes | risk matrix seed, threat library (`App\Assessment\ThreatSuggestions`) |
| `lists.json` | yes | inventory lists, fields, relations (`App\Assessment\InventoryFields`, `InventoryGuide`) |
| `requirements.json` | yes | requirements, the lists they loop over |
| `trees/<standard>/<REQ>.json` | one per walked requirement | `App\Content\Tree` |
| `rules.php` | yes | `ContentPack::rules()` |
| `einfo.json` | optional | E.Info catalogue |
| `einfo-prefill.json` | optional | `App\Assessment\EinfoPrefill` |
| `einfo-variants.json` | optional | `App\Assessment\Justification::variants()` |
| `assessments/<standard>/<REQ>.json` | optional | `Justification`, `AssessmentCases`, `CriteriaText` |
| `catalogue.json` | optional | inventory picker (`App\Assessment\CatalogueSuggestions`) |
| `ai/prompts/<key>.md` | optional | `App\Ai\Prompts\PackPrompts` |
| `templates/mapping.json`, `templates/*.xlsx` | optional | `App\Workbooks` (workbook export and import) |
| `templates/free/free.json`, `templates/free/*.xlsx` | optional | free workbook downloads |

A pack may carry any other file (its own PHP classes under `src/`, tools, reports, a README): the
engine ignores what it does not read.

## 3. Translatable text

Wherever this document says *text*, the value is either a plain string or a locale map:

```json
"label": "The product connects to a network"
"label": { "en": "The product connects to a network", "fr": "Le produit se connecte à un réseau" }
```

`App\Content\Text::of()` returns the current locale, else the fallback locale (`en`), else the
first value. The interface ships in English and French.

Some values are shown as they are, without translation: tree node `text`, branch `label`, tree
`clause` and `figure`, catalogue `source`, E.Info codes and requirement codes.

## 4. pack.json

```json
{
  "id": "demo",
  "name": { "en": "REDact demo pack (invented)", "fr": "Pack de démonstration REDact (inventé)" },
  "version": "1.0.0",
  "description": "Invented sample method. No text from any standard.",
  "standards": [
    { "code": "demo-net", "name": "DEMO-NET (invented)", "article": "3.3d" },
    { "code": "demo-priv", "name": "DEMO-PRIV (invented)", "article": "3.3e" }
  ],
  "articles": [
    { "code": "3.3d", "name": { "en": "3.3 d) Protection of the network" } },
    { "code": "3.3e", "name": { "en": "3.3 e) Protection of personal data and privacy" } }
  ],
  "families": {
    "ACC": { "name": "Access (demo)" },
    "UPD": { "name": "Updates (demo)" }
  },
  "ai": { "system_context": "Optional methodology context appended to every AI call." }
}
```

| Key | Meaning |
|---|---|
| `id`, `version` | Required. The reference `<id>@<version>` is stored on each product version. Bump `version` on every change. |
| `name` | Text, shown as the method's name. Defaults to `id`. |
| `description` | Free text for people. Not read by the engine. |
| `standards[]` | Required. `code` (a slug, also the folder name under `trees/` and `assessments/`, `[A-Za-z0-9._-]` only), `name` (text), `article` (the article code that brings it into scope with `GenericRules`). |
| `articles[]` | `code` and `name` (text). Shown on the scoping, risk and report pages. |
| `families` | Map of family code => `{ "name": text }`. Groups requirements on the tree index, the evaluations list and the reports, and is accepted as a risk "mechanism". |
| `ai.system_context` | Optional text (English is used) appended to every AI call as "Methodology context". Capped at 20,000 characters. |

## 5. scoping.json

The questionnaire, in sections. A section is asked in one workflow step: `"step": "scoping"` at
the start (the default) or `"step": "conformity"` at the end, before the conformity route is
chosen.

```json
{
  "sections": [
    {
      "key": "applicability",
      "title": "Applicability",
      "intro": "Tick what the product does.",
      "step": "scoping",
      "groups": [
        {
          "key": "network",
          "title": "Network",
          "questions": [
            {
              "key": "connected",
              "type": "boolean",
              "label": "The product connects to a network",
              "statement": "Any wired or wireless network, even a local one.",
              "example": "Wi-Fi, Ethernet, a phone app over Bluetooth",
              "articles": ["3.3d"]
            }
          ]
        }
      ]
    },
    {
      "key": "restrictions",
      "title": "Restrictions",
      "step": "conformity",
      "groups": [
        {
          "key": "defaults",
          "title": "Default settings",
          "lead": "Asked because the product connects to a network.",
          "questions": [
            { "key": "no_default_secret", "type": "boolean", "label": "No shared default secret",
              "show_if": { "article": "3.3d" } }
          ]
        }
      ]
    }
  ],
  "restriction_labels": { "en18031": "Decision trees", "defaults": "Default settings" }
}
```

Question keys are unique across the whole file: answers are stored by key.

| Key | Meaning |
|---|---|
| `sections[].key`, `title`, `intro` | Section identity and texts. |
| `sections[].step` | `scoping` (default) or `conformity`. |
| `groups[].key`, `title` | A card or a block of the page. On the conformity page the group key is the restriction key the rules return (see below). |
| `groups[].lead` | Text, conformity step: why the group is asked. |
| `questions[].key` | Required, unique. |
| `questions[].type` | `boolean` (default, a switch, unticked = false), `select` (with `options: [{ "value", "label" }]`, only listed values are kept) or any other type, read as free text (2,000 characters at most). Only boolean questions are offered to the AI scoping suggestion. |
| `questions[].label`, `statement`, `example` | Texts. `statement` is the tooltip and is also sent to the AI with the label. |
| `questions[].articles` | Article codes a "yes" brings into scope (with `GenericRules`, and for the live result on the page). |
| `questions[].show_if` | `{ "article": "3.3d" }` (visible when that article is in scope) or `{ "question": "<key>", "equals": true }`. Answers to hidden questions are dropped on save. |
| `restriction_labels` | Map of restriction key => text, the label of each restriction on the conformity page and in the reports. |

A pack may add keys of its own (for instance localised messages its rules build into
`ScopingResult::$messages`): the engine ignores them.

The conformity step also uses one engine key: the rules receive `_en18031_full` in the answers
(`true` all trees walked with no FAIL, `false` a FAIL, `null` still to walk) and may report it as
the restriction `en18031`, labelled by `restriction_labels.en18031`.

## 6. lists.json

The inventory model: the lists of objects a product is described with, their fields, and the
relations (mappings) between lists.

```json
{
  "lists": [
    {
      "key": "assets",
      "label": "Assets",
      "singular": "Asset",
      "code_prefix": "AS-",
      "help": "What is worth protecting.",
      "fields": [
        { "key": "type", "label": "Type", "type": "select", "required": true,
          "options": [
            { "value": "secret", "label": "Secret", "help": "Keys, passwords", "standards": ["demo-net"] },
            { "value": "personal", "label": "Personal data", "standards": ["demo-priv"] }
          ] },
        { "key": "stored", "label": "Stored on the product", "type": "boolean" },
        { "key": "notes", "label": "Notes", "type": "textarea", "help": "Anything a reviewer should know." }
      ]
    },
    { "key": "interfaces", "label": "Interfaces", "singular": "Interface", "code_prefix": "IF-", "fields": [] }
  ],
  "relations": [
    { "key": "asset_interface", "from": "assets", "to": "interfaces", "label": "Reachable through" }
  ]
}
```

| Key | Meaning |
|---|---|
| `lists[].key` | Required, unique, referenced by requirements, E.Info, relations and the catalogue. Lists appear in file order. |
| `label`, `singular`, `help` | Texts. |
| `code_prefix` | Prefix of the codes the engine proposes (`AS-1`, `AS-2`…). |
| `fields[].key`, `label`, `help` | Field identity and texts. Values are stored in the object's `attributes`. |
| `fields[].type` | `text` (500 characters), `textarea` (10,000), `select` (one option value), `multiselect` (a list of option values), `boolean`, `yesno` (`yes` / `no` / `na`). |
| `fields[].required` | Required on save (booleans are never required). |
| `fields[].options[]` | `value`, `label` (text), optional `help` (text) and `standards` (codes): with either, a select is shown as cards, and a card whose standards are all out of scope is marked so. |
| `relations[]` | `key`, `from`, `to` (list keys), `label` (text). Each relation is a mapping page; links can be read both ways. |

A list a requirement or an E.Info item loops over is shown only when one of those belongs to a
standard in scope; a list used by none (only through relations) is always shown.

## 7. requirements.json

A list of requirements, in display order.

```json
[
  { "code": "ACC-1", "family": "ACC", "title": "Access to an asset (demo)",
    "standards": ["demo-net", "demo-priv"], "list": "assets" },
  { "code": "UPD-1", "family": "UPD", "title": "Software updates (demo)",
    "standards": ["demo-net"], "list": null,
    "follows_from": [{ "requirement": "ACC-1", "verdicts": ["FAIL"] }] }
]
```

| Key | Meaning |
|---|---|
| `code` | Required, unique. Also the file name of its trees and criteria. |
| `family` | A key of `pack.json` `families`. |
| `title` | Text. |
| `standards` | The standards the requirement belongs to (one tree per standard). |
| `list` | The list its tree is walked for (one walk per object), or `null` for one walk for the product. Used by `GenericRules::evaluationTargets()` and to name the targets. |
| `follows_from` | `GenericRules::followsFrom()`: walks of another requirement that settle this one (here: an object whose ACC-1 walk ends FAIL is not walked for UPD-1, shown as "FAIL · follows from ACC-1"). |

Packs may add keys their own rules read (prerequisites, notes…): the engine ignores them.

## 8. trees/

One file per requirement and standard: `trees/<standard code>/<requirement code>.json`, format
`redact.tree/1`. Identical trees in several standards are walked once and kept in sync
(`App\Assessment\SharedWalks`, same start, decision nodes, branch labels and targets).

```json
{
  "schema": "redact.tree/1",
  "standard": "demo-net",
  "requirement": "ACC-1",
  "clause": "D.1",
  "figure": "Demo tree 1",
  "title": "Access to an asset (demo)",
  "scope": "For each asset",
  "start": "DN-1",
  "nodes": [
    { "id": "DN-1", "kind": "decision", "text": "Can the asset be reached from outside the product?",
      "help": "Optional note shown to the AI with the question.",
      "branches": [ { "label": "No", "to": "R-NA" }, { "label": "Yes", "to": "DN-2" } ] },
    { "id": "DN-2", "kind": "decision", "text": "Does the product check who is asking first?",
      "branches": [ { "label": "Yes", "to": "R-PASS" }, { "label": "No", "to": "R-FAIL" } ] },
    { "id": "R-NA", "kind": "result", "result": "NOT APPLICABLE" },
    { "id": "R-PASS", "kind": "result", "result": "PASS" },
    { "id": "R-FAIL", "kind": "result", "result": "FAIL" }
  ]
}
```

| Key | Meaning |
|---|---|
| `schema` | `redact.tree/1`. |
| `standard`, `requirement` | Must match the file path. |
| `clause`, `figure` | Optional references shown on the tree page. |
| `title`, `scope` | Texts. `scope` ("For each …") is shown above the tree. |
| `start` | Id of the first node. |
| `nodes[].kind` | `decision` or `result`. |
| decision `id` | Name decision nodes `DN-<n>`: the engine sorts them naturally, reads the number at the end and shows them as `DT.<REQ>.DN-<n>`. |
| decision `text`, `help` | The question (plain string) and an optional note. |
| decision `branches[]` | `label` (the answer, matched case-insensitively when walking) and `to` (a node id). |
| result `result` | `PASS`, `FAIL` or `NOT APPLICABLE`. |

The engine records, per walk, the path, the last decision node and the verdict. A verdict is
accepted only if a branch of the last decision node leads directly to that result. People may
also mark a walk INCONCLUSIVE while evidence is missing: that is an engine state, never a tree
result.

## 9. E.Info

### einfo.json

The documentation items an assessment may ask for (a list).

```json
[
  { "code": "E.Info.ACC-1.Access", "requirement": "ACC-1", "standards": ["demo-net", "demo-priv"],
    "list": "assets", "label": "Who can reach the asset and how the product checks it (demo)." },
  { "code": "E.Info.UPD-1.Delivery", "requirement": "UPD-1", "standards": ["demo-net"],
    "list": null, "label": "How updates reach the product (demo)." }
]
```

| Key | Meaning |
|---|---|
| `code` | Required, unique. Codes start with `E.Info.`; by convention `E.Info.<REQ>.<Name>`. |
| `requirement` | The requirement it documents (links the E.Info page to the walk). |
| `standards` | Standards where it is asked. |
| `list` | Documented once per object of that list, or `null` for the product. |
| `label` | Text, the field's label (also sent to the AI draft). |

Which items an object must document is decided by the rules (`requiredEinfo()` and
`conditionalEinfo()`), not by this file. Two ids are engine-defined and never listed here:
`E.Info.DT.<REQ>` (the walked path) and `E.Just.DT.<REQ>` (its justification).

### einfo-prefill.json

Texts the E.Info page offers in an empty field, built from the inventory. Nothing is saved until a
person saves the field (it is then marked "From inventory").

```json
{
  "schema": "redact.einfo-prefill/1",
  "rules": [
    { "ids": ["E.Info.ACC-1.Access"], "from": "description" },
    { "ids": ["E.Info.ACC-1.Exposure"], "from": "links", "list": "interfaces", "where": { "kind": "network" } }
  ]
}
```

* `from: description`: the object's own description.
* `from: links`: the objects of `list` linked to the object through any relation of the pack
  (either direction), filtered by `where` (attribute => value, a multiselect matches when it
  contains the value), one `CODE · Name` per line.

The first rule naming a code wins. Other keys (e.g. a `source` note) are ignored.

### einfo-variants.json

Optional. Words that are interchangeable inside E.Info codes, so that a code printed for one kind
of object in the assessment criteria is also found under the object's own kind.

```json
{
  "schema": "redact.einfo-variants/1",
  "variants": [
    { "pattern": "/^(.*?)(?<v>Secret|Personal)(Assets?)$/", "values": ["Secret", "Personal"] },
    { "pattern": "/^(?<v>UserPort|NetworkPort)$/", "values": ["UserPort", "NetworkPort"] }
  ]
}
```

`App\Assessment\Justification::variants()` splits the code on dots and reads the segments after
`E.Info.`. For each segment the first `pattern` (a PCRE with a named group `v`) that matches
applies: the text of group `v` is replaced by each other value, in the order given. With the
example, `E.Info.ACC-1.SecretAsset.Public` is also looked up as
`E.Info.ACC-1.PersonalAsset.Public`. Without the file there are no variants.

## 10. assessments/

Optional, one file per requirement and standard: `assessments/<standard>/<REQ>.json`. It drives
three features: the criteria panel of a walk, the **justification check** (a verdict counts only
once the documentation its rule names is written) and the **assessment cases** of the Review step.
Files are read on demand, one at a time.

```json
{
  "requirement": "ACC-1",
  "standard": "demo-net",
  "clause": "D.1.4",
  "objective": { "en": "Show that access to each asset is checked (demo)." },
  "implementation_categories": { "en": "- Sample category\n  - Sample sub-category" },
  "required_information": [
    { "id": "E.Info.ACC-1.Access", "text": { "en": "How access is checked (demo)." }, "condition": null, "parent": null }
  ],
  "justification": [
    { "node": "DN-1", "outcome": "NOT APPLICABLE", "based_on": ["E.Info.ACC-1.Exposure"], "match": "any",
      "text": { "en": "Unreachable assets are justified by their exposure note (demo)." } },
    { "node": "DN-2", "outcome": null, "based_on": ["E.Info.DT.ACC-1", "E.Just.DT.ACC-1"], "match": "all",
      "text": { "en": "The decision at DN-2 is justified by the walk and its justification (demo)." } }
  ],
  "cases": [
    { "key": "conceptual", "clause": "D.1.4.1",
      "purpose": { "en": "Check the documented decisions (demo)." },
      "preconditions": { "en": "None." },
      "units": { "en": "- Review each walk" },
      "verdict": { "PASS": { "en": "…" }, "FAIL": { "en": "…" }, "NOT APPLICABLE": { "en": "…" } } }
  ]
}
```

| Key | Meaning |
|---|---|
| `objective`, `implementation_categories` | Texts shown with the walk (markdown-like lists with `- `). |
| `required_information[]` | `id`, `text`, optional `condition` (text), `parent` (id), `note`: shown as the information the requirement asks for. |
| `justification[].node` | The decision node the rule is about. |
| `justification[].tree_node` | Optional: the tree node the printed `node` maps to when they differ, `null` = cannot be mapped (shown, never checked). |
| `justification[].outcome` | `PASS`, `FAIL`, `NOT APPLICABLE` or `null` (any outcome). A rule applies when its node is on the walked path and, with an outcome, the walk ends with that outcome. |
| `justification[].based_on` | E.Info ids (and `E.Info.DT.<REQ>`, `E.Just.DT.<REQ>`). Ids starting with `IC.` make the rule informative. |
| `justification[].match` | `any` (default) or `all` of the ids that are placed for this object. |
| `justification[].informative` | `true`: shown, never checked. |
| `justification[].text` | Text of the rule. |
| `cases[].key` | `conceptual`, `conceptual_completeness`, `functional_completeness`, `functional_sufficiency` (shown in that order), or any other key (humanised). |
| `cases[]` `clause`, `purpose`, `preconditions`, `units`, `verdict` | Texts (`verdict` per outcome). A case with none of `purpose`, `preconditions`, `units`, `verdict` is informative (a `text` only, nothing to record). |

An id of a rule counts when the rules place it on the object or the product (or, see
`relatedEinfoObjects` below, on a linked object). When none is placed, the rule is shown "to
check" by a person: never a silent pass. A justification text shorter than 20 characters never
counts.

## 11. risk.json

```json
{
  "scale": [1, 2, 3, 4, 5],
  "levels": [
    { "rank": 1, "name": "Very high", "color": "#DC2626" },
    { "rank": 2, "name": "High", "color": "#EA580C" },
    { "rank": 3, "name": "Medium", "color": "#CA8A04" },
    { "rank": 4, "name": "Low", "color": "#16A34A" },
    { "rank": 5, "name": "Very low", "color": "#0284C7" }
  ],
  "matrix": { "5": [3, 2, 2, 1, 1], "4": [4, 3, 2, 2, 1], "3": [4, 3, 3, 2, 2], "2": [5, 4, 3, 3, 2], "1": [5, 5, 4, 4, 3] },
  "stride": { "S": "Spoofing", "T": "Tampering", "R": "Repudiation", "I": "Information disclosure", "D": "Denial of service", "E": "Elevation of privilege" },
  "threats": [
    { "threat": "Update replaced in transit (demo)", "description": "…", "stride": ["T"],
      "articles": ["3.3d"], "suggest_when": ["updates"], "suggest_note": "Asked because the product receives updates.",
      "requirements": ["UPD-1"], "impact": 4, "likelihood": 3, "residual_rank": 4,
      "remediation": "…", "risk": "…",
      "examples": [{ "title": "Public write-up (demo)", "year": 2024, "url": "https://example.com/" }] }
  ]
}
```

| Key | Meaning |
|---|---|
| `scale` | Rating scale shown in the reports (1 to 5). |
| `levels` | Exactly five levels, rank 1 the highest, `name` (text) and `color` (`#RRGGBB`). Copied into each new version, then editable per version. |
| `matrix` | Impact (`"1"`…`"5"`) => five level ranks, one per likelihood 1…5. Copied into each new version. |
| `stride` | Letter => text. The letters themselves are fixed (`S T R I D E`). |
| `threats[]` | The threat library. `threat` (text, required), `description`, `risk`, `remediation` (texts), `stride` (letters), `articles` (article codes), `requirements` (family or requirement codes), `impact`, `likelihood`, `residual_rank` (1–5), `examples` (`title`, `year`, `url`: public incidents), `suggest_when` (scoping keys), `suggest_note` (text). |

Threat suggestion (`ThreatSuggestions`): a threat whose `suggest_when` question was answered yes,
and whose article is in scope, is pre-ticked; a threat without `suggest_when` whose article is in
scope is listed; threats already in the register are flagged.

## 12. catalogue.json

Optional ready-made objects people pick instead of typing, per list, with named groups and the
picker order.

```json
{
  "schema": "redact.catalogue/1",
  "ranking": {
    "order": ["tier", "yes", "presence", "parts"],
    "presence": { "typical": 2, "common": 1 }
  },
  "groups": {
    "assets": [
      { "key": "secrets", "name": { "en": "Secrets" }, "match": { "type": "secret" } }
    ]
  },
  "lists": {
    "assets": [
      { "name": { "en": "Device password", "fr": "Mot de passe de l’appareil" },
        "description": { "en": "The password that unlocks the settings (demo)." },
        "attributes": { "type": "secret", "stored": true },
        "standards": ["demo-net"],
        "presence": "typical",
        "suggest_when": ["connected"],
        "suggest_note": "Usual on connected products.",
        "source": "demo pack" }
    ]
  }
}
```

Items (`lists.<list key>[]`):

| Key | Meaning |
|---|---|
| `name`, `description` | Texts, copied into the new object (in the person's language). |
| `attributes` | Field key => value, using the list's field types and option values. Set only what is known: the person completes the rest. |
| `standards` | Standards where the item is usual. Empty or absent: usual everywhere. |
| `presence` | `typical`, `common` or absent: how often the item is found, shown as a badge and used by the ranking. |
| `suggest_when` | Scoping keys: the item is pre-ticked when one was answered yes. A key of a later step not answered yet does not hold it back. |
| `suggest_note` | Text: why those answers make it relevant. |
| `source` | Where the item comes from, shown to the person. |

An item is *suggested* when one of its `standards` is in scope and (with `suggest_when`) a linked
question is yes: pre-ticked when linked to a yes answer (`answer`), listed otherwise
(`standard`). Items already in the inventory (same name in any language, also as a group member)
are flagged, not suggested.

`ranking` (optional) decides the picker's order: `order` lists the keys compared in turn, best
first, among `tier` (linked to an answer, then usual for the standards in scope, then the rest),
`yes` (more yes answers first), `presence` (the item's presence rank) and `parts` (more in-scope
standards first). The catalogue order settles the rest. `presence` gives the rank of each presence
value (higher first). Without `ranking` the engine orders by `tier`, then `presence`
(`typical` 2, `common` 1), then catalogue order. Unknown keys are ignored.

`groups.<list key>[]` (optional): `key`, `name` (text) and `match` (attribute values an item must
carry). Among the items put forward, two or more with exactly the same attributes matching the
same group are proposed as one grouped object (one walk, one set of E.Info).

## 13. ai/prompts/

Optional wording of the AI instructions, one plain-text UTF-8 file per key (BOM and CRLF are
removed, trailing blank lines trimmed, 20,000 characters at most). An empty or missing file falls
back to the engine's neutral, generic wording: the AI then works, but knows nothing of a
particular method (this is the case with the open demo pack, whose prompts are the generic ones).
Which provider and model answer is not part of the pack: the install chooses it, with its own key
([AI-PROVIDERS.md](AI-PROVIDERS.md)).

| File | Used for |
|---|---|
| `system.md` | The opening paragraph of every call (who the model assists, in which domain). |
| `improve_text.md` | "Improve text with AI". |
| `suggest_scoping.md` | AI scoping suggestion. |
| `check_evaluation.md` | Consistency check of a walk. |
| `suggest_path.md` | Suggested answers for a tree. |
| `draft.md` | "Draft from documentation". |

The engine always adds its own guardrails after the opening paragraph, whatever the pack says:
the AI never gives a verdict or decides a path, uses only the material given, treats documents and
user text as untrusted data, answers only JSON matching the engine's schema, in the interface
language, and never names itself or its provider. The output schemas are fixed by the engine:
a task text must ask for the fields of that schema (see the `GENERIC_TASK` constants in
`app/Ai/Prompts/*Prompt.php` for the expected shape). `pack.json` `ai.system_context` is appended
after the task as methodology context.

## 14. templates/

Optional. Workbooks the engine fills from a version and reads back (`App\Workbooks`):
`templates/mapping.json` names, for each workbook, its file in `templates/`, how to recognise it
(sheet names) and which cells hold which piece of a version (blocks such as fields, scoping,
lists, matrices and risks). The mapping format is defined by `App\Workbooks\Mapping` and is still
evolving: read that class before writing one.

`templates/free/free.json` lists workbooks offered for download without an account:
`{ "zip": "templates.zip", "files": [{ "key": "scoping", "file": "Scoping.xlsx" }] }` (keys
`[a-z0-9-]`, plain `.xlsx` file names next to the manifest). Without these files the engine offers
no workbook.

## 15. rules.php and the PackRules contract

`rules.php` returns an object implementing `App\Content\Contracts\PackRules`: the computational
part of the method. Most packs extend `App\Content\GenericRules` and override what they need.

```php
<?php // packs/demo/rules.php (shape)

use App\Content\GenericRules;
use App\Content\VersionContext;

return new class extends GenericRules
{
    public function evaluationTargets(string $standard, string $requirement, VersionContext $context): array
    {
        $targets = parent::evaluationTargets($standard, $requirement, $context);

        // Example: DAT-1 is walked only for assets holding personal data.
        return $requirement === 'DAT-1'
            ? array_values(array_filter($targets, fn ($item) => $item?->attr('type') === 'personal'))
            : $targets;
    }
};
```

Every method receives a `VersionContext` (except `scoping`), the read-only view of one version:
`items($list)`, `item($id)`, `linked($item, $relation, $reverse = false)`,
`evaluation($standard, $requirement, $item)`, `node(…)` (last decision node reached, e.g.
`"DN-2"`), `nodeNumber(…)` (its number), `scoping($question, $default)` and `allEvaluations()`.
Rules must be pure: read the context, return values, never write.

| Method | Returns | GenericRules default |
|---|---|---|
| `scoping(array $answers, ContentPack $pack): ScopingResult` | `articles` (code => bool), `standards` (codes in scope), `restrictions` (key => satisfied), `selfAssessment` (`true`, `false`, `null` while unknown), `messages` (localised strings). `$answers` holds every stored answer plus `_en18031_full` (see section 5). | An article is in scope when a question naming it is yes, a standard with its article. Restriction `en18031` = `_en18031_full === true`, self-assessment = that value (null while trees remain). |
| `evaluationTargets(string $standard, string $requirement, VersionContext)` | `list<InventoryItem\|null>`: the objects to walk the tree for, `[null]` for one product-level walk, `[]` when it does not apply. | Every object of the requirement's `list`, or `[null]` without a list. |
| `requiredEinfo(string $standard, ?InventoryItem $item, VersionContext)` | E.Info codes the object (or the product, with `null`) must document. | Every code of `einfo.json` for that standard and list, once its requirement has a decision node for the object. |
| `conditionalEinfo(…)` | Codes shown as optional (never counted as missing), none of them required. | `[]`. |
| `followsFrom(string $standard, string $requirement, VersionContext)` | `list<{item, requirement, state}>`: walks settled by another walk, `state` `fail` (fails with the source) or `unlinked` (the source calls for objects not listed or linked yet). | From `requirements.json` `follows_from`. |
| `innerTargets(string $standard, string $requirement, ?InventoryItem $item, VersionContext)` | `list<InventoryItem>`: the objects of an inner "for each" loop, shown with the walk. | `[]`. |

Optional method, probed with `method_exists`:

* `relatedEinfoObjects(string $standard, string $code, ?InventoryItem $item, VersionContext $context)`:
  `list<{item: InventoryItem, state: string}>`, the objects other than the walked one where the
  rules place `$code` (state `required` when it must be written there). The justification check
  then accepts the text written on those objects.

Whitelisting: the engine validates every value it receives from a form against the pack (list
keys, field keys, option values, question keys, requirement and E.Info codes), so a rule only ever
sees known keys.

## 16. Caching and deployment

* The JSON files at the pack root and `trees/*/*.json` are loaded once and cached
  (`Cache::rememberForever`) under a fingerprint of their paths, modification times and sizes:
  deploying a changed JSON file is picked up on the next request.
* Each `assessments/` file and `templates/mapping.json` is cached on its own fingerprint.
  `ai/prompts/` files are re-read when their modification time or size changes.
* `rules.php` (and any PHP it includes) is loaded with `require`: after changing it, reload
  PHP-FPM (or clear OPcache) so the old code is dropped.
* Put the pack **outside the web root** and make it readable by the PHP user only.

## 17. Security

* **A pack is trusted code.** `rules.php` runs as PHP inside the application with its full
  rights, and `einfo-variants.json` patterns run as regular expressions. Install only packs from
  a source you trust, as you would a Composer package.
* The engine never sends the pack to the browser wholesale: pages receive only what they show
  (the questions of a step, the tree being walked, the fields of a list). The AI receives only
  what a feature needs (for instance the scoping questions, never the rules that turn answers into
  articles and standards).
* Pack texts are printed escaped; they cannot inject HTML or scripts.

## 18. Validating a pack

`tests/Feature/Workflow/ContentPackSmokeTest.php` drives the whole workflow on any pack, using
only the pack's own definitions: every scoping question answered, two objects per list with every
field filled in, every relation linked, the first branch of each tree walked for each target,
then every page and report rendered.

```powershell
$env:REDACT_TEST_PACK="packs/demo"; php artisan test --filter=ContentPackSmoke
```

```sh
REDACT_TEST_PACK=packs/demo php artisan test --filter=ContentPackSmoke
```

Also check by hand: every requirement of a standard has a tree in `trees/<standard>/`, every
`list`, field key and option value used in `requirements.json`, `einfo.json`, `catalogue.json`
and your rules exists in `lists.json`, every `suggest_when` key exists in `scoping.json`, and
every decision node reaches a result. Bump `pack.json` `version` with each change so that versions
assessed with the old pack stay traceable.
