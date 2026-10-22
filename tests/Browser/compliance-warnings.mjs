// Isolated responsive style regression: uses production CSS, no database or app writes.
import assert from 'node:assert/strict';
import { spawn } from 'node:child_process';
import { readFile, mkdtemp } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { createServer } from 'node:http';

const styles = await readFile(new URL('../../public/styles.css', import.meta.url), 'utf8');
const integration = await readFile(new URL('../../public/laravel.css', import.meta.url), 'utf8');
const labels = [['On Schedule', 'success'], ['Completed', 'success'], ['Submission Due Soon', 'warning'], ['Submission Due Today', 'warning'], ['Submission Overdue', 'danger'], ['Filing Due Soon', 'warning'], ['Filing Due Today', 'warning'], ['Filing Overdue', 'danger']];
const workflows = [['Pending', 'warning'], ['In Preparation', 'warning'], ['In Progress', 'warning'], ['Completed', 'success'], ['Filed', 'success'], ['Verified', 'success'], ['Overdue', 'danger'], ['Pending', 'warning']];
const html = `<style>${styles}
${integration}
body{margin:0;padding:16px}.test-grid{display:grid;gap:16px;grid-template-columns:repeat(auto-fit,minmax(min(100%,280px),1fr))}</style><div class="test-grid">${labels.map(([label, tone], index) => `<article class="panel deadline-card deadline-tone-${tone}"><div class="toolbar"><span class="tag">BIR</span><span class="badge-status compliance-workflow deadline-tone-${workflows[index][1]}">${workflows[index][0]}</span></div><h2>Compliance requirement</h2><span class="compliance-warnings"><span class="compliance-warning"><span class="compliance-warning-label">${label.startsWith('Filing') ? 'Filing' : 'Submission'}</span><span class="badge-status deadline-tone-${tone}">${label}</span></span></span></article>`).join('')}</div>`;
const server = createServer((req, res) => { res.setHeader('Content-Type', 'text/html'); res.end(html); });
await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
const profile = await mkdtemp(join(tmpdir(), 'veritas-compliance-css-'));
const browser = spawn(process.env.EDGE_PATH || 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe', ['--headless=new', '--disable-gpu', '--no-first-run', '--remote-debugging-port=0', `--user-data-dir=${profile}`, 'about:blank'], { windowsHide: true, stdio: 'ignore' });
let socket;
try {
  let port;
  for (let attempt = 0; attempt < 100; attempt++) {
    try { port = (await readFile(join(profile, 'DevToolsActivePort'), 'utf8')).split('\n')[0]; break; } catch { await new Promise(resolve => setTimeout(resolve, 100)); }
  }
  assert.ok(port, 'Headless browser started');
  const pages = await (await fetch(`http://127.0.0.1:${port}/json/list`)).json();
  socket = new WebSocket(pages.find(page => page.type === 'page').webSocketDebuggerUrl);
  await new Promise(resolve => socket.addEventListener('open', resolve, { once: true }));
  let sequence = 0;
  const pending = new Map();
  socket.addEventListener('message', event => {
    const message = JSON.parse(event.data);
    if (pending.has(message.id)) { const { resolve, reject } = pending.get(message.id); pending.delete(message.id); message.error ? reject(message.error) : resolve(message.result); }
  });
  const cdp = (method, params = {}) => new Promise((resolve, reject) => { const id = ++sequence; pending.set(id, { resolve, reject }); socket.send(JSON.stringify({ id, method, params })); });
  await cdp('Page.navigate', { url: `http://127.0.0.1:${server.address().port}` });
  for (let attempt = 0; attempt < 50; attempt++) {
    const result = await cdp('Runtime.evaluate', { expression: 'document.querySelectorAll(".deadline-card").length', returnByValue: true });
    if (result.result.value === labels.length) break;
    await new Promise(resolve => setTimeout(resolve, 100));
  }
  for (const width of [1440, 768, 390, 320]) {
    await cdp('Emulation.setDeviceMetricsOverride', { width, height: 1000, deviceScaleFactor: 1, mobile: false });
    const result = await cdp('Runtime.evaluate', { returnByValue: true, expression: `({overflow:document.documentElement.scrollWidth>innerWidth, badges:[...document.querySelectorAll('.compliance-warnings .badge-status')].map(el=>{const r=el.getBoundingClientRect(),s=getComputedStyle(el),range=document.createRange();range.selectNodeContents(el);return {label:el.textContent,width:r.width,height:r.height,clipped:range.getBoundingClientRect().width>r.width-16,color:s.color,bg:s.backgroundColor};}), workflow:[...document.querySelectorAll('.compliance-workflow')].map(el=>{const r=el.getBoundingClientRect(),s=getComputedStyle(el);return {width:r.width,height:r.height,color:s.color,bg:s.backgroundColor,border:s.borderTopColor};})})` });
    const measured = result.result.value;
    assert.equal(measured.overflow, false, `${width}px: page overflow`);
    assert.equal(measured.badges.length, labels.length);
    measured.badges.forEach((badge, index) => {
      assert.ok(badge.width < 180 && badge.height < 32 && !badge.clipped, `${width}px: ${badge.label} compact and unclipped`);
      const colors = { success: ['rgb(22, 101, 52)', 'rgb(220, 252, 231)'], warning: ['rgb(133, 77, 14)', 'rgb(254, 249, 195)'], danger: ['rgb(153, 27, 27)', 'rgb(254, 226, 226)'] };
      assert.deepEqual([badge.color, badge.bg], colors[labels[index][1]]);
    });
    assert.equal(measured.workflow.length, workflows.length);
    measured.workflow.forEach((badge, index) => {
      assert.equal(badge.width, 180, 'Workflow badges retain existing width');
      assert.equal(badge.height, 32, 'Workflow badges retain existing height');
      const colors = { success: ['rgb(22, 101, 52)', 'rgb(220, 252, 231)', 'rgb(134, 239, 172)'], warning: ['rgb(133, 77, 14)', 'rgb(254, 249, 195)', 'rgb(253, 224, 71)'], danger: ['rgb(153, 27, 27)', 'rgb(254, 226, 226)', 'rgb(252, 165, 165)'] };
      assert.deepEqual([badge.color, badge.bg, badge.border], colors[workflows[index][1]], `${width}px: ${workflows[index][0]} workflow colors`);
    });
    console.log(`PASS ${width}px: all eight warning labels, workflow colors and dimensions, compact warning badges, no clipping or page overflow`);
  }
  await cdp('Browser.close');
} finally {
  socket?.close();
  browser.kill();
  server.close();
}
