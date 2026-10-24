# REDact app UI kit

`resources/css/app-ui.css` (imported by `app.css` after `cetome.css`) is the look of the signed-in
application: dense, quiet, keyboard friendly, light and dark. It ports FAST's app components
(`fast.cetome.com-v2/css/fast.css`: workspace steps, toolbar, filter buttons, tags, coloured
selects, side panels, dialog, empty states, figures, product cards, risk matrix) and CRAted's
conventions (compact sticky top bar, left sidebar, segmented controls, verdict chips, micro
labels) onto the cetome tokens (`bg-canvas`, `text-ink`, `border-line`, `--c-primary` …), so every
component follows the theme.

**Prefix `a-`** (app). The `c-*` classes of `cetome.css` stay for the public pages (hero, footer,
marketing headings). On app pages never use `c-h1`, `c-h2`, `c-kicker`, `c-stat`, `c-table`,
`c-card`: use `a-title` (via `<x-ui.page-header>`), `a-h2`, `a-micro`, `a-stat`, `a-table`,
`a-panel`.

Strict CSP holds: no inline style anywhere (bars are `<progress>`, per-version colours go in a
nonce'd `<style>` block like `risks/index`), no inline script (behaviour in
`resources/js/modules/ui.js`, plain delegated listeners that need no Alpine).

## Shell and workspace

| Piece | How |
|---|---|
| Layout | `<x-layouts.app :title>` renders the top bar, the page and a one-line footer. Pass `:product="$product"` for the breadcrumb on product pages. |
| Version workspace | `<x-slot:subnav><x-version-nav :version="$version" active="risks" /></x-slot:subnav>` registers the version: the layout then shows the breadcrumb `Products / Product ⇕ / Version` and the left sidebar `<x-ui.sidebar>` (steps of `Assessment::steps()` via `App\Support\WorkflowSummary`, with counts and bars). `active` = a step key (description, risks, scoping = Essential requirements, inventory, evaluate, einfo, conformity, review) or overview, ai, reports, team. |
| Top bar | `.a-topbar` (56px, sticky, blurred), `.a-topbar-brand`, `.a-crumbs` (only the last crumb on phones), `.a-topnav` + `.a-topnav-link[aria-current]`, `.a-topbar-actions` |
| Menus | `<details class="a-menu"><summary>…</summary><div class="a-menu-panel">` with `.a-menu-head`, `.a-menu-item`, `.a-menu-sep` (no script) |
| Sidebar | `.a-sidebar`, `.a-sidebar-product`, `.a-sidebar-label`, `.a-steps`, `.a-step` (`.is-done`, `[aria-current]`), `.a-step-no`, `.a-step-label`, `.a-step-sub`, `.a-step-count`, `.a-substeps`/`.a-substep` (one line per standard). Below 64rem it folds behind `.a-sidebar-toggle` (`data-disclosure`); without script it is always shown. |
| Page column | `.a-main` (+ `.a-main-narrow` 80rem centred when there is no sidebar) |

## Page structure

- `<x-ui.page-header :title :subtitle :muted>` with `<x-slot:eyebrow>` (small blue caps line: standard, organisation) and `<x-slot:actions>` (buttons, right). Title = `.a-title` (22–24px, sentence case, cetome square).
- `.a-section` + `.a-section-head` + `.a-h2` / `.a-h3`, `.a-micro` (10px black uppercase label), `.a-muted`, `.a-code` (ids like `AS-01`).
- `.a-panel` (+ `-head`, `-body`, `-foot`; an `<a class="a-panel">` lifts on hover), `.a-collapse` (`<details>` section with chevron), `.a-dl` (key/value), `.a-grid-main` (content + 20rem aside from 80rem), `.a-stack`.
- `.a-next` panel: "Next step … Continue".
- Eyebrow of every version page: `<x-slot:eyebrow><x-ui.version-label :version="$version" :standard="$standard" /></x-slot:eyebrow>` (Product · Version 2.0 · standard).
- `.a-aside-sticky`: the right column of `.a-grid-main` stays in view (live result, outcome, the Save button). `.a-outcome` (+ `.is-ok` / `.is-bad`, `.a-outcome-title`): result panel with a coloured top edge.
- `.a-more`: "+N more" disclosure in a table cell (activity changes).

## Controls

| Component | Classes / Blade |
|---|---|
| Buttons | `.a-btn` + `-primary` / `-outline` / `-ghost` / `-danger`, sizes `-sm` (28px) `-icon` `-block`; `.a-link-btn` for text buttons. 34px high, cetome uppercase letters. |
| Inputs | `.a-field` > `.a-label` (+ `.a-label-hint`) + `.a-input` / `.a-select` / `.a-textarea` (36px, boxed, focus ring, `aria-invalid`) + `.a-help` / `.a-error`; `.a-input-sm`; `.a-form-grid` (2 columns from 40rem, `.a-span-all`); `.a-check`; `.a-fieldset` |
| Toolbar | `.a-toolbar` (+ `.a-toolbar-end`, `.a-toolbar-count`), `<x-ui.search target="#rows" />` (filters `[data-filter-item]`; `[data-filter-count]`, `[data-filter-empty]`, `[data-filter-group]` in a `[data-filter-scope]`) |
| Segmented control | `.a-seg` > `.a-seg-btn` (`aria-current`, `aria-pressed="true"` or `.is-active`), `.a-seg-count` |
| Tabs | `.a-tabs` > `.a-tab[aria-current]` + `.a-tab-count` |
| Toggle chips | `<label class="a-chip-toggle"><input type="checkbox" …><span>…</span></label>`: a real checkbox, filled blue when checked |
| Letter chips | `.a-letters` > `.a-letter.is-on` (display); `.a-letter-toggle` > input + `<b>S</b>` word (form) |
| Toggle card | `.a-toggle-card` (+ `.a-toggle-card-text`): a whole box that is a checkbox or radio |
| Scoping cards | `.a-scope-groups` > `fieldset.a-scope-group` (legend `.a-micro`) > `label.a-scope-card` (checkbox, `.a-scope-card-box`, `.a-scope-card-label`, `.a-scope-card-example`): the cetome.com RED scoping, blue when ticked; live result rows `.a-scope-result` > `.a-scope-std` (`.is-in`, `.a-scope-std-state`) |
| Route cards | `.a-route-cards` > `label.a-route-card` (radio, `.a-route-card-title`, `.a-help`, `.a-route-card-note` + `.is-bad`; `.is-disabled` when the rules refuse it) |
| Picker | `[data-picker]` `.a-picker` > `.a-picker-search` (`data-filter`) + `.a-picker-selected[data-picker-summary]` + `.a-picker-list` (`.a-picker-group`): searchable checkbox list; checked ones show as removable chips |
| Heat map | `.a-heat` > `.a-heat-axis`, `label.a-heat-cell` with a radio inside (colour from the page's nonce'd style); `.a-heat-caption`, `.a-heat-y` |
| Inline help | `<x-ui.tip :text="…" />`: (i) button, text on hover and focus |

