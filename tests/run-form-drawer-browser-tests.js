const assert = require('node:assert/strict');
const { chromium } = require('playwright');

(async () => {
    const browser = await chromium.launch({ headless: true });
    try {
        const page = await browser.newPage();
        await page.setContent(`
            <button id="add" type="button" data-drawer-add="#drawer">Add</button>
            <button id="edit" type="button" data-drawer-edit="#drawer" data-record='{"user_id":9,"full_name":"Ada Example","role":"admin"}'>Edit</button>
            <button id="details" type="button" data-drawer-open="#auditDrawer" data-drawer-content='{"actor":"Audit User","role":"admin","action":"program.updated","details":{"before":{"name":"<img src=x>"},"after":{"name":"Updated"}}}'>Details</button>
            <div id="drawer" class="offcanvas" data-form-drawer data-record-label="account" data-open="false" data-editing="false">
                <form data-drawer-form data-submit-once data-confirm-edit="Apply this role and account-status change?" data-announcement-form>
                    <input type="hidden" name="_csrf" value="fixture">
                    <input type="hidden" name="user_id" value="0" data-drawer-id>
                    <div data-drawer-mode="add">
                        <input name="full_name" value="">
                        <label><input type="radio" name="publication_mode" value="publish" checked data-announcement-mode>Publish</label>
                        <label><input type="radio" name="publication_mode" value="schedule" data-announcement-mode>Schedule</label>
                        <div data-schedule-fields hidden><input type="datetime-local" name="starts_at" data-schedule-date></div>
                    </div>
                    <div data-drawer-mode="edit" hidden>
                        <input name="full_name" value="">
                        <select name="role"><option value="reviewer">Reviewer</option><option value="admin">Administrator</option></select>
                    </div>
                    <button type="submit" data-drawer-save data-add-label="Create" data-edit-label="Save changes">Create</button>
                </form>
                <h2 data-drawer-title></h2><p data-drawer-subtitle></p>
                <button id="close" type="button" data-bs-dismiss="offcanvas">Close</button>
            </div>
            <div id="auditDrawer" class="offcanvas" data-form-drawer data-record-label="audit event" data-open="false" data-editing="false">
                <h2 data-drawer-title></h2><p data-drawer-subtitle></p><div data-drawer-body></div><button id="audit-close" data-bs-dismiss="offcanvas">Close</button>
            </div>
        `);
        await page.addStyleTag({ path: 'public/assets/css/app.css' });
        assert.ok(await page.evaluate(() => document.styleSheets[0].cssRules.length > 0));
        await page.evaluate(() => {
            window.confirmCalls = 0;
            window.confirm = () => { window.confirmCalls += 1; return false; };
            window.bootstrap = { Offcanvas: { getOrCreateInstance(element) { return { show() { element.dispatchEvent(new Event('shown.bs.offcanvas')); } }; } } };
        });
        await page.addScriptTag({ path: 'public/assets/js/app.js' });

        await page.locator('#add').click();
        assert.equal(await page.locator('#drawer [data-drawer-mode="add"]').isVisible(), true);
        assert.equal(await page.locator('#drawer [data-drawer-mode="edit"]').isVisible(), false);
        assert.equal(await page.locator('#drawer input[name="publication_mode"][value="publish"]').isChecked(), true);
        await page.locator('#drawer input[name="publication_mode"][value="schedule"]').check();
        assert.equal(await page.locator('#drawer [data-schedule-fields]').isVisible(), true);
        assert.equal(await page.locator('#drawer [data-schedule-date]').evaluate(element => element.required), true);
        await page.locator('#drawer input[name="full_name"]').first().fill('New reviewer');
        const closeWasBlocked = await page.locator('#drawer').evaluate(element => {
            const event = new Event('hide.bs.offcanvas', { cancelable: true });
            element.dispatchEvent(event);
            return event.defaultPrevented;
        });
        assert.equal(closeWasBlocked, true);

        await page.locator('#edit').click();
        assert.equal(await page.locator('#drawer [data-drawer-mode="edit"]').isVisible(), true);
        assert.equal(await page.locator('#drawer [data-drawer-mode="add"]').isVisible(), false);
        assert.equal(await page.locator('#drawer input[name="user_id"]').inputValue(), '9');
        assert.equal(await page.locator('#drawer input[name="full_name"]').last().inputValue(), 'Ada Example');
        assert.equal(await page.locator('#drawer select[name="role"]').inputValue(), 'admin');
        assert.equal(await page.locator('#drawer [data-drawer-save]').isDisabled(), true);

        await page.locator('#drawer select[name="role"]').selectOption('reviewer');
        assert.equal(await page.locator('#drawer [data-drawer-save]').isDisabled(), false);
        const submitPrevented = await page.locator('#drawer form').evaluate(form => {
            const event = new Event('submit', { bubbles: true, cancelable: true });
            form.dispatchEvent(event);
            return event.defaultPrevented;
        });
        assert.equal(submitPrevented, true);
        assert.equal(await page.evaluate(() => window.confirmCalls), 2);
        assert.equal(await page.locator('#drawer [data-drawer-save]').isDisabled(), false);

        await page.locator('#drawer').dispatchEvent('hidden.bs.offcanvas');
        assert.equal(await page.evaluate(() => document.activeElement.id), 'edit');

        await page.locator('#details').click();
        assert.equal(await page.locator('#auditDrawer [data-drawer-title]').textContent(), 'program updated');
        assert.equal(await page.locator('#auditDrawer').getByText('Previous values').count(), 1);
        assert.equal(await page.locator('#auditDrawer').getByText('<img src=x>').count(), 1);
        assert.equal(await page.locator('#auditDrawer img').count(), 0);
        assert.equal(await page.locator('#auditDrawer').getByText('New values').count(), 1);
        console.log('Shared drawer browser tests: 23 assertions, 0 failures.');
    } finally {
        await browser.close();
    }
})().catch(error => {
    console.error(error);
    process.exitCode = 1;
});
