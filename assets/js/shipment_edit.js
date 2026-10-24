(() => {
    const form = document.getElementById('shipmentEditForm');
    if (!form) return;
    const page = document.querySelector('.edit-page');
    const closed = page?.dataset.closed === '1';
    const state = document.getElementById('editState');
    const footer = document.querySelector('.edit-footer');
    const save = document.getElementById('saveShipment');
    const mode = document.getElementById('edit_mode');
    const modeLabel = document.querySelector('[data-mode-label]');
    const rows = document.getElementById('containerRows');
    const dialog = document.getElementById('leaveDialog');
    const cancel = document.querySelector('[data-cancel]');
    const leave = document.querySelector('[data-leave-confirm]');
    const stay = document.querySelector('[data-leave-stay]');
    let leaveTo = '';
    let submitting = false;
    let nextRow = rows ? rows.children.length : 0;

    const snapshot = () => Array.from(new FormData(form).entries()).map(([k,v]) => `${k}=${String(v)}`).join('&');
    const original = snapshot();
    const update = () => {
        const dirty = snapshot() !== original;
        if (footer) footer.classList.toggle('is-dirty', dirty);
        if (state && !closed) state.textContent = dirty ? 'Unsaved changes' : (form.querySelector('.has-error') ? 'Review validation errors' : 'No changes to save');
        if (save && !closed) save.disabled = !dirty;
    };
    const syncMode = () => {
        const selected = mode?.value || 'sea';
        if (modeLabel) modeLabel.textContent = `${selected.charAt(0).toUpperCase()}${selected.slice(1)} freight`;
        const labels = {
            bl_awb_no: selected === 'air' ? 'AWB No.' : selected === 'land' ? 'Waybill No. · Optional' : 'B/L No.',
            vessel_or_flight: selected === 'land' ? 'Truck Plate' : selected === 'air' ? 'Airline / Flight' : 'Vessel / Voyage',
            port_of_loading: selected === 'air' ? 'Airport of Departure' : selected === 'land' ? 'Origin' : 'Port of Loading',
            port_of_discharge: selected === 'air' ? 'Airport of Arrival' : selected === 'land' ? 'Destination' : 'Port of Discharge'
        };
        Object.entries(labels).forEach(([key, label]) => {
            const field = form.querySelector(`[data-field="${key}"] > span`);
            if (field) field.textContent = label;
        });
        const bl = form.querySelector('[data-bl]');
        if (bl) { bl.required = ['sea','air'].includes(selected); bl.setAttribute('aria-required', String(bl.required)); }
    };
    const renumber = () => {
        if (!rows) return;
        Array.from(rows.children).forEach((row, i) => row.querySelectorAll('input').forEach(input => {
            input.name = input.name.replace(/containers\[\d+\]/, `containers[${i}]`);
        }));
    };
    form.addEventListener('input', update);
    form.addEventListener('change', event => { if (event.target === mode) syncMode(); update(); });
    syncMode();
    document.querySelector('[data-add-container]')?.addEventListener('click', () => {
        if (!rows) return;
        const row = document.createElement('div'); row.className = 'container-row';
        row.innerHTML = `<label><span class="sr-only">Container No.</span><input name="containers[${nextRow}][container_no]" maxlength="20" placeholder="Container No."></label><label><span class="sr-only">Seal No.</span><input name="containers[${nextRow}][seal_no]" maxlength="30" placeholder="Seal No."></label><label><span class="sr-only">Size/Type</span><input name="containers[${nextRow}][size_type]" maxlength="20" placeholder="Size/Type"></label><button class="remove-container" type="button" data-remove-container>Remove</button>`;
        rows.appendChild(row); nextRow++; renumber(); row.querySelector('input')?.focus(); update();
    });
    rows?.addEventListener('click', event => {
        const button = event.target.closest('[data-remove-container]');
        if (!button) return;
        button.closest('.container-row')?.remove(); renumber(); update();
    });
    cancel?.addEventListener('click', event => {
        if (snapshot() === original) return;
        event.preventDefault(); leaveTo = cancel.href; dialog?.showModal();
    });
    leave?.addEventListener('click', () => { submitting = true; window.location.assign(leaveTo || cancel?.href || ''); });
    stay?.addEventListener('click', () => dialog?.close());
    dialog?.addEventListener('click', event => { if (event.target === dialog) dialog.close(); });
    form.addEventListener('submit', event => {
        syncMode();
        if (closed || !form.reportValidity()) { event.preventDefault(); return; }
        submitting = true;
        if (save) { save.disabled = true; save.textContent = 'Saving…'; }
        if (state) state.textContent = 'Saving shipment changes…';
    });
    window.addEventListener('beforeunload', event => {
        if (!closed && !submitting && snapshot() !== original) { event.preventDefault(); event.returnValue = ''; }
    });
    update();
})();