## Data display

| Component | Classes / Blade |
|---|---|
| Table | `.a-table-wrap` (+ `.a-table-scroll` keeps the sticky header in view) > `table.a-table` (+ `-compact`, `-matrix` for tick grids with a pinned first column). Light sticky header in micro caps, hairlines, hover. Cells: `.is-num`, `.is-center`, `.is-nowrap`, `.is-shrink`; rows `.is-current`, `.is-muted`; group header row `tr.a-table-group > th`. |
| Clickable rows | Put `data-row-link` on the row's main link (`.a-row-link`, + `.a-row-sub` line under it): a click anywhere on the row follows it. Row actions `.a-row-actions` fade in on hover/focus. |
| Chips | `.a-chips` > `.a-chip` (+ `-sm`, `-code`) |
| Badges | `.a-badge` (info) + `-neutral` `-success` `-warning` `-danger` `-dark` `-outline`; `<x-version-status>`, `<x-eval-status>` use them |
| Verdicts | `<x-verdict :verdict />` → `.a-verdict-pass` / `-fail` / `-na` / `-none` with CRAted's icons |
| Risk level | `.a-level` + a per-page `.risk-level-N` colour class; `.a-level-empty` |
| Progress | `<x-ui.progress :value :max />` (`<progress class="a-progress">`, green when complete), `line` adds `12/40` |
| Stat tiles | `.a-stats` > `<x-ui.stat :label :value :total tone="ok|bad|info" :href bar />` |
| Product picture | `<x-ui.thumb :product size="sm|lg" />` (picture from `products.image`, else initials) |
| Empty state | `<x-ui.empty icon="…" :title :text>` primary action(s) in the slot |
| Alerts | `<x-ui.alert>` / `.a-alert`: see **Alerts** below |

## Alerts

One look for every message box: tinted ground, a full 1px hairline in the tone (never a coloured
edge, never an inset shadow), a soft glow behind the icon, the icon in a round badge on the first line.

| | |
|---|---|
| Markup | `<div class="a-alert a-alert-warning"><x-lucide-… aria-hidden="true" /><span>Text</span></div>`: the first `svg` becomes the badge, the text takes the rest of the line. Tones: info (no modifier), `-success`, `-warning`, `-danger`, `-neutral` (they only set `--al-ink` / `--al-bg` / `--al-line` / `--al-icon`). `<b>`, `.a-alert-title` and `.a-link-btn` inside take the tone ink |
| Component | `<x-ui.alert tone="warning" icon="lock" :title="…" :action="['href' => …, 'label' => …]" dismissible flash>Text</x-ui.alert>`: icon defaults per tone; the action is a tinted button on the right, under the text below 40rem; `dismissible` adds the x (Alpine `dismissible`, absent without JS); `flash` slides in once (none with reduced motion); role `alert` for danger, else `status`; `:post="['action' => path, 'label' => …, 'fields' => [name => value]]"` adds a second action that is a POST form (`button.a-alert-action`) |
| Flash | `<x-flash>`: `status` (success, dismissible), `welcome`, `warning` + `warning_link` (+ `warning_post`, a POST action), `error` + `error_link`, the validation summary (title + list) |
| Same family | guest strip `.a-guest-bar` (`<x-guest-banner>`), justification check `.a-just`, shared tree line `.a-shared`, conformity `.a-outcome.is-ok/.is-bad`, `.a-nb-unknown`, public `.c-callout` (+ `-success` `-warning` `-danger`) |

## Drawer and modal

`<x-ui.drawer id="…" :title :open :close-url wide modal>` is a native `<dialog>` (`.a-drawer` on
the right, or `.a-modal` centred with `modal`): focus is kept inside, Esc and a click on the
backdrop close it, focus returns to the trigger. Footer buttons in `<x-slot:footer>` (`.a-spacer`
pushes the rest right).

- Open a dialog already in the page: `<a href="{fallback URL}" data-dialog-open="id">`.
- Load the dialog for one record: `<a href="…/inventory/assets/ID" data-dialog-fetch="id">` fetches that URL,
  swaps in the `<dialog id>` it renders and opens it (scroll kept, server widgets correct).
- Without script both are plain links: the page renders the dialog with `:open="true"`, shown as
  a block of the page, and its close button links back to `:close-url`.
- `data-fill='{"name":"…"}' data-fill-target="#form"` prefills a form.

## More behaviours (ui.js, no Alpine needed)

