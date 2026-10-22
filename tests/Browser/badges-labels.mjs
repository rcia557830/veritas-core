import assert from 'node:assert/strict';

export async function runJournal({ cdp, evaluate, fill, click, go, loaded, hasText, shot }) {
  const credentials = JSON.parse(process.env.VERITAS_DEMO_BROWSER_CREDENTIALS);
  delete process.env.VERITAS_DEMO_BROWSER_CREDENTIALS;
  await go('/login', '[name=email]');
  await fill('[name=email]', 'owner@demo.example.test');
  await fill('[name=password]', credentials.owner);
  await evaluate('document.querySelector("form").requestSubmit()');
  await loaded('[data-dashboard-role]');
  const pages = ['/notices','/clients','/clients/onboarding','/clients/4/onboarding','/documents','/ledger','/vouchers','/compliance','/compliance/monitoring','/billing','/knowledge','/requirements'];
  for (const width of [1440, 390]) {
    await cdp('Emulation.setDeviceMetricsOverride', { width, height:1000, deviceScaleFactor:1, mobile:false });
    for (const page of pages) {
      await go(page);
      const badges = await evaluate(`[...document.querySelectorAll('.badge-status')].map(el=>{const r=el.getBoundingClientRect(); const range=document.createRange();range.selectNodeContents(el);const text=range.getBoundingClientRect();return {deadline:!!el.closest('.compliance-warnings'),label:el.textContent.trim(),width:r.width,height:r.height,scroll:el.scrollHeight,client:el.clientHeight,textWidth:text.width,overflow:el.scrollHeight>el.clientHeight || text.width>r.width-12,align:getComputedStyle(el).justifyContent};})`);
      assert.ok(badges.length, page);
      for (const badge of badges) {
        if (badge.deadline) {
          assert.ok(badge.width < 180, `${page} ${badge.label}: compact deadline badge`);
          assert.ok(badge.height < 32, `${page} ${badge.label}: single-line deadline badge`);
        } else {
          assert.equal(badge.width, 180, `${page} ${badge.label}`);
          assert.equal(badge.height, 32, `${page} ${badge.label}`);
        }
        assert.equal(badge.overflow, false, `${page} ${JSON.stringify(badge)} clipped`);
        assert.equal(badge.align, 'center');
      }
      assert.ok(await evaluate('document.documentElement.scrollWidth <= innerWidth + 1'), `${page}: ${width}px page overflow`);
      const labels = await evaluate(`[...document.querySelectorAll('tbody a, tbody td:nth-child(2), .article-card h2, .deadline-card h2')].map(e=>e.innerText).join(' ')`);
      assert.doesNotMatch(labels, /\b(?:Fictional|Dummy|Sample)\b|\bDemo\s+(?:Owner|Office|Notice|Contact)/i, `${page}: labels`);
      if (['/notices','/clients/onboarding','/compliance','/vouchers'].includes(page)) await shot(`badges-${page.replaceAll('/','-')}-${width}`);
    }
    console.log(`PASS ${width}px: uniform workflow badges and compact deadline badges on ${pages.length} module pages; no clipped labels or page overflow`);
  }
  for (const page of ['/clients/1','/documents/1','/ledger/1','/vouchers/3','/billing/1','/knowledge/1','/notices/1']) {
    await go(page);
    assert.ok(await evaluate('document.querySelector("main").innerText.length > 100'));
  }
  await go('/clients?q=Amihan'); await hasText('Amihan Paper Trading');
  await go('/ledger?q=Capital%20contribution'); await hasText('Capital contribution');
  assert.equal(await evaluate('document.querySelector("tbody").innerText.includes("Fictional:")'), false);
  for (const status of ['Published','Draft','Archived']) {
    await go('/notices'); await fill('[name=status]',status); await click('.filters button');
    await hasText(status);
    await loaded('tbody');
    assert.ok(await evaluate(`[...document.querySelectorAll('tbody .badge-status')].every(b=>b.textContent.trim()===${JSON.stringify(status)})`));
  }
  console.log('PASS: cleaned-name search, accounting search, notice filters and seven module detail pages');
}
