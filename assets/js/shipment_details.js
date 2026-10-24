(() => {
    document.querySelectorAll('[data-collapse]').forEach(button => {
        const initialBody = document.getElementById(button.getAttribute('aria-controls'));
        button.closest('.collapse-card')?.classList.toggle('is-expanded', !!initialBody && !initialBody.hidden);
        button.addEventListener('click', () => {
            const body = document.getElementById(button.getAttribute('aria-controls'));
            if (!body) return;
            const expanded = button.getAttribute('aria-expanded') !== 'true';
            button.setAttribute('aria-expanded', String(expanded));
            body.hidden = !expanded;
            button.textContent = expanded ? 'Show less' : 'Show more';
            button.closest('.collapse-card')?.classList.toggle('is-expanded', expanded);
        });
    });

    if (window.location.hash) {
        const anchoredCard = document.getElementById(window.location.hash.slice(1));
        const expandButton = anchoredCard?.querySelector('[data-collapse]');
        const body = expandButton ? document.getElementById(expandButton.getAttribute('aria-controls')) : null;
        if (expandButton && body?.hidden) expandButton.click();
    }

    const dialog = document.getElementById('deliveryDialog');
    const form = document.getElementById('deliveryForm');
    if (!dialog || !form) return;
    const field = name => form.elements.namedItem(name);
    const title = document.getElementById('deliveryDialogTitle');
    const deliveredField = document.getElementById('deliveredDateField');
    const save = document.getElementById('deliverySave');
    const note = document.getElementById('deliverySavingNote');
    const statusInputs = Array.from(form.querySelectorAll('input[name="status"]'));

    const selectedStatus = () => statusInputs.find(input => input.checked)?.value || 'scheduled';
    const syncStatusFields = () => {
        const status = selectedStatus();
        const scheduleDate = field('scheduled_date');
        const deliveredDate = field('delivered_date');
        scheduleDate.required = status === 'scheduled';
        deliveredDate.required = status === 'delivered';
        deliveredField.hidden = status !== 'delivered';
        const colours = { scheduled: '#e0a030', in_transit: '#2878b5', delivered: '#2e9e5b', cancelled: '#c0353a' };
        statusInputs.forEach(input => {
            input.closest('label').style.setProperty('--delivery-status-color', colours[input.value] || '#2878b5');
        });
    };
    statusInputs.forEach(input => input.addEventListener('change', syncStatusFields));

    const openAdd = () => {
        form.reset();
        field('delivery_id').value = '';
        ['trucker', 'driver_name', 'plate_no', 'scheduled_date', 'scheduled_time', 'delivery_address', 'contact_name', 'contact_phone', 'delivered_date'].forEach(name => { field(name).value = ''; });
        statusInputs.forEach(input => { input.checked = input.value === 'scheduled'; });
        title.textContent = 'Schedule Delivery';
        syncStatusFields();
        dialog.showModal();
    };
    document.querySelectorAll('[data-delivery-add]').forEach(button => button.addEventListener('click', openAdd));

    document.querySelectorAll('[data-delivery-edit]').forEach(button => {
        button.addEventListener('click', () => {
            let delivery;
            try { delivery = JSON.parse(button.dataset.delivery); } catch { return; }
            form.reset();
            field('delivery_id').value = delivery.delivery_id || '';
            field('trucker').value = delivery.trucker || '';
            field('driver_name').value = delivery.driver_name || '';
            field('plate_no').value = delivery.plate_no || '';
            field('scheduled_date').value = delivery.scheduled_date || '';
            field('scheduled_time').value = (delivery.scheduled_time || '').slice(0, 5);
            field('delivery_address').value = delivery.delivery_address || '';
            const contactParts = (delivery.receiving_contact || '').split(' · ', 2);
            field('contact_name').value = contactParts[0] || '';
            field('contact_phone').value = contactParts[1] || '';
            field('delivered_date').value = delivery.delivered_date || '';
            statusInputs.forEach(input => { input.checked = input.value === (delivery.status || 'scheduled'); });
            title.textContent = 'Edit Delivery Details';
            syncStatusFields();
            dialog.showModal();
        });
    });

    dialog.querySelectorAll('[data-delivery-close]').forEach(button => button.addEventListener('click', () => dialog.close()));
    form.addEventListener('submit', event => {
        if (!form.reportValidity()) { event.preventDefault(); return; }
        save.disabled = true;
        save.textContent = 'Saving…';
        note.textContent = 'Saving delivery details. Please wait.';
    });

    if (dialog.dataset.autoOpen === '1') {
        title.textContent = field('delivery_id').value ? 'Edit Delivery Details' : 'Schedule Delivery';
        syncStatusFields();
        dialog.showModal();
    }
})();
