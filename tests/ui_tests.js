/* =====================================================================
 * tests/ui_tests.js - browser checks for the JavaScript side
 *
 * run_tests.php drives the site without a browser, so it cannot see
 * client-side validation or the seat picker. These
 * five cases cover that, using Playwright (headless Chromium).
 *
 * Optional: needs Node.js and Playwright
 *     npm install playwright && npx playwright install chromium
 * Then, with the site running (XAMPP Apache + MySQL, seed imported):
 *     node tests/ui_tests.js http://localhost/Cinema-Web-Application-design/cinesphere/
 *
 * Without Node, run the same five cases by hand from tests/README.md.
 * ===================================================================== */

const { chromium } = require('playwright');
const channel = process.env.PLAYWRIGHT_CHANNEL;

const BASE = (process.argv[2] || 'http://localhost/Cinema-Web-Application-design/cinesphere/')
    .replace(/\/?$/, '/');

const cases = [];
const tc = (id, req, title, input, expected, run) => cases.push({ id, req, title, input, expected, run });

async function fieldError(page, selector) {
    return page.$eval(selector, (el) => {
        const box = el.closest('.field') && el.closest('.field').querySelector('.error');
        return box ? box.textContent.trim() : '';
    });
}

/* UI01 ------------------------------------------------------------- */
tc('UI01', 'F3, R8', 'Register: passwords that do not match are caught before sending', 'Name, email, phone, born 2000-05-17, password Movie#2026, confirm Movie#2027, terms ticked; press Create account', 'Inline "passwords do not match" error under Confirm; the form is not sent', async (page) => {
    await page.goto(BASE + 'account.php?tab=register');
    await page.fill('#r-name', 'Tan Wei Ming');
    await page.fill('#r-email', 'ui.test@example.com');
    await page.fill('#r-phone', '+65 9123 4567');
    await page.fill('#r-dob', '2000-05-17');
    await page.fill('#r-pass', 'Movie#2026');
    await page.fill('#r-confirm', 'Movie#2027');
    await page.check('#r-terms');
    let posted = false;
    page.on('request', (r) => { if (r.method() === 'POST') posted = true; });
    await page.click('#r-terms >> xpath=ancestor::form//button[@type="submit"]');
    await page.waitForTimeout(400);
    const msg = await fieldError(page, '#r-confirm');
    return [!posted && /match/i.test(msg), `Form ${posted ? 'WAS' : 'not'} sent; message: "${msg}"`];
});

/* UI02 ------------------------------------------------------------- */
tc('UI02', 'F3, R8', 'Register: age under 13 is caught before sending', 'Date of birth = today minus 10 years, then leave the field', 'Inline error "must be at least 13 years old"', async (page) => {
    await page.goto(BASE + 'account.php?tab=register');
    const d = new Date(); d.setFullYear(d.getFullYear() - 10);
    await page.fill('#r-dob', d.toISOString().slice(0, 10));
    await page.locator('#r-dob').blur();
    const msg = await fieldError(page, '#r-dob');
    return [/13/.test(msg), `Message: "${msg}"`];
});

/* UI03 ------------------------------------------------------------- */
tc('UI03', 'F4', 'Seat map: picking 2 seats updates the total and the hidden field', 'Signed in as demo; open the first screening on the schedule; click the first two free seats', 'Total = both seat prices + S$0.80 each; hidden seat_ids field holds both seat ids', async (page) => {
    await page.goto(BASE + 'account.php?tab=login');
    await page.fill('#l-email', 'demo@cinesphere.sg');
    await page.fill('#l-pass', 'Demo@123');
    await page.press('#l-pass', 'Enter');
    await page.waitForLoadState();
    await page.goto(BASE + 'showtimes.php');
    const href = await page.$eval('a[href*="booking.php?showtime="]', (a) => a.getAttribute('href'));
    await page.goto(BASE + href);
    const seats = page.locator('button.seat:not([disabled])');
    const prices = [];
    for (let i = 0; i < 2; i++) {
        prices.push(parseFloat(await seats.nth(i).getAttribute('data-price')));
        await seats.nth(i).click();
    }
    const fee = parseFloat(await page.getAttribute('#seatmap', 'data-fee'));
    const expected = (prices[0] + prices[1] + 2 * fee).toFixed(2);
    const total = (await page.textContent('#sum-total')).replace(/[^0-9.]/g, '');
    const hidden = await page.inputValue('#seat_ids');
    const ok = total === expected && hidden.split(',').filter(Boolean).length === 2;
    return [ok, `Total S$${total} (expected S$${expected}); seat_ids="${hidden}"`];
});