| Attribute | Effect |
|---|---|
| `data-facet-target="#scope"` on a group of `[data-facet="value"]` buttons | facet filter combined with the search box: an item stays shown when its `data-facet-value` (space-separated) contains the pressed value; `data-facet=""` shows all |
| `data-checked-count="#scope"` | live count of ticked boxes; `data-needs-checked="#scope"` disables a button while none is ticked |
| `form[data-autosave]` | sends itself with `fetch` (Accept: JSON) 450 ms after a change; `[data-autosave-status]` with `data-saving`, `data-saved`, `data-failed` shows the state (`.a-autosave`). Keep a normal Save button for no-script. |
| `table[data-tick-grid]` | keeps `[data-row-count]` (per row) and `[data-col-count="n"]` (per column) up to date |
| `data-check-row`, `data-check-col="n"`, `data-check-visible="#scope"` | tick or clear a row, a column, or every box the filters still show |
| `data-disclosure="id"` | toggles `.is-open` on #id and `aria-expanded` (the sidebar on phones) |
| `data-group-bar="#scope"` (inventory.js) | picker save bar: `[data-group-submit]` enabled from two ticks; `input[data-group-name]` takes the ticked boxes' shared `data-group-label` (same `data-group-key`), else shows `data-template` (`:count`) as placeholder; Enter in it adds the group |
| `data-select-bar="#scope"` (inventory.js) | list selection bar: `[data-select-count]`, `[data-select-needs="2"]` buttons, `.is-active` once a `[data-select-item]` box is ticked (hidden until then when script runs); `[data-select-all="#scope"]` ticks every shown row |

## Screen patterns built with the kit

| Pattern | Classes | Where |
|---|---|---|
| List + side panel | `.a-table` rows with `data-row-link data-dialog-fetch` → `<x-ui.drawer>` | inventory, risks |
| Add as a modal | `<x-ui.drawer :modal="true">`, the record form, buttons in the footer (`form="…"`) | inventory |
| Full-screen catalogue picker | `.a-pick`: rail `.a-pick-rail` > `.a-pick-cat[aria-pressed]` (facets), rows `.a-pick-row` (big checkbox, `.a-pick-name`, `.a-pick-desc`, `.a-pick-source`, reason chips `.a-chip-reason`), `.a-savebar` | inventory/pick |
| Catalogue order and groups (block F) | order line `.a-pick-order`; presence chip `.a-badge.a-presence.is-typical` (filled, star) / `.is-common` (outline); suggested groups `.a-proposals` > `.a-proposal-list` > `.a-proposal` (`.a-proposal-title`, `.a-proposal-members`, button `form="proposal-N"` to a hidden form); save bar `.a-pick-bar` + `.a-pick-bar-group` (name + "Add as one group", DOM after "Add selected" so Enter adds one by one, CSS `order` shows it first) | inventory/pick |
| Group rows in a list | row checkbox `td.a-select-cell` > `input.a-select-box[data-select-item][form]`, header `.a-select-all`, bar `form.a-selectbar` (`.a-selectbar-count`, `.a-selectbar-help`); a group: `.a-badge-group` "Group of N" + `details.a-members` (summary = chip + `.a-members-preview`, list of members) | inventory |
| Tick matrix | `.a-table-matrix.a-matrix`, `.a-matrix-corner`, header buttons `.a-matrix-col` / `.a-matrix-row`, `.a-matrix-cell` | inventory mappings |
| One object at a time | `.a-eval` (`.a-eval-objects` > `.a-eval-object[aria-current]`, form panel, `.a-eval-tree`), `.a-walk`, `.a-walk-question`, `.a-walk-outcome` | decision trees |
| Tree walk | `.a-eval` is the `treeWalk` Alpine root (walk + diagram, tree beside the walk from 90rem); `.a-eval-tree-head` (sticky in the column), `.a-eval-tree-hint`, `.a-walk-choice` (answer button + `.a-kbd.a-kbd-hint`) | decision trees |
| Tree diagram | `trees._diagram`: `.a-tree` (`.is-compact`, `.is-interactive`) > `.a-tree-node` (`.is-start`, `.is-on-path`, `.is-current`) > `.a-tree-head` (`.a-tree-id`, `.a-tree-flag-path` / `-current` / `-reached`, `.a-tree-text`) + `.a-tree-branch` (`.is-taken`, `.is-reached`; `.a-tree-answer`, `.a-tree-arrow`, `.a-tree-goto`). With `interactive` the head and branches are `button.a-tree-pick` (`aria-current="step"`, `aria-pressed`) that `tree-walk.js` repaints live | walk page, tree viewer |
| One card per object | `.a-eval.a-einfo`: list `.a-einfo-objects` (`.a-einfo-group`, `.a-einfo-object` with `.a-einfo-state.is-complete/-partial/-empty`, count, bar, `.a-einfo-object-hits`), `.a-einfo-pick` select below 64rem, card `.a-einfo-card` > `.a-einfo-field` (`.is-filled`; `[data-state=done]` folds away in "Missing only"), `.a-einfo-note`, `.a-einfo-savebar`. Behaviour in `einfo.js`: live search across objects (kept per page in sessionStorage, never in the address); `/{object}`, `/missing` keep it linkable without script | E.Info |
| Library drawer | `.a-library` rows: checkbox + one-click Add | risks |
| Mechanisms picker | `.a-picker` with `.a-mech` / `.a-mech-family` rows | risk form |
| Matrix editor | `.a-matrix-edit` (full-width coloured selects), `.a-color` | risk matrix settings |
| Save bar | `.a-savebar` (sticky at the bottom of its form; `.a-spacer`) | E.Info, picker, mappings |
| Misc | `.a-panel-danger`, `.a-file`, `.a-textarea-sm`, `.a-chip-none` (the quiet "not set" choice), `.a-seg-btn` around a radio, `tr.is-new` (rows just added) | |

Risk levels are colours chosen per version: the page writes `.risk-level-N` (background, and black
or white text picked by luminance so AA holds) and `.risk-dot-N` in its nonce'd `<style>`.

## Keyboard shortcuts

