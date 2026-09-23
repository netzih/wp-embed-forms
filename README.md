# Embed Forms

Build forms in WordPress and put them on **any website** with one script tag,
Jotform style. Entries are stored in this site's own tables; payments go
through USAePay using the
[USAePay Payments](https://github.com/netzih/usaepay-wordpress) plugin.

## Installation

Download `embed-forms-<version>.zip` from the latest release, then
**Plugins > Add New Plugin > Upload Plugin**, and **Activate**. Nothing else
is needed on the site: the plugin has no Composer dependencies at runtime.
Activation creates the tables and the `/f/` address; if forms return a 404,
visit **Settings > Permalinks** once.

Requirements: WordPress 6.4+, PHP 8.1+. Payment fields also need USAePay
Payments with credentials under **Settings > USAePay**.

From a clone: `bin/build-zip.sh` makes the same zip.

## Using it

1. **Embed Forms > Add New Form**, drag fields from the palette onto the
   form (or click them), select a field to edit its label, options, width,
   prefill parameter and conditional logic, fill in **Settings**
   (confirmation, emails, allowed websites), set the status to **Live** and save.
2. **Embed & share** tab: copy the code.

| Where | Code |
| --- | --- |
| Any website | `<div data-embed-form="ID"></div>` + `<script src=".../embed-forms/assets/embed.js" async></script>` |
| Direct link | `https://this-site/f/<link-name>/` |
| This WordPress site | `[embed_form id="ID"]` |
| Site builders without scripts | plain `<iframe>` (fixed height) |

The loader turns the placeholder into an iframe that resizes to fit, scrolls
the parent page to the form when it changes step or shows errors, and, for a
"go to a web page" confirmation, navigates the parent page. The parent's link
parameters are passed to the form: a field with **Prefill parameter**
`email` is filled from `?email=...` on the page that embeds it (name and
address parts from `<param>_first`, `<param>_city`, ...). A
`embedforms:submitted` event bubbles from the placeholder after a submission
(`event.detail.entry` is the entry id).

## How it works

- **Forms** live in `{prefix}ef_forms`; each change to the fields saves a new
  row in `{prefix}ef_form_versions`, and each entry records the version it was
  made on, so old entries still display with the fields they had.
- **Entries** (`{prefix}ef_entries`) hold the answers as JSON keyed by field
  id, the payer email, amount, IP, browser and the page the form was
  embedded on. Payments and subscriptions have their own tables.
- **A separate database** can hold the tables: define `EF_DB_NAME`,
  `EF_DB_USER`, `EF_DB_PASSWORD` (and optionally `EF_DB_HOST`) in
  `wp-config.php` before activating.
- **The public page** `/f/<link-name>/` (or `/f/<id>/`) is a standalone HTML
  page, not the theme, so embeds are light and no site CSS leaks in. Its
  `Content-Security-Policy: frame-ancestors` allows the form's **Allowed
  websites** (any site when empty), and it removes `X-Frame-Options`. If the
  server or a security plugin adds `X-Frame-Options` itself, exempt `/f/`.
- **The look** comes from `assets/form.css`, set in Geist (bundled in
  `assets/fonts`, SIL Open Font License, no third-party requests). Each form
  picks an **Accent colour** under Settings; everything else is a `--ef-*`
  CSS variable at the top of the file.
- **Submissions** go to `POST /wp-json/embed-forms/v1/forms/<id>/submit`.
  Embedded iframes get no WordPress cookies, so instead of nonces the page
  carries a signed token (form id and time, HMAC with the site salts, valid
  24 hours, rejected in the first 2 seconds). Also: a honeypot field, an
  hourly per-IP limit, and optional Cloudflare Turnstile (keys under
  **Embed Forms > Settings**; it works inside iframes).
- **Conditional logic** (show or hide a field, section or page when answers
  match) runs in the browser and again on the server
  (`Schema\Conditions`, mirrored in `assets/form.js`). Answers to hidden
  fields are discarded, never stored.
- **Emails** go through `wp_mail`, so Post SMTP or any mailer applies.
  Merge tags: `{field:ID}`, `{field:ID:part}`, `{all_fields}`,
  `{form_title}`, `{entry_id}`, `{entry_url}`, `{date}`, `{admin_email}`,
  `{site_name}`, `{source_url}`.
- **CSV export** splits name and address fields into parts and neutralises
  spreadsheet formulas.

### Field types

`text`, `textarea`, `email`, `phone`, `number`, `select`, `radio`,
`checkbox`, `date`, `name`, `address`, `hidden`, plus layout: `html`,
`section`, `page` (starts a new step), and payment: `amount`, `product`,
`frequency`, `total`, `payment` (the card).

The builder (`assets/builder.js`) runs on WordPress's bundled React and
components, so there is no build step. **Edit as JSON** shows the whole
definition for copying between forms or bulk edits.

## Payments

Payment fields charge through [USAePay Payments](https://github.com/netzih/usaepay-wordpress)
(credentials, sandbox/live and Apple Pay under **Settings > USAePay**).

- **Amount**: preset amounts with an optional "other", an amount the payer
  types, or a fixed amount; minimum and maximum. **Product**: a price, with or
  without a quantity. **Frequency**: one time, weekly, monthly or yearly, with
  an optional number of payments. **Total** shows the sum. **Card payment**
  holds USAePay's hosted Pay.js card fields (card data never reaches this
  site) and, for one-time payments, Apple Pay. It must be on the last page.
- **The server decides the amount.** It is computed from the validated
  answers (`Payments\Pricing`); fields hidden by conditions add nothing,
  whatever the browser sends.
- **Charge at most once.** Every charge, renewal and refund goes through
  USAePay Payments' `Reconcile::once()`, with its marker on the payment or
  subscription row. A page view sends one submission key with every attempt,
  so a resubmit after a decline, a timeout or a double click continues the
  same entry (declined attempts stay on it as history) and a request whose
  answer was lost is looked up at USAePay before anything is sent again.
- **Card testing**: after **Declined payments per hour** declines from one IP
  (Embed Forms > Settings), payments are refused for the hour; use Turnstile
  on payment forms too.
- **Recurring** choices charge the first payment with `save_card` and create
  a subscription; this site charges the saved card hourly when due
  (`embed_forms_renewals` cron), with dates anchored to the signup day. A
  decline is retried every 3 days, 3 attempts in all, then the subscription
  is cancelled and the form's notification recipients are emailed. Payers get
  a receipt for each renewal (per-form setting). Nothing is scheduled in the
  USAePay console, so cancelling here is all it takes. Apple Pay is offered
  only for one-time payments (its keys return no saved card).
- **Entry screen**: payments with USAePay references, refunds (an unsettled
  payment is voided in full; a settled one refunded in full or in part),
  the recurring payment with **Cancel**, and a history of every event.
  **Embed Forms > Subscriptions** lists them all, with **Run renewals now**.
- In-flight requests appear under **Settings > USAePay > Unresolved
  requests**, and "Run renewal workers now" there runs this plugin's worker
  too (needs USAePay Payments with the `usaepay_payments_unresolved` and
  `usaepay_payments_renewal_workers` filters).
- Merge tags for payments: `{payment_summary}`, `{payment_amount}`,
  `{payment_frequency}`, `{transaction_id}`, `{card_brand}`, `{card_last4}`,
  `{next_payment_date}`.
- Invoices are `EF<form>-<entry>` (renewals `EF-S<subscription>`), orderids
  `<site prefix>-ef-<entry>-<attempt>` and
  `<site prefix>-ef-s<subscription>-<date>-<attempt>`, `custid` the payer's
  email, and the first name/address fields go to AVS.
- USD only, like USAePay Payments.

### Hooks

- `embed_forms_entry_submitted` (entry, form): a new entry was stored.
- `embed_forms_payment_completed` (payment, entry, form),
  `embed_forms_subscription_created`, `embed_forms_renewal_charged`,
  `embed_forms_payment_refunded`, `embed_forms_subscription_cancelled`.
- `embed_forms_gateway_client` (NULL, mode): return a `Usaepay\GatewayClient`
  to replace the real one, e.g. on a fake transport in a test site.
- `embed_forms_page_config` (config, form), `embed_forms_page_scripts`:
  extend the public page.
- `embed_forms_base`: the `/f/` path.
- `embed_forms_client_ip`: the visitor IP behind a proxy.

## Development

```
composer install
vendor/bin/phpunit
bin/build-zip.sh
```
