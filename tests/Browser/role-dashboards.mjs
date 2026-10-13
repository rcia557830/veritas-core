import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import path from 'node:path';

// Real rendered UI compared to independently queried disposable database counts.
export async function verifyRoleDashboards({ cdp, evaluate, fill, click, go, loaded, hasText, shot, until }) {
  const expected = JSON.parse(await readFile(path.join(path.dirname(process.env.VERITAS_TEST_DATADIR), 'role-dashboard-expectations.json'), 'utf8'));
  const login = async (email, password) => {
    await go('/dashboard'); await click('form[action$="/logout"] button'); await loaded('[name=email]');
    await fill('[name=email]', email); await fill('[name=password]', password);
    await evaluate(`document.querySelector('form[action$="/login"]').requestSubmit()`);
    await loaded('[data-dashboard-role]');
  };
  for (const [role, email, password] of [
    ['owner', 'owner@veritascore.local', 'password123'],
    ['office-manager', 'manager@veritascore.local', 'password123'],
    ['bookkeeper', 'bookkeeper@veritascore.local', 'password123'],
    ['super-admin', 'technical@example.test', 'Synthetic-admin-123'],
  ]) {
    await login(email, password);
    for (const [size, width, height] of [['desktop', 1440, 1000], ['mobile', 390, 844]]) {
      await cdp('Emulation.setDeviceMetricsOverride', { width, height, deviceScaleFactor: 1, mobile: size === 'mobile' });
      await go('/dashboard');
      assert.equal(await evaluate('document.querySelector("[data-dashboard-role]").dataset.dashboardRole'), role);
      const cards = await evaluate('Object.fromEntries([...document.querySelectorAll(".overview-card")].map(card => [card.querySelector(".stat-label").textContent.trim(), card.querySelector(".stat-value").textContent.trim()]))');
      assert.deepEqual(cards, expected.cards[role], `${role} ${size}: database counts`);
      assert.equal(await evaluate('document.documentElement.scrollWidth <= innerWidth'), true, `${role}: viewport fits`);
      const groups = await evaluate('[...document.querySelectorAll("#mainNav details")].map(group => ({key:group.dataset.navGroup, links:group.querySelectorAll("a").length}))');
      assert.ok(groups.every(group => group.links > 0), 'No empty navigation groups');
      assert.equal(groups.some(group => group.key === 'administration'), role === 'super-admin');
      assert.equal(groups.some(group => group.key === 'accounting'), role !== 'super-admin');
      if (role === 'bookkeeper') {
        await hasText('ROLE-OWN-DRAFT'); await hasText('ROLE-OWN-CORRECTION');
        assert.equal(await evaluate('document.querySelector("[data-dashboard-role]").textContent.includes("ROLE-PRIVATE-DRAFT")'), false);
        assert.equal(await evaluate('document.querySelector("[data-dashboard-role]").textContent.includes("ROLE-OTHER-DRAFT")'), false);
        assert.equal(await evaluate('!!document.querySelector("#mainNav a[href*=reviewable]")'), false);
      }
      if (role === 'office-manager') {
        await hasText('Documents to validate');
        assert.equal(await evaluate(`!!document.querySelector('[data-dashboard-role] a[href$="/ledger/create"]')`), false);
      }
      // Follow every rendered sidebar/card/task/action link, including collapsed submenus.
      const links = await evaluate('[...new Set([...document.querySelectorAll("#mainNav a,[data-dashboard-role] a")].map(a => a.href))]');
      for (const href of links) {
        const result = await evaluate(`fetch(${JSON.stringify(href)}).then(r => ({status:r.status, path:new URL(r.url).pathname}))`);
        assert.equal(result.status, 200, `${role} ${size}: ${href}`);
        assert.notEqual(result.path, '/login', 'No silent sign-in redirect');
      }
      const forbidden = role === 'super-admin'
        ? ['/clients', '/ledger', '/billing', '/financial-audit', '/financial-reports']
        : ['/admin/users', '/admin/roles', '/workspace', '/admin/activity-logs'];
      if (role === 'bookkeeper') forbidden.push('/ledger?work=reviewable', '/ledger?work=postable', `/clients/${expected.private_client}`, `/ledger/${expected.private_journal}`);
      if (role === 'office-manager') forbidden.push('/ledger/create');
      for (const url of forbidden) assert.equal(await evaluate(`fetch(${JSON.stringify(url)}).then(r => r.status)`), 403, `${role}: direct ${url} denied`);
      await shot(`role-${role}-${size}`);
      if (size === 'mobile') {
        const group = role === 'super-admin' ? 'administration' : 'accounting';
        await click('#sidebarToggle');
        await until(() => evaluate('document.querySelector("#sidebarToggle").getAttribute("aria-expanded") === "true"'), 'Drawer opens');
        await until(() => evaluate('Math.abs(document.querySelector("#sidebar").getBoundingClientRect().left) < 1'), 'Drawer transition finishes');
        await click(`[data-nav-group="${group}"] summary`);
        await until(() => evaluate(`document.querySelector("[data-nav-group=${group}]").open`), 'Submenu expands');
        await shot(`role-${role}-navigation-mobile`);
        // The preceding link-following fetches can leave the page without input
        // focus, making a CDP key event unreliable. Real Escape delivery is
        // covered by the business journey; dispatch directly to exercise the
        // app's close-and-return-focus handler.
        await evaluate('document.dispatchEvent(new KeyboardEvent("keydown", { key: "Escape", code: "Escape", bubbles: true, cancelable: true }))');
        await until(() => evaluate('document.querySelector("#sidebarToggle").getAttribute("aria-expanded") === "false"'), 'Drawer closes');
        assert.equal(await evaluate('document.activeElement.id'), 'sidebarToggle', 'Focus returns to navigation toggle');
      }
    }
  }
  await cdp('Emulation.setDeviceMetricsOverride', { width:1440, height:1000, deviceScaleFactor:1, mobile:false });
  await login('owner@veritascore.local', 'password123');
  console.log('PASS: Four distinct dashboards, independent database counts, all visible links, direct access restrictions, client isolation and mobile navigation for every role at desktop/mobile sizes.');
}