`ui.js` (plain delegated listener, no Alpine) runs every shortcut of the app. A page declares its
keys on the elements they act on, the help dialog (`?`) lists them, nothing else to wire.

| Attribute | Meaning |
|---|---|
| `data-shortcut="j"` | A single key: `j`, `/`, `?`, `1`, `Backspace`, `Enter`, `ArrowDown` (named keys as in `KeyboardEvent.key`, any case; single characters are case sensitive, Shift is allowed: `?`) |
| `data-shortcut="mod+s"` | `mod` = Ctrl (Windows, Linux) or ⌘ (macOS). `mod+s`, `mod+enter` also work while typing in a field; `preventDefault()` (no browser save dialog) only when a matching element exists |
| `data-shortcut="g o"` | A sequence: the second key within 1.2 s (a small "g …" chip shows meanwhile). Navigation uses `g` + letter |
| `data-shortcut-label="…"` | Its line in the help dialog (translated). Without it the key works but is not listed |
| `data-shortcut-doc` | Listed in the help, **never triggered** by `ui.js`: for keys the page handles itself in its own JS (e.g. `j`/`k` in a list). Doc entries are listed even when hidden (`<span hidden data-shortcut="j" data-shortcut-doc data-shortcut-label="…">`) |
| `data-shortcut-group="global"` | Lists the entry under "Everywhere" (layout only) |
| `<span data-kbd="mod+s"></span>` | Renders the keys for this platform as `<kbd class="a-kbd">` (⌘ S or Ctrl S) |

Rules, in order:

- Ctrl, Alt or Meta held: ignored unless the shortcut is a `mod+` one (AltGr characters are fine).
- Focus in an input, textarea, select or contenteditable: plain keys go to the field; Esc leaves
  the field (unless a dialog is open, which Esc closes, or a filter box, which Esc clears first).
- Ignored elements: `hidden` (or inside `[hidden]`), not rendered, inside a closed `<details>` or a
  closed `<dialog>`, `:disabled`, `aria-disabled="true"`, inside `[inert]`.
- A `dialog[open]`: only the elements inside it count.
- Several matches: the one inside the form that has the focus, else the first usable one in DOM
  order. A link that is only folded away (sidebar below 64rem, a closed `.a-menu`) is followed when
  nothing visible matches.
- Action: a text input, textarea or select gets the focus (its text selected); anything else is
  `.click()`ed (a submit button submits its form, a `data-dialog-open` link opens its dialog).
- `<body data-shortcuts="off">` (the account preference "Keyboard shortcuts"): only `mod+` keys
  and `?` stay active; search hints are hidden.

Built in: `?` opens or closes the help dialog (`#shortcuts-help` in the layout, also the keyboard
button of the top bar); `/` focuses the first `.a-search input` or `input[data-filter]` of the page
(or of the open dialog) when no element claims `/`. Global navigation (layout): `g p` products,
`g t` decision tree viewer, `g a` your account. In a version workspace (sidebar steps): `g o`
overview, `g s` essential requirements, `g d` product description, `g i` assets & interfaces, `g e` decision trees
(first working standard), `g f` E.Info, `g u` assessment units, `g r` risks, `g c` conformity, `g v` review, `g x` reports.
`mod+s` is on the Save button of the account profile, product, product description, identifiers,
scoping and conformity forms.

| Class | Use |
|---|---|
| `.a-kbd` | One key: small, monospace, 1px border with a 2px bottom, surface background (light and dark). `.a-kbd-then` the "then" between two keys |
| `.a-kbd-hint` | Shown only from 64rem with a mouse (`hover: hover`): hide key hints on phones and touch screens |
| `.a-search-kbd` | The `/` hint inside `<x-ui.search>` (gone on focus, with text, or when shortcuts are off) |
| `.a-hint` > button + `.a-hint-text[role=tooltip]` | Tooltip under a top-bar icon button (the shortcuts button) |
| `.a-keys` > `.a-keys-row` > `.a-keys-label` + `.a-keys-combo` | Key list (help dialog); `.a-keys-cols` two columns |
| `.a-kbd-pending` | The fixed "g …" chip of a pending sequence (made by ui.js) |

## Account page

`/account`, `/account/security|sessions|data|delete`: `.a-tabs` > `.a-tab[aria-current]` (+
`.a-tab-count` for passkeys and sessions), one partial per tab (`account/tab-*.blade.php`), only
the selected one rendered. `AccountController::tabUrl($tab)` is where every form of a tab
redirects; validation errors go `back()` to the tab they came from. `.a-account` (+
`.a-account-split`: content and a 20rem aside from 64rem), `.a-account-pref` (checkbox list with
help), `.a-panel-danger` for the delete tab.

## Review changes (review page)

What changed in an evaluation since it was reviewed (`App\Assessment\ReviewDiff`, word diff `App\Support\WordDiff`).

| Piece | How |
|---|---|
| Changed row | `tr.is-changed` (amber tint) + `.a-changed-badge` (`.a-badge-warning` with the history icon) under the requirement; `.a-review-by` > `.a-review-stale` icon after each stale reviewer |
| What changed | `tr.a-changes-row` right under its row (one cell spanning the table; hidden with its row by the filters) > `details.a-changes` (amber summary, `.a-changes-chevron`) > `.a-changes-body` > `dl.a-changes-list` (dt micro label, side by side from 48rem); `.a-changes-move` old → new (`.is-old` struck through, `.a-changes-arrow`, `.a-changes-node`); `.a-changes-paths` (Before / Now lines of `.a-changes-step`, `.a-changes-sep`); `.a-changes-foot` (last change, `.a-diff-legend`) |
| Word diff | `p.a-diff` (pre-line) with `del.a-diff-del` (struck through, red) and `ins.a-diff-ins` (underlined, green), each with an `.a-sr-only` "[Removed]" / "[Added]" prefix; `.a-diff-gap` for unchanged text left out. Tokens `--a-diff-del-*`, `--a-diff-ins-*` (light and dark, AA) |
| Review again | `.a-review-again` (amber note above the reviewer's own form when their review is stale) |
| Misc | `.a-seg-count.is-warning`, `.a-review-object` (object under the requirement), `tr[data-review-row].is-current` (j/k row, blue edge) |

Keys (`resources/js/modules/review.js`, listed in the ? help with `data-shortcut-doc`): `j`/`k` current row,
`a`/`d`/`q` agree / disagree / question on it (reviewers), `c` toggles "Changed since reviewed".

## Assessment cases and justification (block H)

The standard's "Assessment criteria" (content pack `assessments/<standard>/<REQ>.json`, read by
`ContentPack::assessment()`), "justified before it counts" (`App\Assessment\Justification`) and the
Review step's assessment cases (`App\Assessment\AssessmentCases`).

