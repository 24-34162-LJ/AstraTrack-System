(() => {
    const dialog = document.getElementById('customsDialog');
    const form = document.getElementById('customsForm');
    if (!dialog || !form) return;

    const get = (name) => form.elements.namedItem(name);
    const typeSelect = document.getElementById('customsDeclarationType');
    const customType = document.getElementById('customsDeclarationTypeCustom');
    const title = document.getElementById('customsDialogTitle');
    const assessmentStatus = get('assessment_status');
    const dutiesAmount = get('duties_taxes_amount');
    const dutiesPaidDate = get('duties_paid_date');
    const knownTypes = new Set(Array.from(typeSelect.options, option => option.value).filter(value => value && value !== '__other__'));

    const toggleCustomType = () => {
        const isCustom = typeSelect.value === '__other__';
        customType.hidden = !isCustom;
        customType.required = isCustom;
        if (!isCustom) customType.value = '';
    };
    typeSelect.addEventListener('change', toggleCustomType);
    const syncPaidRequirements = () => {
        const isPaid = assessmentStatus.value === 'paid';
        dutiesAmount.required = isPaid;
        dutiesPaidDate.required = isPaid;
    };
    assessmentStatus.addEventListener('change', syncPaidRequirements);

    document.querySelectorAll('[data-customs-add]').forEach(button => {
        button.addEventListener('click', () => {
            form.reset();
            get('customs_id').value = '';
            get('entry_no').value = '';
            typeSelect.value = '';
            customType.value = '';
            get('lodgement_date').value = '';
            get('assessment_status').value = 'pending';
            get('customs_value').value = '';
            get('duties_taxes_amount').value = '';
            get('duties_paid_date').value = '';
            get('clearance_date').value = '';
            get('customs_remarks').value = '';
            form.querySelectorAll('input[name="examination_lane"]').forEach(input => { input.checked = input.value === ''; });
            title.textContent = 'Add Customs Details';
            toggleCustomType();
            syncPaidRequirements();
            dialog.showModal();
        });
    });

    document.querySelectorAll('[data-customs-edit]').forEach(button => {
        button.addEventListener('click', () => {
            let record;
            try { record = JSON.parse(button.dataset.customs); } catch { return; }
            form.reset();
            get('customs_id').value = record.customs_id || '';
            get('entry_no').value = record.entry_no || '';
            if (knownTypes.has(record.declaration_type || '')) {
                typeSelect.value = record.declaration_type;
                customType.value = '';
            } else if (record.declaration_type) {
                typeSelect.value = '__other__';
                customType.value = record.declaration_type;
            } else {
                typeSelect.value = '';
                customType.value = '';
            }
            get('lodgement_date').value = record.lodgement_date || '';
            get('assessment_status').value = record.assessment_status || 'pending';
            get('customs_value').value = record.customs_value || '';
            get('duties_taxes_amount').value = record.duties_taxes_amount || '';
            get('duties_paid_date').value = record.duties_paid_date || '';
            get('clearance_date').value = record.clearance_date || '';
            get('customs_remarks').value = record.customs_remarks || '';
            form.querySelectorAll('input[name="examination_lane"]').forEach(input => { input.checked = input.value === (record.examination_lane || ''); });
            title.textContent = 'Edit Customs Processing';
            toggleCustomType();
            syncPaidRequirements();
            dialog.showModal();
        });
    });

    dialog.querySelectorAll('[data-customs-close]').forEach(button => button.addEventListener('click', () => dialog.close()));
    if (dialog.dataset.autoOpen === '1') {
        title.textContent = get('customs_id').value ? 'Edit Customs Processing' : 'Add Customs Details';
        toggleCustomType();
        syncPaidRequirements();
        dialog.showModal();
    }
})();
