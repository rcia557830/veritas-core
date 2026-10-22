// Chrome runner for the guarded local populated demo. Launch with tests/verify-demo-population.ps1.
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
let inspectFailure;
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
  const loadedFrames = new Set();
  const errors = [];
  socket.onmessage = event => {
    const message = JSON.parse(event.data);
    if (message.id) {
      const pending = waiting.get(message.id);
      waiting.delete(message.id);
      if (message.error) pending.reject(new Error(message.error.message)); else pending.resolve(message.result);
    } else if (message.method === 'Page.lifecycleEvent' && message.params.name === 'load') loadedFrames.add(message.params.loaderId);
    else if (message.method === 'Runtime.exceptionThrown') errors.push(message.params.exceptionDetails.text + ': ' + (message.params.exceptionDetails.exception?.description ?? ''));
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
  inspectFailure = async () => {
    console.error(await evaluate('({url:location.href, text:document.querySelector("main")?.innerText ?? document.body.innerText})'));
  };
  const loaded = async (selector) => until(() => evaluate(`document.readyState === 'complete' && !!document.querySelector(${JSON.stringify(selector)})`), selector);
  const go = async (url, selector = 'main') => {
    const navigation = await cdp('Page.navigate', { url: base + url });
    if (navigation.loaderId) await until(() => loadedFrames.has(navigation.loaderId), 'page load: ' + url);
    await loaded(selector);
  };
  const fill = (selector, value) => evaluate(`document.querySelector(${JSON.stringify(selector)}).value = ${JSON.stringify(value)}`);
  const click = selector => evaluate(`document.querySelector(${JSON.stringify(selector)}).click()`);
  const hasText = value => until(async () => (await text()).includes(value), value);
  const submit = async (selector, expected) => { await evaluate(`document.querySelector(${JSON.stringify(selector)}).requestSubmit()`); await hasText(expected); await loaded('main'); };
  const shot = async name => { const result = await cdp('Page.captureScreenshot', { format: 'png', captureBeyondViewport: true }); await writeFile(path.join(artifacts, name + '.png'), Buffer.from(result.data, 'base64')); };
  await cdp('Page.enable'); await cdp('Runtime.enable');
  await cdp('Page.setLifecycleEventsEnabled', { enabled: true });
  await cdp('Emulation.setDeviceMetricsOverride', { width: 1440, height: 1000, deviceScaleFactor: 1, mobile: false });
  const workflow = await import(pathToFileURL(path.resolve(process.argv[5])).href);
  await workflow.runJournal({ cdp, evaluate, fill, click, go, loaded, hasText, submit, shot, text, until });
  assert.deepEqual(errors, [], 'No JavaScript exceptions');
} catch (error) {
  await inspectFailure?.();
  throw error;
} finally {
  socket?.close();
  browser.kill();
  browser.stderr.destroy();
  browser.unref();
}
