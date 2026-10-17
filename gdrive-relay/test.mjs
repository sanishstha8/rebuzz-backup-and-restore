// Tests the relay with Node's built-in fetch/crypto (same APIs as Workers). Run: node gdrive-relay/test.mjs
import worker from './worker.js';

const env = { GOOGLE_CLIENT_ID: 'cid.apps.googleusercontent.com', GOOGLE_CLIENT_SECRET: 'csecret', STATE_KEY: 'k'.repeat(40) };
const base = 'https://rebuzz-gdrive.example.workers.dev';
const site = 'https://example.com/wp-admin/admin.php?page=wpcb-storage';
const challenge = 'c'.repeat(43);
const state = 's'.repeat(32);

let pass = 0, fail = 0;
const check = (ok, what) => { console.log(`${ok ? 'PASS' : 'FAIL'}  ${what}`); ok ? pass++ : fail++; };
const get = (path) => worker.fetch(new Request(base + path, { redirect: 'manual' }), env);
const post = (path, body) => worker.fetch(new Request(base + path, { method: 'POST', body: new URLSearchParams(body) }), env);

// Google's token endpoint, faked: records what the relay sent.
let sent = null;
globalThis.fetch = async (url, init) => {
    sent = { url, params: Object.fromEntries(new URLSearchParams(init.body)) };
    return new Response(JSON.stringify({ access_token: 'at', refresh_token: 'rt', expires_in: 3599, scope: 'https://www.googleapis.com/auth/drive.file' }), { status: 200 });
};

/* start */

let r = await get(`/gdrive/start?return=${encodeURIComponent(site)}&state=${state}&challenge=${challenge}`);
let html = await r.text();
check(r.status === 200 && html.includes('example.com') && html.includes('Continue to Google'), 'start shows the site and a Continue button');
const googleUrl = new URL(html.match(/href="(https:\/\/accounts\.google\.com[^"]+)"/)[1].replace(/&amp;/g, '&'));
const q = googleUrl.searchParams;
check(q.get('client_id') === env.GOOGLE_CLIENT_ID && q.get('redirect_uri') === `${base}/gdrive/callback`, 'Google link has the client ID and the callback');
check(q.get('scope') === 'https://www.googleapis.com/auth/drive.file' && q.get('access_type') === 'offline' && q.get('prompt') === 'consent', 'only drive.file, offline, always consent');
check(q.get('code_challenge') === challenge && q.get('code_challenge_method') === 'S256', 'PKCE challenge passed through');
check(!html.includes('csecret'), 'the client secret is not on the page');
check(r.headers.get('x-frame-options') === 'DENY', 'the page cannot be framed');

for (const [what, ret] of [
    ['another page of the site', 'https://example.com/wp-admin/admin.php?page=other'],
    ['a non-admin path', 'https://evil.example/collect?page=wpcb-storage'],
    ['javascript:', 'javascript:alert(1)//wp-admin/admin.php?page=wpcb-storage'],
    ['user:pass@', 'https://u:p@example.com/wp-admin/admin.php?page=wpcb-storage'],
]) {
    r = await get(`/gdrive/start?return=${encodeURIComponent(ret)}&state=${state}&challenge=${challenge}`);
    check(r.status === 400, `start refuses ${what}`);
}
r = await get(`/gdrive/start?return=${encodeURIComponent(site)}&state=${state}&challenge=short`);
check(r.status === 400, 'start refuses a malformed challenge');
r = await get(`/gdrive/start?return=${encodeURIComponent(site)}&state=x&challenge=${challenge}`);
check(r.status === 400, 'start refuses a short state');

r = await get(`/gdrive/start?return=${encodeURIComponent('https://ex<script>.com/wp-admin/admin.php?page=wpcb-storage')}&state=${state}&challenge=${challenge}`);
html = await r.text();
check(!html.includes('<script>'), 'the site name is HTML-escaped');

/* callback */

const signed = q.get('state');
r = await get(`/gdrive/callback?code=4%2Fabc&state=${encodeURIComponent(signed)}`);
let back = new URL(r.headers.get('location') || 'http://x');
check(r.status === 302 && back.origin === 'https://example.com' && back.searchParams.get('page') === 'wpcb-storage', 'callback goes back to the Storage tab');
check(back.searchParams.get('wpcb_gdrive_code') === '4/abc' && back.searchParams.get('wpcb_gdrive_state') === state, 'with the code and the site\'s own state');

r = await get(`/gdrive/callback?error=access_denied&state=${encodeURIComponent(signed)}`);
back = new URL(r.headers.get('location') || 'http://x');
check(back.searchParams.get('wpcb_gdrive_error') === 'access_denied' && !back.searchParams.has('wpcb_gdrive_code'), 'a refusal is passed back as an error');

const [body, mac] = signed.split('.');
const forged = Buffer.from(JSON.stringify({ r: 'https://evil.example/wp-admin/admin.php?page=wpcb-storage', s: state, t: Math.floor(Date.now() / 1000) })).toString('base64url');
r = await get(`/gdrive/callback?code=x&state=${encodeURIComponent(forged + '.' + mac)}`);
check(r.status === 400, 'a state with a changed address is refused');
r = await get(`/gdrive/callback?code=x&state=${encodeURIComponent(forged)}`);
check(r.status === 400, 'an unsigned state is refused');

const realNow = Date.now;
Date.now = () => realNow() + 2 * 3600 * 1000;
r = await get(`/gdrive/callback?code=x&state=${encodeURIComponent(signed)}`);
Date.now = realNow;
check(r.status === 400, 'a state older than an hour is refused');

/* token and refresh */

r = await post('/gdrive/token', { code: '4/abc', code_verifier: 'v'.repeat(64) });
let j = await r.json();
check(r.status === 200 && j.refresh_token === 'rt', 'token passes Google\'s answer on');
check(sent.url === 'https://oauth2.googleapis.com/token' && sent.params.client_secret === 'csecret' && sent.params.grant_type === 'authorization_code', 'token adds the client secret');
check(sent.params.code_verifier === 'v'.repeat(64) && sent.params.redirect_uri === `${base}/gdrive/callback`, 'token sends the verifier and the same callback');
check(r.headers.get('cache-control') === 'no-store', 'tokens are never cached');

r = await post('/gdrive/token', { code: '4/abc' });
check(r.status === 400, 'token without a verifier is refused');

r = await post('/gdrive/refresh', { refresh_token: 'rt' });
check(r.status === 200 && sent.params.grant_type === 'refresh_token' && sent.params.refresh_token === 'rt' && sent.params.client_secret === 'csecret', 'refresh adds the client secret');

globalThis.fetch = async () => new Response('{"error":"invalid_grant"}', { status: 400 });
r = await post('/gdrive/refresh', { refresh_token: 'revoked' });
j = await r.json();
check(r.status === 400 && j.error === 'invalid_grant', 'Google\'s refusal is passed on with its status');

globalThis.fetch = async () => { throw new Error('network down'); };
r = await post('/gdrive/refresh', { refresh_token: 'rt' });
check(r.status === 500, 'a network failure answers 500, not a crash');

r = await get('/gdrive/token');
check(r.status === 404, 'GET on the token address is not found');
r = await get('/');
check(r.status === 302 && r.headers.get('location').includes('wordpress.org/plugins/rebuzz'), 'the root goes to the plugin page');

console.log(`\n${pass} passed, ${fail} failed`);
process.exit(fail ? 1 : 0);
