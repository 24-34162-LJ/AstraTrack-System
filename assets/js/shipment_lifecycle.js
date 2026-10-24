(() => {
  const dialog = document.getElementById('shipmentActionDialog');
  if (!dialog) return;
  const form = document.getElementById('shipmentActionForm');
  const actionInput = document.getElementById('shipmentActionValue');
  const title = document.getElementById('shipmentActionTitle');
  const prompt = document.getElementById('shipmentActionPrompt');
  const reasonField = document.getElementById('lifecycleReasonField');
  const reason = document.getElementById('lifecycleReason');
  const remarksField = document.getElementById('lifecycleRemarksField');
  const remarks = document.getElementById('lifecycleRemarks');
  const error = document.getElementById('lifecycleError');
  const submit = document.getElementById('lifecycleSubmit');
  const csrf = document.querySelector('meta[name="csrf"]')?.content || '';
  const config = {
    hold: { title: 'Put shipment on hold?', prompt: 'Choose a reason. The current shipment stage will be preserved.', button: 'Put on hold', reason: true, remarks: true },
    cancel: { title: 'Cancel shipment?', prompt: 'Choose a reason. The shipment record and history will be retained.', button: 'Cancel shipment', reason: true, remarks: true },
    archive: { title: 'Archive shipment?', prompt: 'This removes the shipment from active lists. Shipment and billing history will be retained.', button: 'Archive shipment', reason: false, remarks: true },
    reopen: { title: 'Reopen shipment?', prompt: 'The shipment will return to its last recorded active stage. You can continue updating it.', button: 'Reopen shipment', reason: false, remarks: true }
  };
  let busy = false;

  document.querySelectorAll('[data-lifecycle-action]').forEach((button) => button.addEventListener('click', () => {
    const action = button.dataset.lifecycleAction;
    const item = config[action];
    if (!item || busy) return;
    actionInput.value = action;
    title.textContent = item.title;
    prompt.textContent = item.prompt;
    submit.textContent = item.button;
    reasonField.hidden = !item.reason;
    reason.required = item.reason;
    remarksField.hidden = !item.remarks;
    reason.value = '';
    remarks.value = '';
    error.hidden = true;
    dialog.showModal();
  }));
  dialog.querySelectorAll('[data-lifecycle-close]').forEach((button) => button.addEventListener('click', () => { if (!busy) dialog.close(); }));

  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    if (busy) return;
    busy = true;
    submit.disabled = true;
    submit.textContent = 'Saving…';
    error.hidden = true;
    try {
      const response = await fetch(dialog.dataset.endpoint, { method: 'POST', body: new FormData(form), credentials: 'same-origin', headers: { 'X-CSRF-Token': csrf } });
      const payload = await response.json();
      if (!response.ok || !payload.ok) throw new Error(payload.error || 'The shipment action could not be saved.');
      window.location.href = `shipment_view.php?id=${encodeURIComponent(dialog.dataset.shipmentId)}&lifecycle_saved=1`;
    } catch (err) {
      error.textContent = err.message || 'The shipment action could not be saved.';
      error.hidden = false;
      submit.disabled = false;
      submit.textContent = config[actionInput.value]?.button || 'Continue';
      busy = false;
    }
  });
})();
