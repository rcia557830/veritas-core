import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import path from 'node:path';

// Runs only after guarded MySQL preparation with --security. No real mailbox.
export async function runJournal({ cdp, evaluate, fill, click, go, loaded, hasText, shot, until, text }) {
  const formSubmit = async (selector, expected) => {
    await evaluate(`document.querySelector(${JSON.stringify(selector)}).requestSubmit()`);
    await hasText(expected);
  };
  const logout = async () => { await click('form[action$="/logout"] button'); await loaded('form[action$="/login"]'); };
  const login = async (email, password, expected) => {
    await go('/login', 'form[action$="/login"]');
    await fill('[name=email]', email); await fill('[name=password]', password);
    await formSubmit('form[action$="/login"]', expected);
  };
  const mailLink = async (segment) => {
    const mailbox = JSON.parse(await readFile(path.join(path.dirname(process.env.VERITAS_TEST_DATADIR), 'browser-mail.json'), 'utf8'));
    const match = mailbox.html.match(new RegExp(`href="([^"]*${segment}[^"]*)"`));
    assert.ok(match, 'Synthetic mailbox contains the requested link');
    const url = new URL(match[1].replaceAll('&amp;', '&'));
    return url.pathname + url.search;
  };
  await cdp('Emulation.setDeviceMetricsOverride', { width: 1440, height: 1000, deviceScaleFactor: 1, mobile: false });
  await go('/admin/users', 'body'); await hasText('403'); // Owner separation
  await go('/dashboard'); await logout();
  await login('technical@example.test', 'Synthetic-admin-123', 'Technical administration');
  await go('/admin/users/create', '[name=role_id]');
  assert.equal(await evaluate('!!document.querySelector("[name=password]")'), false);
  await fill('[name=name]', 'SYNTHETIC Invited Employee'); await fill('[name=email]', 'invited-browser@example.test');
  const role = await evaluate('Array.from(document.querySelector("[name=role_id]").options).find(o=>o.textContent === "Bookkeeper").value');
  await fill('[name=role_id]', role);
  await formSubmit('#viewRoot form', 'Invitation sent.');
  await hasText('Pending Invitation'); await shot('security-user-management-desktop');
  const invitation = await mailLink('/invitations/');
  await logout();
  await go(invitation, '[name=password_confirmation]'); await shot('security-invitation-desktop');
  await fill('[name=email]', 'invited-browser@example.test');
  await fill('[name=password]', 'Browser-password-123'); await fill('[name=password_confirmation]', 'Browser-password-123');
  await formSubmit('form', 'Invitation accepted.');
  await login('invited-browser@example.test', 'Browser-password-123', 'Bookkeeper overview');
  await go('/profile', 'form[action$="/profile/password"]');
  await fill('form[action$="/profile/password"] [name=current_password]', 'Browser-password-123');
  await fill('[name=password]', 'Changed-password-123'); await fill('[name=password_confirmation]', 'Changed-password-123');
  await formSubmit('form[action$="/profile/password"]', 'Password changed.'); await shot('security-change-password');
  await logout();
  await go('/forgot-password', 'form'); await fill('[name=email]', 'invited-browser@example.test');
  await formSubmit('form', 'If this address has an account'); await shot('security-forgot-password');
  const reset = await mailLink('/reset-password/');
  await go(reset, '[name=password_confirmation]');
  await fill('[name=password]', 'Reset-password-123'); await fill('[name=password_confirmation]', 'Reset-password-123');
  await shot('security-reset-password'); await formSubmit('form', 'Password reset.');
  await login('invited-browser@example.test', 'Reset-password-123', 'Bookkeeper overview');
  await logout();
  await go(invitation, '[name=password_confirmation]');
  await fill('[name=email]', 'invited-browser@example.test');
  await fill('[name=password]', 'Replay-password-123'); await fill('[name=password_confirmation]', 'Replay-password-123');
  await formSubmit('form', 'This invitation is invalid or has expired.');
  await login('technical@example.test', 'Synthetic-admin-123', 'Technical administration');
  await go('/admin/users'); await hasText('Send Password Reset Link');
  await cdp('Emulation.setDeviceMetricsOverride', { width: 390, height: 844, deviceScaleFactor: 1, mobile: true });
  await go('/admin/users'); await shot('security-user-management-mobile');
  await until(() => evaluate('document.documentElement.scrollWidth <= innerWidth'), 'User management fits mobile');
  await go('/clients', 'body'); await hasText('403');
  assert.ok((await text()).includes('403'));
  console.log('PASS: Owner technical-route denial; Super Admin invitation UI; email link acceptance; login; self-service password change; forgot/reset email links; invitation replay rejection; mobile account management; Super Admin business-route denial.');
}
