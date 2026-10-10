// Dependency-free Chrome DevTools smoke test. Use ONLY with tests/Browser/server.php
// and fixtures prepared in the separately verified disposable MySQL instance.
import { spawn } from 'node:child_process';
import { readFile, writeFile, mkdir } from 'node:fs/promises';
import path from 'node:path';
import { pathToFileURL } from 'node:url';
import assert from 'node:assert/strict';

const [base, browserPath, artifacts] = process.argv.slice(2);
assert.match(base ?? '', /^http:\/\/127\.0\.0\.1:\d+$/);
assert.ok(browserPath && artifacts, 'Supply URL, browser executable, and a disposable artifact directory.');
await mkdir(artifacts, { recursive: true });
const profile = path.join(artifacts, 'profile');
const browser = spawn(browserPath, ['--headless=new', '--remote-debugging-port=0', '--no-first-run', '--no-default-browser-check', '--disable-gpu', `--user-data-dir=${profile}`, 'about:blank'], { windowsHide: true, stdio: ['ignore', 'ignore', 'pipe'] });
let browserLog = '';
browser.stderr.on('data', chunk => { browserLog = (browserLog + chunk).slice(-4000); });
let socket;
const sleep = ms => new Promise(resolve => setTimeout(resolve, ms));
async function until(operation, description) {
  const end = Date.now() + 20000;
  while (Date.now() < end) { try { const result = await operation(); if (result) return result; } catch {} await sleep(100); }
  throw new Error(`Timed out: ${description}`);
}
try {
  const port = await until(async () => (await readFile(path.join(profile, 'DevToolsActivePort'), 'utf8')).split('\n')[0], 'browser debugger');
  const pages = await (await fetch(`http://127.0.0.1:${port}/json`)).json();
  socket = new WebSocket(pages.find(page => page.type === 'page').webSocketDebuggerUrl);
  await new Promise((resolve, reject) => { socket.onopen = resolve; socket.onerror = reject; });
  let id = 0;
  const waiting = new Map();
  const errors = [];
  socket.onmessage = event => {
    const message = JSON.parse(event.data);
    if (message.id) {
      const pending = waiting.get(message.id);
      waiting.delete(message.id);
      if (message.error) pending.reject(new Error(message.error.message)); else pending.resolve(message.result);
    } else if (message.method === 'Runtime.exceptionThrown') errors.push(message.params.exceptionDetails.text + ': ' + (message.params.exceptionDetails.exception?.description ?? ''));
  };
  function cdp(method, params = {}) {
    return new Promise((resolve, reject) => { const key = ++id; waiting.set(key, { resolve, reject }); socket.send(JSON.stringify({ id: key, method, params })); });
  }
  const evaluate = async expression => {
    const result = await cdp('Runtime.evaluate', { expression, returnByValue: true, awaitPromise: true });
    if (result.exceptionDetails) throw new Error(result.exceptionDetails.exception?.description ?? result.exceptionDetails.text);
    return result.result.value;
  };
  const text = () => evaluate('document.body.innerText');
  const loaded = async (selector) => until(() => evaluate(`document.readyState === 'complete' && !!document.querySelector(${JSON.stringify(selector)})`), selector);
  const go = async (url, selector = 'main') => { await cdp('Page.navigate', { url: base + url }); await loaded(selector); };
  const fill = (selector, value) => evaluate(`document.querySelector(${JSON.stringify(selector)}).value = ${JSON.stringify(value)}`);
  const click = selector => evaluate(`document.querySelector(${JSON.stringify(selector)}).click()`);
  const hasText = value => until(async () => (await text()).includes(value), value);
  const submit = async (selector, expected) => { await evaluate(`document.querySelector(${JSON.stringify(selector)}).requestSubmit()`); await hasText(expected); await loaded('main'); };
  const shot = async name => { const result = await cdp('Page.captureScreenshot', { format: 'png', captureBeyondViewport: true }); await writeFile(path.join(artifacts, name + '.png'), Buffer.from(result.data, 'base64')); };
  await cdp('Page.enable'); await cdp('Runtime.enable');
  await cdp('Emulation.setDeviceMetricsOverride', { width: 1440, height: 1000, deviceScaleFactor: 1, mobile: false });
  await go('/login', '[name=email]');
  await fill('[name=email]', 'owner@veritascore.local'); await fill('[name=password]', 'password123');
  await submit('form', 'SYNTHETIC BROWSER DEMO');
  await go('/accounts'); await hasText('Select a client');
  await fill('[name=client_id]', '1'); await submit('.filters', 'Create account');
  await click('a[href*="/accounts/create"]'); await loaded('[name=code]');
  await fill('[name=code]', '0001-a'); await fill('[name=name]', 'SYNTHETIC Browser Asset');
  await submit('form[data-accounting-form]', 'Account created.');
  await shot('account-details');
  await click('a[href$="/edit"]'); await loaded('[name=code]');
  await fill('[name=name]', 'SYNTHETIC Browser Updated'); await submit('form[data-accounting-form]', 'Account updated.');
  await click('form[data-confirm] button'); await until(() => evaluate('document.querySelector("#confirmModal").classList.contains("show")'), 'deactivation confirmation');
  await click('#confirmAccept'); await hasText('Account status saved.');
  assert.ok((await text()).includes('Inactive'));
  await go('/accounts?client_id=1&status=inactive&q=Updated'); await hasText('SYNTHETIC Browser Updated');
  await go('/accounts/create?client_id=1', '[name=code]');
  await fill('[name=code]', '0001-A'); await fill('[name=name]', 'SYNTHETIC Duplicate');
  await submit('form[data-accounting-form]', 'This client already has an account with this code.');
  await go('/account-templates/create', '[name=version]');
  await fill('[name=name]', 'SYNTHETIC Browser Template'); await fill('[name=is_active]', '1');
  await submit('form[data-accounting-form]', 'Template created.');
  const templateUrl = await evaluate('location.pathname');
  await click('a[href$="/items/create"]'); await loaded('[name=code]');
  await fill('[name=code]', '0099'); await fill('[name=name]', 'SYNTHETIC Template Asset');
  await submit('form[data-accounting-form]', 'Template item created.');
  const templateId = templateUrl.split('/').at(-1);
  await go(`/accounts/initialize?client_id=1&template_id=${templateId}`, '[name=confirmed]');
  await click('[name=confirmed]'); await click('form[data-accounting-form] button');
  await until(() => evaluate('document.querySelector("#confirmModal").classList.contains("show")'), 'initialization confirmation');
  await click('#confirmAccept'); await hasText('1 accounts copied; 0 previously copied accounts retained.');
  await shot('client-chart');
  await go(templateUrl); await hasText('Preserved');
  await click('form[data-confirm] button');
  await until(() => evaluate('document.querySelector("#confirmModal").classList.contains("show")'), 'version confirmation');
  await click('#confirmAccept'); await hasText('New inactive version created.');
  await shot('template-version');
  await cdp('Emulation.setDeviceMetricsOverride', { width: 390, height: 844, deviceScaleFactor: 1, mobile: true });
  await go('/accounts?client_id=1'); await shot('client-chart-mobile');
  assert.equal(await evaluate('document.documentElement.scrollWidth <= innerWidth'), true, 'Mobile page should not overflow horizontally');
  if (process.argv[5]) {
    const workflow = await import(pathToFileURL(path.resolve(process.argv[5])).href);
    await workflow.runJournal({ cdp, evaluate, fill, click, go, loaded, hasText, submit, shot, text, until });
  }
  assert.deepEqual(errors, [], 'No browser JavaScript errors');
  console.log('PASS: Owner login, client selection, account create/edit/deactivate, confirmation dialogs, filtering, duplicate validation, template/item creation, initialization, version cloning, desktop and mobile rendering; no JavaScript exceptions.');
} finally {
  socket?.close();
  if (!socket && browserLog) console.error(browserLog);
  browser.kill();
  browser.stderr.destroy();
  browser.unref();
}
