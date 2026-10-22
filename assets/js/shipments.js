/* shipments.js — AstraTrack shipment list and details pages */

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
});
