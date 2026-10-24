# Notified Bodies for RED (NANDO list)

The Conformity step suggests Notified Bodies as people type a number or a name
("0123 · TÜV SÜD Product Service GmbH (Germany)"). The list is
`resources/data/notified-bodies-red.json`, read by `App\Support\NotifiedBodies`.

## Why a file, not a live feed

NANDO now sits in the Commission's Single Market Compliance Space
(<https://webgate.ec.europa.eu/single-market-compliance-space/notified-bodies>). It is a
single-page application with no documented, machine-readable export or public API (checked
23 October 2026: the page loads an Angular bundle that calls internal endpoints, and none is
published for reuse). REDact has no `redact:nando-refresh` command for that reason. A scraper of
undocumented endpoints would break without warning, so cetome staff update the file by hand.

A number missing from the file is still accepted (four digits): the Conformity step and the
Declaration step then say "Not in the NANDO list for RED retrieved on …", so the person checks
it in NANDO ("Check in NANDO" opens the `url` below in a new tab).

## How to update the file

1. Open the RED list in NANDO, filtered on notification status "active", legislation
   Directive 2014/53/EU and the product scope you want (the URL in the file is the filter cetome
   uses):
   `https://webgate.ec.europa.eu/single-market-compliance-space/notified-bodies/notified-body-list?filter=notificationStatusId:1,legislationId:154428,productId:160969`
2. For each body, copy its four-digit number, its name as NANDO writes it and its country
   (MRA bodies: the country followed by "(MRA)").
3. Edit `resources/data/notified-bodies-red.json`:
   - `retrieved`: the day you read NANDO, `YYYY-MM-DD` (shown in the app as "retrieved on");
   - `url`: the NANDO page (must start with `https://`);
   - `bodies`: `{ "number": "0123", "name": "…", "country": "…" }`, sorted by number,
     numbers as strings (keep the leading zero). Entries without a four-digit number are ignored.
4. Save the file as UTF-8 with LF line endings, run
   `php artisan test --compact --filter=NotifiedBod`, commit and deploy. No cache to clear: the
   file is read once per request.

## Where it is used

- Conformity step: autocomplete of the Notified Body fields (`resources/js/modules/notified-body.js`,
  the list is embedded in the page as `data-bodies`), the warning under the number.
- Saving the Conformity step: a number not in the list is kept and flashed as a warning.
- Declaration step: the Notified Body of point 7 shows "Not in NANDO list" when the number is
  missing from the file.
