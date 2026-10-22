import assert from 'node:assert/strict';
import { runJournal as voucherFlow } from './vouchers.mjs';
import { runFinalWorkflows } from './final-workflows.mjs';

// Increment 7 onboarding / document / compliance browser flow. Chained after the
// chart-of-accounts smoke test and before the voucher flow. Runs desktop and a
// 390px mobile viewport. Use ONLY with tests/Browser/server.php and the
// verified disposable MySQL instance.
export async function runJournal(helpers) {
  const { cdp, evaluate, fill, click, go, loaded, hasText, submit, shot, until } = helpers;

  async function loginAs(email) {
    await click('form[action$="/logout"] button'); await loaded('[name=email]');
    await fill('[name=email]', email); await fill('[name=password]', 'password123'); await submit('form', 'SYNTHETIC BROWSER DEMO');
  }

  await cdp('Emulation.setDeviceMetricsOverride', { width: 1440, height: 1000, deviceScaleFactor: 1, mobile: false });
  await loginAs('owner@veritascore.local');

  // 1. Client registration (full page form).
  await go('/clients/create'); await loaded('[name=business_name]');
  await fill('[name=business_name]', 'SYNTHETIC Browser Client');
  await fill('[name=business_type]', 'Corporation');
  await fill('[name=registration_status]', 'On file');
  await fill('[name=business_license_status]', 'On file');
  await fill('[name=status]', 'Active');
  await shot('client-registration-desktop');
  await submit('#viewRoot form', 'Client created successfully.');
  await hasText('SYNTHETIC Browser Client');
  const clientId = (await evaluate('location.pathname')).split('/').at(-1);

  // 2. Upload, match, review and complete onboarding using actual evidence.
  await go(`/requirements/create?client_id=${clientId}`, '[name=name]');
  await fill('[name=name]', 'SYNTHETIC Browser Registration Requirement');
  await fill('[name=type]', 'Certificate of Registration');
  await fill('[name=remarks]', 'SYNTHETIC saved requirement remarks');
  await submit('#viewRoot form', 'Document requirement created.');
  await hasText('SYNTHETIC saved requirement remarks');
  await go('/documents/create', '[name=file]');
  await fill('[name=client_id]', clientId);
  await fill('[name=title]', 'SYNTHETIC Browser Registration Upload');
  await fill('[name=document_type]', 'Certificate of Registration');
  await fill('[name=status]', 'Submitted');
  await fill('[name=received_date]', '2026-04-12');
  await evaluate(`(() => {
    const transfer = new DataTransfer();
    transfer.items.add(new File(['%PDF-1.4\\nSYNTHETIC browser registration evidence\\n%%EOF'], 'registration.pdf', {type:'application/pdf'}));
    document.querySelector('[name=file]').files = transfer.files;
  })()`);
  await submit('#viewRoot form', 'Document created successfully.');
  const documentPath = await evaluate('location.pathname');
  const documentId = documentPath.split('/').at(-1);
  await go(`/clients/${clientId}/checklist`);
  await fill('form[action$="/link"] [name=document_id]', documentId);
  await submit('form[action$="/link"]', 'Document linked to requirement.');
  await hasText('Awaiting verification');
  await go(documentPath);
  await fill('#validation_status', 'Needs Clarification');
  await fill('form[action$="/validate"] [name=notes]', 'SYNTHETIC registration clarification');
  await submit('form[action$="/validate"]', 'Document validation saved.');
  await go(`/clients/${clientId}`);
  await hasText('required document(s) need clarification.');
  assert.equal(await evaluate('document.body.innerText.includes("Statutory documents verified.")'), false);
  await shot('client-statutory-clarification');
  await go(documentPath);
  await fill('#validation_status', 'Approved');
  await submit('form[action$="/validate"]', 'Document validation saved.');
  await go(`/clients/${clientId}/onboarding`);
  await hasText('Ready for activation');
  await click('form[action$="/onboarding/complete"] button');
  await until(() => evaluate('document.querySelector("#confirmModal").classList.contains("show")'), 'onboarding confirmation');
  await click('#confirmAccept');
  await hasText('Client onboarding completed.');
  await shot('onboarding-document-verified-completed');

  // Explicit fiscal calendar; dates are synthetic test inputs.
  await go(`/accounting-periods?client_id=${clientId}`);
  for (const [action, label] of [['accounting-years', 'year'], ['accounting-periods', 'period']]) {
    const form = `form[action$="/${action}"]`;
    await fill(`${form} [name=label]`, `SYNTHETIC Browser ${label}`);
    await fill(`${form} [name=starts_on]`, '2026-01-01');
    await fill(`${form} [name=ends_on]`, '2026-12-31');
    await submit(form, `Accounting ${label} created.`);
  }
  await shot('accounting-calendar-created');

  // Compliance target and follow-up saved through their forms.
  await go('/compliance/create', '[name=requirement]');
  await fill('[name=client_id]', clientId);
  await fill('[name=agency]', 'Other');
  await fill('[name=requirement]', 'SYNTHETIC Browser Compliance');
  await fill('[name=reporting_period]', '2026');
  await fill('[name=due_date]', '2026-12-31');
  await fill('[name=status]', 'Awaiting Client Documents');
  await submit('#viewRoot form', 'Requirement created successfully.');
  await hasText('Dec 21, 2026');
  await hasText('Follow-ups');
  await fill('form[action$="/follow-ups"] [name=remarks]', 'SYNTHETIC browser follow-up saved');
  await submit('form[action$="/follow-ups"]', 'Follow-up recorded.');
  await hasText('SYNTHETIC browser follow-up saved');
  await click('#follow-ups details summary');
  await fill('#follow-ups details form [name=status]', 'In Progress');
  await submit('#follow-ups details form', 'Follow-up updated.');
  await go(`/compliance/monitoring?client_id=${clientId}`);
  await hasText('SYNTHETIC Browser Compliance');
  await shot('compliance-deadline-followup');

  // 3. Onboarding list reflects the onboarded client.
  await go('/clients/onboarding');
  await hasText('SYNTHETIC Browser Client');
  await shot('onboarding-list-desktop');

  // 4. Dashboard navigation.
  await go('/dashboard');
  await hasText('Owner overview');
  assert.equal(await evaluate('document.querySelectorAll(".overview-card").length'), 6, 'Business overview has six actionable cards');
  assert.equal(await evaluate('document.querySelectorAll("#mainNav > [data-nav-group]").length'), 6, 'Business navigation has six permitted groups');
  assert.equal(await evaluate('!!document.querySelector("[data-nav-group=administration]")'), false, 'Owner cannot see technical administration');
  await shot('dashboard-desktop');
  await click('[data-nav-group=accounting]'); await loaded('[name=work]');
  await hasText('Financial Reports');

  // 5. Document requirements monitoring.
  await go(`/documents?client_id=${clientId}`);
  await hasText('SYNTHETIC Browser Registration Requirement');
  await hasText('Next required action');
  await shot('documents-workspace-desktop');
  const missingLink = await evaluate(`document.querySelector('.workflow-tabs a[href*="state=missing"]').getAttribute('href')`);
  assert.ok(missingLink.includes(`client_id=${clientId}`), 'Document views retain the selected client');
  const missingUrl = new URL(missingLink);
  await go(missingUrl.pathname + missingUrl.search); await hasText('Missing Requirements');
  await hasText('Nothing outstanding');
  await go('/requirements/monitoring');
  await shot('requirements-monitoring-desktop');

  // 6. Compliance monitoring.
  await go('/compliance/monitoring');
  await shot('compliance-monitoring-desktop');

  // 7. Responsive viewport checks across all target breakpoints: 320px, 390px, 768px, 1024px, 1440px, 1920px.
  for (const { width, height, mobile } of [
    { width: 320, height: 568, mobile: true },
    { width: 390, height: 844, mobile: true },
    { width: 768, height: 1024, mobile: false },
    { width: 1024, height: 768, mobile: false },
    { width: 1440, height: 900, mobile: false },
    { width: 1920, height: 1080, mobile: false },
  ]) {
    await cdp('Emulation.setDeviceMetricsOverride', { width, height, deviceScaleFactor: 1, mobile });
    for (const [url, label] of [['/clients/onboarding', 'onboarding'], ['/dashboard', 'dashboard'], ['/compliance/monitoring', 'compliance'], [`/documents?client_id=${clientId}`, 'documents-workspace']]) {
      await go(url);
      await until(() => evaluate(`document.documentElement.scrollWidth <= ${width}`), `${url} fits ${width}px`);
      assert.equal(await evaluate('document.documentElement.scrollWidth <= innerWidth'), true, `${url} should not overflow horizontally at ${width}px`);
      if (width === 390 && url === '/dashboard') {
        await click('#sidebarToggle');
        await until(() => evaluate('document.querySelector("#sidebarToggle").getAttribute("aria-expanded") === "true"'), 'Mobile navigation opens');
        await until(() => evaluate('Math.abs(document.querySelector("#sidebar").getBoundingClientRect().left) < 1'), 'Mobile sidebar transition finishes');
        assert.equal(await evaluate('document.querySelector("[data-nav-group=documents]").tagName'), 'A', 'Documents opens directly');
        await shot('phase5-navigation-mobile');
        // Restore Chrome page focus after capture before sending a real keyboard event.
        await cdp('Page.bringToFront');
        await cdp('Emulation.setFocusEmulationEnabled', { enabled: true });
        await evaluate('document.querySelector("#sidebarClose").focus()');
        assert.equal(await evaluate('document.hasFocus()'), true, 'Chrome page has keyboard focus');
        await cdp('Input.dispatchKeyEvent', {type:'keyDown', key:'Escape', code:'Escape', windowsVirtualKeyCode:27});
        await cdp('Input.dispatchKeyEvent', {type:'keyUp', key:'Escape', code:'Escape', windowsVirtualKeyCode:27});
        await until(() => evaluate('document.querySelector("#sidebarToggle").getAttribute("aria-expanded") === "false"'), 'Escape closes navigation');
        assert.equal(await evaluate('document.activeElement.id'), 'sidebarToggle', 'Focus returns to navigation toggle');
      }
      if (width <= 390) {
        await shot(`${label}-${width}`);
      }
    }
  }
  await cdp('Emulation.setDeviceMetricsOverride', { width: 1440, height: 1000, deviceScaleFactor: 1, mobile: false });

  // 8. Billing and knowledge workflows not covered by the Increment 8.1 chain.
  await runFinalWorkflows(helpers);

  // 9. Continue with the existing voucher creation/posting flow (desktop + mobile).
  await voucherFlow(helpers);

  console.log('PASS: Client registration, onboarding checklist, evidence-backed onboarding, onboarding list, dashboard, document-requirements monitoring, compliance monitoring, and desktop/mobile rendering; then voucher creation and posting.');
}
