const assert = require('node:assert/strict');
const { chromium } = require('playwright');

const notificationMarkup = `
    <div data-notification-menu data-csrf="fixture-token">
        <button type="button" class="notification-bell" aria-label="Notifications, 2 unread" data-notification-toggle>
            <i class="bi bi-bell"></i><span class="notification-badge">2</span>
        </button>
        <section data-notification-panel>
            <div class="notification-dropdown-heading"><span data-notification-unread-count>2 unread</span></div>
            <div class="notification-dropdown-list">
                <article class="notification-dropdown-item is-unread">
                    <a href="/notifications#notification-42" data-notification-link data-notification-id="42">Recent update</a>
                    <button type="button" data-notification-mark data-notification-id="42">Mark read</button>
                </article>
            </div>
            <button type="button" data-notification-mark-all>Mark all as read</button>
        </section>
    </div>`;

(async () => {
    const browser = await chromium.launch({ headless: true });
    let assertions = 0;
    const check = (condition, message) => {
        assertions++;
        assert.ok(condition, message);
    };
    try {
        const page = await browser.newPage();
        await page.route('http://notifications.test/**', route => route.fulfill({ contentType: 'text/html', body: '<!doctype html><title>Account Updates</title>' }));
        await page.goto('http://notifications.test/home');
        await page.setContent(notificationMarkup);
        await page.addScriptTag({ path: 'public/assets/js/app.js' });
        await page.evaluate(() => {
            window.actionCalls = 0;
            window.fetch = () => {
                window.actionCalls++;
                return new Promise(resolve => { window.finishAction = resolve; });
            };
        });
        const markAll = page.locator('[data-notification-mark-all]');
        await markAll.click();
        check(await markAll.isDisabled(), 'mark-all is disabled while the server action is pending');
        check(await page.evaluate(() => window.actionCalls) === 1, 'a pending mark-all action cannot be submitted twice');
        await page.evaluate(() => window.finishAction({ ok: true, json: async () => ({ ok: true, message: 'Notifications marked as read.', unread_count: 0 }) }));
        await page.locator('.notification-dropdown-empty').waitFor();
        check(await page.locator('[data-notification-unread-count]').textContent() === '0 unread', 'successful mark-all immediately updates the unread label');
        check(await page.locator('.notification-badge').count() === 0 && await page.locator('.notification-dropdown-item').count() === 0, 'successful mark-all clears the unread badge and dropdown rows');

        await page.goto('http://notifications.test/home');
        await page.setContent(notificationMarkup);
        await page.addScriptTag({ path: 'public/assets/js/app.js' });
        await page.evaluate(() => {
            window.fetch = async () => ({ ok: false, json: async () => ({ ok: false, message: 'This notification could not be updated.' }) });
        });
        await page.locator('[data-notification-mark-all]').click();
        await page.locator('.toast-body').waitFor();
        check(await page.locator('.notification-dropdown-item').count() === 1 && await page.locator('[data-notification-unread-count]').textContent() === '2 unread', 'failed mark-all preserves unread items and the displayed count');
        check(await page.locator('[data-notification-mark-all]').isEnabled(), 'failed mark-all can be retried');

        const notificationLink = page.locator('[data-notification-link]');
        check(await notificationLink.getAttribute('href') === '/notifications#notification-42', 'recent notification links target its Account Updates row');
        await notificationLink.click();
        await page.waitForFunction(() => document.querySelectorAll('.toast-body').length >= 2);
        check(new URL(page.url()).pathname === '/home' && await notificationLink.getAttribute('aria-busy') === null, 'failed mark-read does not navigate away and clears the pending state');
        await page.evaluate(() => {
            window.fetch = async () => ({ ok: true, json: async () => ({ ok: true, message: 'Notification marked as read.', unread_count: 1 }) });
        });
        await notificationLink.click();
        await page.waitForURL('http://notifications.test/notifications#notification-42');
        check(new URL(page.url()).pathname === '/notifications' && new URL(page.url()).hash === '#notification-42', 'successful recent-notification click opens the matching Account Updates row');

        console.log(`Notification actions browser tests: ${assertions} assertions, 0 failures.`);
    } finally {
        await browser.close();
    }
})().catch(error => {
    console.error(error);
    process.exitCode = 1;
});