# cetome style for REDact

`resources/css/cetome.css` brings the cetome.com look to the app: tokens, the Inter font and
`.c-*` component classes. `app.css` imports it right after Tailwind.

**Where it comes from.** The cetome.com Hugo source (`hugo/assets/css`) is plain CSS. Its tokens
were taken from Tailwind's default theme, so the slate, blue, red and green scales, the radii and
the type scale are Tailwind's own and are not redefined here. The brand values come from
`base/brand.css`. The components follow `nav.css`, `footer.css`, `hero.css`, `contact-form.css`,
`data-table.css`, `insights.css`, `card-grid.css`, `typography.css`, `labels.css` and
`premium.css`. `fast.cetome.com-v2/css/cetome.css` is a compiled copy of the same sheet, and its
fonts are identical (same checksum). Anything marked **derived** below has no cetome.com
equivalent.

## Tokens (`@theme`)

| Token | Value | Utility | Use |
|---|---|---|---|
| `--color-brand-blue` | `#0044aa` | `bg-brand-blue` … | cetome blue: buttons, links, the square |
| `--color-brand-blue-deep` | `#003a8f` | | the second blue in a run of blue panels |
| `--color-brand-blue-light` | `#217aff` | | hover, accents on dark grounds (never as text on white: 3.97:1) |
| `--color-brand-blue-accent` | `#1565d8` | | the light blue as text on white (5.41:1); primary fill in dark mode |
| `--color-brand-blue-pale` | `#a8caff` | | small blue text on near-black (footer labels) |
| `--color-brand-blue-night` | `#5c9bff` | | **derived**: links in dark mode |
| `--color-brand-dark` | `#0f172a` | | footer, dark surfaces (= slate-900) |
| `--color-brand-midnight` | `#020617` | | dark-mode page ground (= slate-950) |
| `--font-brand` / `--font-sans` | Inter, system stack | `font-brand` | everything |
| `--text-micro` / `-label` / `-small` / `-body` | 11 / 13 / 15 / 17px | `text-label` … | cetome.com's small steps between Tailwind's |
| `--tracking-nav` / `-button` / `-kicker` / `-submit` / `-section` | .14 / .2 / .3 / .4 / .5em | `tracking-kicker` … | spacing of uppercase labels |
| `--container-site` | 80rem | `max-w-site` | page width |
| `--spacing-nav` | 5rem | `h-nav`, `top-nav` | header height |
| `--shadow-card` | soft lift | `shadow-card` | card hover |
| `--shadow-raised` / `-raised-hover` | | `shadow-raised` | white tile on the blue hero |
| `--shadow-panel` | Tailwind lg | `shadow-panel` | table box |
| radii | Tailwind defaults | | `lg` buttons, `xl` tables and large buttons, `2xl` cards |

**Semantic colours** change with the theme. They are the ones to reach for in app markup:
`canvas`, `canvas-subtle`, `surface`, `surface-hover`, `ink`, `ink-muted`, `ink-subtle`, `line`,
`line-soft`, `line-strong`, `link`, `link-hover`, `primary`. For example `bg-canvas text-ink
border-line`.

## Dark mode

cetome.com is light-only, so the dark palette is **derived**. Add `class="dark"` to `<html>`. The
`dark:` variant follows the same class, set with `@custom-variant` in cetome.css. In dark mode the
ground is midnight, surfaces are brand-dark, text is slate-100 and slate-400, and lines are
slate-800. Fills use `#1565d8`, links `#5c9bff` and small blue text `#a8caff`, which are the blues
cetome.com already uses on dark grounds. The footer stays dark and the blue hero stays blue.
Switching the class is up to the app: a CSP-safe Alpine component, or the server setting it from
a user preference.

## Components

