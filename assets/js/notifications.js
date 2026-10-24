(() => {
  const menu = document.querySelector('[data-notification-menu]');
  const csrf = document.querySelector('meta[name="csrf"]')?.content || menu?.dataset.csrf || '';
  const toggle = menu?.querySelector('[data-notification-toggle]');
  const preview = menu?.querySelector('[data-notification-preview]');

  const markRead = async (id) => {
    if (!id) return;
    const body = new FormData();
    body.append('action', 'read');
    body.append('notification_id', id);
    await fetch('notifications_action.php', { method: 'POST', body, credentials: 'same-origin', headers: { 'X-CSRF-Token': csrf }, keepalive: true });
  };

  if (menu && toggle && preview) {
    toggle.addEventListener('click', () => {
      const open = toggle.getAttribute('aria-expanded') !== 'true';
      toggle.setAttribute('aria-expanded', String(open));
      preview.hidden = !open;
    });
    document.addEventListener('click', (event) => {
      if (!menu.contains(event.target)) {
        toggle.setAttribute('aria-expanded', 'false');
        preview.hidden = true;
      }
    });
    document.addEventListener('keydown', (event) => {
      if (event.key === 'Escape') { toggle.setAttribute('aria-expanded', 'false'); preview.hidden = true; }
    });
  }

  document.querySelectorAll('[data-notification-read]').forEach((link) => link.addEventListener('click', (event) => {
    const id = link.dataset.notificationRead;
    if (!id) return;
    event.preventDefault();
    const href = link.href;
    markRead(id).catch(() => {}).finally(() => { window.location.href = href; });
  }));
})();
