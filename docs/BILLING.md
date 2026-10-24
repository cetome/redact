# Plans and billing (Stripe)

REDact's pricing: **FREE = 1 product, no AI**: every organisation's first product is free forever
(owner + 1 team member, full cetome rules, downloads and imports, **no AI**). **PAID = AI with 2
products and more**: each additional product is **100 EUR per month** (excluding VAT) and brings
unlimited team, support and the AI for the whole organisation, 500 AI units a month per paid
product (pooled per organisation). Granted products (staff) and the `enterprise` plan count as
paid for the AI. cetome staff can grant extra products (partners), put an organisation on the
`enterprise` plan (custom contract, no product or team limit) and override any limit in the
console (`/admin/organisations`). Every number is in `config/redact.php` (`plans`) with an env
override (`REDACT_FREE_AI_UNITS=0`: no AI on the free plan; a value above 0 makes it a one-time
trial). Downloads and imports are never limited by a plan.

On the free plan every AI button gives way to "AI comes with a paid plan", with a link for
organisation admins (Plan and billing, or "Talk to us" when billing is off), never "AI unavailable
right now"; the server refuses AI calls the same way (`App\Ai\Quota`). When the subscription ends
and no paid or granted product is left, the AI goes off again. Organisations from before plans
(grandfathered products) are free for the AI unless staff grant a product.

Billing uses [Laravel Cashier](https://laravel.com/docs/billing) with the **organisation** as the
Stripe customer: one subscription per organisation (type `products`), one unit per paid product.
Checkout and the Customer Portal are Stripe-hosted redirects: no Stripe.js, no CSP change.

Without `STRIPE_KEY`, `STRIPE_SECRET` and `STRIPE_PRICE_PRODUCT_MONTHLY` billing is **off** (local
development, a self-hosted engine): the free limits apply, staff overrides still work, and the app
says "Talk to us" (`REDACT_SALES_URL`; empty: the REDact contact form, `/contact/pricing`).

## What happens

| Situation | What the person sees |
|---|---|
| First product | New product, free (`products.plan_slot = free`). |
| Another product, no subscription | New product shows "Add a product": **Continue to secure payment** opens Stripe Checkout (subscription, quantity 1, Stripe Tax, billing address and VAT number collected). Back on New product, the product can be created (`plan_slot = paid`). |
| Another product, subscription active | "This adds 100 €/month (prorated)": a confirmation box, then the subscription quantity goes up by one (proration on the next invoice). |
| Deleting a paid product | The delete form offers **Also reduce your subscription** (one unit less, or cancellation at the end of the period for the last one). Never automatic: without it the paid slot stays available for a new product. |
| Payment failed | Stripe retries (Smart Retries). Products stay editable meanwhile, the billing page asks to update the payment method. |
| Subscription cancelled or unpaid | Paid products become **read-only** (banner "Your subscription has ended: reactivate to edit"), never deleted, every download still works. The free product stays editable. Reactivating (portal or a new Checkout) makes them editable again. When the quantity shrinks, the newest paid products are the ones that become read-only. |
| Organisations from before plans | Their products (`plan_slot = null`) are grandfathered: never read-only, unlimited team. They use the free slot, so only new creations are refused. |

Organisation admins see **Plan and billing** (`/organisations/{id}/billing`): summary, next invoice,
invoices (links to Stripe's hosted invoices), **Manage billing** (Customer Portal: payment method,
billing address, VAT number, cancellation). Every change is logged (activity log `billing`; plan
changes by staff in the console log).

## 1. Set up Stripe (test mode first)

1. Stripe dashboard → **Test mode** on.
2. **Product catalogue → Add product**: name "REDact additional product", description "One additional
   product in REDact (RED Delegated Act / EN 18031)". Price: **Recurring, 100.00 EUR, monthly,
   per unit**, tax behaviour **Exclusive**. Copy the price id (`price_…`) to `STRIPE_PRICE_PRODUCT_MONTHLY`.
3. **Tax → Stripe Tax**: activate it, set the origin address (cetome), add the registrations
   (e.g. France, and the EU OSS if used), product tax code "Software as a service (SaaS) - business use".
   Cashier turns on `automatic_tax` and tax ID collection in Checkout; EU business customers with a
   valid VAT number get reverse charge.
4. **Settings → Billing → Customer portal**: activate it; allow updating payment methods,
   billing address, tax IDs, viewing invoices, and cancelling subscriptions (at the end of the
   period). Allow quantity changes only if you want customers to change it there (the app adapts:
   paid products beyond the quantity become read-only).
5. **Settings → Billing → Subscriptions and emails**: Smart Retries on; after all retries fail,
   mark the subscription **unpaid** or **cancel** it (both make paid products read-only). Turn on the
   customer e-mails for failed payments and receipts.
6. **Developers → API keys**: `STRIPE_KEY` (publishable, `pk_test_…`), `STRIPE_SECRET` (secret or a
   restricted key with write access to Customers, Checkout Sessions, Subscriptions, Billing Portal,
   read access to Invoices).
7. **Webhook**: `php artisan cashier:webhook` (with `STRIPE_SECRET` set and `APP_URL` correct) creates
   the endpoint `https://<host>/stripe/webhook` with the events REDact needs, or create it in
   **Developers → Webhooks** with: `customer.subscription.created`, `customer.subscription.updated`,
   `customer.subscription.deleted`, `customer.updated`, `customer.deleted`,
   `payment_method.automatically_updated`, `invoice.payment_action_required`,
   `invoice.payment_succeeded`, `invoice.payment_failed`. Copy the signing secret (`whsec_…`) to
   `STRIPE_WEBHOOK_SECRET`.
8. `.env`: also `CASHIER_CURRENCY=eur`, `CASHIER_CURRENCY_LOCALE=en_IE`; then `php artisan optimize`.

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

1. Repeat steps 2 to 7 in **live mode** (products, prices, tax settings, portal and webhooks are
   separate in test and live mode).
2. Put the live keys, the live price id and the live webhook secret in the production `.env`,
   `php artisan optimize`.
3. Make one real purchase with a company card, check the invoice (VAT, address, VAT number), then
   refund and cancel it from the dashboard.
4. Check the activity log (`/admin/activity`, log "Billing") shows the webhook events.

## Staff overrides (console)

`/admin/organisations/{id}`: plan (`free` or `enterprise`) and, empty meaning "the default":
extra products granted (free of charge, with the benefits of a paid product), team members of
the free product, AI units and their period, support. Lowering the granted products makes the
newest granted products read-only (never deleted). Every change is logged with the old and new
values.
