# Plans and billing (Stripe)

REDact's pricing: **FREE = 1 product, no AI**: every organisation's first product is free forever
(owner + 1 team member, full cetome rules, downloads and imports, **no AI**). **PREMIUM (plan key `paid`) = AI with 2
products and more**: **100 EUR per month or 1000 EUR per year** (excluding VAT) for 2 products (the
free one and one more), then the add-on **Additional product**, **+100 EUR per month or +1000 EUR
per year** each. Premium brings unlimited team, support and the AI for the whole organisation, 500 AI
units a month, plus 500 per additional product (pooled per organisation). Downloads and imports
are never limited by a plan.

Plans above Free are a **catalogue** (`App\Billing\Catalogue`, console **Plans and prices**,
`/admin/plans`): Premium (key `paid`) and Enterprise are built in (`config/redact.php`, `plans`), more can be added
there (Business...). Each plan says what it includes (products, the free one counted; team members per
product; AI units and their period; AI units per additional product; support), whether it is offered
online (pricing page and Checkout) or on request (set by staff), and has its Stripe prices: the plan
per month and per year, and the add-on per month and per year. Without an add-on price, each
additional product is one more unit of the plan price.

An organisation's plan comes from, in this order: a **contract** set by staff (no end date, e.g.
Enterprise: no online payment), its **subscription**, a **trial** set by staff (a plan until a date,
no card, never self-service), or Free. cetome staff can also grant extra products (partners) and
override any limit in the console (`/admin/organisations`). `REDACT_FREE_AI_UNITS=0`: no AI on the
free plan; a value above 0 makes it a one-time trial.

On the free plan every AI button gives way to "AI comes with a paid plan", with a link for
organisation admins (Plan and billing, or "Talk to us" when billing is off), never "AI unavailable
right now"; the server refuses AI calls the same way (`App\Ai\Quota`). When the subscription ends
and no paid or granted product is left, the AI goes off again. Organisations from before plans
(grandfathered products) are free for the AI unless staff grant a product.

Billing uses [Laravel Cashier](https://laravel.com/docs/billing) with the **organisation** as the
Stripe customer: one subscription per organisation (type `products`): the plan's price (one unit)
and the add-on's price (one unit per product beyond the plan), on the same interval (Stripe needs
every price of a subscription on one interval).
Checkout and the Customer Portal are Stripe-hosted redirects: no Stripe.js, no CSP change.

Without Stripe keys and a plan price (console, or `STRIPE_PRICE_PRODUCT_MONTHLY`: the Premium plan's
monthly price, one unit per paid product) billing is **off**.
`REDACT_SELF_HOSTED` then decides:

- empty (automatic) or `true`: a **self-hosted install**. The SaaS plans do not apply: no product or
  team limit, AI with the install's own key or model. This is the open-source default.
- `false`: the **hosted service before Stripe is live** (redact.cetome.com sets it). The free limits
  apply, staff overrides still work, and the app says "Talk to us" (`REDACT_SALES_URL`; empty: the
  REDact contact form, `/contact/pricing`).

## What happens

