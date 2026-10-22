/* shipment_add.js — AstraTrack "Add Shipment": upload -> extract -> review -> save */

document.addEventListener("DOMContentLoaded", () => {
    const $ = (id) => document.getElementById(id);
    const csrf = document.querySelector('meta[name="csrf"]').content;
    const ENDPOINT = "shipment_add.php";

    /* Mobile sidebar toggle (same as the other pages) */
    const sidebar = $("sidebar");
    const toggle = $("menuToggle");
    if (toggle && sidebar) {
        const setMenu = (open) => {
            sidebar.classList.toggle("open", open);
            document.body.classList.toggle("nav-open", open);
        };
        toggle.addEventListener("click", () => setMenu(!sidebar.classList.contains("open")));
        document.addEventListener("click", (e) => {
            if (!sidebar.contains(e.target) && !toggle.contains(e.target)) setMenu(false);
        });
    }

    const form = $("shipForm");
    const fileInput = $("pdfFile");
    const extractBtn = $("extractBtn");
    const saveBtn = $("saveBtn");
    const body = $("containerBody");

    /* Fields that map 1:1 to an input named like the database column */
    const TEXT_FIELDS = [
        "bl_awb_no", "shipper_name", "consignee_name", "vessel_or_flight",
        "port_of_loading", "port_of_discharge", "hs_code", "incoterm",
        "total_packages", "total_gross_weight", "measurement_cbm",
        "invoice_no", "currency", "invoice_value", "freight_amount", "fob_value", "remarks",
    ];

    function setStatus(id, message, isError) {
        const node = $(id);
        node.textContent = message || "";
        node.classList.toggle("error", !!isError);
    }

    // Reads JSON from the server, with a readable error when PHP sends an HTML error page instead
    async function postJson(url, formData) {
        const res = await fetch(url, { method: "POST", headers: { "X-CSRF-Token": csrf }, body: formData });
        const text = await res.text();
        let payload;
        try {
            payload = JSON.parse(text);
        } catch (e) {
            throw new Error("Server replied with status " + res.status + ": " + (text.replace(/<[^>]*>/g, " ").trim().slice(0, 300) || "(empty response)"));
        }
        if (!res.ok) {
            const err = new Error(payload.error || "Request failed.");
            err.detail = payload.detail || "";
            throw err;
        }
        return payload;
    }

    /* ---------- containers table (rows are plain inputs) ---------- */
    function addContainerRow(c) {
        c = c || {};
        const tr = document.createElement("tr");
        [["container_no", "Container no.", 20], ["seal_no", "Seal no.", 30], ["size_type", "Type", 20]].forEach(([key, label, max]) => {
            const td = document.createElement("td");
            td.dataset.label = label;
            const input = document.createElement("input");
            input.type = "text";
            input.maxLength = max;
            input.dataset.field = key;
            input.value = c[key] == null ? "" : c[key];
            input.setAttribute("aria-label", label);
            td.appendChild(input);
            tr.appendChild(td);
        });
        const td = document.createElement("td");
        td.className = "row-action";
        const del = document.createElement("button");
        del.type = "button";
        del.className = "btn-link-danger";
        del.textContent = "Remove";
        del.addEventListener("click", () => tr.remove());
        td.appendChild(del);
        tr.appendChild(td);
        body.appendChild(tr);
    }

    function readContainers() {
        return Array.from(body.querySelectorAll("tr")).map((tr) => {
            const row = {};
            tr.querySelectorAll("input").forEach((i) => { row[i.dataset.field] = i.value; });
            return row;
        });
    }

    $("addContainer").addEventListener("click", () => addContainerRow());

    /* ---------- fill the form from the extraction ---------- */
    function fillForm(data) {
        const f = data.form;
        TEXT_FIELDS.forEach((name) => {
            const input = form.elements[name];
            if (input) input.value = f[name] == null ? "" : f[name];
        });
        form.elements.client_id.value = f.client_id ? String(f.client_id) : "";
        form.elements.transaction_type.value = f.transaction_type || "";
        form.elements.mode.value = f.mode || "sea";

        body.replaceChildren();
        (f.containers || []).forEach(addContainerRow);
        if (!(f.containers || []).length) addContainerRow();

        // Tell staff what was guessed, so they confirm it instead of trusting it blindly
        $("clientHint").textContent = f.client_id
            ? "The client was matched from the consignee/shipper name. Please confirm it."
            : "No client matched the document. Choose the client manually.";

        const checkList = $("checkList");
        const warningList = $("warningList");
        checkList.replaceChildren();
        warningList.replaceChildren();
        (data.checks || []).forEach((c) => {
            const li = document.createElement("li");
            li.className = c.ok ? "ok" : "bad";
            li.textContent = (c.ok ? "Passed: " : "Check this: ") + c.name;
            checkList.appendChild(li);
        });
        (data.warnings || []).forEach((w) => {
            const li = document.createElement("li");
            li.textContent = "Note: " + w;
            warningList.appendChild(li);
        });
        $("checkCard").hidden = !((data.checks || []).length || (data.warnings || []).length);
    }

    /* ---------- extract ---------- */
    extractBtn.addEventListener("click", async () => {
        const file = fileInput.files[0];
        if (!file) return setStatus("status", "Choose a PDF file first.", true);
        if (file.size > 25 * 1024 * 1024) return setStatus("status", "The file is larger than 25 MB.", true);

        extractBtn.disabled = true;
        form.hidden = true;
        $("doneCard").hidden = true;
        setStatus("status", "Reading the document. This can take 20-60 seconds…");

        try {
            const fd = new FormData();
            fd.append("file", file);
            const data = await postJson(ENDPOINT + "?ajax=extract", fd);
            fillForm(data);
            form.hidden = false;
            saveBtn.disabled = false;
            setStatus("saveStatus", "");
            setStatus("status", "Done. Check every field below before saving.");
            form.scrollIntoView({ behavior: "smooth", block: "start" });
        } catch (e) {
            setStatus("status", e.message, true);
        } finally {
            extractBtn.disabled = false;
        }
    });

    /* ---------- save ---------- */
    form.addEventListener("submit", async (ev) => {
        ev.preventDefault();
        const file = fileInput.files[0];
        if (!file) return setStatus("saveStatus", "The PDF is missing. Choose it again.", true);
        if (!form.reportValidity()) return;

        const data = {
            client_id: form.elements.client_id.value,
            transaction_type: form.elements.transaction_type.value,
            mode: form.elements.mode.value,
            containers: readContainers(),
        };
        TEXT_FIELDS.forEach((name) => {
            if (form.elements[name]) data[name] = form.elements[name].value;
        });

        saveBtn.disabled = true;
        setStatus("saveStatus", "Saving…");
        try {
            const fd = new FormData();
            fd.append("file", file);
            fd.append("data", JSON.stringify(data));
            const res = await postJson(ENDPOINT + "?ajax=save", fd);

            $("doneRef").textContent = res.reference_no;
            $("doneOpen").href = "shipment_view.php?id=" + encodeURIComponent(res.shipment_id);
            form.hidden = true;
            $("uploadCard").hidden = true;
            $("doneCard").hidden = false;
            $("doneCard").scrollIntoView({ behavior: "smooth", block: "start" });
        } catch (e) {
            setStatus("saveStatus", e.message + (e.detail ? " (" + e.detail + ")" : ""), true);
            saveBtn.disabled = false;
        }
    });

    $("cancelBtn").addEventListener("click", () => {
        form.hidden = true;
        form.reset();
        body.replaceChildren();
        fileInput.value = "";
        setStatus("status", "");
        setStatus("saveStatus", "");
    });
});
