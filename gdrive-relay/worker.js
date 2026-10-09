/**
 * ReBuzz Backup - Google Drive relay (Cloudflare Worker).
 *
 * Google only lets a web app sign in with a client secret, and a secret
 * can't ship inside a plugin anyone can download. This Worker holds it and
 * does the four things that need it; it stores nothing and logs no tokens.
 *
 *   GET  /gdrive/start     "Connect Google Drive to <site>?" page, then Google's consent screen
 *   GET  /gdrive/callback  Google comes back here; the code is passed on to the site
 *   POST /gdrive/token     code + PKCE verifier -> tokens (called by the site's server)
 *   POST /gdrive/refresh   refresh token -> a new access token (called by the site's server)
 *
 * The code alone is useless: swapping it needs the PKCE verifier, which
 * never leaves the site that started the sign-in.
 *
 * Settings (wrangler.jsonc vars and secrets): GOOGLE_CLIENT_ID,
 * GOOGLE_CLIENT_SECRET, STATE_KEY (any long random string).
 */

const AUTHORIZE = 'https://accounts.google.com/o/oauth2/v2/auth';
const TOKEN = 'https://oauth2.googleapis.com/token';
const SCOPE = 'https://www.googleapis.com/auth/drive.file';
const PLUGIN_PAGE = 'https://wordpress.org/plugins/rebuzz-backup-and-restore/';

// A sign-in left open longer than this has to start again.
const STATE_MAX_AGE = 3600;

export default {
    async fetch(request, env) {
        const url = new URL(request.url);

        try {
            switch (`${request.method} ${url.pathname}`) {
                case 'GET /':
                    return Response.redirect(PLUGIN_PAGE, 302);
                case 'GET /gdrive/start':
                    return await start(url, env);
                case 'GET /gdrive/callback':
                    return await callback(url, env);
                case 'POST /gdrive/token':
                    return await token(request, url, env);
                case 'POST /gdrive/refresh':
                    return await refresh(request, env);
                default:
                    return new Response('Not found', { status: 404 });
            }
        } catch (e) {
            return json({ error: 'relay_error', error_description: 'The relay failed. Try again in a minute.' }, 500);
        }
    },
};

/* Signing in */

/** Shows which site asked, so nobody can be talked into connecting their Drive to someone else's site. */
async function start(url, env) {
    const site = returnUrl(url.searchParams.get('return'));
    const state = url.searchParams.get('state') || '';
    const challenge = url.searchParams.get('challenge') || '';

    if (!site || !/^[A-Za-z0-9_-]{16,128}$/.test(state) || !/^[A-Za-z0-9_-]{43}$/.test(challenge)) {
        return page('Link not valid', '<p>This sign-in link is incomplete. Go back to the Storage tab of ReBuzz Backup and click <b>Connect Google Drive</b> again.</p>', 400);
    }

    const signed = await sign(env, { r: site.href, s: state, t: now() });

    const google = new URL(AUTHORIZE);
    google.search = new URLSearchParams({
        client_id: env.GOOGLE_CLIENT_ID,
        redirect_uri: redirectUri(url),
        response_type: 'code',
        scope: SCOPE,
        access_type: 'offline',
        // Always ask, so Google always returns a refresh token.
        prompt: 'consent',
        state: signed,
        code_challenge: challenge,
        code_challenge_method: 'S256',
    }).toString();

    const host = escapeHtml(site.host);

    return page('Connect Google Drive', `
        <p>ReBuzz Backup &amp; Restore will connect your Google Drive to the WordPress site at</p>
        <p class="site">${host}</p>
        <p>Backups from that site will be stored in your Drive. The plugin can only see the files it creates there, nothing else.</p>
        <p class="warn">Only continue if you clicked <b>Connect Google Drive</b> on <b>${host}</b> yourself.</p>
        <p><a class="button" href="${escapeHtml(google.href)}">Continue to Google</a></p>
        <p><a href="${escapeHtml(site.href)}">Cancel and go back</a></p>`);
}

/** Passes Google's answer back to the site that asked. Only states this relay signed are accepted. */
async function callback(url, env) {
    const state = await verify(env, url.searchParams.get('state') || '');

    if (!state || now() - state.t > STATE_MAX_AGE) {
        return page('Sign-in expired', '<p>This sign-in has expired or was not started from ReBuzz Backup. Go back to the Storage tab and click <b>Connect Google Drive</b> again.</p>', 400);
    }

    const site = returnUrl(state.r);

    if (!site) {
        return page('Link not valid', '<p>This sign-in link is not valid.</p>', 400);
    }

    const code = url.searchParams.get('code');

    site.searchParams.set('wpcb_gdrive_state', state.s);

    if (code) {
        site.searchParams.set('wpcb_gdrive_code', code);
    } else {
        site.searchParams.set('wpcb_gdrive_error', (url.searchParams.get('error') || 'unknown').slice(0, 64));
    }

    return Response.redirect(site.href, 302);
}

/* Server-to-server, from the WordPress site */

async function token(request, url, env) {
    const form = await request.formData();
    const code = String(form.get('code') || '');
    const verifier = String(form.get('code_verifier') || '');

    if (!code || code.length > 2048 || !/^[A-Za-z0-9._~-]{43,128}$/.test(verifier)) {
        return json({ error: 'invalid_request', error_description: 'code and code_verifier are required.' }, 400);
    }

    return google(env, {
        grant_type: 'authorization_code',
        code,
        code_verifier: verifier,
        redirect_uri: redirectUri(url),
    });
}