| Piece | How |
|---|---|
| Standard text | `App\Assessment\CriteriaText::html($value)` → `div.a-criteria-text` (`lang="en"` when the page is not in English): lines `- ` / `  - ` become nested lists, other lines paragraphs |
| Criteria panel | `@include('assessment._criteria', ['criteria', 'standardName', 'withCases', 'open'])`: `details.a-collapse.a-criteria` > `.a-criteria-body` > `dl.a-criteria-dl` (micro dt, side by side from 48rem), `.a-criteria-info` (ids + text, `li.is-child`, `.a-criteria-condition`, `.a-criteria-note`), `.a-criteria-verdicts`, `.a-criteria-case` |
| Justification check | `@include('assessment._justification', ['check' => JustificationCheck])` under the justification of a walked evaluation: `.a-just` (`.is-ok` / `.is-bad` tint, never a coloured edge) > `.a-just-head` (state badge, `.a-just-length.is-short`), `.a-just-rules` > `.a-just-rule` (`.is-informative` dashed: shown, never checked) > `.a-just-when`, `.a-just-ids` > `.a-just-id` (`.is-documented`, `a.is-missing` linking to the field on the E.Info page `/einfo/{standard}/{object}#e-…`, `.is-not-required`, `.is-untracked`), `details.a-just-text` (the rule as printed), `.a-just-note` |
| "Not justified" status | `<x-eval-status status="unjustified" />` (warning badge + scale icon, `.a-badge-unjustified`; `.is-icon` icon-only in the narrow object list); sidebar sub-line `.a-step-warn` |
| INCONCLUSIVE | Not a verdict of EN 18031 (`Verdict::Inconclusive`, never a tree outcome: expert selects list `Verdict::standard()`). `<x-verdict verdict="INCONCLUSIVE" />` → `.a-verdict-inconclusive` (amber, question icon); `<x-eval-status status="inconclusive" />` (`.a-badge-inconclusive`, `.is-icon` in the object list). Walk page: ghost button "Inconclusive for now" in `.a-panel-foot.a-inconclusive-foot` with `.a-inconclusive-note`, `.a-inconclusive-alert` while set; the answers so far post as `partial[]` (tree-walk.js `submitPartial`) |
| Review views | `.a-tabs.a-review-tabs` "Evaluations" / "Assessment units" (`/review/units`). Each evaluation row opens `#evidence` (`x-ui.drawer`, fetched, `/review/evaluations/{id}` without script): `review._evidence` `.a-evidence` > `.a-evidence-verdict`, `.a-evidence-section` (`ol.a-evidence-path` question + answer per step, justification, `dl.a-evidence-einfo`, `.a-evidence-docs`, VEX), reviews; the form `review._opinion-form` (`.a-opinion-form`) in the drawer foot. Read-only: no input that changes the assessment, no link to an editor. Reviews list `review._opinions` (`.a-opinions`, Self-reviewed badge) |
| Self-review | No reviewer on the product: `.a-alert-warning` on the Review page (invite link), "Self-review" badge on the decision panel, "Self-reviewed" badge on the reviews and the Overview |
| Units | `units._cases` (mode `provide` in the "Assessment units" step, `review` in the Review step), stats `units._stats`: `.a-cases` > `section.a-cases-standard` > `article.a-case-req` (`.a-case-req-head`, `.a-case-req-title`, `.a-case-req-trees` figures) > `.a-case[data-case-row]` (`.is-current` row tint, `.is-informative` statement-only case) > `.a-case-head` (`.a-case-name`, clause chip, verdict, Independent / Not independent badge, `.a-case-by`), `details.a-case-standard`, `.a-case-suggest` (info tint: the conceptual verdict proposed from the trees), `form.a-case-form` (`.a-case-verdicts` chips `.a-case-pick.is-pass/-fail/-notapplicable` filled with the verdict colours once chosen, `.a-case-evidence`, `.a-case-foot` document select + Record). From 48rem chips and button share a line, the evidence spans under them. Above them, `form.a-units-where`: "In REDact" / "Outside REDact" as two `.a-toggle-card` radios in `.a-orgteam-roles`, `.a-units-where-foot` (Documents link outside REDact, Save) |

Keys (`resources/js/modules/assessment-cases.js`, listed in the ? help with `data-shortcut-doc`): `j`/`k`
current case, `1`/`2`/`3`/`4` PASS / FAIL / NOT APPLICABLE / INCONCLUSIVE on it (then the evidence gets the focus),
`mod+s` records the current case (or the one whose form has the focus).

## SBOM and vulnerabilities (block G of app-ui.css)

Import a bill of materials (`sbom/import`, `sbom/preview`) and the known vulnerabilities of the
components (`sbom/vulnerabilities`). Behaviour: `resources/js/modules/sbom.js` (`vulnCheckStatus`
polls the status endpoint while a check runs: `x-text="label"`, `<progress>` bound to `done`/`max`).
States are tints and badges, never coloured edges.
Rounded corners only on a box whose border is the same all round (owner, 2026-10-23): no rounded box with a single coloured or thicker side, no top- or bottom-only border on a rounded box. Separators (`border-top` between rows) are fine on square boxes.

