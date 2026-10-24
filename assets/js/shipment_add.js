/* Shipment add: manual entry and PDF assisted draft review. */
document.addEventListener("DOMContentLoaded", () => {
    const $ = id => document.getElementById(id);
    const csrf = document.querySelector('meta[name="csrf"]')?.content || "";
    const endpoint = "shipment_add.php";
    const form = $("shipForm");
    const fileInput = $("pdfFile");
    const saveButton = $("saveBtn");
    const containerBody = $("containerBody");
    const names = ["bl_awb_no", "shipper_name", "consignee_name", "vessel_or_flight", "port_of_loading", "port_of_discharge", "hs_code", "incoterm", "total_packages", "total_gross_weight", "measurement_cbm", "invoice_no", "currency", "invoice_value", "freight_amount", "fob_value", "remarks"];
    let source = "manual";
    let selectedFile = null;
    let readRequest = null;
    let readFailure = false;
    let duplicateState = "clear";
    let duplicateTimer = 0;
    let extractedHsNeedsConfirmation = false;
    let saved = false;

    const setText = (node, text) => { if (node) node.textContent = text || ""; };
    const setStatus = (text, error = false) => {
        const node = $("saveStatus");
        setText(node, text);
        if (node) node.classList.toggle("error", error);
    };
    async function postJson(url, data) {
        const res = await fetch(url, { method: "POST", headers: { "X-CSRF-Token": csrf }, body: data, credentials: "same-origin" });
        const text = await res.text();
        let payload;
        try { payload = JSON.parse(text); } catch (_) { throw new Error("The server returned an unreadable response. Please try again."); }
        if (!res.ok) { const error = new Error(payload.error || "Request failed."); error.detail = payload.detail || ""; throw error; }
        return payload;
    }

    /* Mobile sidebar */
    const sidebar = $("sidebar");
    const toggle = $("menuToggle");
    if (toggle && sidebar) {
        toggle.addEventListener("click", () => { sidebar.classList.toggle("open"); document.body.classList.toggle("nav-open"); });
        document.addEventListener("click", event => { if (!sidebar.contains(event.target) && !toggle.contains(event.target)) { sidebar.classList.remove("open"); document.body.classList.remove("nav-open"); } });
    }

    function addContainerRow(values = {}) {
        const row = document.createElement("tr");
        [["container_no", "Container No.", 20], ["seal_no", "Seal No.", 30], ["size_type", "Size / Type", 20]].forEach(([key, label, max]) => {
            const cell = document.createElement("td");
            const input = document.createElement("input");
            input.type = "text"; input.maxLength = max; input.dataset.field = key; input.setAttribute("aria-label", label); input.placeholder = label; input.value = values[key] || "";
            input.addEventListener("input", updateChecks);
            cell.append(input); row.append(cell);
        });
        const action = document.createElement("td"); action.className = "row-action";
        const remove = document.createElement("button"); remove.type = "button"; remove.className = "btn-link-danger"; remove.textContent = "Remove";
        remove.addEventListener("click", () => { row.remove(); updateChecks(); });
        action.append(remove); row.append(action); containerBody.append(row);
    }
    const readContainers = () => [...containerBody.querySelectorAll("tr")].map(row => {
        const data = {}; row.querySelectorAll("input").forEach(input => data[input.dataset.field] = input.value); return data;
    });
    $("addContainer").addEventListener("click", () => addContainerRow());

    function parseCombined(text, separator) {
        const value = String(text || "").trim();
        const at = value.indexOf(separator);
        return at < 0 ? [value, ""] : [value.slice(0, at).trim(), value.slice(at + separator.length).trim()];
    }
    function setCanonical(name, value) { if (form.elements[name]) form.elements[name].value = value == null ? "" : String(value); }
    function canonical(name) { return form.elements[name]?.value || ""; }
    function syncModeToCanonical(mode = $("f_mode").value) {
        const get = id => $(id)?.value || "";
        if (mode === "sea") {
            setCanonical("vessel_or_flight", [get("seaVessel"), get("seaVoyage")].filter(Boolean).join(" / "));
            ["shipper_name", "consignee_name", "port_of_loading", "port_of_discharge", "hs_code", "total_packages", "total_gross_weight", "measurement_cbm", "incoterm"].forEach(name => setCanonical(name, form.elements[name]?.value || ""));
        } else {
            const controls = [...form.querySelectorAll(`[data-mode-copy]`)];
            controls.forEach(input => { const name = input.dataset.modeCopy; if (input.closest(`[data-mode-section="${mode}"]`)) setCanonical(name, input.value); });
            if (mode === "air") setCanonical("vessel_or_flight", [get("airline"), get("flightNo")].filter(Boolean).join(" · "));
            if (mode === "land") setCanonical("vessel_or_flight", get("truckPlate"));
        }
        const modeBl = mode === "air" ? $("airBl") : mode === "land" ? $("landBl") : null;
        if (modeBl) setCanonical("bl_awb_no", modeBl.value);
        else if (mode === "sea") setCanonical("bl_awb_no", $("f_bl_awb_no").value);
    }
    function populateModeControls(mode) {
        const vessel = canonical("vessel_or_flight");
        if (mode === "sea") {
            const [one, two] = parseCombined(vessel, " / "); $("seaVessel").value = one; $("seaVoyage").value = two;
            names.filter(name => name !== "vessel_or_flight").forEach(name => { if (form.elements[name] && name !== "bl_awb_no") form.elements[name].value = canonical(name); });
            $("f_bl_awb_no").value = canonical("bl_awb_no");
        } else {
            form.querySelectorAll(`[data-mode-copy]`).forEach(input => { if (input.closest(`[data-mode-section="${mode}"]`)) input.value = canonical(input.dataset.modeCopy); });
            if (mode === "air") {
                const [airline, flight] = parseCombined(vessel, " · "); $("airline").value = airline; $("flightNo").value = flight; $("airBl").value = canonical("bl_awb_no");
            } else { $("truckPlate").value = vessel; $("landBl").value = canonical("bl_awb_no"); }
        }
    }

    function setMode(mode, preserve = true) {
        if (!["sea", "air", "land"].includes(mode)) mode = "sea";
        if (preserve) syncModeToCanonical($("f_mode").value);
        $("f_mode").value = mode;
        document.querySelectorAll("[data-mode]").forEach(button => {
            const active = button.dataset.mode === mode; button.classList.toggle("selected", active); button.setAttribute("aria-pressed", String(active));
            button.textContent = (active ? "✓ " : "") + button.dataset.mode[0].toUpperCase() + button.dataset.mode.slice(1);
        });
        document.querySelectorAll("[data-mode-section]").forEach(section => section.hidden = section.dataset.modeSection !== mode);
        const route = $("routeCaption"); route.textContent = mode === "sea" ? "Sea freight" : mode === "air" ? "Air freight" : "Land freight";
        const importOption = [...$("f_transaction_type").options].find(option => option.value === "import");
        if (importOption) importOption.textContent = mode === "land" ? "Import (inland leg)" : "Import";
        $("landHint").hidden = mode !== "land";
        $("f_bl_awb_no").required = mode === "sea";
        $("airBl").required = mode === "air";
        $("truckPlate").required = mode === "land";
        populateModeControls(mode);
        updateChecks();
    }
    document.querySelectorAll("[data-mode]").forEach(button => button.addEventListener("click", () => setMode(button.dataset.mode)));
    form.addEventListener("input", event => {
        if (event.target.id === "f_bl_awb_no" || event.target.id === "airBl" || event.target.id === "landBl") {
            syncModeToCanonical(); clearTimeout(duplicateTimer); duplicateState = "checking"; updateChecks(); duplicateTimer = setTimeout(checkDuplicate, 350);
        } else updateChecks();
    });
    form.addEventListener("change", updateChecks);

    function setReview(mode = "manual") {
        source = mode;
        $("startCard").hidden = true; $("startInfo").hidden = true; $("startFooter").hidden = true;
        $("shipForm").hidden = false; $("pageSubtitle").textContent = mode === "document" ? "Auto-read draft · Confirm extracted values before saving" : "Manual entry · Complete the draft, then review and save";
        $("pageState").textContent = mode === "document" ? "Auto-read draft" : "Manual draft";
        $("stepOne").classList.add("complete"); $("stepOne").classList.remove("active"); $("stepTwo").classList.add("active");
        $("checksIntro").textContent = mode === "document" ? "Auto-read is a starting point. Confirm the draft against the source document." : "Manual draft · Completeness and amount checks. No document extraction was used.";
        $("extractionCard").hidden = mode !== "document";
        $("sourceDocument").hidden = !selectedFile;
        if (selectedFile) { setText($("sourceFileName"), selectedFile.name); setText($("sourceFileMeta"), `PDF · ${(selectedFile.size / 1024 / 1024).toFixed(1)} MB · Draft attachment`); }
        $("pageMessage").textContent = "Unsaved draft · Complete required details before saving";
        $("saveBtn").disabled = false;
        setMode($("f_mode").value || "sea", false);
        updateChecks();
        window.scrollTo({ top: 0, behavior: "smooth" });
    }

    function fillForm(data) {
        const f = data.form || {};
        names.forEach(name => setCanonical(name, f[name]));
        setCanonical("client_id", f.client_id || "");
        setCanonical("transaction_type", f.transaction_type || "");
        setCanonical("mode", f.mode || "sea");
        containerBody.replaceChildren(); (f.containers || []).forEach(addContainerRow);
        if (!(f.containers || []).length) addContainerRow();
        const hint = $("clientHint");
        hint.hidden = false;
        hint.classList.toggle("warn", !f.client_id);
        hint.textContent = f.client_id ? "Client suggestion matched from the shipper or consignee. Confirm the selected client." : `Client not matched. Select an existing client to continue. Extracted consignee: ${f.consignee_name || "Not found"}.`;
        const checks = $("checkList"), warnings = $("warningList"); checks.replaceChildren(); warnings.replaceChildren();
        (data.checks || []).forEach(check => { const li = document.createElement("li"); li.className = check.ok ? "ok" : "bad"; li.textContent = `${check.ok ? "✓ " : "! "}${check.name}`; checks.append(li); });
        (data.warnings || []).forEach(warning => { const li = document.createElement("li"); li.className = "warn"; li.textContent = warning; warnings.append(li); });
        extractedHsNeedsConfirmation = !!f.hs_code;
        $("hsConfirmWrap").hidden = !extractedHsNeedsConfirmation;
        $("hsConfirm").checked = false;
        setMode(f.mode || "sea", false);
        if (canonical("bl_awb_no")) { duplicateState = "checking"; checkDuplicate(); }
    }

    function parseNumber(value) { if (!String(value || "").trim()) return null; const number = Number(String(value).replace(/[,\s]/g, "")); return Number.isFinite(number) ? number : null; }
    function requiredComplete() {
        syncModeToCanonical();
        const mode = $("f_mode").value;
        return !!canonical("client_id") && !!canonical("transaction_type") && (mode === "land" ? !!canonical("vessel_or_flight").trim() : !!canonical("bl_awb_no").trim());
    }
    function updateChecks() {
        if (form.hidden) return;
        syncModeToCanonical();
        const mode = $("f_mode").value;
        const bL = canonical("bl_awb_no").trim();
        const clientOk = !!canonical("client_id");
        const requiredOk = clientOk && !!canonical("transaction_type") && (mode === "land" ? !!canonical("vessel_or_flight").trim() : !!bL);
        const invoice = parseNumber(canonical("invoice_value"));
        const freight = parseNumber(canonical("freight_amount"));
        const fob = parseNumber(canonical("fob_value"));
        const amountsPresent = [invoice, freight, fob].some(value => value !== null);
        const amountsPositive = [invoice, freight, fob].filter(value => value !== null).every(value => value > 0);
        const arithmeticKnown = invoice !== null && freight !== null && fob !== null;
        const arithmeticOk = !arithmeticKnown || Math.abs(invoice - freight - fob) < 0.02;
        const duplicateOk = duplicateState !== "duplicate";
        const hsOk = !extractedHsNeedsConfirmation || $("hsConfirm").checked;
        const rows = [
            { ok: requiredOk, title: requiredOk ? "Required details complete" : "Complete required details", detail: mode === "land" ? "Choose a client and enter a truck plate." : "Choose a client, transaction type and unique B/L or AWB." },
            ...(bL ? [{ ok: duplicateState === "clear", title: duplicateState === "checking" ? "Checking B/L or AWB" : duplicateState === "duplicate" ? "Duplicate B/L or AWB number" : duplicateState === "unknown" ? "Could not check B/L or AWB" : "B/L or AWB number is available", detail: duplicateState === "duplicate" ? `This number belongs to ${window.duplicateReference || "an existing shipment"}.` : duplicateState === "unknown" ? "Retry the check when the connection is available." : "Duplicate numbers are checked again before saving." }] : []),
            ...(amountsPresent ? [{ ok: amountsPositive, title: amountsPositive ? "Amounts are positive" : "Review entered amounts", detail: "Invoice, freight and FOB values must be greater than zero when entered." }] : []),
            ...(arithmeticKnown ? [{ ok: arithmeticOk, title: arithmeticOk ? "Arithmetic matches" : "Arithmetic needs review", detail: `FOB ${fob.toLocaleString()} + freight ${freight.toLocaleString()} ${arithmeticOk ? "matches" : "does not match"} invoice ${invoice.toLocaleString()} (${canonical("currency") || "USD"}). Draft check only; no FX conversion or universal Incoterm rule is applied.` }] : []),
            ...(extractedHsNeedsConfirmation ? [{ ok: hsOk, title: hsOk ? "HS classification confirmed" : "HS code extracted — confirm classification", detail: "The extracted code is a suggestion from the PDF, not a verified classification." }] : []),
        ];
        const host = $("checksDynamic"); host.replaceChildren();
        rows.forEach(row => { const item = document.createElement("div"); item.className = `check-item ${row.ok ? "ok" : "warn"}`; const title = document.createElement("strong"); title.textContent = `${row.ok ? "✓" : "!"} ${row.title}`; const detail = document.createElement("p"); detail.textContent = row.detail; item.append(title, detail); host.append(item); });
        const duplicateMessage = $("duplicateMessage");
        duplicateMessage.hidden = duplicateState !== "duplicate";
        duplicateMessage.textContent = duplicateState === "duplicate" ? `This number is already used by ${window.duplicateReference || "another shipment"}.` : "";
        const duplicateControl = mode === "air" ? $("airBl") : mode === "land" ? $("landBl") : $("f_bl_awb_no");
        duplicateControl.setAttribute("aria-invalid", duplicateState === "duplicate" ? "true" : "false");
        $("checksBadge").textContent = rows.every(row => row.ok) ? "Ready to save" : "Action needed";
        $("checksBadge").classList.toggle("passed", rows.every(row => row.ok));
        // The server enforces required fields, duplicate IDs and numeric ranges. The amount equation is advisory because some invoices include insurance, taxes, or other charges.
        saveButton.disabled = !requiredComplete() || !duplicateOk || !hsOk;
        if (duplicateState === "duplicate") setStatus(`Unsaved draft · Correct the duplicate B/L or AWB before saving`, true);
        else if (!clientOk) setStatus("Unsaved draft · Select an existing client before saving");
        else if (!requiredOk) setStatus(mode === "land" ? "Unsaved draft · Enter a truck plate before saving" : "Unsaved draft · Enter the B/L or AWB number before saving");
        else if (!hsOk) setStatus("Unsaved draft · Confirm the extracted HS classification");
        else if (rows.some(row => !row.ok)) setStatus("Unsaved draft · Review the highlighted checks");
        else setStatus("Unsaved draft · Required details are ready for review");
    }

    async function checkDuplicate() {
        syncModeToCanonical();
        const value = canonical("bl_awb_no").trim();
        if (!value) { duplicateState = "clear"; window.duplicateReference = ""; updateChecks(); return; }
        duplicateState = "checking"; updateChecks();
        const body = new FormData(); body.append("bl_awb_no", value);
        try {
            const result = await postJson(endpoint + "?ajax=check-bl", body);
            if (canonical("bl_awb_no").trim() !== value) return;
            duplicateState = result.duplicate ? "duplicate" : "clear";
            window.duplicateReference = result.reference_no || "";
        } catch (_) { duplicateState = "unknown"; }
        updateChecks();
    }

    function showUploadFailure(message) {
        readFailure = true;
        $("manualBtn").disabled = false;
        $("uploadFeedback").textContent = `We couldn’t read this document. ${message || "No shipment has been saved."}`;
        $("uploadFeedback").className = "upload-feedback error";
        $("uploadActions").hidden = false;
        $("uploadNote").textContent = "Continue manually to keep this PDF as a draft attachment. It is saved only when you save the shipment.";
        $("cancelUpload").hidden = true;
        $("progressTrack").hidden = true;
        $("pageMessage").textContent = "Reading failed · No shipment has been saved";
    }
    function uploadAndRead() {
        if (!selectedFile) return;
        if (selectedFile.size > 25 * 1024 * 1024) { showUploadFailure("The file is larger than 25 MB."); return; }
        if (selectedFile.type && selectedFile.type !== "application/pdf") { showUploadFailure("Only PDF files are accepted."); return; }
        readFailure = false;
        $("manualBtn").disabled = true;
        $("uploadState").hidden = false; $("dropZone").hidden = true; $("uploadActions").hidden = true; $("cancelUpload").hidden = false;
        $("progressTrack").hidden = false; $("uploadProgress").style.width = "0%";
        $("uploadFeedback").className = "upload-feedback"; $("uploadFeedback").textContent = "Uploading document…";
        $("uploadNote").textContent = "Please keep this page open.";
        $("pageMessage").textContent = "Upload in progress · Review is available after reading";
        const data = new FormData(); data.append("file", selectedFile);
        const xhr = new XMLHttpRequest(); readRequest = xhr; xhr.open("POST", endpoint + "?ajax=extract"); xhr.setRequestHeader("X-CSRF-Token", csrf); xhr.timeout = 190000;
        xhr.upload.addEventListener("progress", event => { if (!event.lengthComputable) return; const percent = Math.round(event.loaded / event.total * 100); $("uploadProgress").style.width = `${percent}%`; $("uploadFeedback").textContent = percent >= 100 ? "Reading document…" : `Uploading document… ${percent}%`; $("pageMessage").textContent = `Upload in progress · ${percent}% uploaded`; });
        xhr.addEventListener("load", () => {
            readRequest = null;
            let result;
            try { result = JSON.parse(xhr.responseText); } catch (_) { showUploadFailure("The server returned an unreadable response."); return; }
            if (xhr.status < 200 || xhr.status >= 300) { showUploadFailure(result.error || "No shipment has been saved."); return; }
            $("uploadFeedback").className = "upload-feedback success"; $("uploadFeedback").textContent = `Upload complete · ${(selectedFile.size / 1024 / 1024).toFixed(1)} MB received`;
            $("uploadFeedback").textContent += " · Document read successfully"; $("cancelUpload").hidden = true; $("progressTrack").hidden = true;
            fillForm(result); setReview("document");
        });
        xhr.addEventListener("error", () => { readRequest = null; showUploadFailure("A network error interrupted document reading."); });
        xhr.addEventListener("timeout", () => { readRequest = null; showUploadFailure("Document reading timed out. You can retry or continue manually."); });
        xhr.addEventListener("abort", () => { readRequest = null; $("manualBtn").disabled = false; $("uploadFeedback").textContent = "Upload cancelled. Choose the PDF again or continue with manual entry."; $("uploadActions").hidden = false; $("cancelUpload").hidden = true; $("pageMessage").textContent = "Upload cancelled · No shipment has been saved"; });
        xhr.send(data);
    }

    $("browseBtn").addEventListener("click", event => { event.stopPropagation(); fileInput.click(); });
    $("dropZone").addEventListener("click", () => fileInput.click());
    $("dropZone").addEventListener("keydown", event => { if (event.key === "Enter" || event.key === " ") { event.preventDefault(); fileInput.click(); } });
    $("dropZone").addEventListener("dragover", event => { event.preventDefault(); $("dropZone").classList.add("dragging"); });
    $("dropZone").addEventListener("dragleave", () => $("dropZone").classList.remove("dragging"));
    $("dropZone").addEventListener("drop", event => { event.preventDefault(); $("dropZone").classList.remove("dragging"); const file = event.dataTransfer.files[0]; if (file) selectFile(file); });
    function selectFile(file) {
        selectedFile = file;
        $("fileName").textContent = file.name; $("fileMeta").textContent = `PDF · ${(file.size / 1024 / 1024).toFixed(1)} MB · ${file.size > 25 * 1024 * 1024 ? "Over the 25 MB limit" : "Within 25 MB limit"}`;
        $("uploadState").hidden = false; $("dropZone").hidden = true;
        const isPdf = file.type === "application/pdf" || file.name.toLocaleLowerCase().endsWith(".pdf");
        if (file.size > 25 * 1024 * 1024) { selectedFile = null; showUploadFailure("The file is larger than 25 MB."); }
        else if (!isPdf) { selectedFile = null; showUploadFailure("Only PDF files are accepted."); }
        else uploadAndRead();
    }
    fileInput.addEventListener("change", () => { if (fileInput.files[0]) selectFile(fileInput.files[0]); });
    $("cancelUpload").addEventListener("click", () => { if (readRequest) readRequest.abort(); });
    $("retryRead").addEventListener("click", uploadAndRead);
    $("manualAfterFailure").addEventListener("click", () => { resetDraft(); setReview("manual"); $("pageMessage").textContent = "Preparing an unsaved draft · No shipment record yet"; });
    $("manualBtn").addEventListener("click", () => { resetDraft(); setReview("manual"); });

    function resetDraft() {
        form.reset(); containerBody.replaceChildren(); addContainerRow();
        names.forEach(name => setCanonical(name, "")); setCanonical("transaction_type", "import"); setCanonical("mode", "sea");
        $("f_client_id").value = ""; $("f_transaction_type").value = "import"; $("f_currency").value = "USD"; $("f_mode").value = "sea";
        $("clientHint").hidden = true; $("landHint").hidden = true; $("hsConfirmWrap").hidden = true; $("hsConfirm").checked = false;
        extractedHsNeedsConfirmation = false; duplicateState = "clear"; window.duplicateReference = ""; saved = false;
        $("extractionCard").hidden = true; $("checkList").replaceChildren(); $("warningList").replaceChildren();
        populateModeControls("sea");
    }

    $("hsConfirm").addEventListener("change", updateChecks);
    $("f_client_id").addEventListener("change", updateChecks);
    $("f_transaction_type").addEventListener("change", updateChecks);
    $("f_bl_awb_no").addEventListener("blur", () => { syncModeToCanonical(); checkDuplicate(); });
    $("airBl").addEventListener("blur", () => { syncModeToCanonical(); checkDuplicate(); });
    $("landBl").addEventListener("blur", () => { syncModeToCanonical(); checkDuplicate(); });

    form.addEventListener("submit", async event => {
        event.preventDefault();
        syncModeToCanonical();
        updateChecks();
        if (!requiredComplete()) { setStatus("Choose a client and complete the required fields before saving", true); return; }
        if (duplicateState === "checking") { setStatus("Wait for the B/L or AWB duplicate check to finish", true); return; }
        if (duplicateState === "duplicate") { setStatus("Correct the duplicate B/L or AWB before saving", true); return; }
        if (!form.reportValidity()) return;
        const mode = $("f_mode").value;
        const data = { client_id: canonical("client_id"), transaction_type: canonical("transaction_type"), mode, containers: mode === "sea" ? readContainers() : [] };
        names.forEach(name => data[name] = canonical(name));
        if (mode === "land") {
            const border = $("borderEntry").value.trim();
            if (border) data.remarks = [data.remarks, `Border or Port of Entry: ${border}`].filter(Boolean).join("\n");
        }
        const chosen = selectedFile;
        const fd = new FormData(); fd.append("data", JSON.stringify(data)); if (chosen) fd.append("file", chosen);
        saveButton.disabled = true; saveButton.textContent = "Saving…"; setStatus("Saving shipment… Controls are locked to prevent duplicate submissions");
        try {
            const result = await postJson(endpoint + "?ajax=save", fd);
            saved = true;
            setText($("doneRef"), result.reference_no); setText($("doneClient"), $("f_client_id").selectedOptions[0]?.textContent || "");
            setText($("doneMode"), `${data.transaction_type === "import" ? "Import" : "Export"} / ${mode[0].toUpperCase() + mode.slice(1)}`);
            setText($("doneBl"), data.bl_awb_no || "—"); $("doneOpen").href = `shipment_view.php?id=${encodeURIComponent(result.shipment_id)}`;
            form.hidden = true; $("startCard").hidden = true; $("startInfo").hidden = true; $("startFooter").hidden = true; $("doneCard").hidden = false;
            $("pageSubtitle").textContent = "A new shipment record is ready to view."; $("pageState").textContent = "Shipment created"; $("stepTwo").classList.add("complete"); $("pageMessage").textContent = `Shipment saved · ${result.reference_no}`;
            window.scrollTo({ top: 0, behavior: "smooth" });
        } catch (error) {
            setStatus(error.message || "The shipment could not be saved. Please review and try again.", true);
            saveButton.disabled = false; saveButton.textContent = "Save Shipment";
            if (error.message && /already saved|already used|duplicate/i.test(error.message)) { duplicateState = "duplicate"; window.duplicateReference = ""; updateChecks(); }
        }
    });

    $("cancelBtn").addEventListener("click", () => {
        form.hidden = true; $("startCard").hidden = false; $("startInfo").hidden = false; $("startFooter").hidden = false;
        selectedFile = null; fileInput.value = ""; $("uploadState").hidden = true; $("dropZone").hidden = false; $("manualBtn").disabled = false;
        $("pageSubtitle").textContent = "Choose a starting point, then review and save your shipment."; $("pageState").textContent = "New shipment";
        $("stepOne").classList.remove("complete"); $("stepOne").classList.add("active"); $("stepTwo").classList.remove("active");
        resetDraft(); setText($("pageMessage"), "No shipment saved · Choose a method to begin");
    });

    resetDraft();
});
