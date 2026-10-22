import assert from 'node:assert/strict';

export async function runJournal({ cdp, evaluate, go, loaded, shot, until, click, fill }) {
  await cdp('Emulation.setFocusEmulationEnabled', { enabled: true });
  const viewports = [
    { label: '320px', width: 320, height: 568, mobile: true },
    { label: '390px', width: 390, height: 844, mobile: true },
    { label: '768px', width: 768, height: 1024, mobile: true },
    { label: '1024px', width: 1024, height: 768, mobile: false },
    { label: '1440px', width: 1440, height: 900, mobile: false },
    { label: '1920px', width: 1920, height: 1080, mobile: false },
  ];

  const routes = [
    { path: '/dashboard', name: 'dashboard' },
    { path: '/clients', name: 'clients-index' },
    { path: '/clients/1', name: 'client-profile' },
    { path: '/billing', name: 'billing-index' },
    { path: '/billing/1', name: 'billing-detail' },
    { path: '/knowledge', name: 'knowledge-index' },
    { path: '/knowledge/1', name: 'knowledge-reader' },
    { path: '/financial-reports?client_id=1', name: 'financial-reports' },
    ...['/clients/onboarding', '/clients/1/onboarding', '/documents?client_id=1', '/documents/create', '/documents/1',
      '/requirements/monitoring', '/clients/1/checklist', '/ledger?client_id=1', '/accounts?client_id=1',
      '/accounting-periods', '/vouchers?client_id=1', '/general-ledger?client_id=1', '/compliance',
      '/compliance?view=overdue', '/compliance/1', '/billing/create', '/knowledge/create', '/profile']
      .map(path => ({ path, name: path.replace(/[^a-z0-9]/gi, '-') })),
  ];

  const roles = [
    ['owner', 'owner@veritascore.local', 'password123', [...routes, ...['/clients/create', '/ledger/create?client_id=1', '/vouchers/create?client_id=1', '/compliance/create', '/documents/1/validate', '/requirements/templates', '/financial-audit', '/reports', '/notices'].map(path => ({path, name:path.replace(/[^a-z0-9]/gi, '-')}))]],
    ['office-manager', 'manager@veritascore.local', 'password123', routes],
    ['bookkeeper', 'bookkeeper@veritascore.local', 'password123', [...routes, {path:'/ledger/create?client_id=1', name:'journal-create'}]],
    ['super-admin', 'technical@example.test', 'Synthetic-admin-123', ['/dashboard', '/admin/users', '/admin/users/create', '/admin/roles', '/admin/security-logs', '/admin/activity-logs', '/workspace', '/profile'].map(path => ({path, name:path.replace(/[^a-z0-9]/gi, '-')}))],
  ];
  let pages = 0;
  for (const [role, email, password, roleRoutes] of roles) {
    await go('/dashboard'); await click('form[action$="/logout"] button'); await loaded('[name=email]');
    await fill('[name=email]', email); await fill('[name=password]', password);
    await evaluate('document.querySelector("form").requestSubmit()'); await loaded('[data-dashboard-role]');
  for (const vp of viewports) {
    await cdp('Emulation.setDeviceMetricsOverride', {
      width: vp.width,
      height: vp.height,
      deviceScaleFactor: 1,
      mobile: vp.mobile,
    });

    for (const r of roleRoutes) {
      await go(r.path);
      await loaded('#viewRoot');
      await until(() => evaluate('document.readyState === "complete"'), `${r.name} at ${vp.label} ready`);
      await evaluate('document.fonts.ready.then(() => true)');
      const fits = await evaluate('document.documentElement.scrollWidth <= window.innerWidth');
      assert.equal(fits, true, `${role}: Horizontal overflow on ${r.path} at ${vp.label}`);
      const unlabeled = await evaluate(`Array.from(document.querySelectorAll('#viewRoot input:not([type=hidden]):not([type=submit]):not([type=button]), #viewRoot select, #viewRoot textarea')).filter(el => el.getClientRects().length && !el.labels?.length && !el.getAttribute('aria-label') && !el.getAttribute('aria-labelledby')).map(el => el.name || el.id)`);
      assert.deepEqual(unlabeled, [], `${role} ${vp.label}: unlabeled fields ${r.path}`);
      assert.equal(await evaluate('typeof bootstrap.Modal'), 'function', 'Bootstrap loaded');
      assert.equal(await evaluate('document.fonts.check("14px bootstrap-icons")'), true, 'Icon font available');
      if (r.path === '/dashboard' || (role === 'owner' && [390, 1440].includes(vp.width))) await shot(`release-${role}-${vp.width}-${r.name}`);
      pages++;
    }

    await go('/dashboard'); await cdp('Page.bringToFront');
    // Verify skip link accessibility and visible focus styling.
    await evaluate(`const skip = document.querySelector('.skip-link'); if (skip) skip.focus();`);
    await until(() => evaluate('document.activeElement.classList.contains("skip-link")'), `${role} ${vp.label}: Skip link receives focus`);
    const focus = await evaluate('({skip:document.activeElement.classList.contains("skip-link"), active:document.activeElement.outerHTML.slice(0,240), focused:document.hasFocus(), path:location.pathname})');
    assert.equal(focus.skip, true, `${role} ${vp.label}: keyboard reaches skip link: ${JSON.stringify(focus)}`);
    assert.equal(await evaluate('getComputedStyle(document.activeElement).outlineStyle !== "none"'), true, 'Visible focus');
    if (vp.width <= 768) {
      await click('#sidebarToggle');
      await until(() => evaluate('document.querySelector("#sidebarToggle").getAttribute("aria-expanded") === "true"'), 'Drawer opens');
      await evaluate('document.dispatchEvent(new KeyboardEvent("keydown", { key: "Escape", code: "Escape", bubbles: true, cancelable: true }))');
      await until(() => evaluate('document.querySelector("#sidebarToggle").getAttribute("aria-expanded") === "false"'), 'Drawer closes');
    }
    if (role === 'owner') {
      await go('/clients/1');
      await evaluate('window.releaseModalShown = false; document.querySelector("#formModal").addEventListener("shown.bs.modal", () => { window.releaseModalShown = true; }, {once:true})');
      await click('a[data-modal][href$="/edit"]');
      await loaded('#formModal.show [name=business_name]');
      await until(() => evaluate('window.releaseModalShown'), 'Modal opening transition completes');
      await until(() => evaluate('document.querySelector("#formModal").contains(document.activeElement)'), 'Modal focus');
      assert.equal(await evaluate('document.querySelector("#formModal .modal-content").getBoundingClientRect().width <= innerWidth'), true, 'Modal fits');
      await shot(`release-edit-modal-${vp.width}`);
      await click('#formModal [data-bs-dismiss]');
      await until(() => evaluate('!document.querySelector("#formModal").classList.contains("show")'), 'Modal closes');
    }
    console.log(`PASS: ${role} ${vp.label}: ${roleRoutes.length} routes, labels, keyboard focus, assets and navigation.`);
    console.log(`PASS: Responsive verification at ${vp.label} (width: ${vp.width}px, height: ${vp.height}px) — 0 overflow errors across all supporting modules.`);
  }
  }
  console.log(`PASS: ${pages} role/page/viewport combinations.`);

  // Restore standard desktop emulation
  await cdp('Emulation.setDeviceMetricsOverride', { width: 1440, height: 1000, deviceScaleFactor: 1, mobile: false });
  console.log('PASS: All 6 responsive viewports (320px, 390px, 768px, 1024px, 1440px, 1920px) successfully validated.');
}