| Piece | How |
|---|---|
| Drop zone | `label.a-sbom-drop` around a real `input.a-sbom-file` (multiple), `.a-sbom-drop-text`; focus ring on the label |
| Side lists | `.a-sbom-formats` (icon + text rows), `.a-sbom-steps` (numbered), `.a-sbom-file-link` (original file, ellipsis) |
| Preview table | `.a-sbom-table` with the kit's tick column (`.a-select-cell`, `.a-select-box`, `data-check-visible`), `.a-sbom-tags` (status badge, "Matches SW-03", "The product itself"), `.a-sbom-ids` (purl / CPE chips in mono), `.a-sbom-savebar` (`.a-savebar` + `.a-check`) |
| Row status | `sbom/_row-status` under a component's name in the component list: `a.a-vuln-status` (badges found / possible / confirmed / Exploited + `.a-vuln-status-date`), `data-no-row-click` |
| Run panel | `.a-vuln-run` > `.a-vuln-run-body` > `.a-vuln-run-main` (`.a-vuln-run-title`, `.a-progress`) + `ul.a-vuln-sources` > `li.a-vuln-source.is-ok / .is-error / .is-deferred` (skipped: neutral) |
| Results table | `table.a-vuln-table` (the filter scope) with one `tbody[data-filter-group]` per component: `tr.a-table-group.a-vuln-component` (`.a-vuln-component-row`, `-name`, `-meta`, `details.a-vuln-ids` > `.a-vuln-ids-form` popover for purl / CPE), rows `tr.a-vuln-row` (`.is-exploited` = light red tint, `.is-muted` = set aside) > `.a-vuln-main` (`.a-vuln-id`, `.a-vuln-title`, `.a-row-sub`, `.a-vuln-meta`); `tr.a-vuln-empty` when a component has none |
| Severity | `.a-sev` + `.a-sev-critical` (solid red) / `-high` / `-medium` (low and none stay neutral); score in `<b>` and the word next to it. Tokens `--a-sev-*`, `--a-exploited-row` (light and dark) |
| Source chips | `.a-vuln-chips` > `a.a-vuln-chip.is-euvd / .is-nvd / .is-osv / .is-kev` (links to the record) |
| Folding | per component, known exploited and CVSS ≥ 7 rows first; the rest are `tr.is-folded` after a `tr.a-vuln-more` > `details.a-vuln-fold[data-vuln-fold]` ("Show N more", `.a-vuln-fold-show` / `.a-vuln-fold-hide`). sbom.js keeps folded rows `hidden` (so "tick all shown" skips them) and shows matching ones while a search or facet filters (`table.is-filtering`); without script a CSS `:has()` rule folds them |
| Decisions | set aside needs a reason: `input.a-vuln-reason-input` (`name="reason"`) in the decision bar; shown as `.a-vuln-reason` + `.a-vuln-reason-by` in the decision cell, earlier decisions in `details.a-vuln-history`. "New" badge on findings of the last weekly check, summary alert above the stats |
| Row status | `.a-vuln-status-muted` for "n set aside" |
| Attribution | `footer.a-vuln-attribution` with `.a-vuln-credits` (one line linking EUVD, NVD, OSV.dev, CISA KEV) and the NVD notice required by its terms |

## Plans and billing (app-plans.css)

`App\Billing\Plan` decides; these only show it. No coloured edges: alerts, badges, icons.

| Piece | How |
|---|---|
| Plan line | `@include('plans._line', ['plan' => Plan::for($organisation)])` → `p.a-plan-line` (`.a-plan-line-name`, `.a-plan-line-sep`): "Free plan · 1 of 1 product · 37 AI units left · Talk to us to upgrade" (dashboard foot `.a-plan-foot`, account Profile tab) |
| Limit hint | `p.a-plan-hint` (icon + sentence): limit reached, free team seats, "invite your reviewer" |
| Upgrade | dashboard `.a-plan-upgrade` (help + "Upgrade" button instead of New product); `products/upgrade` (Checkout, confirm "adds 100 €/month (prorated)", or Talk to us), `.a-plan-features` list |
| Billing page | `billing/show`: summary `.a-dl`, products with their allowance badge (Free, Paid, Granted, Included) and `Read-only`, `.a-plan-invoices`, Reactivate form |
| Read-only product | `<x-read-only-notice>` shows "Your subscription has ended: reactivate to edit" (`ProductAccess::isBillingLocked()`); refused edits and imports answer with that message (`Controller::allow`), downloads always work |
| Console | `.a-plan-form` (plan select + overrides grid) on `admin/organisations/show` |

## Blade gotcha

Never put an inline `@php($x = …)` **before** a block `@php … @endphp` in the same view: Blade's
block pattern then swallows everything in between ("unexpected endif"). In loops use the block
form on one line: `@php $x = …; @endphp`.

## Conventions

- Density: 14px body in tables, 13px labels, 11px badges, 10px micro labels; rows 36–40px.
- Spacing scale: 0.25 / 0.5 / 0.75 / 1 / 1.5rem; panels 1rem padding; radius `lg` controls, `xl` panels and tables, `2xl` modals.
- Focus: every interactive piece has a visible 2–3px light-blue outline.
- Colour carries meaning only with a word or an icon next to it (verdicts, levels, statuses).
- Dark mode: all colours come from `--c-*` and `--a-*` variables, redefined under `.dark`.

## Guided inventory (block K of app-ui.css)

"Assets & interfaces" is a sequence (`App\Assessment\InventoryGuide`): the pack's lists that the
working standards use (or that hold objects), in pack order, then the workflow's next step. The
mappings are not a step (owner, 2026-10-23): a separate button (`InventoryGuide::mappings()`). A list is done when it has objects or is marked "This product has none"
(`product_versions.inventory_none`, cleared when an object is added).

