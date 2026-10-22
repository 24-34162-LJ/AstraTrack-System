/* staff.js — AstraTrack Staff Dashboard */

document.addEventListener("DOMContentLoaded", () => {

    /* 1) Mobile sidebar toggle */
    const sidebar = document.getElementById("sidebar");
    const toggle  = document.getElementById("menuToggle");

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

    /* 2) Shipment details dialog (used by "Track" and every "View Details" button) */
    const modal    = document.getElementById("detailModal");
    const title    = document.getElementById("modalTitle");
    const status   = document.getElementById("modalStatus");
    const details  = document.getElementById("modalDetails");
    const timeline = document.getElementById("modalTimeline");

    const form     = document.getElementById("trackForm");
    const input    = document.getElementById("trackInput");
    const errorBox = document.getElementById("trackError");

    function showError(message) {
        errorBox.textContent = message;
        errorBox.hidden = !message;
    }

    // Everything is written with textContent, so shipment data can never inject HTML
    function el(tag, className, text) {
        const node = document.createElement(tag);
        if (className) node.className = className;
        if (text !== undefined) node.textContent = text;
        return node;
    }

    function fillModal(data) {
        title.textContent  = data.reference;
        status.textContent = data.status.label;
        status.className   = "badge badge-" + data.status.class;

        details.replaceChildren();
        data.details.forEach(([label, value]) => {
            const row = el("div");
            row.append(el("dt", "", label), el("dd", "", value));
            details.append(row);
        });

        timeline.replaceChildren();
        if (!data.timeline.length) {
            timeline.append(el("li", "empty", "No progress updates yet."));
        }
        data.timeline.forEach((item) => {
            const li = el("li", "dot-" + item.class);
            li.append(el("time", "", item.date), el("strong", "", item.title));
            if (item.note) li.append(el("p", "", item.note));
            timeline.append(li);
        });

        if (!modal.open) modal.showModal();
    }

    // Returns true when a shipment was found and shown
    async function loadShipment(query) {
        showError("");
        try {
            const res  = await fetch("staff.php?ajax=track&ref=" + encodeURIComponent(query), {
                headers: { "Accept": "application/json" },
                credentials: "same-origin"
            });
            const data = await res.json();
            if (!res.ok) {
                showError(data.error || "Something went wrong. Please try again.");
                return false;
            }
            fillModal(data);
            return true;
        } catch (err) {
            showError("Could not reach the server. Check your connection and try again.");
            return false;
        }
    }

    if (form) {
        form.addEventListener("submit", (e) => {
            e.preventDefault();
            const query = input.value.trim();
            if (!query) {
                showError("Enter a shipment reference number.");
                input.focus();
                return;
            }
            loadShipment(query);
        });
        input.addEventListener("input", () => showError(""));
    }

    document.querySelectorAll("[data-ref]").forEach((btn) => {
        btn.addEventListener("click", () => loadShipment(btn.dataset.ref));
    });

    document.getElementById("modalClose").addEventListener("click", () => modal.close());
    modal.addEventListener("click", (e) => { if (e.target === modal) modal.close(); });   // click on backdrop
});