/* UI04 ------------------------------------------------------------- */
tc('UI04', 'F4, R5', 'Seat map: arrow keys move between seats', 'Focus the first free seat, press the Right arrow key', 'Keyboard focus moves to the next seat', async (page) => {
    const first = page.locator('button.seat:not([disabled])').first();
    await first.focus();
    const before = await page.evaluate(() => document.activeElement.getAttribute('data-seat'));
    await page.keyboard.press('ArrowRight');
    const after = await page.evaluate(() => document.activeElement.getAttribute('data-seat'));
    return [before && after && before !== after, `Focus moved from seat ${before} to seat ${after}`];
});

/* UI05 ------------------------------------------------------------- */
tc('UI05', 'F9, R8', 'Contact: a message under 20 characters is caught before sending', 'Valid contact details with message "Too short" (9 characters); press Send', 'Inline error "at least 20 characters"; the form is not sent', async (page) => {
    await page.goto(BASE + 'contact.php');
    await page.fill('#c-name', 'Aisha Rahman');
    await page.fill('#c-email', 'aisha@example.com');
    await page.fill('#c-phone', '+65 8123 4567');
    await page.selectOption('#c-subject', 'Feedback');
    await page.selectOption('#c-rating', '5');
    await page.fill('#c-message', 'Too short');
    let posted = false;
    page.on('request', (r) => { if (r.method() === 'POST') posted = true; });
    await page.click('#c-message >> xpath=ancestor::form//button[@type="submit"]');
    await page.waitForTimeout(400);
    const msg = await fieldError(page, '#c-message');
    return [!posted && /20/.test(msg), `Form ${posted ? 'WAS' : 'not'} sent; message: "${msg}"`];
});

(async () => {
    const browser = await chromium.launch(channel ? { channel } : {});
    const page = await browser.newPage();
    let pass = 0;
    const results = [];
    console.log(`\nCineSphere browser tests against ${BASE}\n`);
    for (const c of cases) {
        let ok = false, actual = '';
        try { [ok, actual] = await c.run(page); } catch (e) { actual = 'Error: ' + e.message.split('\n')[0]; }
        if (ok) pass++;
        results.push({ ...c, ok, actual });
        console.log(`  ${ok ? 'PASS' : 'FAIL'}  ${c.id}  ${c.title}\n        ${actual}`);
    }
    console.log(`\n  ${pass} of ${cases.length} passed\n`);

    /* Same table layout as test-results.md, for the report. */
    const cell = (t) => String(t).replace(/\|/g, '\\|').replace(/\n/g, ' ');
    let md = '# CineSphere browser test results\n\n'
        + `Generated by \`tests/ui_tests.js\` on ${new Date().toLocaleString('en-SG')} against ${BASE}.\n\n`
        + `**${pass} of ${cases.length} test cases passed.**\n\n`
        + '| ID | Req. | Test case | Input data | Expected output | Test method | Test result |\n'
        + '| --- | --- | --- | --- | --- | --- | --- |\n';
    for (const r of results) {
        md += '| ' + [r.id, r.req, r.title, r.input, r.expected, `Automated browser (Playwright, ${channel || 'Chromium'})`,
                      (r.ok ? 'PASS - ' : 'FAIL - ') + r.actual].map(cell).join(' | ') + ' |\n';
    }
    require('fs').writeFileSync(require('path').join(__dirname, 'ui-test-results.md'), md);
    console.log('  Results table written to tests/ui-test-results.md\n');
    await browser.close();
    process.exit(pass === cases.length ? 0 : 1);
})();