async function refresh(request, env) {
    const form = await request.formData();
    const refreshToken = String(form.get('refresh_token') || '');

    if (!refreshToken || refreshToken.length > 2048) {
        return json({ error: 'invalid_request', error_description: 'refresh_token is required.' }, 400);
    }

    return google(env, { grant_type: 'refresh_token', refresh_token: refreshToken });
}

/** Google's token endpoint with the client credentials added; its answer is passed on as it is. */
async function google(env, params) {
    const response = await fetch(TOKEN, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({
            ...params,
            client_id: env.GOOGLE_CLIENT_ID,
            client_secret: env.GOOGLE_CLIENT_SECRET,
        }),
    });

    return new Response(await response.text(), {
        status: response.status,
        headers: { 'Content-Type': 'application/json', 'Cache-Control': 'no-store' },
    });
}

/* Helpers */

/** The plugin's Storage tab on some WordPress site, or null - never any other address. */
function returnUrl(value) {
    let site;

    try {
        site = new URL(value || '');
    } catch (e) {
        return null;
    }

    const ok = (site.protocol === 'https:' || site.protocol === 'http:')
        && !site.username && !site.password
        && site.pathname.endsWith('/wp-admin/admin.php')
        && site.searchParams.get('page') === 'wpcb-storage';

    if (!ok) {
        return null;
    }

    site.hash = '';

    return site;
}

function redirectUri(url) {
    return `${url.origin}/gdrive/callback`;
}

async function hmac(env, data) {
    const key = await crypto.subtle.importKey('raw', new TextEncoder().encode(env.STATE_KEY), { name: 'HMAC', hash: 'SHA-256' }, false, ['sign']);

    return b64url(new Uint8Array(await crypto.subtle.sign('HMAC', key, new TextEncoder().encode(data))));
}

async function sign(env, payload) {
    const body = b64url(new TextEncoder().encode(JSON.stringify(payload)));

    return `${body}.${await hmac(env, body)}`;
}

async function verify(env, signed) {
    const [body, mac] = signed.split('.');

    if (!body || !mac || !env.STATE_KEY) {
        return null;
    }

    const expected = await hmac(env, body);

    // Compared in full, not stopping at the first difference.
    let diff = expected.length ^ mac.length;
    for (let i = 0; i < Math.min(expected.length, mac.length); i++) {
        diff |= expected.charCodeAt(i) ^ mac.charCodeAt(i);
    }

    if (diff !== 0) {
        return null;
    }

    try {
        const payload = JSON.parse(new TextDecoder().decode(fromB64url(body)));
        return typeof payload.r === 'string' && typeof payload.s === 'string' && Number.isInteger(payload.t) ? payload : null;
    } catch (e) {
        return null;
    }
}

function b64url(bytes) {
    let s = '';
    for (const b of bytes) {
        s += String.fromCharCode(b);
    }
    return btoa(s).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
}

function fromB64url(s) {
    const bin = atob(s.replace(/-/g, '+').replace(/_/g, '/'));
    return Uint8Array.from(bin, (c) => c.charCodeAt(0));
}

function now() {
    return Math.floor(Date.now() / 1000);
}

function json(body, status) {
    return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json', 'Cache-Control': 'no-store' } });
}

function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

function page(title, body, status = 200) {
    return new Response(`<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="referrer" content="no-referrer">
<title>${escapeHtml(title)} - ReBuzz Backup</title>
<style>
  :root { color-scheme: light dark; --bg: #f6f7f7; --card: #fff; --text: #1d2327; --muted: #50575e; --accent: #2271b1; --warn: #fcf9e8; --warn-border: #dba617; }
  @media (prefers-color-scheme: dark) { :root { --bg: #16181a; --card: #1f2326; --text: #e8eaed; --muted: #a7aaad; --accent: #72aee6; --warn: #2c2a1c; --warn-border: #a8841a; } }
  body { margin: 0; padding: 48px 16px; background: var(--bg); color: var(--text); font: 16px/1.55 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
  main { max-width: 520px; margin: 0 auto; background: var(--card); border-radius: 8px; padding: 28px 28px 20px; box-shadow: 0 1px 3px rgba(0,0,0,.12); }
  h1 { font-size: 22px; margin: 0 0 16px; }
  .site { font-size: 20px; font-weight: 600; word-break: break-all; }
  .warn { background: var(--warn); border-left: 4px solid var(--warn-border); padding: 10px 12px; }
  a { color: var(--accent); }
  .button { display: inline-block; background: var(--accent); color: #fff; padding: 10px 18px; border-radius: 4px; text-decoration: none; font-weight: 600; }
  @media (prefers-color-scheme: dark) { .button { color: #101214; } }
  footer { margin-top: 20px; font-size: 13px; color: var(--muted); }
</style>
</head>
<body>
<main>
<h1>${escapeHtml(title)}</h1>
${body}
<footer>ReBuzz Backup &amp; Restore for WordPress</footer>
</main>
</body>
</html>`, {
        status,
        headers: {
            'Content-Type': 'text/html; charset=utf-8',
            'Cache-Control': 'no-store',
            'X-Frame-Options': 'DENY',
            'Content-Security-Policy': "default-src 'none'; style-src 'unsafe-inline'; frame-ancestors 'none'",
        },
    });
}
