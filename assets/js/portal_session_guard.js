(function () {
    "use strict";

    var cfg = window.__PORTAL_SESSION_GUARD__ || {};
    if (!cfg.key) {
        return;
    }

    var storageKey = "portal-session-guard:" + cfg.key;

    function normalizePath(path) {
        if (typeof path !== "string" || path === "") {
            return "";
        }
        return path.split("?")[0].split("#")[0];
    }

    function redirect(url) {
        if (typeof url !== "string" || url === "") {
            return;
        }
        window.location.replace(url);
    }

    function markActive() {
        try {
            window.sessionStorage.setItem(storageKey, "active");
        } catch (e) {}
    }

    function clearActive() {
        try {
            window.sessionStorage.removeItem(storageKey);
        } catch (e) {}
    }

    function isActive() {
        try {
            return window.sessionStorage.getItem(storageKey) === "active";
        } catch (e) {
            return false;
        }
    }

    function bindLogoutLinks() {
        if (!cfg.logoutUrl) {
            return;
        }

        document.addEventListener("click", function (event) {
            var link = event.target && event.target.closest ? event.target.closest("a[href]") : null;
            if (!link) {
                return;
            }

            var href = normalizePath(link.getAttribute("href"));
            if (href === normalizePath(cfg.logoutUrl)) {
                clearActive();
            }
        }, true);
    }

    if (cfg.mode === "protected") {
        markActive();
        bindLogoutLinks();

        window.addEventListener("pageshow", function () {
            if (!isActive()) {
                redirect(cfg.loginUrl || "");
                return;
            }
            markActive();
        });

        document.addEventListener("realtime:refreshed", function () {
            markActive();
        });

        return;
    }

    if (cfg.mode === "login") {
        var bounceToHome = function () {
            if (isActive()) {
                redirect(cfg.homeUrl || "");
            }
        };

        if (document.readyState === "loading") {
            document.addEventListener("DOMContentLoaded", bounceToHome);
        } else {
            bounceToHome();
        }

        window.addEventListener("pageshow", bounceToHome);
        return;
    }

    if (cfg.mode === "logout") {
        clearActive();
        redirect(cfg.loginUrl || "");
    }
})();
