/* admin.js — AstraTrack Admin Dashboard */

document.addEventListener("DOMContentLoaded", () => {

    /* 1) Animate the shipment bars to the width calculated in admin.php */
    document.querySelectorAll(".bar-fill").forEach((bar) => {
        const width = parseFloat(bar.dataset.width) || 0;
        requestAnimationFrame(() => { bar.style.width = width + "%"; });
    });

    /* 2) Mobile sidebar toggle */
    const sidebar = document.getElementById("sidebar");
    const toggle  = document.getElementById("menuToggle");

    if (toggle && sidebar) {
        const setMenu = (open) => {
            sidebar.classList.toggle("open", open);
            document.body.classList.toggle("nav-open", open);
        };
        toggle.addEventListener("click", () => setMenu(!sidebar.classList.contains("open")));

        // Close the menu when clicking outside of it or pressing Esc
        document.addEventListener("click", (e) => {
            if (!sidebar.contains(e.target) && !toggle.contains(e.target)) setMenu(false);
        });
        document.addEventListener("keydown", (e) => { if (e.key === "Escape") setMenu(false); });
    }

    /* 3) OPTIONAL: load live numbers without refreshing the page
     *
     * Example of how to connect the dashboard (or User Management) to PHP.
     * Create api/stats.php that returns JSON, for example:
     *
     *     <?php
     *     require_once '../db.php';
     *     echo json_encode(['totalUsers' => $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn()]);
     *
     * Then un-comment the code below.
     *
     * fetch("api/stats.php")
     *     .then((res) => res.json())
     *     .then((data) => {
     *         document.querySelector("[data-count]").textContent = data.totalUsers;
     *     })
     *     .catch((err) => console.error("Could not load stats:", err));
     */

    /* 4) USER MANAGEMENT hook (example)
     *
     * The "Manage Users" button already links to user_management.php.
     * If you prefer to confirm or track the click first, use this:
     *
     * document.querySelector('a[href="user_management.php"]')
     *     .addEventListener("click", () => console.log("Opening User Management"));
     */
});
