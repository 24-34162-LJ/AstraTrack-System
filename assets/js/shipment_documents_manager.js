(() => {
  const dialog = document.getElementById('documentsDialog');
  if (!dialog) return;
  const form = document.getElementById('documentsUploadForm');
  const input = document.getElementById('documentFiles');
  const queue = document.getElementById('documentQueue');
  const submit = document.getElementById('documentUploadSubmit');
  const message = document.getElementById('documentUploadMessage');
  const progress = document.getElementById('documentUploadProgress');
  const dropzone = document.getElementById('documentDropzone');
  const csrf = document.querySelector('meta[name="csrf"]')?.content || '';
  const catalog = [
    ['B/L or AWB', 'B/L or AWB'], ['Commercial Invoice', 'Commercial Invoice'],
    ['Packing List', 'Packing List'], ['Import Entry', 'Import Entry'],
    ['Assessment Notice', 'Assessment Notice'], ['Duties Receipt', 'Duties Receipt'],
    ['Permit (if needed)', 'Permit (if needed)']
  ];
  let files = [];
  let busy = false;

  const bytes = (size) => size >= 1048576 ? `${(size / 1048576).toFixed(1).replace(/\.0$/, '')} MB` : `${Math.max(1, Math.round(size / 1024))} KB`;
  const esc = (value) => String(value).replace(/[&<>"']/g, (char) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[char]));

  function render() {
    queue.replaceChildren();
    if (!files.length) {
      const empty = document.createElement('p');
      empty.className = 'document-queue-empty';
      empty.textContent = 'No files selected.';
      queue.append(empty);
    }
    files.forEach((item, index) => {
      const row = document.createElement('div');
      row.className = `document-queue-row${item.error ? ' has-error' : ''}`;
      const options = catalog.map(([label, value]) => `<option value="${esc(value)}"${item.type === value ? ' selected' : ''}>${esc(label)}</option>`).join('');
      row.innerHTML = `<div class="document-queue-file"><strong>${esc(item.file.name)}</strong><small>${bytes(item.file.size)}</small></div><label>Document type<select data-type="${index}">${options}</select></label><label>Remarks <span>(optional)</span><input type="text" maxlength="255" data-remarks="${index}" value="${esc(item.remarks)}" placeholder="Add a note"></label><button type="button" data-remove="${index}" aria-label="Remove ${esc(item.file.name)}" ${busy ? 'disabled' : ''}>Remove</button>${item.error ? `<p class="document-row-error">${esc(item.error)}</p>` : ''}`;
      queue.append(row);
    });
    submit.disabled = busy || files.length === 0 || files.some((item) => item.error);
  }

  function addFiles(list) {
    for (const file of Array.from(list || [])) {
      const ext = file.name.split('.').pop().toLowerCase();
      let error = '';
      if (!['pdf', 'jpg', 'jpeg', 'png', 'xls', 'xlsx'].includes(ext)) error = 'Unsupported file type.';
      else if (!file.size || file.size > 10 * 1024 * 1024) error = 'File must be between 1 byte and 10 MB.';
      else if (files.some((item) => item.file.name === file.name && item.file.size === file.size)) error = 'This file is already in the upload list.';
      files.push({ file, type: 'B/L or AWB', remarks: '', error });
    }
    input.value = '';
    message.hidden = true;
    render();
  }

  document.querySelectorAll('[data-documents-open]').forEach((button) => button.addEventListener('click', () => {
    files = [];
    message.hidden = true;
    progress.textContent = 'Uploads are recorded in the shipment audit trail.';
    render();
    dialog.showModal();
  }));
  dialog.querySelectorAll('[data-documents-close]').forEach((button) => button.addEventListener('click', () => { if (!busy) dialog.close(); }));
  input.addEventListener('change', () => addFiles(input.files));
  dropzone.addEventListener('dragover', (event) => { event.preventDefault(); dropzone.classList.add('is-dragging'); });
  dropzone.addEventListener('dragleave', () => dropzone.classList.remove('is-dragging'));
  dropzone.addEventListener('drop', (event) => { event.preventDefault(); dropzone.classList.remove('is-dragging'); addFiles(event.dataTransfer.files); });
  queue.addEventListener('input', (event) => {
    const typeIndex = event.target.dataset.type;
    const remarksIndex = event.target.dataset.remarks;
    if (typeIndex !== undefined && files[typeIndex]) files[typeIndex].type = event.target.value;
    if (remarksIndex !== undefined && files[remarksIndex]) files[remarksIndex].remarks = event.target.value;
  });
  queue.addEventListener('click', (event) => {
    const index = event.target.dataset.remove;
    if (index === undefined || busy) return;
    files.splice(Number(index), 1);
    render();
  });
  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    if (busy || !files.length) return;
    busy = true;
    render();
    message.hidden = true;
    let succeeded = 0;
    for (let index = 0; index < files.length; index += 1) {
      const item = files[index];
      progress.textContent = `Uploading ${index + 1} of ${files.length}: ${item.file.name}`;
      const data = new FormData();
      data.append('csrf', csrf);
      data.append('shipment_id', dialog.dataset.shipmentId);
      data.append('doc_type', item.type);
      data.append('remarks', item.remarks);
      data.append('file', item.file);
      try {
        const response = await fetch(dialog.dataset.uploadEndpoint, { method: 'POST', body: data, credentials: 'same-origin' });
        const result = await response.json();
        if (!response.ok || !result.ok) throw new Error(result.error || 'Upload failed.');
        item.done = true;
        succeeded += 1;
      } catch (error) {
        item.error = error.message || 'Upload failed.';
      }
    }
    files = files.filter((item) => !item.done);
    busy = false;
    render();
    if (succeeded) {
      message.className = 'document-upload-message is-success';
      message.textContent = `${succeeded} document${succeeded === 1 ? '' : 's'} uploaded successfully.`;
      message.hidden = false;
      progress.textContent = files.length ? 'Fix the remaining files and retry.' : 'Refreshing shipment details…';
      if (!files.length) window.setTimeout(() => window.location.reload(), 900);
    } else {
      message.className = 'document-upload-message is-error';
      message.textContent = 'No files were uploaded. Review the messages below and try again.';
      message.hidden = false;
      progress.textContent = 'Upload did not complete.';
    }
  });

  document.querySelectorAll('[data-document-delete]').forEach((button) => button.addEventListener('click', async () => {
    if (!window.confirm(`Delete ${button.dataset.fileName || 'this document'}? This cannot be undone.`)) return;
    button.disabled = true;
    const data = new FormData();
    data.append('csrf', csrf);
    data.append('document_id', button.dataset.documentDelete);
    try {
      const response = await fetch('shipment_documents_delete.php', { method: 'POST', body: data, credentials: 'same-origin' });
      const result = await response.json();
      if (!response.ok || !result.ok) throw new Error(result.error || 'Delete failed.');
      window.location.reload();
    } catch (error) {
      window.alert(error.message || 'The document could not be deleted.');
      button.disabled = false;
    }
  }));
})();
