import assert from 'node:assert/strict';

// Chained by onboarding.mjs against disposable browser fixtures only.
export async function runFinalWorkflows({ cdp, evaluate, fill, click, go, loaded, hasText, submit, shot, until }) {
  await go('/knowledge/create', '[name=title]');
  await fill('[name=title]', 'SYNTHETIC Browser Knowledge');
  await fill('[name=category]', 'Accounting');
  await fill('[name=status]', 'Published');
  await fill('[name=content]', 'SYNTHETIC browser retrieval content');
  await submit('#viewRoot form', 'Article created successfully.');
  await hasText('SYNTHETIC browser retrieval content');
  await go('/knowledge?q=SYNTHETIC%20Browser%20Knowledge');
  await hasText('SYNTHETIC Browser Knowledge');
  await shot('knowledge-retrieval-desktop');

  await go('/billing/create', '[name=client_id]');
  await fill('[name=client_id]', '1');
  await fill('[name=invoice_date]', '2026-04-01');
  await fill('[name=due_date]', '2026-04-30');
  await fill('[name=tax]', '0');
  await fill('[name="items[0][description]"]', 'SYNTHETIC Browser Service');
  await fill('[name="items[0][quantity]"]', '1');
  await fill('[name="items[0][unit_price]"]', '100');
  await submit('#viewRoot form', 'Invoice created successfully.');
  await hasText('SYNTHETIC Browser Service');
  await submit('form[action$="/transition"]', 'Invoice status updated.');
  await fill('[name=amount]', '25');
  await fill('[name=payment_method]', 'Cash');
  await fill('[name=reference_number]', 'SYNTHETIC-BROWSER-PAYMENT');
  await submit('form[action$="/payments"]', 'Payment recorded successfully.');
  await hasText('SYNTHETIC-BROWSER-PAYMENT');
  await shot('billing-partial-payment-desktop');

  await cdp('Emulation.setDeviceMetricsOverride', { width: 390, height: 844, deviceScaleFactor: 1, mobile: true });
  for (const [url, label] of [['/knowledge', 'knowledge'], ['/billing', 'billing']]) {
    await go(url);
    await until(() => evaluate('document.readyState === "complete"'), `${label} mobile load`);
    assert.equal(await evaluate('document.documentElement.scrollWidth <= innerWidth'), true, `${label} mobile overflow`);
    await shot(`${label}-mobile`);
  }
  await cdp('Emulation.setDeviceMetricsOverride', { width: 1440, height: 1000, deviceScaleFactor: 1, mobile: false });
  await go('/dashboard');
  await click('form[action$="/logout"] button');
  await loaded('[name=email]');
  await fill('[name=email]', 'bookkeeper@veritascore.local');
  await fill('[name=password]', 'password123');
  await submit('form', 'SYNTHETIC BROWSER DEMO');
  await go('/admin/users', 'body');
  await hasText('403');
  await go('/dashboard');
  await click('form[action$="/logout"] button');
  await loaded('[name=email]');
  await fill('[name=email]', 'owner@veritascore.local');
  await fill('[name=password]', 'password123');
  await submit('form', 'SYNTHETIC BROWSER DEMO');
  console.log('PASS: Knowledge publication/retrieval, billing creation/issue/partial payment, mobile lists, and Bookkeeper admin-route denial.');
}