| Situation | What the person sees |
|---|---|
| First product | New product, free (`products.plan_slot = free`). |
| Subscribe (no subscription) | The **subscribe page** (`/billing/{id}/subscribe`, as FAST's `/subscribe`): monthly or yearly, the number of products (the free one counted, up to 11, that is 10 000 € a year, more: talk to us), the total, the line "REDact is sold to businesses only: by paying, you confirm…" (no box to tick), then **Continue to secure payment** opens Stripe Checkout with the plan price and the add-on units (Stripe Tax, billing address and VAT number collected). Reached from **Premium** on the pricing page (`/subscribe`: a visitor creates an account first, the e-mail code brings them back to it, and the passkey is offered after the payment), **Upgrade to Premium** in the app bar (organisations on Free), and New product at the limit. Cancelled on Stripe: back to the subscribe page. Paid: New product. |
| Another product, subscription active | "This adds 100 € per month (prorated)" (or per year): a confirmation box, then one more add-on unit on the subscription's interval (proration on the next invoice). |
| Deleting a paid product | The delete form offers **Also reduce your subscription** (one add-on unit less, or cancellation at the end of the period when only the plan is left). Never automatic: without it the paid slot stays available for a new product. |
| Trial (staff) | The plan applies until the end of the chosen day, without a card. Then, or when staff end it, back to Free: products beyond it become read-only, never deleted. A subscription takes over a trial. |
| Payment failed | Stripe retries (Smart Retries). Products stay editable meanwhile, the billing page asks to update the payment method. |
| Subscription cancelled or unpaid | Paid products become **read-only** (banner "Your subscription has ended: reactivate to edit"), never deleted, every download still works. The free product stays editable. Reactivating (portal or a new Checkout) makes them editable again. When the quantity shrinks, the newest paid products are the ones that become read-only. |
| Organisations from before plans | Their products (`plan_slot = null`) are grandfathered: never read-only, unlimited team. They use the free slot, so only new creations are refused. |

Organisation admins see **Plan and billing** (`/billing/{id}`, or `/billing` from the account menu): summary, next invoice,
invoices (links to Stripe's hosted invoices), **Manage billing** (Customer Portal: payment method,
billing address, VAT number, cancellation). Every change is logged (activity log `billing`; plan
changes by staff in the console log).

## 1. Set up Stripe (sandbox first)

Everything is done in the console, **Stripe** tab (`/admin/stripe`, a super-admin changes it, staff
read it). Values saved there override `.env`; the secret key and the webhook secret are stored
encrypted with the app key and never shown again. They are put into the config at each request,
never at boot, so `php artisan optimize` never writes them to `bootstrap/cache`.

1. In Stripe, create a **sandbox** (or use test mode).
2. **Tax → Stripe Tax**: activate it, set the origin address (cetome), add the registrations
   (e.g. France, and the EU OSS if used). Cashier turns on `automatic_tax` and tax ID collection in
   Checkout; EU business customers with a valid VAT number get reverse charge.
3. **Settings → Billing → Customer portal**: activate it; allow updating payment methods,
   billing address, tax IDs, viewing invoices, and cancelling subscriptions (at the end of the
   period). Allow quantity changes only if you want customers to change it there (the app adapts:
   paid products beyond the quantity become read-only).
4. **Settings → Billing → Subscriptions and emails**: Smart Retries on; after all retries fail,
   mark the subscription **unpaid** or **cancel** it (both make paid products read-only). Turn on the
   customer e-mails for failed payments and receipts.
5. Console, **1. Keys**: paste the publishable key (`pk_test_…`) and the secret key (`sk_test_…`,
   or a restricted key with write access to Customers, Checkout Sessions, Subscriptions, Billing
   Portal, Products, Prices and Webhook Endpoints, read access to Invoices). Save.
6. Console, **2. Webhook**: **Create the endpoint in Stripe** creates
   `https://<host>/stripe/webhook` with the events REDact needs and saves its signing secret
   (an endpoint already at that address is replaced). The address and the events are shown on the
   page. Stripe only accepts a public https address.
7. Console, **Plans and prices** (`/admin/plans`): for Premium, create the 4 prices: Plan per month
   (100), Plan per year (1000), Additional product per month (100), Additional product per year
   (1000). Each button creates the price in Stripe (recurring, per unit, tax behaviour exclusive,
   lookup key `redact_<plan>_<item>_<interval>`) on the plan's product, or the add-on's (created the
   first time, tax code SaaS, business use), in the mode of the keys. The amounts shown in the app
   (pricing page, terms, New product, billing) come from these prices. A new price later applies to
   new subscriptions; existing ones keep theirs (Stripe never changes a price), and the page counts
   the subscriptions on each.

Without the console, the same values go in `.env` (`STRIPE_KEY`, `STRIPE_SECRET`,
`STRIPE_PRICE_PRODUCT_MONTHLY`, `STRIPE_WEBHOOK_SECRET`, and `REDACT_PRODUCT_PRICE_LABEL` and
`REDACT_PRODUCT_PRICE_LABEL_YEARLY`, shown while there is no price), then
`php artisan optimize`; `php artisan cashier:webhook` creates the endpoint (copy its secret from the
dashboard). Also `CASHIER_CURRENCY=eur`, `CASHIER_CURRENCY_LOCALE=en_IE`.

The webhook route is registered outside the `web` middleware (no session, no CSRF, no cookies);
Cashier rejects any request whose `Stripe-Signature` does not match the secret (403).

## 2. Test

- Locally: `stripe listen --forward-to http://127.0.0.1:8765/stripe/webhook` (Stripe CLI) and put the
  `whsec_…` it prints in `.env`.
- Create a second product with an account whose free product exists: Checkout opens. Test cards:
  `4242 4242 4242 4242` (paid), `4000 0000 0000 3220` (3-D Secure), `4000 0000 0000 0341` (attaches,
  then fails at the next charge: test the failed payment and the read-only state). Any future
  expiry date, any CVC. A test VAT number such as `DE123456789` shows the reverse charge.
- Add a third product (confirmation screen, quantity 2), delete one with "Also reduce your
  subscription", cancel in the portal, then check the banner, the downloads and the reactivation.
- Automated tests never call Stripe: `tests/Concerns/FakesStripe.php` stubs the stripe-php HTTP
  client and signs webhook payloads (`tests/Feature/Billing`).

## 3. Go live

1. Repeat steps 2 to 4 of section 1 in **live mode** (tax settings, portal and retries are
   separate in the sandbox and live).
2. Console: paste the **live** keys and save. The sandbox webhook secret is dropped and the
   sandbox prices are no longer used (they do not exist in live; the catalogue keeps prices per
   mode). Organisations linked to sandbox customers must be unlinked (a box
   to tick): their Stripe customer and subscriptions are removed from REDact, their paid products
   stay, read-only until a live subscription covers them.
3. Create the live webhook endpoint (same address, its own secret), then the live prices on
   Plans and prices.
4. Make one real purchase with a company card, check the invoice (VAT, address, VAT number), then
   refund and cancel it from the dashboard.
5. Check the activity log (`/admin/activity`, log "Billing") shows the webhook events, and log
   "Admin" the Stripe changes (never a secret).

## Names from Stripe

Checkout asks for the billing name, and the business name when buying as a business (VAT number
collection is on). They fill what REDact does not have yet (`App\Billing\StripeNames`): the payer's
name when the account is still known by its e-mail address, the business name when the
organisation is still named after its creator. A name someone chose is never overwritten. Done on
the return from Checkout and by the `checkout.session.completed` webhook (an endpoint made before
2026-11-05 lacks that event: **Create the webhook** again on the Stripe page). Logged in "Billing"
as `names from Stripe`.

## Staff overrides (console)

`/admin/organisations/{id}`: the plan (Free, or any plan of the catalogue) with an optional end
date: none is a **contract** (Enterprise or another plan, no online payment), a date is a
**trial** without a card until the end of that day; **End the trial** goes back to Free at once.
Then, empty meaning "what the plan includes": extra products granted (free of charge, with the
benefits of a paid product), team members, AI units and their period, support. Lowering the granted
products or ending a trial makes the newest products beyond the plan read-only (never deleted).
Every change is logged with the old and new values.

While Stripe pays, the plan list shows "**Premium, paid on Stripe**" (nothing granted by staff):
saving the form as shown changes nothing, and choosing a plan there never touches the
subscription. To move a paying organisation to Free, a super-admin uses **End the subscription**
on the same page: the Stripe subscription is cancelled now, with a refund of the unused part of
the period (the last payment times the days left, `App\Billing\ProratedRefund`) or without. The
Stripe customer, the account, the team and every product stay; products beyond Free become
read-only. Logged as `subscription_ended` (log "Admin"). Deleting the whole account is on the
person's console page (`App\Admin\AccountEraser`, same refund).
