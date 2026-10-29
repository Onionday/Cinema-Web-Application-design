/* Read-only modern catalogue checks against a running local site.
   NODE_PATH must resolve Playwright; PLAYWRIGHT_CHANNEL=msedge is supported. */
const { chromium } = require('playwright');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const base = (process.argv[2] || 'http://127.0.0.1:8088/cinesphere/').replace(/\/?$/, '/');
const records = [];
let browser;
async function test(name, run) {
    try { await run(); records.push({ name, result: 'PASS' }); }
    catch (error) { records.push({ name, result: 'FAIL', error: error.message }); }
    console.log(records.at(-1));
}
async function ready(page, q) {
    await page.waitForFunction(q => document.querySelector('#movie-results').getAttribute('aria-busy') === 'false'
        && new URL(location.href).searchParams.get('q') === q, q);
}
(async () => {
    browser = await chromium.launch({ headless: true, channel: process.env.PLAYWRIGHT_CHANNEL || 'msedge' });
    const context = await browser.newContext();
    const page = await context.newPage();
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.goto(base + 'movies.php');
    await page.evaluate(() => window.searchTestMarker = 'same-document');
    await test('Typing updates cards without navigation and preserves focus', async () => {
        await page.locator('#f-q').fill('Meridian');
        await ready(page, 'Meridian');
        assert.equal(await page.locator('.film-card').count(), 1);
        assert.equal(await page.locator('.film-card h3').textContent(), 'Meridian');
        assert.equal(await page.evaluate(() => window.searchTestMarker), 'same-document');
        assert.equal(await page.evaluate(() => document.activeElement.id), 'f-q');
    });
    await test('Unknown keyword shows empty state and reset restores catalogue', async () => {
        await page.locator('#f-q').fill('12345');
        await ready(page, '12345');
        assert.equal(await page.locator('.film-card').count(), 0);
        assert.match(await page.locator('.empty-state').innerText(), /Nothing matched/i);
        await page.locator('.empty-state [data-search-reset]').click();
        await ready(page, '');
        assert.ok(await page.locator('.film-card').count() > 1);
    });
    await test('Combined keyword/status/genre/rating/sort matches server fallback', async () => {
        await page.locator('#f-status').selectOption('all');
        await page.locator('#f-genre').selectOption({ index: 1 });
        await page.locator('#f-rating').selectOption('PG13');
        await page.locator('#f-sort').selectOption('title');
        await page.locator('#f-q').fill('a');
        await ready(page, 'a');
        const params = await page.locator('#movie-filters').evaluate(f => new URLSearchParams(new FormData(f)).toString());
        const response = await context.request.get(base + 'movies.php?' + params);
        const html = await response.text();
        const expected = [...html.matchAll(/<h3><a href="movie.php\?id=\d+">(.*?)<\/a><\/h3>/g)].map(m => m[1]);
        const actual = await page.locator('.film-card h3 a').evaluateAll(nodes => nodes.map(n => n.innerHTML));
        assert.deepEqual(actual, expected);
        assert.equal(await page.evaluate(() => window.searchTestMarker), 'same-document');
    });
    await test('An outdated delayed response cannot overwrite a newer search', async () => {
        await page.locator('form [data-search-reset]').click();
        await ready(page, '');
        await page.route('**/movies_search.php?**', async route => {
            if (new URL(route.request().url()).searchParams.get('q') === 'Meridian') {
                const response = await route.fetch();
                await new Promise(resolve => setTimeout(resolve, 900));
                await route.fulfill({ response }).catch(() => {});
            } else { await route.continue(); }
        });
        const started = page.waitForRequest(r => r.url().includes('movies_search.php') && new URL(r.url()).searchParams.get('q') === 'Meridian');
        await page.locator('#f-q').fill('Meridian');
        await started;
        await page.locator('#f-q').fill('Kopi');
        await ready(page, 'Kopi');
        await page.waitForTimeout(1100);
        assert.equal(await page.locator('.film-card h3').textContent(), 'Kopi & Kaya');
        await page.unrouteAll({ behavior: 'wait' });
    });
    await test('Network failure retains results; retry recovers', async () => {
        await page.route('**/movies_search.php?**', route => route.abort());
        await page.locator('#f-q').fill('Meridian');
        await page.locator('#search-retry').waitFor({ state: 'visible' });
        assert.match(await page.locator('#search-status').innerText(), /Previous results/);
        assert.equal(await page.locator('.film-card h3').textContent(), 'Kopi & Kaya');
        await page.unrouteAll();
        await page.locator('#search-retry').click();
        await ready(page, 'Meridian');
        assert.equal(await page.locator('.film-card h3').textContent(), 'Meridian');
    });
    await test('Back and Forward restore controls and results without reloading', async () => {
        await page.locator('#f-q').fill('Kopi');
        await ready(page, 'Kopi');
        await page.goBack();
        await ready(page, 'Meridian');
        assert.equal(await page.locator('#f-q').inputValue(), 'Meridian');
        assert.equal(await page.locator('.film-card h3').textContent(), 'Meridian');
        await page.goForward();
        await ready(page, 'Kopi');
        assert.equal(await page.locator('.film-card h3').textContent(), 'Kopi & Kaya');
        assert.equal(await page.evaluate(() => window.searchTestMarker), 'same-document');
    });
    await test('HTML-like search is escaped; malformed parameters are handled', async () => {
        await page.locator('#f-q').fill('<img src=x onerror=alert(1)>');
        await ready(page, '<img src=x onerror=alert(1)>');
        assert.equal(await page.locator('#movie-results img[onerror]').count(), 0);
        assert.match(await page.locator('.empty-state').innerText(), /<img src=x/);
        const malformed = await context.request.get(base + 'movies_search.php?q[]=x&sort[]=x&status=invalid');
        assert.equal(malformed.status(), 200);
        assert.ok(Number.isInteger((await malformed.json()).count));
        const post = await context.request.post(base + 'movies_search.php');
        assert.equal(post.status(), 405);
    });
    await test('Mobile search works with no page overflow or JavaScript errors', async () => {
        await page.setViewportSize({ width: 390, height: 844 });
        await page.locator('form [data-search-reset]').click();
        await ready(page, '');
        await page.locator('#f-q').fill('Meridian');
        await ready(page, 'Meridian');
        assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth));
        assert.deepEqual(errors, []);
        await page.screenshot({ path: path.join(__dirname, 'search-mobile.png'), fullPage: true });
        await page.setViewportSize({ width: 1440, height: 1000 });
        await page.locator('form [data-search-reset]').click();
        await ready(page, '');
        await page.screenshot({ path: path.join(__dirname, 'search-desktop.png'), fullPage: true });
    });
    await test('Without JavaScript, Apply still performs ordinary PHP search', async () => {
        const fallbackContext = await browser.newContext({ javaScriptEnabled: false });
        const fallback = await fallbackContext.newPage();
        await fallback.goto(base + 'movies.php');
        await fallback.locator('#f-q').fill('Meridian');
        await Promise.all([fallback.waitForURL('**/movies.php?**'), fallback.locator('button[type=submit]').click()]);
        assert.equal(await fallback.locator('.film-card h3').textContent(), 'Meridian');
        await fallbackContext.close();
    });
    const passed = records.filter(r => r.result === 'PASS').length;
    fs.writeFileSync(path.join(__dirname, 'search-test-results.md'), '# Modern catalogue search tests\n\n'
        + 'Run: ' + new Date().toISOString() + '. Playwright / Edge against ' + base + '\n\n'
        + passed + '/' + records.length + ' passed. Read-only searches; existing local database not reset.\n\n'
        + '| Scenario | Result |\n|---|---|\n'
        + records.map(r => '| ' + r.name + ' | ' + r.result + (r.error ? ': ' + r.error.replace(/\n/g, ' ') : '') + ' |').join('\n') + '\n');
    await browser.close();
    process.exitCode = passed === records.length ? 0 : 1;
})().catch(async error => { console.error(error); if (browser) await browser.close(); process.exitCode = 1; });
