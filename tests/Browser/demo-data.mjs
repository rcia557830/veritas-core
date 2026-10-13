import assert from 'node:assert/strict';
import { createHmac } from 'node:crypto';

export async function runJournal({ cdp, evaluate, fill, click, go, loaded, hasText, submit, shot }) {
  for (const role of ['owner', 'office-manager', 'bookkeeper', 'super-admin']) {
    await go('/login', '[name=email]');
    await fill('[name=email]', `${role}@demo.example.test`);
    await fill('[name=password]', createHmac('sha256', process.env.VERITAS_TEST_TOKEN).update(role).digest('hex'));
    await evaluate('document.querySelector("form").requestSubmit()');
    await loaded('[data-dashboard-role]');
    assert.equal(await evaluate('document.querySelector("[data-dashboard-role]").dataset.dashboardRole'), role);
    for (const width of [1440, 390]) {
      await cdp('Emulation.setDeviceMetricsOverride', { width, height: 1000, deviceScaleFactor: 1, mobile: width === 390 });
      await go('/dashboard');
      assert.equal(await evaluate('document.documentElement.scrollWidth <= innerWidth'), true);
      await shot(`phase6-${role}-${width}`);
    }
    const routes = role === 'super-admin' ? ['/admin/users', '/admin/roles'] : ['/clients', '/documents', '/requirements', '/ledger', '/vouchers', '/billing', '/compliance', '/knowledge'];
    for (const route of routes) {
      const result = await evaluate(`fetch(${JSON.stringify(route)}).then(async r => ({status:r.status, body:await r.text()}))`);
      assert.equal(result.status, 200, `${role}: ${route}`);
      assert.ok(!result.body.includes('name="password"'), 'No hidden login redirect');
    }
    const forbidden = role === 'super-admin' ? '/clients' : '/admin/users';
    assert.equal(await evaluate(`fetch(${JSON.stringify(forbidden)}).then(r => r.status)`), 403);
    if (role !== 'super-admin') {
      await go('/clients?q=Amihan'); await hasText('Amihan Paper Trading (Fictional)');
      await go('/knowledge?q=Independent'); await hasText('Independent accounting review');
      const pdf = await evaluate('fetch("/documents/1/download").then(async r => ({status:r.status, body:(await r.text()).slice(0,5)}))');
      assert.deepEqual(pdf, {status:200, body:'%PDF-'});
      await go('/billing'); await hasText('6,500.00');
      if (role === 'bookkeeper') {
        assert.equal(await evaluate('fetch("/clients/6").then(r => r.status)'), 403);
        await go('/clients?q=Habagat');
        assert.ok(!await evaluate('document.querySelector("main").innerText.includes("Habagat Hardware (Fictional)")'));
      }
    }
    if (role === 'owner') {
      await go('/clients/1/edit', '[name=phone]');
      await fill('[name=phone]', '+63 900 000 9999');
      await fill('[name=tin]', '000-000-999');
      await submit('#viewRoot form', 'Record updated successfully.');
      await go('/financial-audit?client_id=1&q=JV-000001'); await hasText('Capital contribution');
      await go('/financial-reports?client_id=1'); await hasText('Trial Balance');
      await go('/billing/2'); await hasText('Partially Paid');
      await fill('[name=amount]', '4500.01');
      await fill('[name=payment_method]', 'Bank Transfer');
      await fill('[name=reference_number]', 'PHASE6-FINAL-PAYMENT');
      await submit('form[action$="/payments"]', 'Payment cannot exceed the remaining balance.');
      await fill('[name=amount]', '4500.00');
      await submit('form[action$="/payments"]', 'Payment recorded successfully.');
      await go('/billing/2');
      assert.equal(await evaluate('[...document.querySelectorAll(".mini-stat")].find(el => el.querySelector("span").textContent === "Balance").querySelector("strong").textContent'), '₱0.00');
      assert.equal(await evaluate('!!document.querySelector("form[action$=\\"/payments\\"]")'), false);
      await hasText('PHASE6-FINAL-PAYMENT');
      await shot('phase6-billing-paid');
      await go('/compliance/1/edit', '[name=status]');
      await fill('[name=status]', 'Completed');
      await submit('#viewRoot form', 'Record updated successfully.');
    }
    await go('/dashboard'); await click('form[action$="/logout"] button'); await loaded('[name=email]');
  }
  console.log('PASS: Phase 6 dataset, four role logins, desktop/mobile dashboards, module pages, boundaries, client isolation, searches, PDF download, overpayment rejection, completed payment, completed compliance, reports and financial audit.');
}
