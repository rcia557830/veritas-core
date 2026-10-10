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

  // 2. Onboarding checklist with a justified exemption (no configured requirements).
  await go(`/clients/${clientId}/onboarding`);
  await hasText('No required onboarding documents');
  await fill('[name=exemption_reason]', 'SYNTHETIC browser exemption justification');
  await submit('#viewRoot form', 'Onboarded on');
  await shot('onboarding-exemption-completed');

  // 3. Onboarding list reflects the onboarded client.
  await go('/clients/onboarding');
  await hasText('SYNTHETIC Browser Client');
  await shot('onboarding-list-desktop');

  // 4. Dashboard navigation.
  await go('/dashboard');
  await hasText('Owner overview');
  await shot('dashboard-desktop');

  // 5. Document requirements monitoring.
  await go('/requirements/monitoring');
  await shot('requirements-monitoring-desktop');

  // 6. Compliance monitoring.
  await go('/compliance/monitoring');
  await shot('compliance-monitoring-desktop');

  // 7. Mobile viewport checks (no horizontal overflow, sidebar collapses).
  await cdp('Emulation.setDeviceMetricsOverride', { width: 390, height: 844, deviceScaleFactor: 1, mobile: true });
  for (const [url, label] of [['/clients/onboarding', 'onboarding'], ['/dashboard', 'dashboard'], ['/compliance/monitoring', 'compliance']]) {
    await go(url);
    await until(() => evaluate('document.documentElement.scrollWidth <= 390'), `${url} fits mobile`);
    assert.equal(await evaluate('document.documentElement.scrollWidth <= innerWidth'), true, `${url} should not overflow horizontally`);
    await shot(`${label}-mobile`);
  }
  await cdp('Emulation.setDeviceMetricsOverride', { width: 1440, height: 1000, deviceScaleFactor: 1, mobile: false });

  // 8. Billing and knowledge workflows not covered by the Increment 8.1 chain.
  await runFinalWorkflows(helpers);

  // 9. Continue with the existing voucher creation/posting flow (desktop + mobile).
  await voucherFlow(helpers);

  console.log('PASS: Client registration, onboarding checklist, justified onboarding exemption, onboarding list, dashboard, document-requirements monitoring, compliance monitoring, and desktop/mobile rendering; then voucher creation and posting.');
}
