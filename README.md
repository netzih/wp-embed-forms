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

1. **Embed Forms > Add New Form**, build the fields, fill in **Settings**
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
`section`, `page` (starts a new step).

### Hooks

- `embed_forms_entry_submitted` (entry, form): a new entry was stored.
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
