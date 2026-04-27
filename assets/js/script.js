(function () {
    "use strict";

    if (window.__APP_UI_BOOTSTRAPPED__) {
        if (typeof window.__APP_UI_INIT__ === "function") {
            window.__APP_UI_INIT__();
        }
        return;
    }

    function pad2(n) { n = String(n); return n.length < 2 ? "0" + n : n; }

    function isoToDdMmYyyy(iso) {
        if (!iso) return "";
        var m = String(iso).match(/^(\d{4})-(\d{2})-(\d{2})/);
        if (!m) return "";
        return m[3] + "-" + m[2] + "-" + m[1];
    }

    function ddMmYyyyToIso(val) {
        if (!val) return "";
        var s = String(val).replace(/[^\d-]/g, "");
        var m = s.match(/^(\d{1,2})-(\d{1,2})-(\d{4})$/);
        if (!m) return "";
        var d = parseInt(m[1], 10), mo = parseInt(m[2], 10), y = parseInt(m[3], 10);
        if (mo < 1 || mo > 12 || d < 1 || d > 31) return "";
        return y + "-" + pad2(mo) + "-" + pad2(d);
    }

    function maskDdMmYyyy(value) {
        var digits = String(value || "").replace(/\D/g, "").slice(0, 8);
        var out = "";
        if (digits.length > 0) out = digits.slice(0, 2);
        if (digits.length > 2) out += "-" + digits.slice(2, 4);
        if (digits.length > 4) out += "-" + digits.slice(4, 8);
        return out;
    }

    function ensureTheme() {
        var body = document.body;
        var themeToggle = document.getElementById("themeToggle");
        var savedTheme = localStorage.getItem("theme");

        if (savedTheme === "dark") {
            body.classList.add("dark-mode");
            if (themeToggle) {
                themeToggle.checked = true;
            }
        } else {
            body.classList.remove("dark-mode");
            if (themeToggle) {
                themeToggle.checked = false;
            }
        }

        if (themeToggle && themeToggle.dataset.themeBound !== "1") {
            themeToggle.dataset.themeBound = "1";
            themeToggle.addEventListener("change", function () {
                if (this.checked) {
                    body.classList.add("dark-mode");
                    localStorage.setItem("theme", "dark");
                } else {
                    body.classList.remove("dark-mode");
                    localStorage.setItem("theme", "light");
                }
            });
        }
    }

    function ensureSidebar() {
        var sidebar = document.getElementById("sidebar");
        var sidebarToggle = document.getElementById("sidebarToggle");
        var mobileSidebarBtn = document.getElementById("mobileSidebarBtn");
        var sidebarOverlay = document.getElementById("sidebarOverlay");

        if (sidebarToggle && sidebar && sidebarToggle.dataset.sidebarBound !== "1") {
            sidebarToggle.dataset.sidebarBound = "1";
            sidebarToggle.addEventListener("click", function () {
                sidebar.classList.toggle("collapsed");
            });
        }

        if (mobileSidebarBtn && sidebar && sidebarOverlay && mobileSidebarBtn.dataset.mobileSidebarBound !== "1") {
            mobileSidebarBtn.dataset.mobileSidebarBound = "1";
            mobileSidebarBtn.addEventListener("click", function () {
                sidebar.classList.add("mobile-open");
                sidebarOverlay.classList.add("show");
            });
        }

        if (sidebarOverlay && sidebar && sidebarOverlay.dataset.overlayBound !== "1") {
            sidebarOverlay.dataset.overlayBound = "1";
            sidebarOverlay.addEventListener("click", function () {
                sidebar.classList.remove("mobile-open");
                sidebarOverlay.classList.remove("show");
            });
        }
    }

    function ensureEgyptClock() {
        if (!window.__APP_UI_EGYPT_FORMATTER__) {
            window.__APP_UI_EGYPT_FORMATTER__ = new Intl.DateTimeFormat("ar-EG", {
                timeZone: "Africa/Cairo",
                year: "numeric",
                month: "2-digit",
                day: "2-digit",
                hour: "2-digit",
                minute: "2-digit",
                hour12: true
            });
        }

        var updateEgyptDateTimeDisplay = function () {
            var egyptDateTimeBadges = document.querySelectorAll(".egypt-datetime-badge");
            if (egyptDateTimeBadges.length === 0) {
                return;
            }

            var formattedDateTime = window.__APP_UI_EGYPT_FORMATTER__.format(new Date());
            egyptDateTimeBadges.forEach(function (egyptDateTimeBadge) {
                egyptDateTimeBadge.textContent = formattedDateTime;
            });
        };

        updateEgyptDateTimeDisplay();

        if (!window.__APP_UI_EGYPT_INTERVAL__) {
            window.__APP_UI_EGYPT_INTERVAL__ = window.setInterval(updateEgyptDateTimeDisplay, 1000);
        }
    }

    function enhanceDateInput(originalInput) {
        if (!originalInput || originalInput.dataset.rtlDateBound === "1") return;
        originalInput.dataset.rtlDateBound = "1";

        var hidden = document.createElement("input");
        hidden.type = "hidden";
        hidden.name = originalInput.name;
        hidden.value = originalInput.value || "";

        var visible = document.createElement("input");
        visible.type = "text";
        visible.placeholder = "يوم-شهر-سنة";
        visible.setAttribute("dir", "rtl");
        visible.setAttribute("inputmode", "numeric");
        visible.setAttribute("autocomplete", "off");
        visible.setAttribute("maxlength", "10");
        visible.value = isoToDdMmYyyy(originalInput.value);
        visible.className = originalInput.className;
        visible.id = originalInput.id;
        if (originalInput.required) visible.required = true;
        if (originalInput.disabled) visible.disabled = true;
        if (originalInput.readOnly) visible.readOnly = true;
        visible.style.direction = "rtl";
        visible.style.textAlign = "right";

        var parent = originalInput.parentNode;
        parent.insertBefore(hidden, originalInput);
        parent.insertBefore(visible, originalInput);
        originalInput.removeAttribute("name");
        originalInput.removeAttribute("id");
        originalInput.required = false;
        originalInput.disabled = true;
        originalInput.style.display = "none";

        visible.addEventListener("input", function () {
            visible.value = maskDdMmYyyy(visible.value);
            try { visible.setSelectionRange(visible.value.length, visible.value.length); } catch (e) {}
            hidden.value = ddMmYyyyToIso(visible.value);
        });

        visible.addEventListener("blur", function () {
            var iso = ddMmYyyyToIso(visible.value);
            hidden.value = iso;
            if (iso === "" && visible.value !== "" && visible.required) {
                visible.setCustomValidity("الرجاء إدخال تاريخ صحيح بصيغة يوم-شهر-سنة");
            } else {
                visible.setCustomValidity("");
            }
        });

        var form = visible.form;
        if (form && form.dataset.rtlDateFormBound !== "1") {
            form.dataset.rtlDateFormBound = "1";
            form.addEventListener("submit", function (ev) {
                var bad = false;
                form.querySelectorAll('input[type="text"][dir="rtl"]').forEach(function (vi) {
                    if (vi.required && vi.value.trim() === "") { bad = true; }
                    if (vi.value.trim() !== "" && ddMmYyyyToIso(vi.value) === "") {
                        vi.setCustomValidity("الرجاء إدخال تاريخ صحيح بصيغة يوم-شهر-سنة");
                        bad = true;
                    }
                });
                if (bad) { ev.preventDefault(); }
            });
        }
    }

    function ensureDateInputs() {
        document.querySelectorAll('input[type="date"]').forEach(enhanceDateInput);
    }

    window.__APP_UI_INIT__ = function () {
        ensureTheme();
        ensureSidebar();
        ensureEgyptClock();
        ensureDateInputs();
    };

    window.__APP_UI_BOOTSTRAPPED__ = true;

    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", window.__APP_UI_INIT__);
    } else {
        window.__APP_UI_INIT__();
    }

    document.addEventListener("realtime:refreshed", window.__APP_UI_INIT__);
})();
