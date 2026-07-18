// Full frontend E2E with a real browser (two users, QR pairing, text + audio).
// Run: node api/tests/e2e_browser.js  (needs the local server on :8080)
const { chromium } = require('playwright');
const BASE = 'http://127.0.0.1:8080';
const SHOT = '/tmp/claude-0/-home-user-walkie/846d6d57-1a1e-524d-9763-dea3804ef62c/scratchpad';

let passed = 0, failed = 0;
function ok(d) { console.log('  ok  -', d); passed++; }
function bad(d, extra) { console.log('  FAIL-', d, extra || ''); failed++; }

async function signup(page, email) {
  const codeP = page.waitForResponse(r => r.url().includes('/auth/request-code'));
  await page.goto(BASE + '/web/');
  await page.fill('input[type=email]', email);
  await page.click('button:has-text("Enviar código")');
  const code = (await (await codeP).json()).debug_code;
  await page.fill('input[inputmode=numeric]', code);
  await page.click('button:has-text("Entrar")');
  await page.waitForSelector('.topbar h1');
}

(async () => {
  const browser = await chromium.launch({
    executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome',
    args: ['--use-fake-device-for-media-stream', '--use-fake-ui-for-media-stream']
  });
  const ctxA = await browser.newContext({ permissions: ['microphone'] });
  const ctxB = await browser.newContext({ permissions: ['microphone'] });
  const alice = await ctxA.newPage();
  const bob = await ctxB.newPage();
  alice.on('pageerror', e => console.log('  [alice jerror]', e.message));
  bob.on('pageerror', e => console.log('  [bob jerror]', e.message));

  try {
    // --- Auth ---
    await signup(alice, 'alice2@x.com');
    ok('Alice signs up and reaches home');
    await signup(bob, 'bob2@x.com');
    ok('Bob signs up and reaches home');

    // Empty state visible for Alice
    const empty = await alice.textContent('.empty');
    if (empty && empty.includes('Aún no tienes contactos')) ok('empty state shown'); else bad('empty state', empty);

    // --- Alice opens her QR, capture pairing token ---
    const qrP = alice.waitForResponse(r => r.url().includes('/link/qr'));
    await alice.click('button:has-text("Vincular")');
    const token = (await (await qrP).json()).token;
    await alice.waitForSelector('#qr-overlay svg');
    const svgBox = await alice.$('.qr-card svg');
    if (svgBox) ok('QR SVG rendered full-screen'); else bad('QR svg missing');
    await alice.screenshot({ path: SHOT + '/shot_qr.png' });

    // --- Bob pairs via deep link (same token the QR encodes) ---
    // Blank first so navigating to the #p= URL is a full load, like a real
    // QR scan opening the link fresh in the browser.
    const claimP = bob.waitForResponse(r => r.url().includes('/link/claim'));
    await bob.goto('about:blank');
    await bob.goto(BASE + '/web/#p=' + token);
    const claimRes = await claimP;
    if (claimRes.ok()) ok('Bob claims pairing token'); else bad('claim failed', claimRes.status());
    await bob.waitForSelector('.contact');
    ok('Bob sees Alice in list');

    // Alice's overlay auto-detects pairing and returns home
    await alice.waitForSelector('.contact', { timeout: 8000 });
    ok('Alice sees Bob in list');

    // --- Bob sends a text ---
    await bob.click('.contact');
    await bob.waitForSelector('#text-input');
    await bob.fill('#text-input', 'hola desde bob');
    await bob.click('#composer .ptt[title="Enviar"], #composer button[title="Enviar"]');
    await bob.waitForSelector('.bubble.mine');
    ok('Bob sends text');

    // --- Alice opens chat, sees the text ---
    await alice.click('.contact');
    await alice.waitForSelector('.bubble');
    const txt = await alice.textContent('.messages');
    if (txt.includes('hola desde bob')) ok('Alice receives Bob\'s text'); else bad('text not received', txt);

    // --- Alice records a voice note (push-to-talk) ---
    const ptt = await alice.$('#ptt');
    const box = await ptt.boundingBox();
    await alice.mouse.move(box.x + box.width / 2, box.y + box.height / 2);
    await alice.mouse.down();
    await alice.waitForTimeout(1000);
    await alice.mouse.up();
    // preview bar appears
    await alice.waitForSelector('.preview audio', { timeout: 5000 });
    ok('voice preview shown after release');
    const sendP = alice.waitForResponse(r => r.url().includes('/messages') && r.request().method() === 'POST');
    await alice.click('.preview button:has-text("Enviar")');
    const sr = await sendP;
    if (sr.ok()) ok('Alice sends voice note'); else bad('voice send failed', sr.status());

    // --- Bob receives audio bubble ---
    await bob.waitForSelector('.audio-msg', { timeout: 8000 });
    ok('Bob receives voice note (audio bubble)');

    await alice.screenshot({ path: SHOT + '/shot_chat_alice.png' });
    await bob.screenshot({ path: SHOT + '/shot_chat_bob.png' });

    // --- Settings: rename ---
    await alice.click('.topbar .iconbtn[title="Ajustes"]').catch(() => {});
  } catch (e) {
    bad('exception', e.message);
  } finally {
    console.log(`\nRESULT: ${passed} passed, ${failed} failed`);
    await browser.close();
    process.exit(failed ? 1 : 0);
  }
})();
