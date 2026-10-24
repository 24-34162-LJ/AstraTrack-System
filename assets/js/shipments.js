/* shipments.js — AstraTrack shipment list and details pages */

// Chrome can restore nested horizontal scroll after the first paint. Reset on
// initial load and BFCache/history restores so the reference and client columns
// remain visible when the shipment list opens.
const resetShipmentTablePosition = () => {
    const shipmentTableWrap = document.querySelector(".ship-table")?.closest(".table-wrap");
    if (!shipmentTableWrap || window.innerWidth < 1400) return;
    shipmentTableWrap.scrollLeft = 0;
    requestAnimationFrame(() => { shipmentTableWrap.scrollLeft = 0; });
    window.setTimeout(() => { shipmentTableWrap.scrollLeft = 0; }, 120);
};
resetShipmentTablePosition();
window.addEventListener("pageshow", resetShipmentTablePosition);

document.addEventListener("DOMContentLoaded", () => {

    /* Mobile sidebar toggle (same behaviour as the other pages) */
    const sidebar = document.getElementById("sidebar");
    const toggle = document.getElementById("menuToggle");
    if (toggle && sidebar) {
        const setMenu = (open) => {
            sidebar.classList.toggle("open", open);
            document.body.classList.toggle("nav-open", open);
        };
        toggle.addEventListener("click", () => setMenu(!sidebar.classList.contains("open")));
        document.addEventListener("click", (e) => {
            if (!sidebar.contains(e.target) && !toggle.contains(e.target)) setMenu(false);
        });
        document.addEventListener("keydown", (e) => { if (e.key === "Escape") setMenu(false); });
    }

    /* Status dropdown: apply the filter as soon as it changes */
    document.querySelectorAll("[data-autosubmit]").forEach((el) => {
        el.addEventListener("change", () => el.form.submit());
    });

    /* "..." row menu (admin) */
    const closeMenus = () => document.querySelectorAll(".more-menu").forEach((m) => {
        m.hidden = true;
        const b = m.previousElementSibling;
        if (b) b.setAttribute("aria-expanded", "false");
    });
    document.querySelectorAll("[data-more]").forEach((btn) => {
        btn.addEventListener("click", (e) => {
            e.stopPropagation();
            const menu = btn.nextElementSibling;
            const open = menu.hidden;
            closeMenus();
            menu.hidden = !open;
            if (open) {
                // fixed position so the table's horizontal scroll area can't clip it
                const r = btn.getBoundingClientRect();
                menu.style.top = (r.bottom + 6) + "px";
                menu.style.left = Math.max(8, r.right - 170) + "px";
            }
            btn.setAttribute("aria-expanded", String(open));
        });
    });
    document.addEventListener("click", closeMenus);
    window.addEventListener("scroll", closeMenus, true);
    window.addEventListener("resize", closeMenus);
    document.addEventListener("keydown", (e) => { if (e.key === "Escape") closeMenus(); });

    /* Copy shipment reference */
    document.querySelectorAll("[data-copy]").forEach((btn) => {
        btn.addEventListener("click", async () => {
            const text = btn.dataset.copy;
            try {
                await navigator.clipboard.writeText(text);
                btn.textContent = "Copied!";
            } catch (err) {
                window.prompt("Copy the reference:", text);
            }
            setTimeout(closeMenus, 700);
        });
    });

    /* Assign one or more unassigned shipments to active staff (admin list). */
    const assignmentDialog = document.getElementById("assignmentDialog");
    if (assignmentDialog) {
        const cards = [...assignmentDialog.querySelectorAll("[data-staff-card]")];
        const search = document.getElementById("assignmentSearch");
        const submit = document.getElementById("assignmentSubmit");
        const preview = document.getElementById("assignmentPreview");
        const toast = document.getElementById("assignmentToast");
        const selectedBoxes = () => [...document.querySelectorAll(".shipment-select:checked")];
        let shipmentIds = [];
        let singleReference = "";
        let toastTimer;

        const updateSelection = () => {
            const radio = assignmentDialog.querySelector('input[name="assignment_staff"]:checked');
            const count = shipmentIds.length;
            submit.disabled = !radio || !count || !cards.length;
            submit.textContent = count > 1 ? `Assign ${count} shipments` : "Assign";
            if (radio && count) {
                const name = radio.closest(".assignment-staff").querySelector("strong").textContent;
                preview.textContent = count > 1
                    ? `${name} will be assigned to all ${count} selected shipments.`
                    : `${name} will be assigned to ${singleReference || "the selected shipment"}.`;
                preview.hidden = false;
            } else preview.hidden = true;
        };
        const openPicker = (ids, reference) => {
            shipmentIds = ids;
            singleReference = reference || "";
            assignmentDialog.querySelector("#assignmentSubtitle").textContent = ids.length > 1 ? `${ids.length} shipments · Active staff only` : "Active staff only";
            const firstRadio = assignmentDialog.querySelector('input[name="assignment_staff"]');
            if (firstRadio && !assignmentDialog.querySelector('input[name="assignment_staff"]:checked')) firstRadio.checked = true;
            updateSelection();
            if (!assignmentDialog.open) assignmentDialog.showModal();
            search.value = "";
            cards.forEach(card => card.hidden = false);
            search.focus();
        };
        document.querySelectorAll("[data-assignment-close]").forEach(button => button.addEventListener("click", () => assignmentDialog.close()));
        assignmentDialog.addEventListener("click", event => { if (event.target === assignmentDialog) assignmentDialog.close(); });
        search.addEventListener("input", () => {
            const term = search.value.trim().toLocaleLowerCase();
            cards.forEach(card => card.hidden = !card.dataset.staffName.includes(term));
        });
        assignmentDialog.querySelectorAll('input[name="assignment_staff"]').forEach(radio => radio.addEventListener("change", updateSelection));

        const refreshBulkBar = () => {
            const boxes = selectedBoxes();
            const bar = document.getElementById("bulkAssignBar");
            const count = document.getElementById("bulkSelectedCount");
            if (bar) bar.hidden = boxes.length === 0;
            if (count) count.textContent = String(boxes.length);
            const selectAll = document.getElementById("selectVisible");
            const all = [...document.querySelectorAll(".shipment-select")];
            if (selectAll) {
                selectAll.checked = all.length > 0 && all.every(box => box.checked);
                selectAll.indeterminate = boxes.length > 0 && boxes.length < all.length;
            }
        };
        document.querySelectorAll(".shipment-select").forEach(box => box.addEventListener("change", refreshBulkBar));
        document.getElementById("selectVisible")?.addEventListener("change", event => {
            document.querySelectorAll(".shipment-select").forEach(box => box.checked = event.target.checked);
            refreshBulkBar();
        });
        document.getElementById("bulkClear")?.addEventListener("click", () => {
            document.querySelectorAll(".shipment-select").forEach(box => box.checked = false);
            refreshBulkBar();
        });
        document.getElementById("bulkAssignOpen")?.addEventListener("click", () => {
            const boxes = selectedBoxes();
            openPicker(boxes.map(box => box.value), "");
        });
        document.querySelectorAll("[data-assign-one]").forEach(button => button.addEventListener("click", () => {
            openPicker([button.dataset.assignOne], button.dataset.reference || "");
        }));

        submit.addEventListener("click", async () => {
            const radio = assignmentDialog.querySelector('input[name="assignment_staff"]:checked');
            if (!radio || !shipmentIds.length) return;
            const oldLabel = submit.textContent;
            submit.disabled = true;
            assignmentDialog.querySelectorAll("input, button").forEach(control => { if (control !== submit) control.disabled = true; });
            submit.textContent = "Assigning…";
            const body = new URLSearchParams({ csrf: assignmentDialog.dataset.csrf, staff_id: radio.value });
            shipmentIds.forEach(id => body.append("shipment_ids[]", id));
            try {
                const response = await fetch(assignmentDialog.dataset.endpoint, { method: "POST", headers: { "Content-Type": "application/x-www-form-urlencoded;charset=UTF-8", Accept: "application/json" }, credentials: "same-origin", body });
                const data = await response.json();
                if (!response.ok) throw new Error(data.error || "The assignment could not be saved.");
                (data.items || []).forEach(item => {
                    const row = document.querySelector(`[data-shipment-row="${CSS.escape(String(item.id))}"]`);
                    if (!row) return;
                    const selectCell = row.querySelector(".select-col");
                    if (selectCell) selectCell.replaceChildren();
                    const assignButton = row.querySelector("[data-assign-one]");
                    if (assignButton) {
                        const staffCell = assignButton.closest("td");
                        const chip = document.createElement("span");
                        chip.className = "staff-chip";
                        const avatar = document.createElement("span");
                        avatar.className = "chip-av";
                        avatar.textContent = data.staff.split(/\s+/).map(part => part[0] || "").join("").slice(0, 2).toUpperCase();
                        chip.append(avatar, document.createTextNode(data.staff));
                        staffCell.replaceChildren(chip);
                    }
                });
                assignmentDialog.close();
                document.querySelectorAll(".shipment-select").forEach(box => box.checked = false);
                refreshBulkBar();
                toast.textContent = `Assigned to ${data.staff}`;
                toast.hidden = false;
                clearTimeout(toastTimer);
                toastTimer = setTimeout(() => { toast.hidden = true; }, 4500);
            } catch (err) {
                preview.textContent = err.message || "The assignment could not be saved.";
                preview.hidden = false;
                submit.textContent = oldLabel;
            } finally {
                assignmentDialog.querySelectorAll("input, button").forEach(control => { control.disabled = false; });
                updateSelection();
            }
        });
    }
});

