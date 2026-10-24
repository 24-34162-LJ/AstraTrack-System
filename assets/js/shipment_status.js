/* shipment_status.js — Update Status modal on shipment_view.php (admin and staff)
 * Talks to shipment_status.php (POST, X-CSRF-Token header, JSON back).
 * The valid stages come from the data-state attribute on #statusDialog (built by PHP for this person's role);
 * the server checks every rule again, so this file only makes the form pleasant, it does not protect anything.
 * Needs: <meta name="csrf">, #statusDialog, #stepperList, #timelineList, optional #auditBody,
 *        [data-status-badge] and [data-last-updated] on the page.
 */
(function () {
    "use strict";

    var dlg = document.getElementById("statusDialog");
    if (!dlg || typeof dlg.showModal !== "function") return;

    var $ = function (sel, root) { return (root || dlg).querySelector(sel); };
    var csrf = (document.querySelector('meta[name="csrf"]') || {}).content || "";
    var endpoint = dlg.dataset.endpoint || "shipment_status.php";

    var state;
    try { state = JSON.parse(dlg.dataset.state || "{}"); } catch (e) { state = null; }
    if (!state || !state.shipmentId) return;

    var form = $("#statusForm");
    var confirmView = $("#statusConfirm");
    var els = {
        badge: $("#sfCurrentBadge"), ref: $("#sfRef"),
        correct: $("#sfCorrect"), correctWrap: $("#sfCorrectWrap"),
        stageLabel: $("#sfStageLabel"), stage: $("#sfStage"), current: $("#sfCurrent"),
        note: $("#sfNote"), warn: $("#sfWarn"),
        at: $("#sfAt"), arrival: $("#sfArrival"), reason: $("#sfReason"), reasonField: $("#sfReasonField"),
        remarks: $("#sfRemarks"), remarksHint: $("#sfRemarksHint"), remarksReq: $("#sfRemarksReq"),
        counter: $("#sfCounter"), notify: $("#sfNotify"),
        banner: $("#sfBanner"), save: $("#sfSave"), cancel: $("#sfCancel"),
        confirmFlow: $("#sfConfirmFlow"), confirmText: $("#sfConfirmText"), confirmSmall: $("#sfConfirmSmall"),
        confirmBack: $("#sfConfirmBack"), confirmYes: $("#sfConfirmYes")
    };

    var saving = false;
    var clockBase = 0, clockAt = 0;

    /* ---------- small helpers ---------- */
    function make(tag, cls, text) {
        var n = document.createElement(tag);
        if (cls) n.className = cls;
        if (text !== undefined) n.textContent = text;
        return n;
    }
    function badgeEl(label, colour) {
        return make("span", "badge badge-" + (colour || "blue"), label);
    }
    function pad(n) { return (n < 10 ? "0" : "") + n; }

    // "now" follows the database clock (same one the server checks against), not the browser's time zone
    function setClock(serverNow) {
        var m = /^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/.exec(serverNow || "");
        clockBase = m ? Date.UTC(+m[1], m[2] - 1, +m[3], +m[4], +m[5]) : Date.now();
        clockAt = Date.now();
    }
    function nowMs() { return clockBase + (Date.now() - clockAt); }
    function fmtLocal(ms) {
        var d = new Date(ms);
        return d.getUTCFullYear() + "-" + pad(d.getUTCMonth() + 1) + "-" + pad(d.getUTCDate()) + "T" + pad(d.getUTCHours()) + ":" + pad(d.getUTCMinutes());
    }
    function parseLocal(v) {
        var m = /^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})/.exec(v || "");
        return m ? Date.UTC(+m[1], m[2] - 1, +m[3], +m[4], +m[5]) : NaN;
    }

    var toastTimer = null;
    function toast(title, text, isError) {
        var t = document.getElementById("statusToast");
        if (!t) { t = make("div", "sf-toast"); t.id = "statusToast"; t.setAttribute("role", "status"); t.setAttribute("aria-live", "polite"); document.body.appendChild(t); }
        t.className = "sf-toast" + (isError ? " is-error" : "");
        t.textContent = "";
        var ico = make("span", "ico");
        ico.innerHTML = isError
            ? '<svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-linecap="round"><path d="M12 7v6M12 17h.01"/></svg>'
            : '<svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg>';
        var body = make("div");
        body.appendChild(make("strong", "", title));
        if (text) body.appendChild(make("span", "t", text));
        t.appendChild(ico); t.appendChild(body);
        void t.offsetWidth;
        t.classList.add("show");
        clearTimeout(toastTimer);
        toastTimer = setTimeout(function () { t.classList.remove("show"); }, 4500);
    }

    /* ---------- errors ---------- */
    function fieldOf(name) { return $('[data-field="' + name + '"]'); }
    function clearErrors() {
        dlg.querySelectorAll(".sf-field.has-error").forEach(function (f) { f.classList.remove("has-error"); });
        dlg.querySelectorAll(".sf-error").forEach(function (n) { n.remove(); });
        dlg.querySelectorAll("[aria-invalid]").forEach(function (n) { n.removeAttribute("aria-invalid"); });
        els.banner.hidden = true; els.banner.textContent = "";
    }
    function showError(name, msg) {
        var f = fieldOf(name);
        if (!f) { showBanner(msg); return; }
        f.classList.add("has-error");
        var old = f.querySelector(".sf-error"); if (old) old.remove();
        var p = make("div", "sf-error", msg);
        p.id = "sfErr_" + name;
        var anchor = f.querySelector(".sf-meta") || f.querySelector(".sf-help") || f;
        if (anchor === f) f.appendChild(p); else anchor.parentNode.insertBefore(p, anchor);
        var input = f.querySelector("select, input, textarea");
        if (input) { input.setAttribute("aria-invalid", "true"); input.setAttribute("aria-describedby", p.id); }
    }
    function showBanner(msg) { els.banner.textContent = msg; els.banner.hidden = false; }
    function showErrors(errors, general) {
        clearErrors();
        var first = null;
        ["stage", "reason", "occurred_at", "actual_arrival_date", "remarks"].forEach(function (k) {
            if (errors && errors[k]) { showError(k, errors[k]); if (!first) first = k; }
        });
        if (!first && general) showBanner(general);
        if (first) { var i = fieldOf(first).querySelector("select, input, textarea"); if (i) i.focus(); }
    }

    /* ---------- form state ---------- */
    function correcting() { return !!(els.correct && els.correct.checked); }
    function stageList() { return correcting() ? (state.correctionStages || []) : (state.stages || []); }
    function pickedStage() {
        var name = els.stage.value, list = stageList();
        for (var i = 0; i < list.length; i++) if (list[i].name === name) return list[i];
        return null;
    }
    function remarksRequired() {
        var s = pickedStage();
        return correcting() || !!(s && s.name === "Cancelled");
    }

    function fillHeader() {
        els.ref.textContent = state.reference;
        els.badge.textContent = "";
        els.badge.appendChild(badgeEl(state.current.badgeLabel, state.current.badgeClass));
        els.current.textContent = "";
        els.current.appendChild(document.createTextNode("Current stage:"));
        els.current.appendChild(make("strong", "", state.current.name));
    }

    function fillStages() {
        var list = stageList(), sel = els.stage;
        sel.textContent = "";
        var ph = make("option", "", correcting() ? "Select the correct stage" : "Select next stage");
        ph.value = ""; ph.selected = true; ph.hidden = true;   // shown as the empty value, not listed
        sel.appendChild(ph);

        var cur = make("option", "", state.current.name + " (current)");
        cur.value = ""; cur.disabled = true;
        sel.appendChild(cur);

        var main = list.filter(function (s) { return !s.exception; });
        var exc  = list.filter(function (s) { return s.exception; });
        function opt(s) {
            var o = make("option", "", s.group && s.group !== s.name ? s.name + " · " + s.group : s.name);
            o.value = s.name; return o;
        }
        main.forEach(function (s) { sel.appendChild(opt(s)); });
        if (exc.length) {
            var g = document.createElement("optgroup"); g.label = "Exceptions";
            exc.forEach(function (s) { g.appendChild(opt(s)); });
            sel.appendChild(g);
        }
        sel.disabled = list.length === 0;
    }

    function refreshDependent() {
        var s = pickedStage(), corr = correcting();
        els.stageLabel.firstChild.nodeValue = corr ? "Correct stage to " : "New Stage ";
        els.save.lastChild.nodeValue = corr ? "Save Correction" : "Save Update";
        els.warn.hidden = !corr;

        // note under the dropdown: closed shipment, or the optional "add details" helper
        var msg = "", link = "", href = "";
        if (stageList().length === 0) {
            if (state.current.closed && !state.canCorrect) msg = "This shipment is closed (" + state.current.name + "). Only an administrator can correct it.";
            else if (state.current.closed) msg = 'This shipment is closed. Turn on "Correct a previous stage" to change it.';
            else msg = "No further stages are available.";
        } else if (s && s.helper === "delivery") {
            msg = "Review the trucking and drop-off details for this shipment."; link = "View delivery details"; href = (state.links || {}).delivery || "";
        } else if (s && s.helper === "customs") {
            msg = "Review the customs entry and duty details for this shipment."; link = "View customs details"; href = (state.links || {}).customs || "";
        }
        els.note.textContent = "";
        els.note.hidden = msg === "";
        els.note.classList.toggle("sf-closed", stageList().length === 0);
        if (msg) {
            els.note.appendChild(document.createTextNode(msg));
            if (link && href) {
                var a = make("a", "", link + " →"); a.href = href;   // href comes from PHP, never from user input
                els.note.appendChild(a);
            }
        }

        var req = remarksRequired();
        var needsReason = !!(s && s.name === "On Hold");
        els.reasonField.hidden = !needsReason;
        if (!needsReason) els.reason.value = "";
        els.remarksReq.hidden = !req;
        els.remarksHint.textContent = corr ? "Required for every correction." : (req ? "Required for " + s.name + "." : "Optional.");
        els.save.disabled = saving || stageList().length === 0;
    }

    function updateCounter() {
        var n = els.remarks.value.length;
        els.counter.textContent = n + " / 255";
        els.counter.classList.toggle("is-full", n >= 255);
    }

    function resetForm() {
        clearErrors();
        if (els.correct) els.correct.checked = false;                 // staff have no toggle in the markup
        if (els.correctWrap) els.correctWrap.hidden = !state.canCorrect;
        els.remarks.value = "";
        els.reason.value = "";
        els.arrival.value = state.actualArrival || "";
        els.notify.checked = true;
        els.at.value = fmtLocal(nowMs());
        if (state.lastAt) els.at.min = String(state.lastAt).replace(" ", "T").slice(0, 16);
        fillHeader(); fillStages(); updateCounter(); refreshDependent();
        showView("form");
    }

    function showView(which) {
        form.hidden = which !== "form";
        confirmView.hidden = which !== "confirm";
    }

    function setSaving(on) {
        saving = on;
        [els.save, els.cancel, els.confirmBack, els.confirmYes].forEach(function (b) { if (b) b.disabled = on; });
        [els.save, els.confirmYes].forEach(function (b) {
            if (!b) return;
            b.setAttribute("aria-busy", on ? "true" : "false");
            var sp = b.querySelector(".sf-spin");
            if (on && !sp) { b.insertBefore(make("span", "sf-spin"), b.firstChild); }
            if (!on && sp) sp.remove();
        });
        if (!on) refreshDependent();
        var x = $(".sdlg-x"); if (x) x.disabled = on;
    }

    /* ---------- open / close ---------- */
    function openDialog() {
        if (!dlg.open) { setClock(state.now); resetForm(); dlg.showModal(); if (!els.stage.disabled) els.stage.focus(); }
    }
    function closeDialog() { if (!saving && dlg.open) dlg.close(); }

    document.querySelectorAll("[data-status-open]").forEach(function (b) { b.addEventListener("click", openDialog); });
    dlg.querySelectorAll("[data-status-close]").forEach(function (b) { b.addEventListener("click", closeDialog); });
    dlg.addEventListener("cancel", function (e) { if (saving) e.preventDefault(); });            // Escape
    var downOnBackdrop = false;
    dlg.addEventListener("mousedown", function (e) { downOnBackdrop = (e.target === dlg); });
    dlg.addEventListener("click", function (e) { if (e.target === dlg && downOnBackdrop) closeDialog(); });   // overlay click
    dlg.addEventListener("close", function () { showView("form"); });

    /* ---------- field events ---------- */
    els.stage.addEventListener("change", function () { clearErrors(); refreshDependent(); });
    if (els.correct) els.correct.addEventListener("change", function () { clearErrors(); fillStages(); refreshDependent(); });
    els.reason.addEventListener("change", function () { var f = fieldOf("reason"); if (f) { f.classList.remove("has-error"); var er = f.querySelector(".sf-error"); if (er) er.remove(); } });
    els.arrival.addEventListener("input", function () { var f = fieldOf("actual_arrival_date"); if (f) { f.classList.remove("has-error"); var er = f.querySelector(".sf-error"); if (er) er.remove(); } });
    els.remarks.addEventListener("input", function () { updateCounter(); var f = fieldOf("remarks"); if (f && f.classList.contains("has-error")) { f.classList.remove("has-error"); var er = f.querySelector(".sf-error"); if (er) er.remove(); } });
    els.at.addEventListener("input", function () { var f = fieldOf("occurred_at"); if (f) { f.classList.remove("has-error"); var er = f.querySelector(".sf-error"); if (er) er.remove(); } });

    /* ---------- submit ---------- */
    function localErrors() {
        var errs = {}, s = pickedStage();
        if (!s) errs.stage = "Choose a valid stage.";
        if (s && s.name === "On Hold" && !els.reason.value) errs.reason = "Select a reason.";
        var r = els.remarks.value.replace(/\r\n/g, "\n").trim();
        if (r.length > 255) errs.remarks = "Remarks can be up to 255 characters.";
        else if (r === "" && remarksRequired()) {
            errs.remarks = correcting() ? "Remarks are required for every correction." : "Remarks are required when setting status to " + s.name + ".";
        }
        var at = parseLocal(els.at.value);
        if (isNaN(at)) errs.occurred_at = "Enter a valid date and time.";
        else if (at > nowMs() + 5 * 60000) errs.occurred_at = "The date and time cannot be in the future.";
        if (els.arrival.value && els.arrival.value > new Date(nowMs()).toISOString().slice(0, 10)) errs.actual_arrival_date = "Actual arrival cannot be in the future.";
        return errs;
    }

    form.addEventListener("submit", function (e) {
        e.preventDefault();
        if (saving) return;
        var errs = localErrors();
        if (Object.keys(errs).length) { showErrors(errs); return; }
        clearErrors();
        var s = pickedStage();
        if (s.confirm && !correcting()) { showConfirm(s); return; }
        send();
    });

    function showConfirm(s) {
        els.confirmFlow.textContent = "";
        els.confirmFlow.appendChild(badgeEl(state.current.badgeLabel === state.current.name ? state.current.name : state.current.badgeLabel + " · " + state.current.name, state.current.badgeClass));
        els.confirmFlow.appendChild(make("span", "arrow", "→"));
        els.confirmFlow.appendChild(badgeEl(s.name, "green"));
        els.confirmText.textContent = "Mark this shipment as " + s.name + "?";
        els.confirmSmall.textContent = (els.notify.checked
            ? "The client will be notified. "
            : "The client will not be emailed but will still see it in their timeline. ") + "Only an Admin can correct a completed shipment later.";
        showView("confirm");
        els.confirmYes.focus();
    }
    els.confirmBack.addEventListener("click", function () { if (!saving) { showView("form"); els.save.focus(); } });
    els.confirmYes.addEventListener("click", function () { send(); });

    function send() {
        if (saving) return;                // double click: the second one does nothing
        setSaving(true);

        var fd = new FormData(form);
        fd.set("stage", els.stage.value);
        fd.set("remarks", els.remarks.value);
        fd.set("occurred_at", els.at.value);
        fd.set("actual_arrival_date", els.arrival.value);
        fd.set("reason", els.reason.value);
        fd.set("correction", correcting() ? "1" : "0");
        fd.set("notify", els.notify.checked ? "1" : "0");
        fd.set("shipment_id", String(state.shipmentId));
        fd.set("expected_current", state.current.name);

        fetch(endpoint, { method: "POST", body: fd, credentials: "same-origin", headers: { "X-CSRF-Token": csrf, "Accept": "application/json" } })
            .then(function (res) {
                return res.text().then(function (txt) {
                    var data = null;
                    try { data = JSON.parse(txt); } catch (e) { /* not JSON */ }
                    return { status: res.status, data: data };
                });
            })
            .then(function (r) {
                var d = r.data || {};
                if (r.status === 200 && d.ok) { onSaved(d); return; }
                showView("form");
                if (r.status === 422) { showErrors(d.errors || {}, d.error); return; }
                if (r.status === 409) { applyResult(d, true); showBanner(d.error || "This shipment was changed by someone else."); return; }
                if (r.status === 401) { showBanner((d.error || "Your session expired.") + " Please log in again."); return; }
                showBanner(d.error || "The status could not be updated. Please try again.");
            })
            .catch(function () {
                showView("form");
                showBanner("Network problem. Check your connection and try again.");
            })
            .then(function () { setSaving(false); });
    }

    /* ---------- after saving: redraw stepper, timeline, audit trail, badges (no page reload) ---------- */
    function applyResult(d, keepState) {
        if (d.html) {
            var st = document.getElementById("stepperList"); if (st && d.html.stepper !== undefined) st.innerHTML = d.html.stepper;
            var tl = document.getElementById("timelineList"); if (tl && d.html.timeline !== undefined) tl.innerHTML = d.html.timeline;
            var au = document.getElementById("auditBody");    if (au && d.html.audit !== undefined) au.innerHTML = d.html.audit;
        }
        if (d.status) document.querySelectorAll("[data-status-badge]").forEach(function (n) { n.innerHTML = d.status.badge; });
        if (d.lastUpdated) document.querySelectorAll("[data-last-updated]").forEach(function (n) { n.textContent = d.lastUpdated; });
        if (d.modal) {
            state = d.modal; setClock(state.now);
            document.querySelectorAll("[data-actual-arrival]").forEach(function (n) { n.textContent = state.actualArrival || "—"; });
            if (keepState) { fillHeader(); fillStages(); refreshDependent(); }
            els.stage.value = "";
        }
    }

    if (new URLSearchParams(window.location.search).get('open_status') === '1') dlg.showModal();

    function onSaved(d) {
        if (d.reload) { window.location.reload(); return; }
        applyResult(d, false);
        setSaving(false);
        dlg.close();
        var t = d.detail || "";
        if (d.notify) t += d.emailSent ? " · client emailed" : "";
        toast(d.message || "Status updated", t);
    }
})();
