import assert from 'node:assert/strict';

// Named hook shared with the existing dependency-free browser harness.
export async function runJournal({ cdp, evaluate, fill, go, loaded, hasText, submit, shot, until }) {
  await cdp('Emulation.setDeviceMetricsOverride', { width: 1440, height: 1000, deviceScaleFactor: 1, mobile: false });
  await go('/financial-reports'); await hasText('Select a client');
  const client = await evaluate(`[...document.querySelector('[name=client_id]').options].find(o=>o.text==='SYNTHETIC FINANCIAL CLIENT').value`);
  await fill('[name=client_id]', client); await submit('[data-report-client]', 'Generate report');
  const cash = await evaluate(`[...document.querySelector('[name=account_id]').options].find(o=>o.text.startsWith('100')).value`);
  const base = `/financial-reports?client_id=${client}&start_date=2026-04-01&end_date=2026-04-30`;
  const value = selector => evaluate(`document.querySelector(${JSON.stringify(selector)}).textContent.trim()`);
  await fill('[name=start_date]', '2026-04-01'); await fill('[name=end_date]', '2026-04-30');
  await submit('[data-report-filters]', 'Trial Balance');
  await until(() => evaluate(`location.search.includes('2026-04-30') && document.readyState==='complete'`), 'report filter navigation');
  assert.equal(await value('[data-report-debits]'), '₱2,000.00');
  assert.equal(await value('[data-report-credits]'), '₱2,000.00');
  await shot('trial-balance-desktop');
  for (const type of ['trial-balance','income-statement','balance-sheet','accounts-ledger']) {
    await go(`${base}&type=${type}&account_id=${cash}`);
    await loaded('[data-report-print]');
    if (type === 'income-statement') {
      assert.equal(await value('[data-report-revenue]'), '₱500.00');
      assert.equal(await value('[data-report-expenses]'), '₱150.00');
      assert.equal(await value('[data-report-income]'), '₱350.00');
      await shot('income-statement-desktop');
    }
    if (type === 'balance-sheet') {
      assert.equal(await value('[data-report-assets]'), '₱1,850.00');
      assert.equal(await value('[data-report-equity]'), '₱1,850.00');
      assert.equal(await value('[data-report-prior]'), '₱200.00');
      assert.equal(await value('[data-report-current]'), '₱350.00');
      await hasText('not retained earnings'); await hasText('Verified');
      await shot('balance-sheet-desktop');
    }
    if (type === 'accounts-ledger') {
      assert.equal(await value('[data-report-opening]'), '₱1,200.00 Dr');
      assert.equal(await value('[data-report-closing]'), '₱1,850.00 Dr');
      assert.equal(await evaluate('document.querySelectorAll("[data-report-line]").length'), 3);
      const journal = await evaluate(`document.querySelector('[data-report-line] a').getAttribute('href')`);
      await go(new URL(journal).pathname); await hasText('Posted by');
      await go(`${base}&type=${type}&account_id=${cash}`);
    }
    const print = await evaluate(`document.querySelector('[data-report-print]').getAttribute('href')`);
    await go(new URL(print).pathname + new URL(print).search, 'table');
    await hasText('Print report');
    await cdp('Emulation.setEmulatedMedia', { media: 'print' });
    assert.equal(await evaluate(`getComputedStyle(document.querySelector('.print-actions')).display`), 'none');
    await shot(`${type}-print`);
    await cdp('Emulation.setEmulatedMedia', { media: '' });
  }
  await go(`${base}&type=balance-sheet`);
  await cdp('Emulation.setDeviceMetricsOverride', { width: 390, height: 844, deviceScaleFactor: 1, mobile: true });
  await until(() => evaluate('document.querySelector("#sidebar").getBoundingClientRect().right <= 0 && document.documentElement.scrollWidth <= 390'), 'financial report mobile fits');
  await shot('balance-sheet-mobile');
  for (const type of ['trial-balance', 'income-statement', 'accounts-ledger']) {
    await go(`${base}&type=${type}&account_id=${cash}`);
    await until(() => evaluate('document.documentElement.scrollWidth <= 390'), `${type} mobile fits`);
    await shot(`${type}-mobile`);
  }
  await go(`${base}&type=balance-sheet`);
  const period = await evaluate(`[...document.querySelector('[name=period_id]').options].find(o=>o.text.startsWith('SYNTHETIC FY 2025')).value`);
  await fill('[name=type]', 'income-statement'); await fill('[name=period_id]', period);
  await submit('[data-report-filters]', 'Accounting period: SYNTHETIC FY 2025');
  await loaded('[data-report-income]'); assert.equal(await value('[data-report-income]'), '₱350.00');
  await hasText('2025-07-01 through 2026-06-30');
  await go(`/financial-reports?client_id=${client}&type=income-statement&start_date=2026-03-01&end_date=2026-03-31`);
  assert.equal(await value('[data-report-income]'), '₱0.00');
  await fill('[data-report-client] [name=client_id]', '2'); await submit('[data-report-client]', 'Generate report');
  await until(() => evaluate(`location.search==='?client_id=2' && document.readyState==='complete'`), 'client selection clears previous filters');
  assert.equal(await evaluate('document.querySelector("[name=account_id]").value'), '');
  console.log('PASS: All four financial reports, independent totals, provisional earnings disclosure, full printing, journal drill-down, fiscal period selection, empty period, client filter clearing, desktop/mobile layout.');
}