| Class | What it is | When to use |
|---|---|---|
| `.c-container`, `.c-container-narrow` | 80rem (48rem) centred, 2rem gutters | wrap page content |
| `.c-section`, `.c-section-band` | 4rem vertical padding; band = slate-50 ground | page sections; alternate bands |
| `.c-h1` | 36→48→60px, weight 900, uppercase, blue square | page title on a white page |
| `.c-h2` | 36px, weight 800, uppercase, blue square | section headings |
| `.c-h3` | 20px, weight 800, sentence case | card and entry titles |
| `.c-dot`, `.c-dot-end`, `.c-no-dot` | add the square, keep it with the last word (`<span class="c-dot-end">word</span>`), drop it | headings |
| `.c-kicker` | 13px, weight 900, 0.5em, blue, uppercase | label above a `.c-h2` |
| `.c-kicker-bar` | kicker with a 4px light-blue bar | group headings in menus and sidebars |
| `.c-meta`, `.c-lead`, `.c-text` | small label, intro paragraph, body block | text |
| `.c-link`, `.c-link-more`, `.c-link-external` | underlined prose link; quiet "All … →"; adds the external arrow | links |
| `.c-btn` + `.c-btn-primary` | 40px, 11px uppercase, 0.2em; inverts to white on hover | the main action |
| `.c-btn-outline` | primary inverted | secondary action |
| `.c-btn-ghost` | no border (**derived**) | cancel, back |
| `.c-btn-danger` | red, same inversion (**derived**) | destructive actions |
| `.c-btn-on-dark` | white button | on the hero or a dark band |
| `.c-btn-sm`, `.c-btn-lg`, `.c-btn-block`, `.c-btn-row` | 32px; 52px form submit; full width; a row of buttons | |
| `.c-card`, `.c-card-body`, `.c-card-footer` | 1rem radius, hairline, white | grouped content |
| `.c-card-link` | lifts on hover | a card that is a link |
| `.c-card-blue` | blue ground, alternating with the deep blue | highlight panels |
| `.c-rule-list` | entries split by hairlines, no box | lists of items (cetome.com's preferred list) |
| `.c-stat`, `.c-stat-value`, `.c-stat-label` | large blue figure over its label | key figures, dashboards |
| `.c-badge` (+ `-neutral`, `-success`, `-warning`, `-danger`) | blue wash tag; status colours are **derived** | statuses, tags |
| `.c-callout` (+ `-success`, `-danger`, `-warning`) | 4px left rule on a pale ground | flash messages, notes |
| `.c-field`, `.c-label`, `.c-input`, `.c-textarea`, `.c-select` | uppercase 13px label; underlined field, blue on focus; `aria-invalid="true"` turns it red | forms |
| `.c-input-boxed`, `.c-check`, `.c-help`, `.c-error` | boxed field for dense forms; checkbox and radio in blue; help text; error text | forms |
| `.c-table-wrap` + `.c-table` | rounded box; blue sticky header, hairlines, zebra rows | data tables |
| `.c-table-center`, `.c-table-hover`, `.c-table-sticky-col` | centred cells, row hover, pinned first column | |
| `.c-nav`, `.c-nav-logo`, `.c-nav-menu`, `.c-nav-link`, `.c-nav-actions` | the header (see `<x-brand.header>`) | |
| `.c-hero` (+ `.c-hero-dark`) | blue band (dark: brand-dark with cetome.com's hero gradient) | landing and page openers |
| `.c-hero-grid`, `-main`, `-aside`, `-kicker`, `-title`, `-lead`, `-card` | hero layout: 7 + 5 columns, white type, light-blue square, white action tiles | |
| `.c-footer` … | the footer (see `<x-brand.footer>`) | |
| `.c-skip-link` | "Skip to content", shown on focus | first element of `<body>` |
| `.c-logo`, `.c-only-light`, `.c-only-dark`, `.c-square`, `.c-icon` | logo image; show per theme; the blue square bullet; Material Icons ligature | |

The current header link takes `aria-current="page"`, or the `.is-active` class.

## Blade components (`resources/views/components/brand/`)

- `<x-brand.logo variant="color|white|auto" subtitled class="…" />` renders `<img>` from
  `public/brand/`. `auto` swaps to the white logo in dark mode.
- `<x-brand.header :href="…">` takes the menu links (`.c-nav-link`) in its default slot and the
  buttons on the right in `<x-slot:actions>`. Below 64rem both move into a `<details>` hamburger
  menu, so no JavaScript is needed. The slots are rendered twice, so do not put `id` attributes in
  them.
- `<x-brand.footer :cetome="true">` puts extra columns in its default slot, between the brand
  column and the "cetome" column. `<x-slot:legal>` replaces the legal links.

All visible text goes through `__()`. There are no inline scripts or `style` attributes anywhere,
so a strict CSP holds.

## Assets

- `resources/fonts/inter-latin-variable.woff2`: Inter variable, weights 300–900, latin subset,
  SIL OFL 1.1 (`Inter-OFL.txt`). Vite bundles it through the relative `url()`.
- `resources/fonts/material-icons-v145-subset.a09b54da.woff2`: the Material Icons subset from
  cetome.com, Apache 2.0 (`MaterialIcons-LICENSE.txt`). It holds only 16 glyphs: arrow_back
  bar_chart calendar_today cloud enhanced_encryption groups home hub key memory recommend schedule
  security sell supervised_user_circle.
- `public/brand/`: `cetome.svg`, `cetome_white.svg`, the `_subtitled` versions and
  `cetomesquare.svg` (the mark).
- `public/brand/favicon/`: the favicon set (`favicon.svg`, `favicon.ico`, 96px PNG,
  apple-touch-icon, web-app-manifest icons). Its `site.webmanifest` has been repointed to
  `/brand/favicon/`. Head tags, as cetome.com uses them:

```html
<link rel="icon" type="image/png" href="/brand/favicon/favicon-96x96.png" sizes="96x96">
<link rel="icon" type="image/svg+xml" href="/brand/favicon/favicon.svg">
<link rel="apple-touch-icon" sizes="180x180" href="/brand/favicon/apple-touch-icon.png">
<link rel="manifest" href="/brand/favicon/site.webmanifest">
```

Do not use the "redact" Lucida Handwriting face from redact.cetome.com: it is not licensed for
this use.

## Differences from cetome.com

- The header is `position: sticky`. cetome.com uses `fixed` plus a spacer.
- The mobile menu is a `<details>` element, not cetome.com's Alpine overlay.
- The desktop menu shows from 64rem whatever the pointer. cetome.com also requires a pointer that
  can hover.
- Table cells are left-aligned. cetome.com centres its comparison tables; use `.c-table-center`
  for that.
- The square stays with the last word through `.c-dot-end` markup, not cetome.com's `dots.js`.
