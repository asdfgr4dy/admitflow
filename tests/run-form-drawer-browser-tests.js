let assertionCount = 0;
const strictAssert = require('node:assert/strict');
const assert = new Proxy(strictAssert, {
    get(target, property) {
        const method = Reflect.get(target, property);
        if (typeof method !== 'function') return method;
        return (...args) => {
            assertionCount++;
            return Reflect.apply(method, target, args);
        };
    },
    apply(target, thisArg, args) {
        assertionCount++;
        return Reflect.apply(target, thisArg, args);
    },
});
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
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

        const reportPage = await browser.newPage({ viewport: { width: 1400, height: 1100 } });
        await reportPage.setContent(`
            <body class="report-print-document"><section class="report-preview-page" data-print-preview-sheet>
                <header class="report-preview-header"><div><p class="eyebrow">AdmitFlow · Admission Office</p><h1>Applications report</h1><p>Generated November 2, 2026 12:00 PM Asia/Manila</p><p>Generated by Fixture Reviewer (reviewer)</p></div></header>
                <section class="report-preview-filters"><h2>Applied filters</h2><p>None</p></section>
                <section class="report-summary report-preview-summary"><article><span>Applications</span><strong>1</strong></article><article><span>Reviewed</span><strong>1</strong><small>100.0% of matching applications</small></article><article><span>Average review time</span><strong>1.0h</strong><small>Submission to first recorded screening or document-review event</small></article><article><span>Enrollment rate</span><strong>0.0%</strong><small>0 enrolled of 1 applications</small></article></section>
                <section class="report-preview-funnel"><h2>Applicant funnel</h2><table class="report-preview-funnel-table"><thead><tr><th>Stage</th><th>Count</th><th>% of submitted</th><th>Step conversion</th></tr></thead><tbody><tr><td>Submitted</td><td>1</td><td>100.0%</td><td>—</td></tr></tbody></table></section>
                <section class="report-preview-table-section"><h2>Applications (1)</h2><div class="report-preview-table-wrap"><table class="table staff-table report-preview-table"><colgroup><col class="report-col-reference"><col class="report-col-applicant"><col class="report-col-program"><col class="report-col-submitted"><col class="report-col-documents"><col class="report-col-reviewer"><col class="report-col-status"></colgroup><thead><tr><th>Reference</th><th>Applicant</th><th>Program / year</th><th>Submitted</th><th>Documents</th><th>Reviewer</th><th>Status</th></tr></thead><tbody><tr><td class="report-cell-reference">AF-2026-ABCDE12345</td><td class="report-cell-applicant">Alexandria-Cecilia Maria Fernanda de la Cruz-Santos</td><td class="report-cell-program"><span>Bachelor of Arts in Communication and Digital Media</span><small>AY 2026–2027</small></td><td>Nov 02, 2026</td><td>12 / 12</td><td class="report-cell-plain">Unassigned</td><td class="report-cell-status">Documents Incomplete</td></tr></tbody></table></div></section>
                <p class="report-preview-total">Total records: 1</p><p class="report-preview-definitions">“Status: documents incomplete” counts applications in that workflow status. “Applications with missing documents” counts applications with at least one required document not verified.</p><p class="report-preview-privacy">This report excludes residential addresses, dates of birth, and authentication information.</p><footer class="report-preview-confidentiality">Confidential · For authorized Admission Office use only</footer><section class="report-preview-signatures" hidden><div><span>Prepared by</span><i></i></div><div><span>Reviewed by</span><i></i></div><div><span>Date</span><i></i></div></section>
            </section></body>
        `);
        await reportPage.addStyleTag({ path: 'public/assets/css/app.css' });
        const inspectReportLayout = async landscape => {
            await reportPage.locator('.report-preview-page').evaluate((sheet, value) => sheet.classList.toggle('print-landscape', value), landscape);
            await reportPage.emulateMedia({ media: 'screen' });
            const signatureState = await reportPage.locator('.report-preview-signatures').evaluate(block => ({ hidden: block.hidden, display: getComputedStyle(block).display }));
            await reportPage.locator('.report-preview-signatures').evaluate(block => { block.hidden = false; document.body.classList.add('include-signature-lines'); });
            const signatureVisible = await reportPage.locator('.report-preview-signatures').evaluate(block => getComputedStyle(block).display !== 'none');
            await reportPage.locator('.report-preview-signatures').evaluate(block => { block.hidden = true; document.body.classList.remove('include-signature-lines'); });
            const screenSummary = await reportPage.locator('.report-preview-summary').evaluate(summary => {
                const cards = [...summary.querySelectorAll('article')].map(card => card.getBoundingClientRect());
                return { display: getComputedStyle(summary).display, sameRow: cards.every(rect => Math.abs(rect.top - cards[0].top) < 1), widths: cards.map(rect => rect.width) };
            });
            const screenLayout = await reportPage.locator('.report-preview-table').evaluate(table => {
                const tableRect = table.getBoundingClientRect();
                const headers = [...table.querySelectorAll('thead th')].map(cell => cell.getBoundingClientRect());
                const reference = table.querySelector('.report-cell-reference');
                const applicant = table.querySelector('.report-cell-applicant');
                const program = table.querySelector('.report-cell-program');
                const status = table.querySelector('.report-cell-status');
                const tokenRects = (element, token) => {
                    const walker = document.createTreeWalker(element, NodeFilter.SHOW_TEXT);
                    while (walker.nextNode()) {
                        const start = walker.currentNode.textContent.indexOf(token);
                        if (start < 0) continue;
                        const range = document.createRange();
                        range.setStart(walker.currentNode, start);
                        range.setEnd(walker.currentNode, start + token.length);
                        return [...range.getClientRects()].map(rect => rect.toJSON());
                    }
                    return [];
                };
                const statusRange = document.createRange();
                statusRange.selectNodeContents(status);
                const statusTextRect = statusRange.getBoundingClientRect();
                return {
                    fits: table.scrollWidth <= table.clientWidth + 1 && tableRect.width <= table.parentElement.clientWidth + 1,
                    scrollWidth: table.scrollWidth,
                    clientWidth: table.clientWidth,
                    parentWidth: table.parentElement.clientWidth,
                    headersDoNotOverlap: headers.every((rect, index) => index === 0 || rect.left >= headers[index - 1].right - 1),
                    columnRatios: headers.map(rect => rect.width / tableRect.width),
                    refNoWrap: getComputedStyle(reference).whiteSpace === 'nowrap',
                    refFont: getComputedStyle(reference).fontSize,
                    wordsDoNotBreak: [reference, applicant, program, status].every(cell => getComputedStyle(cell).overflowWrap === 'normal' && getComputedStyle(cell).wordBreak === 'normal' && getComputedStyle(cell).hyphens === 'none'),
                    applicantWordRects: tokenRects(applicant, 'Fernanda').length,
                    programWordRects: tokenRects(program, 'Communication').length,
                    applicantWrap: getComputedStyle(applicant).whiteSpace === 'normal',
                    programWrap: getComputedStyle(program).whiteSpace === 'normal',
                    statusNoWrap: getComputedStyle(status).whiteSpace === 'nowrap',
                    statusFits: statusTextRect.right <= status.getBoundingClientRect().right - 3,
                    tableFont: getComputedStyle(table).fontFamily,
                    tableSize: getComputedStyle(table).fontSize,
                    tableBorder: getComputedStyle(table.querySelector('th')).borderTopColor,
                    tableCellPadding: getComputedStyle(table.querySelector('th')).padding,
                    rowBreakInside: getComputedStyle(table.querySelector('tbody tr')).breakInside,
                    programRect: program.getBoundingClientRect().toJSON(),
                    headingRect: table.querySelector('thead').getBoundingClientRect().toJSON(),
                };
            });
            const screenshotPreview = await reportPage.screenshot();
            await reportPage.emulateMedia({ media: 'print' });
            const printLayout = await reportPage.locator('.report-preview-table').evaluate(table => ({
                fits: table.scrollWidth <= table.clientWidth + 1,
                headersDoNotOverlap: [...table.querySelectorAll('thead th')].every((cell, index, cells) => index === 0 || cell.getBoundingClientRect().left >= cells[index - 1].getBoundingClientRect().right - 1),
                statusFits: (() => {
                    const status = table.querySelector('.report-cell-status');
                    const range = document.createRange();
                    range.selectNodeContents(status);
                    return range.getBoundingClientRect().right <= status.getBoundingClientRect().right - 3;
                })(),
                font: getComputedStyle(table).fontFamily,
                size: getComputedStyle(table).fontSize,
                borderColor: getComputedStyle(table.querySelector('th')).borderTopColor,
                cellPadding: getComputedStyle(table.querySelector('th')).padding,
                rowBreakInside: getComputedStyle(table.querySelector('tbody tr')).breakInside,
                summarySameRow: (() => {
                    const cards = [...document.querySelectorAll('.report-preview-summary article')].map(card => card.getBoundingClientRect());
                    return cards.length === 4 && cards.every(rect => Math.abs(rect.top - cards[0].top) < 1);
                })(),
                columnRatios: [...table.querySelectorAll('thead th')].map(cell => cell.getBoundingClientRect().width / table.getBoundingClientRect().width),
                headers: [...table.querySelectorAll('thead th')].map(cell => cell.getBoundingClientRect().toJSON()),
            }));
            const screenshotPrint = await reportPage.screenshot();
            const pdf = await reportPage.pdf({ preferCSSPageSize: true, printBackground: true });
            const mediaBox = pdf.toString('latin1').match(/\/MediaBox\s*\[([^\]]+)\]/)?.[1] || '';
            assert.equal(screenLayout.fits, true, JSON.stringify({ landscape, screenLayout }));
            assert.equal(screenLayout.headersDoNotOverlap, true, JSON.stringify({ landscape, screenLayout }));
            assert.equal(screenLayout.refNoWrap, true);
            assert.equal(screenLayout.refFont, '10.6667px');
            assert.equal(screenLayout.applicantWrap, true);
            assert.equal(screenLayout.programWrap, true);
            assert.equal(screenLayout.statusNoWrap, true);
            assert.deepEqual(signatureState, { hidden: true, display: 'none' });
            assert.equal(signatureVisible, true);
            assert.equal(screenSummary.display, 'table');
            assert.equal(screenSummary.sameRow, true);
            assert.equal(screenSummary.widths.length, 4);
            assert.equal(screenLayout.statusFits, true);
            assert.equal(screenLayout.wordsDoNotBreak, true);
            assert.equal(screenLayout.applicantWordRects, 1);
            assert.equal(screenLayout.programWordRects, 1);
            assert.equal(printLayout.fits, true);
            assert.equal(printLayout.headersDoNotOverlap, true);
            assert.equal(printLayout.statusFits, true);
            assert.equal(printLayout.columnRatios.length, screenLayout.columnRatios.length);
            assert.ok(printLayout.columnRatios.every((ratio, index) => Math.abs(ratio - screenLayout.columnRatios[index]) < 0.02));
            assert.equal(printLayout.font, screenLayout.tableFont);
            assert.equal(printLayout.size, screenLayout.tableSize);
            assert.equal(printLayout.borderColor, screenLayout.tableBorder);
            assert.equal(printLayout.cellPadding, screenLayout.tableCellPadding);
            assert.equal(printLayout.rowBreakInside, screenLayout.rowBreakInside);
            assert.equal(printLayout.summarySameRow, true);
            assert.ok(screenshotPreview.length > 1000 && screenshotPrint.length > 1000);
            if (landscape) {
                const comparisonDir = path.join(os.tmpdir(), 'admitflow-report-print-comparison');
                fs.mkdirSync(comparisonDir, { recursive: true });
                fs.writeFileSync(path.join(comparisonDir, 'preview-landscape.png'), screenshotPreview);
                fs.writeFileSync(path.join(comparisonDir, 'print-landscape.png'), screenshotPrint);
                fs.writeFileSync(path.join(comparisonDir, 'save-as-pdf-landscape.pdf'), pdf);
                console.log('Landscape comparison artifacts: ' + comparisonDir);
            }
            const pageDimensions = mediaBox.split(/\s+/).slice(-2).map(Number);
            assert.equal(pageDimensions[0] > pageDimensions[1], landscape);
            assert.ok(Math.abs((pageDimensions[0] / pageDimensions[1]) - (landscape ? 1.4142 : 0.7071)) < 0.01);
            return { landscape, screenLayout, printLayout, mediaBox, previewBytes: screenshotPreview.length, printBytes: screenshotPrint.length };
        };
        const portraitReport = await inspectReportLayout(false);
        const landscapeReport = await inspectReportLayout(true);
        const repeatedPortraitReport = await inspectReportLayout(false);
        assert.ok(landscapeReport.screenLayout.programRect.height > 20 && portraitReport.screenLayout.programRect.height > 20);
        assert.ok(Math.abs(repeatedPortraitReport.printLayout.columnRatios[0] - portraitReport.printLayout.columnRatios[0]) < 0.02);
        await reportPage.close();

        const orientationPage = await browser.newPage({ viewport: { width: 1400, height: 1000 } });
        const orientationFixtureHtml = '<!doctype html><html lang="en"><head><meta charset="utf-8"><link rel="stylesheet" href="http://127.0.0.1:8000/assets/css/app.css"></head><body class="print-preview-document report-print-document"><section class="report-preview-page" data-print-preview-sheet><header class="report-preview-header"><p class="eyebrow">AdmitFlow · Admission Office</p><h1>Applications report</h1></header><section class="report-preview-funnel"><table class="report-preview-funnel-table"><thead><tr><th>Stage</th><th>Count</th><th>% of submitted</th><th>Step conversion</th></tr></thead><tbody><tr><td>Submitted</td><td>1</td><td>100%</td><td>—</td></tr></tbody></table></section><table class="report-preview-table"><colgroup><col class="report-col-reference"><col class="report-col-applicant"><col class="report-col-program"><col class="report-col-submitted"><col class="report-col-documents"><col class="report-col-reviewer"><col class="report-col-status"></colgroup><thead><tr><th>Reference</th><th>Applicant</th><th>Program / year</th><th>Submitted</th><th>Documents</th><th>Reviewer</th><th>Status</th></tr></thead><tbody><tr><td class="report-cell-reference">AF-2026-ABCDE12345</td><td class="report-cell-applicant">Alexandria-Cecilia Maria Fernanda de la Cruz-Santos</td><td class="report-cell-program">Bachelor of Arts in Communication and Digital Media</td><td>Nov 02, 2026</td><td>12 / 12</td><td>Unassigned</td><td class="report-cell-status">Documents Incomplete</td></tr></tbody></table><footer class="report-preview-confidentiality">Confidential</footer></section></body></html>';
        await orientationPage.route('http://127.0.0.1:8000/report-print-fixture', route => route.fulfill({ status: 200, contentType: 'text/html', body: orientationFixtureHtml }));
        const orientationHarness = '<!doctype html><html lang="en"><head><meta charset="utf-8"><link rel="stylesheet" href="/assets/css/app.css"><script>window.bootstrap={Modal:function(){}};</script><script src="/assets/js/app.js" defer></script></head><body><button id="preview-trigger" data-preview-url="/report-print-fixture"></button><div data-print-preview-modal><button data-preview-orientation="portrait" aria-pressed="false">Portrait</button><button data-preview-orientation="landscape" aria-pressed="true">Landscape</button><button data-preview-print disabled>Print</button><div data-preview-loading></div><div data-preview-error hidden></div><iframe id="preview-frame" data-preview-frame hidden></iframe></div><input data-pdf-orientation value="landscape"><input data-pdf-signatures value="0"></body></html>';
        await orientationPage.route('http://127.0.0.1:8000/orientation-harness', route => route.fulfill({ status: 200, contentType: 'text/html', body: orientationHarness }));
        await orientationPage.goto('http://127.0.0.1:8000/orientation-harness');
        await orientationPage.evaluate(() => sessionStorage.removeItem('admitflow-report-orientation'));
        await orientationPage.waitForFunction(() => window.bootstrap?.Modal && document.querySelector('[data-preview-orientation]'));
        const openOrientationPreview = async () => orientationPage.locator('[data-print-preview-modal]').evaluate(modal => {
            const event = new Event('show.bs.modal');
            Object.defineProperty(event, 'relatedTarget', { value: document.querySelector('#preview-trigger') });
            modal.dispatchEvent(event);
            modal.dispatchEvent(new Event('shown.bs.modal'));
        });
        const closeOrientationPreview = async () => orientationPage.locator('[data-print-preview-modal]').evaluate(modal => modal.dispatchEvent(new Event('hidden.bs.modal')));
        const iframeOrientation = async () => orientationPage.locator('#preview-frame').evaluate(frame => ({ body: frame.contentDocument.body.className, sheet: frame.contentDocument.querySelector('[data-print-preview-sheet]').className, page: getComputedStyle(frame.contentDocument.querySelector('[data-print-preview-sheet]')).page, exportOrientation: document.querySelector('[data-pdf-orientation]').value }));
        const pdfFromIframePrintView = async () => {
            const html = await orientationPage.locator('#preview-frame').evaluate(frame => frame.contentDocument.documentElement.outerHTML);
            const printPage = await browser.newPage();
            await printPage.route('http://127.0.0.1:8000/orientation-pdf-harness', route => route.fulfill({ status: 200, contentType: 'text/html', body: '<!doctype html><html><head><meta charset="utf-8"></head><body></body></html>' }));
            await printPage.goto('http://127.0.0.1:8000/orientation-pdf-harness');
            await printPage.setContent(html);
            await printPage.emulateMedia({ media: 'print' });
            const bytes = await printPage.pdf({ preferCSSPageSize: true, printBackground: true });
            const box = bytes.toString('latin1').match(/\/MediaBox\s*\[([^\]]+)\]/)?.[1] || '';
            await printPage.close();
            return box.split(/\s+/).slice(-2).map(Number);
        };
        await openOrientationPreview();
        await orientationPage.frameLocator('#preview-frame').locator('[data-print-preview-sheet]').waitFor();
        await orientationPage.waitForFunction(() => !document.querySelector('[data-preview-print]').disabled);
        assert.match((await iframeOrientation()).sheet, /print-landscape/);
        const initialLandscapeSize = await pdfFromIframePrintView();
        assert.ok(initialLandscapeSize[0] > initialLandscapeSize[1] && Math.abs(initialLandscapeSize[0] - 841.89) < 2 && Math.abs(initialLandscapeSize[1] - 595.28) < 2);
        await orientationPage.locator('[data-preview-orientation="portrait"]').click();
        const portraitState = await iframeOrientation();
        assert.doesNotMatch(portraitState.sheet, /print-landscape/);
        assert.doesNotMatch(portraitState.body, /print-landscape/);
        assert.equal(portraitState.exportOrientation, 'portrait');
        const selectedPortraitSize = await pdfFromIframePrintView();
        assert.ok(selectedPortraitSize[1] > selectedPortraitSize[0] && Math.abs(selectedPortraitSize[0] - 595.28) < 2 && Math.abs(selectedPortraitSize[1] - 841.89) < 2);
        await orientationPage.locator('[data-preview-orientation="landscape"]').click();
        const switchedLandscapeSize = await pdfFromIframePrintView();
        assert.ok(switchedLandscapeSize[0] > switchedLandscapeSize[1] && Math.abs(switchedLandscapeSize[0] - initialLandscapeSize[0]) < 2 && Math.abs(switchedLandscapeSize[1] - initialLandscapeSize[1]) < 2);
        await orientationPage.locator('[data-preview-orientation="portrait"]').click();
        const switchedPortraitSize = await pdfFromIframePrintView();
        assert.ok(switchedPortraitSize[1] > switchedPortraitSize[0] && Math.abs(switchedPortraitSize[0] - selectedPortraitSize[0]) < 2 && Math.abs(switchedPortraitSize[1] - selectedPortraitSize[1]) < 2);
        await closeOrientationPreview();
        await openOrientationPreview();
        await orientationPage.frameLocator('#preview-frame').locator('[data-print-preview-sheet]').waitFor();
        await orientationPage.waitForFunction(() => !document.querySelector('[data-preview-print]').disabled);
        assert.doesNotMatch((await iframeOrientation()).sheet, /print-landscape/);
        const reopenedPortraitSize = await pdfFromIframePrintView();
        assert.ok(reopenedPortraitSize[1] > reopenedPortraitSize[0] && Math.abs(reopenedPortraitSize[0] - selectedPortraitSize[0]) < 2 && Math.abs(reopenedPortraitSize[1] - selectedPortraitSize[1]) < 2);
        console.log('Iframe print PDF point sizes: ' + JSON.stringify({ initialLandscapeSize, selectedPortraitSize, switchedLandscapeSize, switchedPortraitSize, reopenedPortraitSize }));
        await orientationPage.close();

        console.log('Report screen/print comparison: ' + JSON.stringify([landscapeReport, portraitReport].map(result => ({ orientation: result.landscape ? 'landscape' : 'portrait', mediaBox: result.mediaBox, font: result.printLayout.font, size: result.printLayout.size, border: result.printLayout.borderColor, padding: result.printLayout.cellPadding, tableFits: result.printLayout.fits, headersDoNotOverlap: result.printLayout.headersDoNotOverlap }))));
        console.log('Shared drawer, academic dropdown, and report print browser tests: ' + assertionCount + ' assertions, 0 failures.');
    } finally {
        await browser.close();
    }
})().catch(error => {
    console.error(error);
    process.exitCode = 1;
});
