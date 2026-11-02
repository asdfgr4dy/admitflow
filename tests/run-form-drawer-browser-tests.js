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

        const dropdownPage = await browser.newPage({ viewport: { width: 900, height: 360 } });
        const dropdownConsoleErrors = [];
        dropdownPage.on('console', message => { if (message.type() === 'error') dropdownConsoleErrors.push(message.text()); });
        await dropdownPage.setContent(`
            <main class="staff-wrap container-xl">
                <h1>Academic Years</h1>
                <p>Configured admission intake records.</p>
                <p>Existing assignments remain attached to their intake.</p>
                <p>Changes apply to new applications.</p>
                <p>Use the row actions to edit or change admission status.</p>
                <p>Program and requirement settings remain available.</p>
                <div class="table-responsive staff-table-wrap academic-table-wrap"><table class="table staff-table academic-table"><tbody>
                    <tr><td>AY Browser Fixture</td><td>Aug 2026</td><td>May 2027</td><td><div class="dropdown academic-row-actions"><button id="academic-actions" class="btn btn-sm btn-outline-navy" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Actions for AY Browser Fixture">...</button><ul class="dropdown-menu dropdown-menu-end"><li><button class="dropdown-item" type="button" data-academic-edit data-record='{"year_id":77,"label":"AY Browser Fixture","starts_on":"2026-08-01","ends_on":"2027-05-31","is_open":1}'>Edit</button></li><li><button class="dropdown-item" type="button">Close admission period</button></li></ul></div></td></tr>
                </tbody></table></div>
            </main>
            <div id="academicRecordDrawer" class="offcanvas offcanvas-end admitflow-form-drawer" tabindex="-1" data-form-drawer data-record-label="academic year" data-open="false" data-editing="false">
                <h2 data-drawer-title></h2><p data-drawer-subtitle></p>
                <form data-drawer-form><input type="hidden" name="year_id" value="0" data-drawer-id><div data-drawer-mode="add"><input name="label" value=""></div><div data-drawer-mode="edit" hidden><input name="label" value=""><input type="date" name="starts_on"><input type="date" name="ends_on"><input type="checkbox" name="is_open" value="1"></div><button type="submit" data-drawer-save data-add-label="Add year" data-edit-label="Save changes">Save</button></form>
                <button id="academic-drawer-close" type="button" data-bs-dismiss="offcanvas">Close</button>
            </div>
            <button id="outside-page-control" type="button">Outside</button>
        `);
        await dropdownPage.addStyleTag({ url: 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css' });
        await dropdownPage.addStyleTag({ path: 'public/assets/css/app.css' });
        await dropdownPage.addScriptTag({ url: 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js' });
        await dropdownPage.addScriptTag({ path: 'public/assets/js/app.js' });
        await dropdownPage.locator('#academic-actions').scrollIntoViewIfNeeded();
        await dropdownPage.locator('#academic-actions').click();
        await dropdownPage.waitForFunction(() => document.querySelector('#academic-actions')?.classList.contains('show'));
        const dropdownState = await dropdownPage.evaluate(() => {
            const menu = document.querySelector('.academic-row-actions .dropdown-menu');
            const wrapper = document.querySelector('.staff-table-wrap');
            const toggle = document.querySelector('#academic-actions');
            const menuRect = menu.getBoundingClientRect();
            const toggleRect = toggle.getBoundingClientRect();
            const wrapperRect = wrapper.getBoundingClientRect();
            const itemRect = menu.querySelector('.dropdown-item').getBoundingClientRect();
            const hit = document.elementFromPoint(itemRect.left + Math.min(itemRect.width / 2, 20), itemRect.top + itemRect.height / 2);
            return {
                shown: menu.classList.contains('show'),
                position: getComputedStyle(menu).position,
                withinViewport: menuRect.top >= 0 && menuRect.left >= 0 && menuRect.bottom <= innerHeight && menuRect.right <= innerWidth,
                overflowsScrollBoxVertically: menuRect.bottom > wrapperRect.bottom || menuRect.top < wrapperRect.top,
                flipsAboveToggle: menuRect.bottom <= toggleRect.top,
                itemReceivesHit: hit?.closest('.dropdown-menu') === menu,
            };
        });
        assert.equal(dropdownState.shown, true);
        assert.equal(dropdownState.position, 'fixed');
        assert.equal(dropdownState.withinViewport, true);
        assert.equal(dropdownState.overflowsScrollBoxVertically, true);
        assert.equal(dropdownState.flipsAboveToggle, true);
        assert.equal(dropdownState.itemReceivesHit, true);

        await dropdownPage.locator('.academic-row-actions .dropdown-item').filter({ hasText: 'Edit' }).click();
        await dropdownPage.waitForFunction(() => document.querySelector('#academicRecordDrawer')?.classList.contains('show'));
        assert.equal(await dropdownPage.locator('.academic-row-actions .dropdown-menu.show').count(), 0);
        assert.equal(await dropdownPage.locator('#academicRecordDrawer input[name="year_id"]').inputValue(), '77');
        assert.equal(await dropdownPage.locator('#academicRecordDrawer input[name="label"]').last().inputValue(), 'AY Browser Fixture');
        await dropdownPage.locator('#academic-drawer-close').click();
        await dropdownPage.waitForFunction(() => !document.querySelector('#academicRecordDrawer')?.classList.contains('show'));
        assert.equal(await dropdownPage.evaluate(() => document.activeElement.id), 'academic-actions');

        await dropdownPage.locator('#academic-actions').click();
        await dropdownPage.keyboard.press('Escape');
        await dropdownPage.waitForFunction(() => !document.querySelector('#academic-actions')?.classList.contains('show'));
        assert.equal(await dropdownPage.evaluate(() => document.activeElement.id), 'academic-actions');
        await dropdownPage.locator('#academic-actions').click();
        await dropdownPage.locator('#outside-page-control').click();
        await dropdownPage.waitForFunction(() => !document.querySelector('#academic-actions')?.classList.contains('show'));
        assert.equal(await dropdownPage.locator('#academic-actions').getAttribute('aria-expanded'), 'false');

        await dropdownPage.evaluate(() => {
            const row = document.createElement('tr');
            row.innerHTML = '<td>Dynamic year</td><td></td><td></td><td><div class="dropdown academic-row-actions"><button id="dynamic-actions" type="button" data-bs-toggle="dropdown">...</button><ul class="dropdown-menu dropdown-menu-end"><li><button class="dropdown-item">Edit</button></li></ul></div></td>';
            document.querySelector('.academic-table tbody').append(row);
        });
        await dropdownPage.waitForFunction(() => window.bootstrap.Dropdown.getInstance(document.querySelector('#dynamic-actions')) !== null);
        assert.ok(await dropdownPage.locator('#dynamic-actions').count());
        await dropdownPage.locator('#dynamic-actions').click();
        await dropdownPage.waitForFunction(() => document.querySelector('#dynamic-actions')?.classList.contains('show'));
        assert.equal(await dropdownPage.locator('.academic-row-actions .dropdown-menu.show').evaluate(menu => getComputedStyle(menu).position), 'fixed');
        await dropdownPage.evaluate(() => window.scrollBy(0, 120));
        await dropdownPage.waitForFunction(() => !document.querySelector('#dynamic-actions')?.classList.contains('show'));
        assert.deepEqual(dropdownConsoleErrors, []);
        await dropdownPage.close();

        console.log('Shared drawer and academic dropdown browser tests: 40 assertions, 0 failures.');
    } finally {
        await browser.close();
    }
})().catch(error => {
    console.error(error);
    process.exitCode = 1;
});