| Piece | How |
|---|---|
| Stepper row | `<x-inventory.stepper :guide :current :from :editable>`: `.a-guide-row` > `nav.a-guide` > `ol.a-guide-steps` > `a.a-guide-step` (`.is-done`, `.is-current` + `aria-current="step"`) with `.a-guide-no` (number, tick once done), `.a-guide-label`, `.a-guide-count` (`.is-none`). Chips share the width, long names truncate; below 40rem only the current one keeps its name. Then `.a-guide-tools`: "Prefill everything" (editors; `.a-guide-tool-off` disabled with its reason when nothing is left) and "Mappings" (`a.a-guide-map`, `.a-guide-tool-count`, primary + `aria-current="page"` on that view) |
| Prefill everything | modal `#prefill-all` (`/inventory/{list}/prefill` without script): `ul.a-prefill-lists` > `details.a-prefill-list` (`.a-prefill-chevron`, names in `.a-prefill-names`), note, POST `versions.inventory.prefill` (the server recomputes `InventoryGuide::prefillPlan()`: picker-ticked items of every list not marked none, not yet in the inventory) |
| Empty / none | `<x-ui.empty class="a-guide-empty">` "Add <list>": catalogue (primary), by hand, SBOM import (components), "This product has none" (`form.a-guide-none`); `.a-guide-empty.is-none` with "Undo: we have some" |
| Bar | `<x-inventory.guide-bar :guide :current :summary :warning :primary :map :back>` (`.a-savebar.a-guide-bar`): `.a-guide-prev` (`p`; on the mappings page "Back to <list>"), `.a-guide-summary` + `.a-guide-warn` ("n assets have no type yet"), slot (mappings autosave + Save), `a.a-guide-maplink` ("Map to interfaces…", icon only on phones), `.a-guide-next` (`n`; outline "Skip to …" while the list is empty; "Next step: …" after the last list and on the mappings page) |
| Picker | `.a-continue-bar` (`data-continue-bar`, inventory.js): `.a-pick-bar-actions` > "Add selected" + `button.a-continue-btn[name=continue]` with `.a-continue-add` / `.a-continue-skip` (shown while nothing is ticked, script only) |
| Type cards | a select whose pack options carry `help` / `standards` renders `.a-type-cards` > `label.a-toggle-card.a-type-card` (`.a-type-card-title`, `.a-toggle-card-text`, `.a-type-card-parts`, `.is-out` + `.a-type-card-out` when none of its parts is in scope) |
| Sidebar | on the step: `ul.a-guide-substeps` one line per list, `.a-guide-tick` once done |

## Workflow order and articles (blocks M and N of app-ui.css)

Steps run in `App\Assessment\Assessment::ORDER`: product, essential requirements (route key
`scoping`), risk assessment, assets & interfaces, decision trees, E.Info, conformity, review.
`Assessment::for($version)->nextStepAfter('inventory')` gives `['key', 'label', 'href']` of the next
step (null after review); `stepUrl($key)` the URL of one step.

| Piece | How |
|---|---|
| Next step link | `<x-ui.next-step :version="$version" after="risks" />` → `nav.a-next-step` (`.a-next-step-text`, `.a-next-step-label`, primary "Continue to …") at the foot of a step page |
| Risks vs essential requirements | Risk page, under the register: `risks._coverage` = `section.a-panel.a-risk-cover` with `table.a-art-table` (rows `.a-art-row.has-issue` tinted, `.a-art-name`, `.a-art-risks` > `a.a-art-risk` "#n", `.a-art-issue` + `.a-art-card-link` to the scoping card `versions.scoping#scope-q-{key}` or the threat library, `.a-art-ok`), `.a-art-norisk` (scoping not answered). Below 48rem rows become labelled blocks (`td[data-label]`) |
| Requirements named | Same panel: `section.a-art-part` (`.a-art-part-head`, count, bar), `.a-art-uncovered` chips `.a-art-free`, `details.a-art-named` > `table.a-art-cover` (`.a-art-req`, `a.a-art-risk.is-via` dashed = through the family), `.a-art-outside` |
| Confirmation | `scoping._confirm`: `.a-art-confirm.is-confirmed / .is-stale / .is-unconfirmed / .is-unanswered` (tints), `.a-art-actions`. Data: `App\Assessment\ArticlesCheck` (state, fingerprint of the scoping only, confirm) |
| Follows from another walk | `<x-follows-badge code="SCM-1" />` = `.a-verdict.a-verdict-fail.a-verdict-follows` (dashed) "FAIL · follows from SCM-1"; `state="unlinked"` = `.is-unlinked` "To link". Index: badge + count in the verdicts column, stats "Follow from another walk" / "To link". Walk page: `.a-eval-follows-head` + `a.a-eval-object.is-follows` under the walks, `.a-follows-panel` > `ul.a-follows-list` when nothing is walked. Data: `Assessment::followsFrom()` (`PackRules::followsFrom`) |
| Inner loop of a walk | `.a-eval-inner` under the walk panel head: "For each" + chips of `Assessment::innerTargets()` (e.g. the communications a mechanism secures) |
| Out of scope on the risk form | `.a-chip-toggle.a-chip-out` + `.a-chip-out-note`, `.a-chip.a-chip-out` (register), picker bands `.a-picker-band` (`.is-out`, `.a-picker-band-head`), `.a-mech.is-out` + `.a-mech-out` |

## Product card (block T of app-ui.css)

One product block on the version Overview (`versions._product`: card + versions table with "New
version" modal + team), Product settings and the Product step: `<x-product.card>` (CRAted's
ProductProfileCard). `GET /products/{product}` redirects to the latest version's Overview
(`Product::homeUrl()`); a product without a version keeps a small page.

