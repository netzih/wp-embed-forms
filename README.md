# Embed Forms

Build forms in WordPress and put them on **any website** with one script tag,
Jotform style. Entries are stored in this site's own tables; payments go
through USAePay (using the
[USAePay Payments](https://github.com/netzih/usaepay-wordpress) plugin) or
Stripe, with the processor and merchant account chosen per form.

## Installation

Download `embed-forms-<version>.zip` from the latest release, then
**Plugins > Add New Plugin > Upload Plugin**, and **Activate**. Nothing else
is needed on the site: the plugin has no Composer dependencies at runtime.
Activation creates the tables and the `/f/` address; if forms return a 404,
visit **Settings > Permalinks** once.

Requirements: WordPress 6.4+, PHP 8.1+. Payment fields also need either
USAePay Payments (0.2+ for additional accounts) with credentials under
**Settings > USAePay**, or a Stripe account under **Embed Forms > Settings**.

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
- **Emails** go through `wp_mail`, so Post SMTP or any mailer applies. The
  sender is set under Embed Forms > Settings and can be changed per form
  (Settings > Email sender: from name and address for the notification, the
  confirmation to the submitter and renewal receipts).
  Merge tags: `{field:ID}`, `{field:ID:part}`, `{all_fields}`,
  `{form_title}`, `{entry_id}`, `{entry_url}`, `{date}`, `{admin_email}`,
  `{site_name}`, `{source_url}`.
- **CSV export** splits name and address fields into parts and neutralises
  spreadsheet formulas.

### Field types

`text`, `textarea`, `email`, `phone`, `number`, `select`, `radio`,
`checkbox`, `date`, `name`, `address`, `hidden`, plus layout: `html`,
`section`, `page` (starts a new step), payment: `amount`, `product`,
`frequency`, `total`, `payment` (the card), and donor list:
`display_name` (how the person appears on a campaign's donor list) and
`anonymous` (one box; hides the name). A form holds at most one of each.

The builder (`assets/builder.js`) runs on WordPress's bundled React and
components, so there is no build step. **Edit as JSON** shows the whole
definition for copying between forms or bulk edits.

### Design

Per form (Settings tab): a preset accent or any **brand colour** (button
text turns dark on light colours), the **fonts** for text and for
headings/amounts (Geist and System are local, the rest load from Google
Fonts; the Stripe card field uses the text font too), **On its own card**
or **Plain** (embedded without a card, for a page that already puts the
form on one), and soft or **round** corners. A form that changes none of
these looks exactly as before.

## Google Sheets

A form can add a row to a Google Sheet for every new entry (Settings >
Google Sheets on the form).

- **Setup, once:** in console.cloud.google.com create a project, enable the
  Google Sheets API, create a service account (IAM & Admin > Service
  accounts) and add a JSON key to it. Paste the key under **Embed Forms >
  Settings > Google Sheets**; it is stored in the settings option and never
  shown again. Then share each spreadsheet with the service account's email
  as **Editor** and paste the spreadsheet's link into the form (optionally a
  tab name; the first tab otherwise).
- **What is sent:** entries that are submitted, or paid when the form takes
  payments, and offline donations; not failed or unfinished payments.
  Columns as in the CSV export (names and addresses in parts) without the
  IP address, plus the transaction (marked "(test)" in test mode). Values
  are written as they are (`RAW`), so nothing is run as a formula. Rows are
  not updated afterwards.
- **Columns** follow row 1 by name: an empty sheet gets the header, a field
  added to the form gets a new column at the end, and columns may be
  reordered or added in the sheet as long as the names stay.
- **When:** from WP-Cron right after the submission (the person does not
  wait for Google). A failed send is retried 5, 20, 45 and 80 minutes
  later; the last result shows under the form's Google Sheets settings and
  errors go to the PHP error log.
- **Send all entries** in the editor sends the form's entries not yet in the
  sheet (matched by the Entry column), for entries made before, or after a
  send that never went through. Safe to repeat.
- A duplicated form starts with sending off. Tests and test sites can
  answer Google's calls with the `embed_forms_sheets_response` filter.

## Campaigns

**Settings > Campaign > Donor list** publishes a form's running total and
public donor list for a campaign page:

- `GET /wp-json/embed-forms/v1/forms/<id>/campaign` answers (only while the
  campaign is on) with `raised`, `goal`, `percent`, `donors` and up to 50
  `recent` donors: `name` (the display name; else the first name and last
  initial, or Anonymous, per the setting), `anonymous`, `amount` and `city`
  (only if the campaign shows them; never a city for anonymous donors),
  `frequency`, `at`, and an opaque `id`. Nothing else about the person is
  published.
- **Donors listed** sets how many recent donors the list carries (default
  50, at most 200); **Count from** limits the total, the donor count and
  the list to payments made on or after a day (site time zone), so a form
  reused for a new campaign starts from zero.
- It counts approved charges and renewals less refunds, in the
  processor's current mode (test payments only while the form is in test
  mode), plus offline donations, plus the "Raised elsewhere" offsets. Cached
  for a minute and dropped when a payment, refund, edit or status change
  touches the form.
- `assets/campaign.js` on any page fills `data-ef-raised`, `data-ef-goal`,
  `data-ef-donors`, `data-ef-percent`, `data-ef-progress` (width) and a
  simple `data-ef-donor-list` inside `<div data-ef-campaign="ID">`,
  refreshes every 30 seconds and right after a donation in an embedded
  form, and fires `embedforms:campaign` (all data) and
  `embedforms:donation` (each new donor) for pages that draw their own
  donor wall. The editor shows a starter snippet.
- The public form previews the name under the display name field.

**Embed Forms: Full page** is a page template any theme gets: the page
content alone, without the theme's header, footer or stylesheets (core
and plugin styles and `wp_head`/`wp_footer` stay), for campaign pages that
bring their own design in a Custom HTML block. A page made only of Custom HTML
blocks also leaves out other plugins' scripts and styles, the Customizer's
Additional CSS and the emoji script (filter
`embed_forms_full_page_clean`). Its **Own address** box (page editor
sidebar) serves the page at the root of another domain pointed at this
site, such as a Plesk domain alias: other paths there go to the same path
on this site, and forms, the REST API and logins keep working.

