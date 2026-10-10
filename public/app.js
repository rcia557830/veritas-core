(() => {
  'use strict';
  const $ = id => document.getElementById(id);
  const csrf = document.querySelector('meta[name="csrf-token"]').content;
  const formModal = new bootstrap.Modal($('formModal'));
  const confirmModal = new bootstrap.Modal($('confirmModal'));
  let pendingForm = null, approvedForm = null, paletteItems = [], paletteIndex = 0, searchTimer, searchAbort;
  let paletteReturn, modalReturn, sidebarOpen = false;
  const node = (tag, text, className = '') => {
    const el = document.createElement(tag); el.textContent = text; el.className = className; return el;
  };
  function toast(message) {
    const item = node('div', '', 'app-toast is-error'); item.setAttribute('role', 'alert');
    item.append(node('div', message)); const close = node('button', '×', 'icon-button');
    close.setAttribute('aria-label', 'Dismiss message'); close.addEventListener('click', () => item.remove());
    item.append(close); $('toastStack').append(item); setTimeout(() => item.remove(), 10000);
  }
  async function api(url, method = 'GET') {
    const response = await fetch(url, { method, credentials: 'same-origin', headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf } });
    if (!response.ok) throw new Error(response.status === 419 || response.status === 401 ? 'Your session expired. Reload to sign in again.' : 'This request could not be completed. Please try again.');
    return response.json();
  }
  function sidebar(open) {
    sidebarOpen = open; $('sidebar').classList.toggle('is-open', open); $('sidebarOverlay').hidden = !open;
    $('sidebarToggle').setAttribute('aria-expanded', String(open)); $('mainArea').inert = open;
    document.body.classList.toggle('nav-open', open);
    if (open) { $('sidebar').setAttribute('role', 'dialog'); $('sidebar').setAttribute('aria-modal', 'true'); $('sidebarClose').focus(); }
    else { $('sidebar').removeAttribute('role'); $('sidebar').removeAttribute('aria-modal'); }
  }
  $('sidebarToggle').addEventListener('click', () => sidebar(!sidebarOpen));
  [$('sidebarOverlay'), $('sidebarClose')].forEach(el => el.addEventListener('click', () => { sidebar(false); $('sidebarToggle').focus(); }));
  matchMedia('(min-width: 992px)').addEventListener('change', e => { if (e.matches) sidebar(false); });
  document.addEventListener('click', async event => {
    const modalLink = event.target.closest('a[data-modal]');
    if (modalLink && !event.ctrlKey && !event.metaKey) {
      event.preventDefault(); modalReturn = modalLink;
      $('formModalBody').textContent = 'Loading form…'; formModal.show();
      try {
        const response = await fetch(modalLink.href, { headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' });
        if (!response.ok || response.redirected) throw new Error('The form could not load. Reload to check your session.');
        $('formModalBody').innerHTML = await response.text(); // Server-rendered Blade escapes record content.
        $('formModalTitle').textContent = $('formModalBody').querySelector('h2')?.textContent || 'Record form';
        $('formModalBody').querySelector('input:not([type=hidden]),select,textarea')?.focus();
        $('formModalBody').querySelectorAll('[data-lines]').forEach(updateTotals);
      } catch (error) { $('formModalBody').textContent = error.message; }
    }
    const add = event.target.closest('[data-add-line]');
    const remove = event.target.closest('[data-remove-line]');
    if (add) {
      const section = add.closest('[data-lines]'), body = section.querySelector('[data-line-body]');
      const row = body.firstElementChild.cloneNode(true);
      row.querySelectorAll('.invalid-feedback,.text-danger').forEach(el => el.remove());
      if (body.children.length >= 100) return toast('Use at most 100 lines.');
      row.querySelectorAll('input,select').forEach(input => { input.value = input.name.includes('quantity') ? '1' : input.type === 'number' || /\[(debit|credit)\]$/.test(input.name) ? '0' : ''; input.classList.remove('is-invalid'); });
      body.append(row); reindex(section); row.querySelector('select,input:not([type=hidden])').focus(); updateTotals(section);
    }
    if (remove) {
      const section = remove.closest('[data-lines]');
      if (section.querySelectorAll('[data-line-body] tr').length <= (['ledger','journal'].includes(section.dataset.lines) ? 2 : 1)) return toast('Keep at least ' + (['ledger','journal'].includes(section.dataset.lines) ? 'two journal lines.' : 'one invoice item.'));
      remove.closest('tr').remove(); reindex(section); updateTotals(section);
    }
    if (event.target.closest('[data-print]')) window.print();
    if (!event.target.closest('.notification-wrap')) closeNotifications();
  });
  function reindex(section) {
    section.querySelectorAll('[data-line-body] tr').forEach((row, index) => row.querySelectorAll('input,select').forEach(input => {
      const key = input.name.match(/\[([^\]]+)\]$/)[1]; input.name = `items[${index}][${key}]`;
      input.id = `item_${index}_${key}`; 
      if (input.previousElementSibling?.tagName === 'LABEL') {
        input.previousElementSibling.setAttribute('for', input.id);
        const labels = { account_id: 'Account', account_name: 'Account', debit: 'Debit', credit: 'Credit', description: 'Service description', quantity: 'Quantity', unit_price: 'Unit price' };
        input.previousElementSibling.textContent = (labels[key] || key) + ' line ' + (index + 1);
      }
    }));
  }
  function updateTotals(section) {
    if (section.dataset.lines === 'journal') {
      let debit = 0n, credit = 0n, valid = true;
      const cents = value => {
        if (!/^\d{1,9}(?:\.\d{1,2})?$/.test(value)) { valid = false; return 0n; }
        const [whole, fraction = ''] = value.split('.');
        return BigInt(whole) * 100n + BigInt(fraction.padEnd(2, '0'));
      };
      section.querySelectorAll('[data-line-body] tr').forEach(row => {
        debit += cents(row.querySelector('[name$="[debit]"]').value);
        credit += cents(row.querySelector('[name$="[credit]"]').value);
      });
      const format = value => 'PHP ' + (value / 100n).toString().replace(/\B(?=(\d{3})+(?!\d))/g, ',') + '.' + (value % 100n).toString().padStart(2, '0');
      section.querySelector('[data-line-total]').textContent = valid ? `Debit ${format(debit)} · Credit ${format(credit)} · ${debit === credit && debit > 0n ? 'Balanced' : 'Unbalanced — Draft only'}` : 'Enter non-negative amounts with at most two decimal places.';
      return;
    }
    let debit = 0, credit = 0, subtotal = 0;
    section.querySelectorAll('[data-line-body] tr').forEach(row => {
      const value = key => Math.round(Number(row.querySelector(`[name$="[${key}]"]`)?.value || 0) * 100);
      if (section.dataset.lines === 'ledger') { debit += value('debit'); credit += value('credit'); }
      else subtotal += Math.round(value('quantity') * value('unit_price') / 100);
    });
    const peso = cents => new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' }).format(cents / 100);
    section.querySelector('[data-line-total]').textContent = section.dataset.lines === 'ledger'
      ? `Debit ${peso(debit)} · Credit ${peso(credit)} · ${debit === credit && debit > 0 ? 'Balanced' : 'Unbalanced — submission will be blocked'}`
      : `Subtotal ${peso(subtotal)} · Total with tax ${peso(subtotal + Math.round(Number(section.closest('form').querySelector('[name=tax]')?.value || 0) * 100))}`;
  }
  document.addEventListener('input', event => {
    const section = event.target.closest('[data-lines]') || (event.target.name === 'tax' ? event.target.closest('form').querySelector('[data-lines]') : null);
    if (section) updateTotals(section);
  });
  document.querySelectorAll('[data-lines]').forEach(updateTotals);
  const journalRequests = new WeakMap();
  document.addEventListener('change', async event => {
    const form = event.target.closest('[data-journal-form]');
    if (!form || event.target.name !== 'client_id') return;
    journalRequests.get(form)?.abort();
    const controller = new AbortController(); journalRequests.set(form, controller);
    const client = event.target.value, status = form.querySelector('[data-journal-options-status]');
    const accountSelects = () => form.querySelectorAll('[name$="[account_id]"], [name="cash_account_id"]');
    const reset = (select, prompt) => { select.replaceChildren(new Option(prompt, '')); };
    accountSelects().forEach(select => reset(select, 'Choose an active account'));
    const period = form.querySelector('[name=accounting_period_id]'); reset(period, 'Select an open period');
    const documents = form.querySelector('[data-journal-documents]'); documents.replaceChildren();
    status.textContent = client ? 'Loading client accounts, periods and documents…' : 'Choose a client.';
    if (!client) return;
    try {
      const response = await fetch(form.dataset.optionsUrl + '?client_id=' + encodeURIComponent(client), { signal: controller.signal, headers: { Accept: 'application/json' }, credentials: 'same-origin' });
      if (!response.ok) throw new Error('Client options could not load. Select the client again to retry.');
      const data = await response.json();
      if (controller.signal.aborted || event.target.value !== client) return;
      accountSelects().forEach(select => data.accounts.filter(account => select.name !== 'cash_account_id' || account.classification === 'Asset').forEach(account => select.add(new Option(account.code + ' · ' + account.name, account.id))));
      data.periods.forEach(item => period.add(new Option(`${item.label} · ${item.starts_on} – ${item.ends_on}`, item.id)));
      data.documents.forEach(item => {
        const wrap = node('div', '', 'form-check'), input = document.createElement('input'), label = node('label', item.label, 'form-check-label');
        input.type = 'checkbox'; input.name = 'document_ids[]'; input.value = item.id; input.id = 'journal_doc_' + item.id; input.className = 'form-check-input'; label.htmlFor = input.id; wrap.append(input, label); documents.append(wrap);
      });
      if (!data.documents.length) documents.append(node('p', 'No accessible documents for this client.', 'subtext'));
      status.textContent = data.accounts.length ? (data.periods.length ? 'Client options loaded. Previous client selections were cleared.' : 'No open periods configured. You may save a Draft, but cannot submit it yet.') : 'No active accounts. An authorized employee must configure this client’s Chart of Accounts.';
    } catch (error) { if (error.name !== 'AbortError') status.textContent = error.message; }
  });
  document.addEventListener('submit', event => {
    const form = event.target;
    if (form.dataset.confirm && approvedForm !== form) {
      event.preventDefault(); pendingForm = form; $('confirmText').textContent = form.dataset.confirm; confirmModal.show(); return;
    }
    // Avoid disabling named submitters before the browser builds the form data.
    setTimeout(() => form.querySelectorAll('button[type=submit],button:not([type])').forEach(button => {
      button.disabled = true;
      if (form.hasAttribute('data-accounting-form')) { button.textContent = 'Saving…'; form.setAttribute('aria-busy', 'true'); }
    }), 0);
  });
  $('confirmAccept').addEventListener('click', () => {
    approvedForm = pendingForm; confirmModal.hide(); approvedForm?.requestSubmit();
  });
  $('formModal').addEventListener('hidden.bs.modal', () => { $('formModalBody').replaceChildren(); modalReturn?.focus(); });
  function selectResult(index) {
    paletteIndex = index;
    $('commandResults').querySelectorAll('[role=option]').forEach((el, i) => el.setAttribute('aria-selected', String(i === index)));
    const current = $('result' + index);
    if (current) { $('commandInput').setAttribute('aria-activedescendant', current.id); current.scrollIntoView({ block: 'nearest' }); }
    else $('commandInput').removeAttribute('aria-activedescendant');
  }
  async function search() {
    searchAbort?.abort(); searchAbort = new AbortController();
    const url = new URL(document.body.dataset.searchUrl); url.searchParams.set('q', $('commandInput').value.slice(0, 150));
    $('commandStatus').textContent = 'Searching…';
    try {
      const response = await fetch(url, { signal: searchAbort.signal, headers: { Accept: 'application/json' } });
      if (!response.ok) throw new Error('Search unavailable. Check your session and try again.');
      const results = await response.json(); if (!$('commandPalette').open) return;
      paletteItems = results; $('commandResults').replaceChildren();
      results.forEach((result, index) => {
        const row = node('div', '', 'command-result'); row.id = 'result' + index; row.setAttribute('role', 'option');
        const text = node('span', result.title); text.append(node('small', [result.type, result.client, result.status].filter(Boolean).join(' · ')));
        row.append(text); row.addEventListener('click', () => location.assign(result.url)); $('commandResults').append(row);
      });
      if (!results.length) $('commandResults').append(node('p', 'No matches. Try a client name, reference, or article title.', 'empty-state'));
      selectResult(0); $('commandStatus').textContent = `${results.length} results`;
    } catch (error) {
      if (error.name !== 'AbortError') { $('commandResults').replaceChildren(node('p', error.message, 'empty-state')); $('commandStatus').textContent = error.message; }
    }
  }
  function openSearch() {
    if (document.querySelector('.modal.show')) return;
    paletteReturn = document.activeElement; closeNotifications(); sidebar(false);
    $('commandPalette').showModal(); $('commandInput').value = ''; $('commandInput').focus(); search();
  }
  function closeSearch() { clearTimeout(searchTimer); searchAbort?.abort(); $('commandPalette').close(); paletteReturn?.focus(); }
  $('commandTrigger').addEventListener('click', openSearch); $('commandClose').addEventListener('click', closeSearch);
  $('commandInput').addEventListener('input', () => { clearTimeout(searchTimer); searchTimer = setTimeout(search, 200); });
  $('commandPalette').addEventListener('cancel', event => { event.preventDefault(); closeSearch(); });
  $('commandPalette').addEventListener('click', event => { if (event.target === $('commandPalette')) { const r = $('commandPalette').getBoundingClientRect(); if (event.clientX < r.left || event.clientX > r.right || event.clientY < r.top || event.clientY > r.bottom) closeSearch(); } });
  document.addEventListener('keydown', event => {
    if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k') { event.preventDefault(); $('commandPalette').open ? closeSearch() : openSearch(); }
    if ($('commandPalette').open && event.target === $('commandInput')) {
      if (['ArrowDown','ArrowUp'].includes(event.key)) { event.preventDefault(); if (paletteItems.length) selectResult((paletteIndex + (event.key === 'ArrowDown' ? 1 : -1) + paletteItems.length) % paletteItems.length); }
      if (event.key === 'Enter') { event.preventDefault(); if (paletteItems[paletteIndex]) location.assign(paletteItems[paletteIndex].url); }
    }
    if (event.key === 'Escape') { closeNotifications(); if (sidebarOpen) { sidebar(false); $('sidebarToggle').focus(); } }
    if (event.key === 'Tab' && sidebarOpen) {
      const items = [...$('sidebar').querySelectorAll('a,button')], first = items[0], last = items.at(-1);
      if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
      if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
    }
  });
  function closeNotifications() { $('notificationPanel').hidden = true; $('notificationTrigger').setAttribute('aria-expanded','false'); }
  async function notifications() {
    const data = await api(document.body.dataset.notificationsUrl); $('notificationDot').hidden = data.unread === 0;
    $('notificationTrigger').setAttribute('aria-label', `Notifications, ${data.unread} unread`);
    $('notificationPanel').replaceChildren(); const heading = node('div', '', 'panel-heading');
    heading.append(node('h2', 'Notifications', 'section-title')); const read = node('button', 'Mark all read', 'btn btn-sm btn-outline-secondary');
    read.addEventListener('click', async () => { try { await api(document.body.dataset.readAllUrl, 'POST'); await notifications(); } catch (error) { toast(error.message); } });
    heading.append(read); $('notificationPanel').append(heading);
    data.items.forEach(item => {
      const button = node('button', '', 'notification-item' + (item.read ? '' : ' is-unread'));
      const text = node('span', ''); text.append(node('strong', item.title), node('small', item.client || 'Workspace')); button.append(text);
      button.addEventListener('click', async () => {
        try { await api(`${document.body.dataset.notificationsUrl}/${encodeURIComponent(item.id)}/read`, 'POST'); location.assign(item.url); }
        catch (error) { toast(error.message); }
      }); $('notificationPanel').append(button);
    });
    if (!data.items.length) $('notificationPanel').append(node('p', 'You’re all caught up. No notifications need your attention.', 'empty-state'));
  }
  $('notificationTrigger').addEventListener('click', async () => {
    const open = $('notificationPanel').hidden; $('notificationPanel').hidden = !open; $('notificationTrigger').setAttribute('aria-expanded', String(open));
    if (open) { $('notificationPanel').textContent = 'Loading notifications…'; try { await notifications(); } catch (error) { $('notificationPanel').textContent = error.message; } }
  });
  notifications().catch(() => { $('notificationTrigger').setAttribute('aria-label', 'Notifications — click to retry'); });
})();
