import assert from 'node:assert/strict';
import { runJournal as journalRegression } from './general-journal.mjs';

export async function runJournal(helpers) {
  await journalRegression(helpers);
  const { cdp, evaluate, fill, click, go, loaded, hasText, submit, shot, text, until } = helpers;
  async function loginAs(email) {
    await click('form[action$="/logout"] button'); await loaded('[name=email]');
    await fill('[name=email]', email); await fill('[name=password]', 'password123'); await submit('form', 'SYNTHETIC BROWSER DEMO');
  }
  await loginAs('bookkeeper@veritascore.local');
  const vouchers = [];
  let client;
  for (const type of ['JV', 'CV', 'CR', 'CD']) {
    await go(`/vouchers/create?type=${type}`, '[data-journal-form]');
    client = await evaluate(`[...document.querySelector('[name=client_id]').options].find(o=>o.text==='SYNTHETIC VOUCHER CLIENT').value`);
    await fill('[name=client_id]', client);
    await evaluate(`document.querySelector('[name=client_id]').dispatchEvent(new Event('change',{bubbles:true}))`);
    await hasText('Client options loaded.');
    const account = async code => evaluate(`[...document.querySelector('[name="items[0][account_id]"]').options].find(o=>o.text.startsWith('${code} ·')).value`);
    const cash = await account('100');
    const other = await account(type === 'JV' ? '300' : type === 'CR' ? '400' : '500');
    const amount = type === 'JV' ? '1000.00' : type === 'CV' ? '200.00' : type === 'CR' ? '500.00' : '100.00';
    const incoming = type === 'JV' || type === 'CR';
    await fill('[name=transaction_date]', '2026-04-12');
    await fill('[name=accounting_period_id]', await evaluate(`[...document.querySelector('[name=accounting_period_id]').options].find(o=>o.text.startsWith('SYNTHETIC FY 2025')).value`));
    await fill('[name=description]', `SYNTHETIC Browser ${type}`);
    await fill('[name="items[0][account_id]"]', cash); await fill('[name="items[1][account_id]"]', other);
    await fill('[name="items[0][debit]"]', incoming ? amount : '0'); await fill('[name="items[0][credit]"]', incoming ? '0' : amount);
    await fill('[name="items[1][debit]"]', incoming ? '0' : amount); await fill('[name="items[1][credit]"]', incoming ? amount : '0');
    if (type !== 'JV') {
      await fill('[name=party]', 'SYNTHETIC Browser Counterparty'); await fill('[name=amount]', amount); await fill('[name=cash_account_id]', cash);
    }
    if (type === 'CV') {
      await fill('[name=check_number]', 'SYN-CHECK-001'); await fill('[name=check_date]', '2026-04-12');
      await shot('voucher-check-form-desktop');
      await cdp('Emulation.setDeviceMetricsOverride', { width: 390, height: 844, deviceScaleFactor: 1, mobile: true });
      await until(() => evaluate('document.documentElement.scrollWidth <= 390 && document.querySelector("#sidebar").getBoundingClientRect().right <= 0'), 'voucher form fits mobile');
      await shot('voucher-check-form-mobile');
      await cdp('Emulation.setDeviceMetricsOverride', { width: 1440, height: 1000, deviceScaleFactor: 1, mobile: false });
    }
    await click('[name="document_ids[]"]');
    await submit('[data-journal-form]', 'Voucher saved with its journal.'); await hasText(`${type}-000001`);
    const url = await evaluate('location.pathname'); vouchers.push({ type, url });
    await hasText('Download retained synthetic-voucher.pdf');
    if (type === 'CR') {
      await click('a[href$="/edit"]'); await loaded('[data-journal-form]'); await fill('[name=party]', 'SYNTHETIC Corrected Payer');
      await submit('[data-journal-form]', 'Voucher and journal updated.'); await hasText('SYNTHETIC Corrected Payer');
    }
    await click('form:has([name=action][value=submit]) button'); await hasText('Journal workflow updated.');
    assert.ok(!(await text()).includes('Approve review'));
  }
  await loginAs('manager@veritascore.local');
  for (const { type, url } of vouchers) {
    await go(url); await click('button[name=action][value=review]'); await hasText('Post journal');
    await click('form[action$="/post"] button');
    await until(() => evaluate('document.querySelector("#confirmModal").classList.contains("show")'), 'voucher posting confirmation');
    await click('#confirmAccept'); await hasText('Journal posted successfully.'); await hasText('Posted by Sample Office Manager');
    assert.ok(!(await text()).includes('Edit voucher'));
    await shot(`voucher-${type}-posted-desktop`);
    await go(`${url}/print`, 'table');
    await cdp('Emulation.setEmulatedMedia', { media: 'print' });
    assert.equal(await evaluate(`getComputedStyle(document.querySelector('.print-actions')).display`), 'none');
    await hasText(`${type}-000001`); await shot(`voucher-${type}-print`);
    await cdp('Emulation.setEmulatedMedia', { media: '' });
  }
  await go(`/vouchers?client_id=${client}&type=CV&status=Posted`); await hasText('CV-000001'); assert.ok(!(await text()).includes('JV-000001'));
  await cdp('Emulation.setDeviceMetricsOverride', { width: 390, height: 844, deviceScaleFactor: 1, mobile: true });
  await until(() => evaluate('document.documentElement.scrollWidth <= 390'), 'voucher list fits mobile'); await shot('voucher-list-mobile');
  await go(vouchers[1].url); await until(() => evaluate('document.documentElement.scrollWidth <= 390'), 'posted voucher fits mobile'); await shot('voucher-posted-mobile');
  await go(`/financial-reports?client_id=${client}&type=trial-balance&start_date=2026-04-01&end_date=2026-04-30`);
  assert.equal(await evaluate(`document.querySelector('[data-report-debits]').textContent.trim()`), '₱1,500.00');
  assert.equal(await evaluate(`document.querySelector('[data-report-credits]').textContent.trim()`), '₱1,500.00');
  console.log('PASS: Four voucher types created, receipt edited, evidence retained, independent review/posting, fixed references, immutable posted UI, type/client/status filtering, four print layouts, desktop/mobile forms and details, Trial Balance integration.');
}