| Piece | How |
|---|---|
| Card | `section.a-panel.a-pcard` (container query) > `.a-pcard-grid`: picture, `.a-pcard-main`, `.a-pcard-docs`; three columns from 60rem of card, documents under from 36rem, stacked below |
| Picture | `products._picture`: `.a-pcard-pic` > `.a-pcard-pic-frame` > `label.a-pcard-pic-box` (`.is-empty`, `.is-editable`, `.is-busy`) around a hidden `input[data-autosubmit]` (product-card.js sends it; `[data-autosubmit-fallback]` Save for no-script), `.a-pcard-pic-change` hover overlay, round `.a-pcard-pic-remove` (confirm), `.a-pcard-pic-help` |
| Main | `.a-pcard-head` (`.a-pcard-name` + reference chip), `.a-pcard-block`, `.a-pcard-label` (micro + tip), `.a-pcard-text`; Models: `.a-pcard-models` chips (view) or `identifierRows` rows `.a-pcard-model-row` (+ hidden `models_sent`); Product version: `.a-pcard-numbers` fields or `dl.a-pcard-dl`; `.a-pcard-foot` buttons |
| Data | models = `product_identifiers` of kind `model` with `product_id` (`Product::models()`, saved by `App\Support\ProductModels`); `product_versions.hardware_version` / `software_version`; `ProductVersion::identification()` = models + version identifiers + those two (declaration order) |

## Organisation team (block O of app-ui.css)

`/team` (account menu "Team") redirects to `/organisations/{organisation}/team`
(`OrganisationTeamController`, `organisations/team.blade.php`): everyone in the organisation and the
outsiders on its products. Organisation admins manage people, billing, legal details and every
product; members read the page; guests never reach it (`GuestSandbox` CLOSED). Product roles stay on
each product's Team page, which links admins here ("Manage everyone in …").

| Piece | How |
|---|---|
| People table | `table.a-table.a-orgteam`: person (name + `.a-row-sub` e-mail, "You" badge), organisation role badge (Admin `.a-badge`, Member `-neutral`, External `-warning`), product chips `a.a-chip.a-orgteam-chip` (name + `.a-orgteam-role`, `.is-expired` dashed) linking to the product's Team page when the reader can open it, "Manage" (`data-dialog-fetch="person"`, `/team/{person}` without script) |
| Invite | modal `#invite` (`/team/invite` without script): e-mail, organisation role as two `.a-toggle-card` radios in `.a-orgteam-roles`, `organisations._products` (`table.a-orgteam-products`, one role select per product, "Team full" badge + disabled when the free product has no seat) |
| Person dialog | modal `#person`: same role cards (`.is-disabled` keeps the only admin), the product table (Owner shown fixed), Save in the footer; `form.a-orgteam-remove` (hairline above): "New owner of …" selects for the products they own, then the danger button (confirm) |
| Data | organisation invitation = `invitations` row with `organisation_id`, `organisation_role`, no product; one child row per product offered (`parent_id`, no token) counts as a pending seat (`App\Billing\Plan`). Seats and roles go through `App\Actions\ProductTeam` (also used by the product Team page). Changes are logged in the `team` activity log |

## History (block Y of app-ui.css)

`/products/{product}/history` (sidebar "More" > History, Product settings) and
`/organisations/{organisation}/history` (Team | History tabs, `organisations._tabs`, admins only), both
`history/index.blade.php` (`HistoryController`, `App\History\Feed`). Every activity entry is stamped
with its organisation, product and version (`App\History\ActivityScope`, columns of `activity_log`);
`App\History\Describer` writes the sentence (`lang/*/history.php`, `events.*`), `App\History\ChangeSet`
is shared with the console's activity explorer.

| Piece | How |
|---|---|
| Filters | `form.a-panel.a-hist-filters` (auto-fit grid of `.a-field` with `.a-input-sm`), `.a-hist-filter-actions` |
| Table | `table.a-table.a-table-compact.a-hist`; day rows `tr.a-table-group.a-hist-day`; `tr.a-hist-row` (`.is-support` amber tint) > `.a-hist-time` (`time`, full date in the title), `td > .a-hist-who` (`.a-avatar.a-hist-avatar` initials, `.is-support` life-buoy, `.is-system` cog; `.a-hist-name` with the e-mail as its tooltip for owners and admins), `.a-hist-what` (`.a-hist-line` = `.a-hist-kind` icon + `.a-hist-text` with `b.a-hist-ref`), `.a-hist-where` (version badge or product) |
| Folds | `.a-hist-folds` (one line) > `details.a-hist-more` (full width when open): "+N more" (`ul.a-hist-others`, similar entries within 10 minutes) and "N changes" (`dl.a-hist-changes`: `<x-ui.word-diff>` for texts, `del.a-hist-old` → `ins.a-hist-new`, `p.a-hist-written` for a new text) |
| Foot | `.a-hist-foot`: retention line (`redact.retention.activity_log_days`), `.a-hist-pages` Newest / Older (cursor `before`) |
| Phones | below 48rem each row is a grid block: time, person, version, then the sentence full width |
| Transfer ownership | product Team page, owner and organisation admins: header "Transfer ownership" → modal `#transfer` (`/team/transfer` without script): new owner select (organisation members, no guests, no outsiders), previous owner as three `.a-toggle-card` radios in `.a-orgteam-roles.is-three` (Contributor, Reviewer, Leaves), confirm. The organisation person dialog shows "Transfer" next to each owned product (link to that modal). `App\Actions\TransferOwnership` (plan seat checked, slot unchanged, e-mail to the new owner) |
| Invited sign-up | no organisation of one's own while an organisation invitation waits (`App\Actions\PersonalOrganisation`); `EnsureOrganisation` (dashboard, New product, /team) sends them to `/invitation` (Join / Decline) or creates their own organisation when none waits |
