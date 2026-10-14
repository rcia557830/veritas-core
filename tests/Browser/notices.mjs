import assert from 'node:assert/strict';

export async function runJournal({ cdp, evaluate, fill, click, go, loaded, hasText, shot }) {
  const credentials = JSON.parse(process.env.VERITAS_DEMO_BROWSER_CREDENTIALS);
  delete process.env.VERITAS_DEMO_BROWSER_CREDENTIALS;
  const request = url => evaluate(`fetch(${JSON.stringify(url)}).then(async r => ({status:r.status,body:await r.text()}))`);
  for (const role of ['owner', 'office-manager', 'bookkeeper', 'super-admin']) {
    await go('/login', '[name=email]');
    await fill('[name=email]', `${role}@demo.example.test`);
    await fill('[name=password]', credentials[role]);
    await evaluate('document.querySelector("form").requestSubmit()');
    await loaded('[data-dashboard-role]');
    if (['bookkeeper', 'super-admin'].includes(role)) {
      for (const url of ['/notices', '/notices/create', '/notices/1', '/notices/1/edit']) {
        assert.equal((await request(url)).status, 403, `${role} ${url}`);
      }
    } else {
      await go('/notices?per_page=50');
      assert.equal(await evaluate('document.querySelectorAll("tbody tr").length'), 15);
      const details = await evaluate('[...document.querySelectorAll("tbody tr")].map(row=>({url:new URL(row.querySelector("a").href).pathname,title:row.cells[0].innerText,client:row.cells[1].innerText}))');
      for (const detail of details) {
        await go(detail.url);
        await hasText(detail.title);
        await hasText(detail.client);
        assert.ok(await evaluate('document.querySelector(".prose").innerText.length > 200'));
      }
      for (const [status, count] of [['Published',10], ['Draft',3], ['Archived',2]]) {
        await go('/notices');
        await fill('[name=status]', status);
        await click('.filters button');
        await loaded('tbody');
        await hasText(`of ${count}`);
        assert.equal(await evaluate('document.querySelectorAll("tbody tr").length'), count);
        assert.ok(await evaluate(`[...document.querySelectorAll("tbody .badge-status")].every(b=>b.textContent.trim()===${JSON.stringify(status)})`));
      }
      await fill('[name=status]', '');
      await fill('[name=q]', 'Monthly Staff Coordination');
      await click('.filters button');
      await hasText('Monthly Staff Coordination Meeting');
      await hasText('of 1');
      await go('/notices?q=no-matching-notice');
      await hasText('No notices found');
      await go('/notices/create');
      await loaded('[name=body]');
      for (const width of [1440, 768, 390]) {
        await cdp('Emulation.setDeviceMetricsOverride', {width, height: 1000, deviceScaleFactor: 1, mobile: false});
        for (const [path, label] of [['/notices','list'], [details[0].url,'detail'], ['/notices/create','form']]) {
          await go(path);
          assert.ok(await evaluate('document.documentElement.scrollWidth <= innerWidth + 1'), `${width}px ${label}: no page overflow`);
          await shot(`notices-${role}-${label}-${width}`);
        }
      }
      await cdp('Emulation.setDeviceMetricsOverride', {width:1440,height:1000,deviceScaleFactor:1,mobile:false});
    }
    console.log(`PASS ${role}: notices access, details, search, filters and responsive views`);
    await click('form[action$="/logout"] button');
    await loaded('[name=email]');
  }
}