## Entries: editing and offline donations

- **Edit entry** (entry screen, or the list's row action) changes the
  answers; each change is written to the history with who made it. An
  online payment's amount, product and frequency stay as charged (refunds
  are on the entry screen). Required fields may be left empty.
- **Add offline donation** (Entries, per form) records a check, cash, bank
  transfer, donor-advised fund, stock or other gift: amount, reference,
  date received, an internal note and the form's answers, optionally
  sending the form's emails. It is an approved payment on the `offline`
  processor, so it counts in totals, exports and the donor list; it is
  never sent to a processor and cannot be refunded here (edit or trash the
  entry instead). Hooks: `embed_forms_entry_submitted`,
  `embed_forms_offline_donation_added` (payment, entry, form);
  `embed_forms_entry_edited` (entry, form, changes) after an edit.

## Payments

Each form picks its processor and account under **Settings > Payments**:

- **USAePay**, through [USAePay Payments](https://github.com/netzih/usaepay-wordpress):
  the default account or one of its **Additional accounts** (credentials,
  sandbox/live and Apple Pay under **Settings > USAePay**).
- **Stripe**: one of the Stripe accounts under **Embed Forms > Settings**
  (a name plus test and live publishable and secret keys; one test/live
  switch for all). Card details go into Stripe's card element; the browser
  creates a PaymentMethod, the server creates and confirms the
  PaymentIntent. Cards that need 3D Secure show the bank's check and the
  form is submitted again to finish. Apple Pay is USAePay-only for now.

Every payment, subscription and refund row records its processor and
account (`gateway`, `account`; rows from before 0.3 are USAePay's default
account). Refunds, renewals and lookups always go there, so a form can be
moved to another account without stranding its earlier payments.

- **Amount**: preset amounts with an optional "other", an amount the payer
  types, or a fixed amount; minimum and maximum. **Product**: a price, with or
  without a quantity. **Frequency**: one time, weekly, monthly or yearly, with
  an optional number of payments. **Total** shows the sum. **Card payment**
  holds the processor's hosted card fields (USAePay Pay.js or Stripe's card
  element; card data never reaches this site) and, for one-time USAePay
  payments, Apple Pay. It must be on the last page.
