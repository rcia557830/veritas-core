import assert from 'node:assert/strict';

// Real logins against the local isolated demo; credentials stay in process memory.
export async function runJournal({ cdp, evaluate, fill, click, go, loaded, hasText, shot, until }) {
  const credentials = JSON.parse(process.env.VERITAS_DEMO_BROWSER_CREDENTIALS);
  delete process.env.VERITAS_DEMO_BROWSER_CREDENTIALS;
  const destinations = ['/ledger?work=mine', '/knowledge', '/knowledge?type=Internal%20Procedure', '/knowledge?q=template', '/knowledge?q=guideline', '/vouchers'];
  const request = url => evaluate(`fetch(${JSON.stringify(url)}).then(async r => ({status:r.status,body:await r.text(),url:r.url}))`);
  async function login(role) {
    await go('/login', '[name=email]');
    await fill('[name=email]', `${role}@demo.example.test`);
    await fill('[name=password]', credentials[role]);
    await evaluate('document.querySelector("form").requestSubmit()');
    await loaded('[data-dashboard-role]');
    assert.equal(await evaluate('document.querySelector("[data-dashboard-role]").dataset.dashboardRole'), role);
  }
  async function logout() {
    await click('form[action$="/logout"] button');
    await loaded('[name=email]');
  }
  for (const role of process.argv.includes('--workflow-only') ? [] : ['bookkeeper', 'owner', 'office-manager', 'super-admin']) {
    await login(role);
    for (const url of destinations) {
      const result = await request(url);
      assert.equal(result.status, role === 'super-admin' ? 403 : 200, `${role}: ${url}`);
      assert.ok(!result.url.endsWith('/login'), 'Session must be authenticated');
    }
    if (role !== 'super-admin') {
      for (const url of destinations) {
        await go(url);
        if (url.startsWith('/ledger') && role !== 'bookkeeper') continue;
        const links = await evaluate('[...document.querySelectorAll("tbody a, .article-card h2 a")].map(a=>a.getAttribute("href"))');
        assert.ok(links.length, `${role}: nonempty ${url}`);
        const detail = await request(links[0]);
        assert.equal(detail.status, 200);
        if (url.includes('q=template')) assert.match(detail.body, /Template/);
        if (url.includes('q=guideline')) assert.match(detail.body, /Guidelines/);
        if (url.includes('type=Internal')) assert.match(detail.body, /Internal Procedure/);
        const next = await evaluate('document.querySelector("a[rel=next]")?.href');
        if (next) {
          const second = await request(next);
          assert.equal(second.status, 200);
          assert.notEqual(second.body, (await request(url)).body);
        }
      }
      for (const type of ['JV','CV','CR','CD']) {
        await go(`/vouchers?type=${type}`);
        const references = await evaluate('[...document.querySelectorAll("tbody tr td:first-child a")].map(a=>a.textContent)');
        assert.ok(references.length);
        assert.ok(references.every(ref=>ref.startsWith(type+'-')));
      }
      await go('/knowledge?q=Accounting%20Voucher%20Template');
      await hasText('Accounting Voucher Template');
      await go('/ledger?work=mine&q=service');
      if (role === 'bookkeeper') {
        await hasText('service');
        assert.equal((await request('/clients/6')).status, 403);
        assert.equal((await request('/ledger/61')).status, 403);
        assert.equal((await request('/vouchers/61')).status, 403);
        await go('/ledger?work=mine&client_id=6');
        assert.equal(await evaluate('document.querySelector(".pagination-summary strong:last-child")?.textContent'), '0', await evaluate('document.querySelector("main").innerText'));
        await go('/ledger?work=mine');
        await shot('populated-my-journals');
        await go('/knowledge?q=template');
        await shot('populated-templates');
      }
      assert.equal((await request('/financial-reports?client_id=1')).status, 200);
    } else {
      for (const url of ['/ledger/1','/vouchers/1','/financial-reports?client_id=1']) assert.equal((await request(url)).status, 403);
    }
    console.log(`PASS ${role}: six destinations, details, filters and role boundaries`);
    await logout();
  }
  // Exercise an existing demo draft without changing its amounts or evidence.
  await login('bookkeeper');
  await go('/ledger/9');
  if ((await evaluate('document.querySelector("main").innerText')).includes('Posted transaction - read-only')) {
    await logout();
    console.log('PASS: previously verified journal #9 remains posted and read-only; repeat run preserves history.');
    return;
  }
  if (await evaluate('!!document.querySelector("form:has([name=action][value=submit])")')) {
    await go('/vouchers/9/edit', '[name=description]');
    await evaluate('document.querySelector("[name=description]").form.requestSubmit()');
    await hasText('Voucher and journal updated.');
    await go('/ledger/9');
    await click('form:has([name=action][value=submit]) button');
    await until(()=>evaluate('!document.querySelector("form:has([name=action][value=submit])") && document.querySelector("main").innerText.includes("For Review")'), 'submitted journal');
  }
  assert.equal(await evaluate('!!document.querySelector("button[value=review]")'), false);
  await logout();
  await login('office-manager');
  await go('/ledger/9');
  await fill('[name=notes]', 'Demo verification: confirm service adjustment source before resubmission.');
  await click('button[value=return]');
  await until(()=>evaluate('!document.querySelector("button[value=return]") && document.querySelector("main").innerText.includes("Needs Correction")'), 'returned journal');
  await logout();
  await login('bookkeeper');
  await go('/ledger/9');
  await click('form[action$="/transition"] button');
  await until(()=>evaluate('!document.querySelector("form:has([name=action][value=submit])") && document.querySelector("main").innerText.includes("For Review")'), 'resubmitted journal');
  await logout();
  await login('office-manager');
  await go('/ledger/9');
  await click('button[value=review]');
  await hasText('Post journal');
  await go('/ledger/9');
  await click('form[action$="/post"] button');
  await until(()=>evaluate('document.querySelector("#confirmModal").classList.contains("show")'), 'posting confirmation');
  await click('#confirmAccept');
  await hasText('Posted transaction - read-only');
  await go('/ledger/9');
  await hasText('Posted transaction - read-only');
  await shot('populated-independent-posting');
  await logout();
  console.log('PASS: draft save, submission, return, resubmission, independent review and authorized posting.');
}
