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
  document.querySelectorAll('.table-responsive').forEach(region => {
    if (!region.hasAttribute('tabindex')) region.tabIndex = 0;
    if (!region.hasAttribute('aria-label')) region.setAttribute('aria-label', 'Scrollable records');
  });
  const errorSummary = document.querySelector('[data-error-summary]');
  if (errorSummary) errorSummary.focus();
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
      let debit = 0n, credit = 0n, valid = true, validLines = true;
      const cents = value => {
        if (!/^\d{1,9}(?:\.\d{1,2})?$/.test(value)) { valid = false; return 0n; }
        const [whole, fraction = ''] = value.split('.');
        return BigInt(whole) * 100n + BigInt(fraction.padEnd(2, '0'));
      };
      section.querySelectorAll('[data-line-body] tr').forEach(row => {
        const lineDebit = cents(row.querySelector('[name$="[debit]"]').value);
        const lineCredit = cents(row.querySelector('[name$="[credit]"]').value);
        if ((lineDebit > 0n) === (lineCredit > 0n)) validLines = false;
        debit += lineDebit; credit += lineCredit;
      });
      const format = value => 'PHP ' + (value / 100n).toString().replace(/\B(?=(\d{3})+(?!\d))/g, ',') + '.' + (value % 100n).toString().padStart(2, '0');
      const difference = debit >= credit ? debit - credit : credit - debit;
      [['debit', debit], ['credit', credit], ['difference', difference]].forEach(([name, value]) => {
        const output = section.querySelector(`[data-total-${name}]`);
        if (output) output.textContent = valid ? format(value) : '—';
      });
      const balanceMessage = section.querySelector('[data-line-total]');
      balanceMessage.dataset.balance = !valid || !validLines ? 'invalid' : debit === credit ? 'balanced' : 'unbalanced';
      balanceMessage.textContent = !valid
        ? 'Invalid entry: Use non-negative amounts with at most two decimal places.'
        : !validLines
          ? 'Invalid entry: Enter a positive amount in either Debit or Credit for each line, not both.'
          : debit === credit
            ? 'Balanced: Total debits equal total credits.'
            : `Unbalanced: Debits and credits differ by ${format(difference)}. ${section.closest('[data-voucher-form]') ? 'Balance the totals before saving this voucher.' : 'You can save a draft.'}`;

      const form = section.closest('form');
      const guide = form?.querySelector('[data-debit-credit-guide]');
      if (guide) {
        const isVoucher = form.hasAttribute('data-voucher-form');
        const periodSelect = form.querySelector('[name="accounting_period_id"]');
        const setupPanel = form.querySelector('[data-client-setup]');
        const setup = JSON.parse(setupPanel?.dataset.setup || 'null');
        const date = form.querySelector('[name="transaction_date"]')?.value || '';
        const hasPeriod = Boolean(periodSelect?.value && setup?.periods?.some(period =>
          String(period.id) === periodSelect.value && date >= period.starts_on && date <= period.ends_on));
        const rows = section.querySelectorAll('[data-line-body] tr');
        let allAccountsSelected = true;
        let anyLineHasAmount = false;
        rows.forEach(row => {
          const acc = row.querySelector('[name$="[account_id]"]')?.value;
          const deb = cents(row.querySelector('[name$="[debit]"]')?.value || '0');
          const cred = cents(row.querySelector('[name$="[credit]"]')?.value || '0');
          if (deb > 0n || cred > 0n) {
            anyLineHasAmount = true;
            if (!acc) allAccountsSelected = false;
          }
        });
        if (!anyLineHasAmount) allAccountsSelected = false;
        const isBalanced = valid && validLines && debit === credit && debit > 0n;

        const setCheck = (key, state, sym) => {
          const item = guide.querySelector(`[data-guide-check="${key}"]`);
          if (item) {
            item.dataset.state = state;
            const icon = item.querySelector('.guide-check-symbol');
            if (icon) icon.textContent = sym;
          }
        };

        setCheck('single-sided', validLines ? 'complete' : 'invalid', validLines ? '\u2713' : '!');
        setCheck('valid-amounts', valid ? 'complete' : 'invalid', valid ? '\u2713' : '!');
        setCheck('accounts-assigned', allAccountsSelected ? 'complete' : 'missing', allAccountsSelected ? '\u2713' : '\u2014');
        setCheck('totals-balanced', isBalanced ? 'complete' : 'missing', isBalanced ? '\u2713' : '\u2014');
        setCheck('period-selected', hasPeriod ? 'complete' : 'missing', hasPeriod ? '\u2713' : '\u2014');

        const balText = guide.querySelector('[data-balanced-text]');
        if (balText) {
          balText.textContent = isBalanced
            ? 'Totals balance (Difference: PHP 0.00)'
            : `Totals balance (Difference: ${valid ? format(difference) : '—'})`;
        }

        const badge = guide.querySelector('[data-live-balance-badge]');
        if (badge) {
          badge.textContent = !valid || !validLines ? 'Invalid' : isBalanced ? 'Balanced' : 'Unbalanced';
          badge.className = 'badge ' + (!valid || !validLines ? 'bg-danger' : isBalanced ? 'bg-success' : 'bg-warning text-dark');
        }

        const blockReason = guide.querySelector('[data-guidance-block-reason]');
        const banner = form.querySelector('[data-save-status-banner]');
        const bannerText = form.querySelector('[data-save-status-text]');

        let message = '';
        if (!valid) {
          message = 'Saving is blocked: Use non-negative amounts with at most two decimal places.';
        } else if (!validLines) {
          message = 'Saving is blocked: Each line must have an amount in either Debit or Credit, not both.';
        } else if (!allAccountsSelected) {
          message = 'Saving is blocked: Select an active account for each line.';
        } else if (isVoucher) {
          if (!isBalanced) {
            message = `Saving voucher is blocked: Total Debits must equal Total Credits. Adjust lines to resolve the ${format(difference)} difference.`;
          } else if (!hasPeriod) {
            message = 'Saving voucher is blocked: Select an open accounting period for the transaction date.';
          } else if (!allAccountsSelected) {
            message = 'Saving voucher is blocked: Select an active account for each line.';
          } else {
            message = 'Ready to save: Debits and credits balance and open period is selected.';
          }
        } else {
          if (!validLines) {
            message = 'Saving draft is blocked: Enter an amount in either Debit or Credit for each line, not both.';
          } else if (!isBalanced) {
            message = `Draft can be saved. Submitting for review will be blocked until lines balance (Difference: ${format(difference)}) and an open period is selected.`;
          } else if (!hasPeriod) {
            message = 'Draft can be saved. Submitting for review requires selecting an open accounting period.';
          } else if (!allAccountsSelected) {
            message = 'Draft can be saved, but each line needs an account before review submission.';
          } else {
            message = 'Ready for review: Totals balance and open period is selected.';
          }
        }

        if (blockReason) blockReason.textContent = message;
        if (banner && bannerText) {
          const isWarning = (!valid || !validLines || !allAccountsSelected || (isVoucher && (!isBalanced || !hasPeriod)));
          banner.hidden = false;
          banner.className = 'system-alert mb-3 ' + (isWarning ? 'alert-warning' : 'alert-info');
          bannerText.textContent = message;
        }
      }
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
  function updateClientSetup(form, setup, message = '') {
    const panel = form.querySelector('[data-client-setup]');
    if (!panel) return;
    if (setup !== undefined) panel.dataset.setup = JSON.stringify(setup);
    const data = JSON.parse(panel.dataset.setup || 'null');
    const date = form.querySelector('[name="transaction_date"]')?.value || '';
    const validDate = /^\d{4}-\d{2}-\d{2}$/.test(date);
    const covers = items => validDate && items.some(item => item.starts_on <= date && item.ends_on >= date);
    const ready = { accounts: !!data && data.account_count > 0, year: !!data && covers(data.years), period: !!data && covers(data.periods) };
    const descriptions = {
      accounts: ['No active accounts', 'Active accounts available'],
      year: ['No year covers this date', 'Transaction date is covered'],
      period: ['No open period covers this date', 'Open period covers this date'],
    };
    panel.querySelectorAll('[data-setup-check]').forEach(item => {
      const key = item.dataset.setupCheck, pending = !data || (key !== 'accounts' && !validDate);
      item.dataset.state = pending ? 'pending' : ready[key] ? 'complete' : 'missing';
      item.querySelector('[data-setup-symbol]').textContent = pending ? '\u2014' : ready[key] ? '\u2713' : '!';
      item.querySelector('[data-setup-detail]').textContent = !data ? (message || 'Select a client to check') : pending ? 'Enter a transaction date' : descriptions[key][Number(ready[key])];
      const link = item.querySelector('[data-setup-link]'), url = data?.[key === 'accounts' ? 'accounts_url' : 'periods_url'];
      link.hidden = !url;
      if (url) link.href = url; else link.removeAttribute('href');
    });
    const count = Object.values(ready).filter(Boolean).length;
    panel.querySelector('[data-setup-summary]').textContent = !data ? (message || 'Select a client') : `${count} of 3 complete`;
    panel.querySelector('[data-setup-guidance]').textContent = !data
      ? (message || 'Choose a client to see what is ready and what needs setup.')
      : count === 3
        ? 'Setup is complete for this date. Select the matching period below; accounts, amounts, and permissions are still checked when saving.'
        : data.accounts_url
          ? 'Complete the missing setup before entering your voucher. Use an existing year or period if it already covers your dates.'
          : 'Ask an Owner or Office Manager to complete the missing setup for this client.';
  }
  document.addEventListener('input', event => {
    const form = event.target.closest('[data-journal-form]');
    if (form && event.target.name === 'transaction_date') { updateClientSetup(form); const section = form.querySelector('[data-lines]'); if (section) updateTotals(section); }
  });
  const journalRequests = new WeakMap();
  document.addEventListener('change', async event => {
    const form = event.target.closest('[data-journal-form]');
    if (!form) return;
    if (event.target.name === 'accounting_period_id' || event.target.name?.endsWith('[account_id]')) {
      const section = form.querySelector('[data-lines]');
      if (section) updateTotals(section);
      return;
    }
    if (event.target.name === 'transaction_date') { updateClientSetup(form); const section = form.querySelector('[data-lines]'); if (section) updateTotals(section); return; }
    if (event.target.name !== 'client_id') return;
    journalRequests.get(form)?.abort();
    const controller = new AbortController(); journalRequests.set(form, controller);
    const client = event.target.value, status = form.querySelector('[data-journal-options-status]');
    const accountSelects = () => form.querySelectorAll('[name$="[account_id]"], [name="cash_account_id"]');
    const reset = (select, prompt) => { select.replaceChildren(new Option(prompt, '')); };
    accountSelects().forEach(select => reset(select, 'Choose an active account'));
    const period = form.querySelector('[name=accounting_period_id]'); reset(period, 'Select an open period');
    const documents = form.querySelector('[data-journal-documents]'); documents.replaceChildren();
    const periodHelp = form.querySelector('[data-period-empty-help]');
    if (periodHelp) periodHelp.hidden = true;
    const accountHelp = form.querySelector('[data-account-empty-help]');
    if (accountHelp) accountHelp.hidden = true;
    const cashHelp = form.querySelector('[data-voucher-cash-empty-help]');
    if (cashHelp) cashHelp.hidden = true;
    status.textContent = client ? 'Loading client accounts, periods and documents…' : 'Choose a client.';
    updateClientSetup(form, null, client ? 'Checking client setup...' : '');
    const currentLines = form.querySelector('[data-lines]');
    if (currentLines) updateTotals(currentLines);
    if (!client) return;
    try {
      const response = await fetch(form.dataset.optionsUrl + '?client_id=' + encodeURIComponent(client), { signal: controller.signal, headers: { Accept: 'application/json' }, credentials: 'same-origin' });
      if (!response.ok) throw new Error('Client options could not load. Select the client again to retry.');
      const data = await response.json();
      if (controller.signal.aborted || event.target.value !== client) return;
      const isVoucher = form.hasAttribute('data-voucher-form');
      const accountsUrl = data.setup?.accounts_url;
      const periodsUrl = data.setup?.periods_url;
      const periodPrompt = data.periods.length ? (isVoucher ? 'Select an open period (required to save)' : 'Accounting period required before submission') : 'No open periods for this client';
      reset(period, periodPrompt);
      data.periods.forEach(item => period.add(new Option(`${item.label} · ${item.starts_on} – ${item.ends_on}`, item.id)));
      if (periodHelp) {
        periodHelp.hidden = data.periods.length > 0;
        const link = periodHelp.querySelector('[data-setup-period-link]');
        const restricted = periodHelp.querySelector('[data-setup-period-restricted]');
        if (link) { link.hidden = !periodsUrl; if (periodsUrl) link.href = periodsUrl; else link.removeAttribute('href'); }
        if (restricted) restricted.hidden = !!periodsUrl;
      }
      const lineSelects = form.querySelectorAll('[name$="[account_id]"]');
      const accountPrompt = data.accounts.length ? 'Choose an active account' : 'No active accounts for this client';
      lineSelects.forEach(select => {
        reset(select, accountPrompt);
        data.accounts.forEach(account => select.add(new Option(account.code + ' · ' + account.name, account.id)));
      });
      if (accountHelp) {
        accountHelp.hidden = data.accounts.length > 0;
        const link = accountHelp.querySelector('[data-setup-account-link]');
        const restricted = accountHelp.querySelector('[data-setup-account-restricted]');
        if (link) { link.hidden = !accountsUrl; if (accountsUrl) link.href = accountsUrl; else link.removeAttribute('href'); }
        if (restricted) restricted.hidden = !!accountsUrl;
      }
      const cashSelect = form.querySelector('[name="cash_account_id"]');
      if (cashSelect) {
        const assetAccounts = data.accounts.filter(account => account.classification === 'Asset');
        const cashPrompt = assetAccounts.length ? 'Choose a same-client asset account' : (data.accounts.length ? 'No active asset accounts for this client' : 'No active accounts for this client');
        reset(cashSelect, cashPrompt);
        assetAccounts.forEach(account => cashSelect.add(new Option(account.code + ' · ' + account.name, account.id)));
        if (cashHelp) {
          cashHelp.hidden = assetAccounts.length > 0;
          const link = cashHelp.querySelector('[data-setup-account-link]');
          const restricted = cashHelp.querySelector('[data-setup-account-restricted]');
          if (link) { link.hidden = !accountsUrl; if (accountsUrl) link.href = accountsUrl; else link.removeAttribute('href'); }
          if (restricted) restricted.hidden = !!accountsUrl;
        }
      }
      updateClientSetup(form, data.setup ? { ...data.setup, account_count: data.accounts.length, periods: data.periods } : null, data.setup ? '' : 'Setup could not be verified. Reload this page.');
      data.documents.forEach(item => {
        const wrap = node('div', '', 'form-check'), input = document.createElement('input'), label = node('label', item.label, 'form-check-label');
        input.type = 'checkbox'; input.name = 'document_ids[]'; input.value = item.id; input.id = 'journal_doc_' + item.id; input.className = 'form-check-input'; label.htmlFor = input.id; wrap.append(input, label); documents.append(wrap);
      });
      if (!data.documents.length) documents.append(node('p', 'No accessible documents for this client.', 'subtext'));
      status.textContent = data.accounts.length ? (data.periods.length ? 'Client options loaded. Previous client selections were cleared.' : (form.hasAttribute('data-voucher-form') ? 'No open periods configured. Complete period setup before saving a voucher.' : 'No open periods configured. You may save a Draft, but cannot submit it yet.')) : 'No active accounts. An authorized employee must configure this client’s Chart of Accounts.';
    } catch (error) { if (error.name !== 'AbortError' && !controller.signal.aborted && event.target.value === client) { status.textContent = error.message; updateClientSetup(form, null, 'Setup could not be checked. Select the client again to retry.'); } }
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
      const items = [...$('sidebar').querySelectorAll('a,button,summary')].filter(el => el.getClientRects().length), first = items[0], last = items.at(-1);
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
      const text = node('span', ''); text.append(node('strong', item.title), node('small', item.client || 'Workspace'));
      if (item.warnings && Object.keys(item.warnings).length) {
        const warnings = node('span', '', 'compliance-warnings');
        Object.entries(item.warnings).forEach(([action, warning]) => {
          const group = node('span', '', 'compliance-warning');
          group.append(node('span', action === 'submission' ? 'Submission' : 'Filing', 'compliance-warning-label'));
          if (['success', 'warning', 'danger'].includes(warning.tone)) group.append(node('span', warning.label, 'badge-status deadline-tone-' + warning.tone));
          warnings.append(group);
        });
        text.append(warnings);
      }
      button.append(text);
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