- **The server decides the amount.** It is computed from the validated
  answers (`Payments\Pricing`); fields hidden by conditions add nothing,
  whatever the browser sends.
- **Charge at most once.** Every USAePay charge, renewal and refund goes
  through USAePay Payments' `Reconcile::once()`, with its marker on the
  payment or subscription row. Stripe requests carry the orderid as
  idempotency key and are stored on the row before they are sent; a retry
  sends the stored request again unchanged (even if the payer entered
  another card), so Stripe answers with the first result. A marker older
  than Stripe keeps keys (23 hours here) is looked up by the orderid in the
  metadata first. A page view sends one submission key with every attempt,
  so a resubmit after a decline, a timeout or a double click continues the
  same entry (declined attempts stay on it as history) and a request whose
  answer was lost is looked up at USAePay before anything is sent again.
- **Card testing**: after **Declined payments per hour** declines from one IP
  (Embed Forms > Settings), payments are refused for the hour; use Turnstile
  on payment forms too.
- **Recurring** choices charge the first payment with `save_card` (Stripe: on
  a new customer with `setup_future_usage=off_session`) and create a
  subscription; this site charges the saved card hourly when due
  (`embed_forms_renewals` cron), with dates anchored to the signup day. A
  decline is retried every 3 days, 3 attempts in all, then the subscription
  is cancelled and the form's notification recipients are emailed. Stripe
  renewals are off-session PaymentIntents; a card that asks for
  authentication counts as a decline. Payers get
  a receipt for each renewal (per-form setting). Nothing is scheduled in the
  USAePay console, so cancelling here is all it takes. Apple Pay is offered
  only for one-time payments (its keys return no saved card).
- **Entry screen**: payments with USAePay references, refunds (an unsettled
  payment is voided in full; a settled one refunded in full or in part),
  the recurring payment with **Cancel**, and a history of every event.
  **Embed Forms > Subscriptions** lists them all, with **Run renewals now**.
- In-flight USAePay requests appear under **Settings > USAePay > Unresolved
  requests** (with their account), and "Run renewal workers now" there runs this plugin's worker
  too (needs USAePay Payments with the `usaepay_payments_unresolved` and
  `usaepay_payments_renewal_workers` filters).
- Merge tags for payments: `{payment_summary}`, `{payment_amount}`,
  `{payment_frequency}`, `{transaction_id}`, `{card_brand}`, `{card_last4}`,
  `{next_payment_date}`.
- Invoices are `EF<form>-<entry>` (renewals `EF-S<subscription>`), orderids
  `<site prefix>-ef-<entry>-<attempt>` and
  `<site prefix>-ef-s<subscription>-<date>-<attempt>`, `custid` the payer's
  email, and the first name/address fields go to AVS.
- Stripe objects: `metadata[orderid]`, `invoice`, `entry_id`, `form_id`
  (renewals `subscription_id`), description = the form title.
- USD only.

### Hooks

- `embed_forms_entry_submitted` (entry, form): a new entry was stored.
- `embed_forms_payment_completed` (payment, entry, form),
  `embed_forms_subscription_created`, `embed_forms_renewal_charged`,
  `embed_forms_payment_refunded`, `embed_forms_subscription_cancelled`.
- `embed_forms_gateway_client` (NULL, mode, account): return a
  `Usaepay\GatewayClient` to replace the real one, e.g. on a fake transport
  in a test site.
- `embed_forms_stripe_response` (NULL, method, path, params, idempotency
  key): return `['status' => 200, 'body' => [...]]` to answer instead of
  Stripe; `embed_forms_stripe_client` (NULL, account, mode) replaces the
  client.
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