/* Update Status / Reassign dialogs on the details page */
document.addEventListener("DOMContentLoaded", () => {
    document.querySelectorAll("[data-open-dialog]").forEach((btn) => {
        btn.addEventListener("click", () => {
            const dlg = document.getElementById(btn.dataset.openDialog);
            if (dlg && typeof dlg.showModal === "function") dlg.showModal();
        });
    });
    document.querySelectorAll("[data-close-dialog]").forEach((btn) => {
        btn.addEventListener("click", () => btn.closest("dialog").close());
    });
    document.querySelectorAll("dialog.dlg").forEach((dlg) => {
        // a click on the dark backdrop closes it
        dlg.addEventListener("click", (e) => { if (e.target === dlg) dlg.close(); });
    });

    const form = document.getElementById("reassignForm");
    if (form) {
        const picker = document.getElementById("reassignPickerPanel");
        const confirm = document.getElementById("reassignConfirmPanel");
        const radios = [...form.querySelectorAll('input[name="staff_choice"]')];
        const continueButton = document.getElementById("reassignContinue");
        const saveButton = document.getElementById("reassignSave");
        const hiddenStaff = document.getElementById("reassignStaffId");
        const currentId = Number(form.dataset.currentStaff || 0);
        const selected = () => radios.find(radio => radio.checked && radio.value !== String(currentId));
        const update = () => { continueButton.disabled = !selected(); };
        radios.forEach(radio => radio.addEventListener("change", update));
        document.getElementById("reassignSearch")?.addEventListener("input", event => {
            const term = event.target.value.trim().toLocaleLowerCase();
            form.querySelectorAll("[data-reassign-staff]").forEach(card => card.hidden = !card.dataset.name.includes(term));
        });
        continueButton?.addEventListener("click", () => {
            const radio = selected();
            if (!radio) return;
            hiddenStaff.value = radio.value;
            document.getElementById("reassignNewName").textContent = radio.dataset.nameLabel;
            picker.hidden = true;
            confirm.hidden = false;
        });
        document.getElementById("reassignBack")?.addEventListener("click", () => {
            confirm.hidden = true;
            picker.hidden = false;
        });
        form.addEventListener("submit", event => {
            if (confirm.hidden || !hiddenStaff.value) {
                event.preventDefault();
                update();
                return;
            }
            saveButton.disabled = true;
            saveButton.textContent = "Reassigning…";
        });
    }
});
