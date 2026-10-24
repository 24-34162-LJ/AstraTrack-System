(function () {
  const dialog = document.getElementById('auditDialog');
  if (!dialog) return;
  const list = document.getElementById('auditList');
  const empty = document.getElementById('auditEmpty');
  const error = document.getElementById('auditError');
  const more = document.getElementById('auditLoadMore');
  const filters = [...dialog.querySelectorAll('[data-audit-filter]')];
  const endpoint = dialog.dataset.endpoint;
  const shipmentId = dialog.dataset.shipmentId;
  let activeFilter = 'all';
  let offset = 0;
  let loading = false;

  const make = (tag, className, value) => {
    const el = document.createElement(tag);
    if (className) el.className = className;
    if (value !== undefined) el.textContent = value;
    return el;
  };

  function formatDate(value) {
    if (!value) return 'Time unavailable';
    const date = new Date(String(value).replace(' ', 'T'));
    if (Number.isNaN(date.getTime())) return String(value);
    return new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' }).format(date);
  }

  function renderDetails(details) {
    if (!details || typeof details !== 'object' || Array.isArray(details)) return null;
    const entries = Object.entries(details).filter(([, values]) => values && typeof values === 'object' && ('old' in values || 'new' in values));
    if (!entries.length) return null;
    const wrapper = make('details', 'audit-diff');
    const summary = make('summary', '', `Show ${entries.length === 1 ? 'change' : `${entries.length} changes`}`);
    const rows = make('div', 'audit-diff-rows');
    entries.forEach(([label, values]) => {
      const row = make('div', 'audit-diff-row');
      row.append(make('strong', 'audit-diff-label', label));
      const before = make('div', 'audit-diff-value audit-diff-before');
      before.append(make('small', '', 'Previous'), make('span', '', String(values.old ?? '—')));
      const after = make('div', 'audit-diff-value audit-diff-after');
      after.append(make('small', '', 'Updated'), make('span', '', String(values.new ?? '—')));
      row.append(before, after);
      rows.append(row);
    });
    wrapper.append(summary, rows);
    return wrapper;
  }

  function renderEvent(item) {
    const article = make('article', `audit-event audit-kind-${String(item.kind || 'edit').toLowerCase().replace(/[^a-z0-9]+/g, '-')}`);
    const marker = make('span', 'audit-event-marker', '');
    marker.setAttribute('aria-hidden', 'true');
    const body = make('div', 'audit-event-body');
    const top = make('div', 'audit-event-meta');
    top.append(make('span', `audit-kind-badge audit-category-${item.category || 'edits'}`, item.kind || 'Activity'));
    top.append(make('time', '', formatDate(item.at)));
    const description = make('p', 'audit-event-description', item.description || 'Activity recorded.');
    const actor = make('p', 'audit-event-actor', `${item.actor || 'System'} · ${item.role || 'system'}`);
    body.append(top, description, actor);
    if (item.note) body.append(make('p', 'audit-event-note', item.note));
    const detailNode = renderDetails(item.details);
    if (detailNode) body.append(detailNode);
    article.append(marker, body);
    return article;
  }

  async function load(reset) {
    if (loading) return;
    loading = true;
    error.hidden = true;
    more.disabled = true;
    if (reset) {
      offset = 0;
      list.replaceChildren(make('p', 'audit-loading', 'Loading shipment activity…'));
      empty.hidden = true;
      more.hidden = true;
    }
    const separator = endpoint.includes('?') ? '&' : '?';
    const url = `${endpoint}${separator}shipment_id=${encodeURIComponent(shipmentId)}&filter=${encodeURIComponent(activeFilter)}&offset=${offset}`;
    try {
      const response = await fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
      const data = await response.json();
      if (!response.ok) throw new Error(data.error || 'Could not load shipment activity.');
      if (reset) list.replaceChildren();
      (Array.isArray(data.items) ? data.items : []).forEach(item => list.append(renderEvent(item)));
      offset = Number(data.nextOffset) || offset;
      const isEmpty = list.children.length === 0;
      empty.hidden = !isEmpty;
      more.hidden = !data.hasMore;
      if (isEmpty) list.replaceChildren();
    } catch (err) {
      if (reset) list.replaceChildren();
      error.textContent = err.message || 'Could not load shipment activity.';
      error.hidden = false;
    } finally {
      loading = false;
      more.disabled = false;
    }
  }

  document.querySelectorAll('[data-audit-open]').forEach(button => button.addEventListener('click', () => {
    if (!dialog.open) dialog.showModal();
    load(true);
  }));
  dialog.querySelectorAll('[data-audit-close]').forEach(button => button.addEventListener('click', () => dialog.close()));
  dialog.addEventListener('click', event => { if (event.target === dialog) dialog.close(); });
  filters.forEach(button => button.addEventListener('click', () => {
    activeFilter = button.dataset.auditFilter || 'all';
    filters.forEach(filter => {
      const selected = filter === button;
      filter.classList.toggle('active', selected);
      filter.setAttribute('aria-selected', selected ? 'true' : 'false');
    });
    load(true);
  }));
  more.addEventListener('click', () => load(false));
})();
