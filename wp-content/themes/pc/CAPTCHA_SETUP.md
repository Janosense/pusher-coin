# Guest Captcha Setup

The support form's **guest** path (`POST /pc/v1/support/tickets` without a
Bearer token) is protected by a captcha. Logged-in players never see one —
their session is the proof.

**Until both halves below are set, the captcha is disabled and guests can
submit tickets with no challenge at all.** That is deliberate: it keeps
local dev and a pre-account staging environment usable. It is also the
one thing to finish before the support form goes live on production.

The admin SPA shows the live state under **Support → Manage subjects →
Guest captcha**, so you can confirm it from the UI rather than the DB.

## 1. Create the site

Either provider works; the backend speaks both.

- **Cloudflare Turnstile** (default) — <https://dash.cloudflare.com/> →
  Turnstile → Add site. Add every hostname the SPA is served from
  (production domain, and `localhost` if you want it locally).
- **hCaptcha** — <https://dashboard.hcaptcha.com/> → Sites → New site.

Each gives you two values: a **site key** (public, shipped to the
browser) and a **secret key** (server-side only).

## 2. Site key → admin SPA

Admin SPA → **Support** → **Manage subjects** → **Guest captcha**: pick
the provider, paste the site key, Save.

This writes the `pc_captcha_provider` and `pc_captcha_site_key` WP
options. The site key is public by design — it appears in the page
source — so it is fine in the database.

## 3. Secret key → `wp-config.php`

On the production host, add the constant **above** the
`/* That's all, stop editing! */` line:

```php
define( 'PC_CAPTCHA_SECRET', 'your-secret-key-here' );
```

Never put the secret in the database. Same rule as
`PC_LIQPAY_PRIVATE_KEY` and `PC_MACHINE_TOKEN`: secrets stay out of DB
backups, out of `wp db export`, and out of the admin UI. `wp-config.php`
is gitignored, so it is also out of the repo.

The backend reads it through `Captcha_Verifier::secret()`. Nothing ever
reads it back over HTTP — `GET /pc/v1/admin/support/captcha` reports only
`secret_configured: true|false`.

## 4. Confirm

Reload the admin captcha panel. It should read **"Active — the guest form
is protected."** If it still says inactive, one half is missing:

| Panel says | Meaning |
| --- | --- |
| `PC_CAPTCHA_SECRET` is missing | Site key saved, constant not added (or not deployed / not the same host) |
| the secret is set but no site key | Constant added, site key field empty |
| no captcha configured | Neither half done |

Then open the support form as a guest (log out or use a private window).
The widget should render above the Send button, and submitting without
solving it should fail with `captcha_failed`.

## Rotation

Rotate in the provider dashboard, update `wp-config.php`, and paste the
new site key in the admin panel. There is no cached copy anywhere else.

## Behaviour notes

- An unreachable provider **fails closed** — a support form is a spam
  target, and if we cannot tell a human from a bot we reject. The escape
  hatch is clearing the site key, which is a deliberate act.
- The SPA loads the provider's widget script only when a captcha is
  configured; no third-party script is fetched otherwise.
- Ticket submission is rate-limited to 5 per hour per IP regardless of
  captcha state.
