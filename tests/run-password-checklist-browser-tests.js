const assert = require('node:assert/strict');
const { chromium } = require('playwright');

(async () => {
    const browser = await chromium.launch({ headless: true });
    let assertions = 0;
    const check = (condition, message) => {
        assertions++;
        assert.ok(condition, message);
    };
    try {
        const page = await browser.newPage();
        await page.setContent(`
            <form data-password-checklist data-password-name-hint="" data-password-email-hint="">
                <input name="full_name" value="">
                <input name="email" value="">
                <input id="password" type="password" maxlength="128" data-max-characters="64" data-password-new>
                <ul id="password-checklist" aria-live="polite">
                    <li data-password-rule="length"><span data-rule-state>Not checked</span><span>At least 12 characters</span></li>
                    <li data-password-rule="variety"><span data-rule-state>Not checked</span><span>Mix 3 of 4 character types, or use at least 16 characters</span></li>
                    <li data-password-rule="personal"><span data-rule-state>Not checked</span><span>Does not contain your name or email</span></li>
                    <li data-password-rule="match"><span data-rule-state>Not checked</span><span>Passwords match</span></li>
                </ul>
                <div data-password-strength-panel><div role="progressbar" data-password-strength-meter></div><p data-password-strength-status></p></div>
                <input id="confirmation" type="password" maxlength="128" data-max-characters="64" data-password-confirmation>
                <button type="button" class="input-group"><input type="password"><span data-password-toggle></span></button>
            </form>
        `);
        await page.addScriptTag({ path: 'public/assets/js/app.js' });
        const state = name => page.locator(`[data-password-rule="${name}"] [data-rule-state]`).textContent();
        check(await state('length') === 'Not checked', 'rules start in the neutral state');
        await page.locator('#password').fill('Qz7!mV2#rT9x');
        await page.locator('#confirmation').fill('Qz7!mV2#rT9x');
        check(await state('length') === 'Met' && await state('variety') === 'Met' && await state('personal') === 'Met' && await state('match') === 'Met', 'all four rules update to met for a valid matching password');
        check((await page.locator('[data-password-strength-panel]').getAttribute('data-state')) === 'fair', 'a valid 12-character mixed password is rated fair');

        await page.locator('[name="full_name"]').fill('Qz7');
        check(await state('personal') === 'Not met' && (await page.locator('[data-password-strength-panel]').getAttribute('data-state')) === 'weak', 'personal-information hints update as the name field changes');
        await page.locator('[name="full_name"]').fill('');
        await page.locator('[name="email"]').fill('Qz7@example.test');
        check(await state('personal') === 'Not met', 'email-local-part hints update as the email field changes');
        await page.locator('[name="email"]').fill('');

        await page.locator('#confirmation').fill('different value');
        check(await state('match') === 'Not met', 'confirmation mismatch is shown while typing');
        await page.locator('#password').fill('a'.repeat(65));
        check(Array.from(await page.locator('#password').inputValue()).length === 64, 'the browser checklist clamps to 64 Unicode code points');
        await page.locator('#password').fill('\u{1F600}'.repeat(64));
        check((await page.locator('#password').inputValue()).length === 128 && Array.from(await page.locator('#password').inputValue()).length === 64, 'HTML maxlength permits 64 supplementary Unicode characters without splitting surrogate pairs');
        check(await state('length') === 'Not met', 'the checklist still enforces the UTF-8 byte ceiling for emoji');
        console.log(`Password checklist browser tests: ${assertions} assertions, 0 failures.`);
    } finally {
        await browser.close();
    }
})().catch(error => {
    console.error(error);
    process.exitCode = 1;
});
