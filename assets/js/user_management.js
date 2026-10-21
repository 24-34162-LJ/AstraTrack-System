/* user_management.js — filters, empty states, Add/Edit/View popups, validation, confirm popups */

document.addEventListener("DOMContentLoaded", () => {
    const $ = (id) => document.getElementById(id);
    const cap = (s) => (s ? s[0].toUpperCase() + s.slice(1) : "");
    const firstName = (n) => (n || "").trim().split(/\s+/)[0];
    const initialsOf = (n) => (n || "").split(/\s+/).filter(Boolean).slice(0, 2).map((w) => w[0].toUpperCase()).join("");

    /* ---------- Mobile sidebar ---------- */
    const sidebar = $("sidebar"), menuToggle = $("menuToggle");
    if (sidebar && menuToggle) menuToggle.addEventListener("click", () => sidebar.classList.toggle("open"));

    /* ---------- Close buttons + click on the dark backdrop ---------- */
    document.querySelectorAll("dialog").forEach((d) => {
        d.querySelectorAll("[data-close]").forEach((b) => b.addEventListener("click", () => d.close()));
        d.addEventListener("click", (e) => { if (e.target === d) d.close(); });
    });

    /* ---------- Search + filters + "no results" state ---------- */
    const search = $("searchInput"), roleSel = $("roleFilter"), statusSel = $("statusFilter");
    const rows = Array.from(document.querySelectorAll("#usersTable tbody tr"));
    const table = $("usersTable"), emptyFilter = $("emptyFilter"), countText = $("countText");

    function applyFilters() {
        if (!rows.length) return;   // no accounts at all: the server already shows the "No user accounts yet" state
        const q = search.value.trim().toLowerCase();
        let shown = 0;
        rows.forEach((r) => {
            const ok = r.textContent.toLowerCase().includes(q)
                && (!roleSel.value || r.dataset.role === roleSel.value)
                && (!statusSel.value || r.dataset.status === statusSel.value);
            r.hidden = !ok;
            if (ok) shown++;
        });
        emptyFilter.hidden = shown !== 0;
        table.querySelector("tbody").hidden = shown === 0;
        countText.textContent = shown
            ? `Showing 1\u2013${shown} of ${rows.length} users`
            : `0 results \u00b7 ${rows.length} total users`;
    }
    [search, roleSel, statusSel].forEach((el) => el.addEventListener("input", applyFilters));
    $("clearFilters").addEventListener("click", () => {
        search.value = ""; roleSel.value = ""; statusSel.value = "";
        applyFilters();
    });

    /* ---------- Add / Edit popup ---------- */
    const formDlg = $("formDialog"), form = $("userForm");
    const f = { full_name: $("fName"), email: $("fEmail"), password: $("fPassword"), role: $("fRole"), status: $("fStatus"), client_id: $("fClient") };
    const box = (name) => form.querySelector(`[data-field="${name}"]`);

    function clearErrors() {
        form.querySelectorAll(".field").forEach((el) => {
            el.classList.remove("invalid");
            const h = el.querySelector(".hint");
            h.textContent = h.dataset.hint;
        });
        $("formAlert").hidden = true;
    }
    function setError(name, msg) {
        const el = box(name);
        el.classList.add("invalid");
        el.querySelector(".hint").textContent = msg;
    }
    function showAlert(mode) {
        $("formAlertText").textContent = mode === "add"
            ? "User not saved. Complete the required fields below."
            : "Changes not saved. Complete the required fields below.";
        $("formAlert").hidden = false;
    }
    function syncClientRow() {
        $("clientRow").hidden = f.role.value !== "client";
    }
    f.role.addEventListener("change", syncClientRow);

    // Show / hide the typed password
    $("pwToggle").addEventListener("click", () => {
        const hidden = f.password.type === "password";
        f.password.type = hidden ? "text" : "password";
        $("pwToggle").textContent = hidden ? "Hide" : "Show";
    });

    function openForm(mode, u = {}, values = null, errors = null) {
        form.reset();
        // Password: required for a new user; optional when editing (blank keeps the current one)
        f.password.type = "password";
        $("pwToggle").textContent = "Show";
        $("pwLabel").textContent = mode === "add" ? "Password *" : "New Password";
        f.password.placeholder = mode === "add" ? "Enter password" : "Leave blank to keep current password";
        box("password").querySelector(".hint").dataset.hint = mode === "add"
            ? "Use at least 8 characters."
            : "Optional. Enter a new password (8+ characters) to reset it.";
        clearErrors();
        $("formTitle").textContent = mode === "add" ? "Add User" : "Edit User";
        $("formSub").textContent = mode === "add"
            ? "Create an account and choose its role and access status."
            : `Update ${u.name}\u2019s account details and role assignment.`;
        $("reqNote").textContent = mode === "add"
            ? "Fields marked with * are required."
            : `Fields marked with * are required. User ID: ${u.code} (read-only).`;
        $("fAction").value = mode;
        $("fId").value = u.id || "";

        const v = values || {
            full_name: u.name || "", email: u.email || "", role: u.role || "",
            status: u.status || "active", client_id: u.clientId && u.clientId !== "0" ? u.clientId : "",
        };
        f.full_name.value = v.full_name;
        f.email.value = v.email;
        f.role.value = v.role;
        f.status.value = v.status || "active";
        f.client_id.value = v.client_id && String(v.client_id) !== "0" ? v.client_id : "";
        syncClientRow();

        if (errors && Object.keys(errors).length) {
            Object.entries(errors).forEach(([k, m]) => box(k) && setError(k, m));
            showAlert(mode);
        }
        if (!formDlg.open) formDlg.showModal();
    }

    // Same rules as the PHP validation
    function validate() {
        const e = {};
        if (f.full_name.value.trim().length < 2) e.full_name = "Enter a full name with at least 2 characters.";
        if (!/^[^\s@.]+(\.[^\s@.]+)*@[^\s@.]+(\.[^\s@.]+)+$/.test(f.email.value.trim()))
            e.email = "Enter a valid email address, e.g. riley@logistics.example.";
        const pw = f.password.value;
        if (($("fAction").value === "add" || pw !== "") && (pw.length < 8 || pw.length > 72))
            e.password = "Password must be 8 to 72 characters.";
        if (!f.role.value) e.role = "Role is required. Choose Admin, Staff, or Client.";
        if (f.role.value === "client" && !f.client_id.value) e.client_id = "Choose the client company for this account.";
        return e;
    }
    form.addEventListener("submit", (ev) => {
        clearErrors();
        const errs = validate();
        if (Object.keys(errs).length) {
            ev.preventDefault();
            Object.entries(errs).forEach(([k, m]) => setError(k, m));
            showAlert($("fAction").value);
            const bad = form.querySelector(".field.invalid input, .field.invalid select");
            if (bad) bad.focus();
        }
    });

    /* ---------- View popup ---------- */
    const viewDlg = $("viewDialog");
    let current = null;

    function pillsInto(el, u) {
        el.textContent = "";
        ["role", "status"].forEach((k) => {
            const s = document.createElement("span");
            s.className = `pill ${k}-${u[k]}`;
            s.textContent = cap(u[k]);
            el.appendChild(s);
        });
    }
    function openView(u) {
        current = u;
        $("vAvatar").textContent = initialsOf(u.name);
        $("vName").textContent = u.name;
        $("vEmail").textContent = u.email;
        pillsInto($("vPills"), u);
        $("vCode").textContent = u.code;
        $("vCreated").textContent = u.created;
        $("vRole").textContent = cap(u.role);
        $("vStatus").textContent = cap(u.status);
        const self = u.self === "1";
        $("vToggle").textContent = u.status === "active" ? "Deactivate" : "Activate";
        $("vToggle").disabled = self;
        $("vDelete").disabled = self;
        viewDlg.showModal();
    }
    $("vEdit").addEventListener("click", () => { viewDlg.close(); openForm("edit", current); });
    $("vToggle").addEventListener("click", () => { viewDlg.close(); openConfirm(current.status === "active" ? "deactivate" : "activate", current); });
    $("vDelete").addEventListener("click", () => { viewDlg.close(); openConfirm("delete", current); });

    /* ---------- Deactivate / Activate / Delete confirmation popup ---------- */
    const confirmDlg = $("confirmDialog"), cSubmit = $("cSubmit"), cConfirm = $("cConfirm");

    function openConfirm(kind, u) {
        const n = firstName(u.name);
        const cfg = {
            deactivate: {
                title: "Deactivate account?", sub: `Please confirm before changing ${u.name}\u2019s access.`,
                action: "toggle", tone: "danger", button: "Deactivate", danger: true,
                alert: `Account access will be disabled. ${n} will no longer be able to sign in to AstraTrack.`,
                body: "Shipment, billing, and transaction history will be retained. No records will be deleted. An Admin can activate this account again later.",
            },
            activate: {
                title: "Activate account?", sub: `Please confirm before changing ${u.name}\u2019s access.`,
                action: "toggle", tone: "info", button: "Activate", danger: false,
                alert: `Account access will be restored. ${n} will be able to sign in to AstraTrack again.`,
                body: "Role and permissions stay as they were. An Admin can deactivate this account again at any time.",
            },
            delete: {
                title: "Delete account permanently?", sub: `This cannot be undone. Please confirm before deleting ${u.name}.`,
                action: "delete", tone: "danger", button: "Delete account", danger: true,
                alert: `Permanent deletion. ${n}\u2019s account will be removed and cannot be recovered.`,
                body: "Shipment, billing, and transaction records are kept, but they will no longer show who handled them. To keep the account on file, deactivate it instead.",
            },
        }[kind];

        $("confirmForm").reset();
        $("cTitle").textContent = cfg.title;
        $("cSub").textContent = cfg.sub;
        $("cAction").value = cfg.action;
        $("cId").value = u.id;
        $("cName").textContent = u.name;
        $("cMeta").textContent = `${u.code} \u00b7 ${u.email}`;
        pillsInto($("cPills"), u);
        $("cAlert").className = "alert-box" + (cfg.tone === "info" ? " info" : "");
        $("cAlertText").textContent = cfg.alert;
        $("cBody").textContent = cfg.body;
        cSubmit.textContent = cfg.button;
        cSubmit.className = "btn-add" + (cfg.danger ? " btn-danger" : "");

        const needsTyping = kind === "delete";
        $("cConfirmRow").hidden = !needsTyping;
        cSubmit.disabled = needsTyping;   // must type DELETE first
        confirmDlg.showModal();
    }
    cConfirm.addEventListener("input", () => { cSubmit.disabled = cConfirm.value !== "DELETE"; });

    /* ---------- Buttons ---------- */
    $("addUserBtn").addEventListener("click", () => openForm("add"));
    document.querySelectorAll(".js-add").forEach((b) => b.addEventListener("click", () => openForm("add")));

    // Row buttons (View / Edit / Deactivate-Activate / Delete) read the row's data-* attributes
    table.querySelector("tbody").addEventListener("click", (e) => {
        const btn = e.target.closest("[data-do]");
        if (!btn || btn.disabled) return;
        const u = Object.assign({}, btn.closest("tr").dataset);
        switch (btn.dataset.do) {
            case "view":   openView(u); break;
            case "edit":   openForm("edit", u); break;
            case "status": openConfirm(u.status === "active" ? "deactivate" : "activate", u); break;
            case "delete": openConfirm("delete", u); break;
        }
    });

    /* ---------- Re-open the form if the server found errors ---------- */
    const s = window.UM && window.UM.formState;
    if (s) {
        const u = { id: s.id, name: s.origName, code: "USR-" + String(s.id).padStart(4, "0") };
        openForm(s.mode, u, s.values, s.errors);
    }
});