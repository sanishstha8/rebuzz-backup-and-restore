# Google Drive sign-in relay

A Cloudflare Worker that lets ReBuzz Backup connect to Google Drive. Google only lets a website sign in with a **client secret**, and a secret can't ship inside a plugin anyone can download, so this Worker holds it. It is not part of the plugin zip.

It does four things and stores nothing:

| Address | What it does |
| --- | --- |
| `GET /gdrive/start` | Shows "Connect Google Drive to *this site*?", then sends the browser to Google |
| `GET /gdrive/callback` | Google comes back here; the one-time code goes back to the site's Storage tab |
| `POST /gdrive/token` | The site's server swaps the code (plus its PKCE verifier) for tokens; the secret is added here |
| `POST /gdrive/refresh` | The site's server renews an expired access token; the secret is added here |

Backups never pass through it. The plugin talks to `www.googleapis.com` directly for files.

The plugin points at `https://rebuzz-gdrive.xthasanish44.workers.dev` (`WPCB_GDRIVE_RELAY` in the main plugin file). A site can override that in `wp-config.php`.

## One-time setup

### 1. Google Cloud app

In [console.cloud.google.com](https://console.cloud.google.com):

1. Create a project, e.g. **ReBuzz Backup**.
2. **APIs & Services → Library → Google Drive API → Enable.**
3. **Google Auth Platform → Branding:** app name *ReBuzz Backup & Restore* and a support email. Leave the logo empty: adding a logo makes Google require brand verification before it shows.
4. **Audience:** *External*, then **Publish app** so the status is **In production**. In *Testing*, connections stop working after 7 days and only test users can sign in.
5. **Data Access → Add or remove scopes:** add `https://www.googleapis.com/auth/drive.file` only. It is a non-sensitive scope, so there is no security review and no 100-user limit.
6. **Clients → Create client → Web application.** Under **Authorized redirect URIs** add:
   `https://rebuzz-gdrive.xthasanish44.workers.dev/gdrive/callback`
   Copy the **Client ID** and **Client secret**.

### 2. Deploy the Worker

From this folder, logged in to the Cloudflare account that hosts the landing page:

```
npx wrangler login
```

Put the client ID in `wrangler.jsonc` (`GOOGLE_CLIENT_ID`; it is public), then:

```
npx wrangler deploy
npx wrangler secret put GOOGLE_CLIENT_SECRET
npx wrangler secret put STATE_KEY
```

`GOOGLE_CLIENT_SECRET` is the secret from step 1.6. `STATE_KEY` is any long random string, used to sign the sign-in state so nobody can redirect a code to another site. Make one with:

```
node -e "console.log(require('crypto').randomBytes(32).toString('base64url'))"
```

Check it: `https://rebuzz-gdrive.xthasanish44.workers.dev/` should forward to the plugin's WordPress.org page.

### 3. Try it

On a test site, open **ReBuzz Backup → Storage → Connect Google Drive**. You should see the relay's confirmation page naming your site, then Google's consent screen, then the Storage tab saying *Connected*.

## Later: your own domain

With a custom domain on the Worker, Google can verify the brand and show the app's name and logo on its consent screen. Add the new `…/gdrive/callback` to the client's redirect URIs, point the next plugin version's `WPCB_GDRIVE_RELAY` at it, and keep the `workers.dev` address running for sites that haven't updated.

## Tests

`node test.mjs` checks the Worker on its own (Node 18+). `../test-tools/test190-relay.mjs` runs it against a fake Google for the plugin's end-to-end test.
