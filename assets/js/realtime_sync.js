(function () {
    "use strict";

    if (window.__REALTIME_SYNC_CLIENT__) {
        return;
    }
    window.__REALTIME_SYNC_CLIENT__ = true;

    var cfg = window.__REALTIME_SYNC_CONFIG__ || {};
    var endpoint = cfg.endpoint || "realtime_check.php";
    var intervalMs = Math.max(2000, parseInt(cfg.intervalMs, 10) || 5000);
    var rootSelector = typeof cfg.rootSelector === "string" && cfg.rootSelector !== "" ? cfg.rootSelector : ".dashboard-layout";
    var watch = cfg.watch;
    var tablesParam = "*";
    if (Array.isArray(watch) && watch.length > 0) {
        tablesParam = watch.join(",");
    } else if (typeof watch === "string" && watch !== "") {
        tablesParam = watch;
    }

    var lastId = -1;          // -1 means "ask for baseline"
    var pollTimer = null;
    var inFlight = false;
    var consecutiveErrors = 0;
    var pendingRefresh = false;
    var refreshInFlight = false;

    function buildUrl() {
        var url = endpoint + "?since_id=" + encodeURIComponent(lastId) + "&tables=" + encodeURIComponent(tablesParam);
        url += "&_t=" + Date.now();
        return url;
    }

    function isInteractive() {
        // Don't disturb users while a modal/dialog is open.
        var openDialog = document.querySelector(
            'dialog[open], .modal.show, .modal.in, .swal2-container, .sweet-alert.showSweetAlert, .ui-dialog:visible, [aria-modal="true"]'
        );
        if (openDialog) {
            return true;
        }

        // Don't disturb mid-typing or active form interaction.
        var ae = document.activeElement;
        if (ae) {
            var tag = (ae.tagName || "").toUpperCase();
            if (tag === "INPUT" || tag === "TEXTAREA" || tag === "SELECT") {
                var type = (ae.type || "").toLowerCase();
                if (type !== "button" && type !== "submit" && type !== "reset") {
                    return true;
                }
            }
            if (ae.isContentEditable) {
                return true;
            }
        }

        // Don't disturb during text selection.
        var sel = window.getSelection ? window.getSelection() : null;
        if (sel && sel.toString && sel.toString().length > 0) {
            return true;
        }

        // Don't disturb during file uploads in flight.
        if (document.querySelector("form[data-uploading=\"true\"]")) {
            return true;
        }

        return false;
    }

    function ensureBadgeStyles() {
        if (document.getElementById("rt-sync-style")) {
            return;
        }
        var style = document.createElement("style");
        style.id = "rt-sync-style";
        style.textContent =
            "#rt-sync-badge{position:fixed;bottom:18px;left:18px;z-index:99999;" +
            "background:#0f766e;color:#fff;padding:10px 14px;border-radius:10px;" +
            "font-family:inherit;font-size:14px;box-shadow:0 8px 24px rgba(0,0,0,.18);" +
            "cursor:pointer;display:none;direction:rtl;align-items:center;gap:8px;" +
            "transition:transform .2s ease, opacity .2s ease;opacity:0;transform:translateY(8px)}" +
            "#rt-sync-badge.show{display:inline-flex;opacity:1;transform:translateY(0)}" +
            "#rt-sync-badge .rt-dot{width:8px;height:8px;border-radius:50%;background:#fde68a;" +
            "box-shadow:0 0 0 0 rgba(253,230,138,.7);animation:rtPulse 1.6s infinite}" +
            "@keyframes rtPulse{0%{box-shadow:0 0 0 0 rgba(253,230,138,.7)}" +
            "70%{box-shadow:0 0 0 10px rgba(253,230,138,0)}" +
            "100%{box-shadow:0 0 0 0 rgba(253,230,138,0)}}" +
            "#rt-sync-toast{position:fixed;bottom:18px;left:18px;z-index:99999;" +
            "background:#1e293b;color:#fff;padding:10px 14px;border-radius:10px;" +
            "font-family:inherit;font-size:13px;box-shadow:0 8px 24px rgba(0,0,0,.18);" +
            "direction:rtl;display:none;opacity:0;transition:opacity .2s ease}" +
            "#rt-sync-toast.show{display:block;opacity:1}";
        document.head.appendChild(style);
    }

    function ensureBadge() {
        ensureBadgeStyles();
        var badge = document.getElementById("rt-sync-badge");
        if (badge) {
            return badge;
        }
        badge = document.createElement("div");
        badge.id = "rt-sync-badge";
        badge.setAttribute("role", "button");
        badge.setAttribute("tabindex", "0");
        badge.innerHTML = '<span class="rt-dot"></span><span>تحديثات جديدة • اضغط للتحديث الآن</span>';
        badge.addEventListener("click", function () {
            performRefresh(true);
        });
        badge.addEventListener("keydown", function (e) {
            if (e.key === "Enter" || e.key === " ") {
                e.preventDefault();
                performRefresh(true);
            }
        });
        document.body.appendChild(badge);
        return badge;
    }

    function showBadge() {
        var badge = ensureBadge();
        badge.classList.add("show");
    }

    function hideBadge() {
        var badge = document.getElementById("rt-sync-badge");
        if (badge) {
            badge.classList.remove("show");
        }
    }

    function showToast(text) {
        ensureBadgeStyles();
        var toast = document.getElementById("rt-sync-toast");
        if (!toast) {
            toast = document.createElement("div");
            toast.id = "rt-sync-toast";
            document.body.appendChild(toast);
        }
        toast.textContent = text;
        toast.classList.add("show");
        setTimeout(function () {
            toast.classList.remove("show");
        }, 1200);
    }

    function findRefreshRoot(doc) {
        var selectors = [];
        if (rootSelector) {
            selectors.push(rootSelector);
        }
        selectors.push(".dashboard-layout", ".portal-root", ".pp-shell", "main.main-content");

        for (var i = 0; i < selectors.length; i++) {
            var selector = selectors[i];
            if (typeof selector !== "string" || selector === "") {
                continue;
            }
            var node = doc.querySelector(selector);
            if (node) {
                return node;
            }
        }

        return null;
    }

    function isRealtimeNode(node) {
        if (!node || node.nodeType !== 1) {
            return false;
        }
        if (node.id === "rt-sync-badge" || node.id === "rt-sync-toast") {
            return true;
        }
        if (node.getAttribute("data-realtime-sync")) {
            return true;
        }
        if (node.tagName === "SCRIPT" && /assets\/js\/realtime_sync\.js(?:\?|$)/.test(node.getAttribute("src") || "")) {
            return true;
        }
        return false;
    }

    function syncBodyAttributes(sourceBody) {
        Array.prototype.slice.call(document.body.attributes).forEach(function (attr) {
            if (attr.name === "class") {
                return;
            }
            document.body.removeAttribute(attr.name);
        });
        Array.prototype.slice.call(sourceBody.attributes).forEach(function (attr) {
            document.body.setAttribute(attr.name, attr.value);
        });
        document.body.className = sourceBody.className || "";
    }

    function isExecutableScript(node) {
        if (!node || node.tagName !== "SCRIPT") {
            return false;
        }
        if (isRealtimeNode(node)) {
            return false;
        }
        var type = (node.getAttribute("type") || "").trim().toLowerCase();
        if (type === "" || type === "text/javascript" || type === "application/javascript" || type === "module") {
            return true;
        }
        return false;
    }

    function replaceScriptNode(scriptNode) {
        return new Promise(function (resolve) {
            if (!scriptNode || !scriptNode.parentNode) {
                resolve();
                return;
            }

            var replacement = document.createElement("script");
            Array.prototype.slice.call(scriptNode.attributes).forEach(function (attr) {
                replacement.setAttribute(attr.name, attr.value);
            });
            replacement.async = false;

            if (scriptNode.src) {
                replacement.onload = resolve;
                replacement.onerror = resolve;
                replacement.src = scriptNode.src;
            } else {
                replacement.text = scriptNode.textContent || "";
            }

            scriptNode.parentNode.replaceChild(replacement, scriptNode);

            if (!scriptNode.src) {
                resolve();
            }
        });
    }

    function runScriptsSequentially(nodes, index) {
        if (index >= nodes.length) {
            return Promise.resolve();
        }
        return replaceScriptNode(nodes[index]).then(function () {
            return runScriptsSequentially(nodes, index + 1);
        });
    }

    function refreshMarkup() {
        if (refreshInFlight) {
            return Promise.resolve();
        }
        refreshInFlight = true;

        var requestUrl = window.location.href;
        requestUrl += (requestUrl.indexOf("?") === -1 ? "?" : "&") + "_rt_refresh=" + Date.now();
        var scrollX = window.scrollX || window.pageXOffset || 0;
        var scrollY = window.scrollY || window.pageYOffset || 0;

        return fetch(requestUrl, {
            method: "GET",
            credentials: "same-origin",
            cache: "no-store",
            headers: {
                "Accept": "text/html",
                "X-Requested-With": "XMLHttpRequest"
            }
        })
            .then(function (response) {
                if (!response.ok) {
                    throw new Error("HTTP " + response.status);
                }
                return response.text();
            })
            .then(function (html) {
                var parser = new DOMParser();
                var nextDoc = parser.parseFromString(html, "text/html");
                var currentRoot = findRefreshRoot(document);
                var nextRoot = findRefreshRoot(nextDoc);

                if (!currentRoot || !nextRoot) {
                    throw new Error("refresh_root_missing");
                }

                document.title = nextDoc.title || document.title;
                syncBodyAttributes(nextDoc.body);

                var currentRealtimeAnchor = document.querySelector('script[data-realtime-sync="config"]')
                    || document.querySelector('script[data-realtime-sync="client"]');
                var nextSiblings = [];
                var nextSibling = nextRoot.nextSibling;
                while (nextSibling) {
                    nextSiblings.push(nextSibling);
                    nextSibling = nextSibling.nextSibling;
                }

                var currentSibling = currentRoot.nextSibling;
                while (currentSibling) {
                    var nodeToRemove = currentSibling;
                    currentSibling = currentSibling.nextSibling;
                    if (!isRealtimeNode(nodeToRemove)) {
                        nodeToRemove.parentNode.removeChild(nodeToRemove);
                    }
                }

                currentRoot.replaceWith(document.importNode(nextRoot, true));

                var executableScripts = [];
                nextSiblings.forEach(function (node) {
                    if (node.nodeType === 1 && isRealtimeNode(node)) {
                        return;
                    }

                    var importedNode = document.importNode(node, true);
                    if (currentRealtimeAnchor && currentRealtimeAnchor.parentNode) {
                        currentRealtimeAnchor.parentNode.insertBefore(importedNode, currentRealtimeAnchor);
                    } else {
                        document.body.appendChild(importedNode);
                    }

                    if (importedNode.nodeType === 1 && isExecutableScript(importedNode)) {
                        executableScripts.push(importedNode);
                    }
                });

                return runScriptsSequentially(executableScripts, 0).then(function () {
                    document.dispatchEvent(new Event("DOMContentLoaded", { bubbles: true }));
                    document.dispatchEvent(new Event("realtime:refreshed", { bubbles: true }));
                    window.scrollTo(scrollX, scrollY);
                });
            })
            .finally(function () {
                refreshInFlight = false;
            });
    }

    function performRefresh(force) {
        if (refreshInFlight) {
            return;
        }
        if (!force && isInteractive()) {
            pendingRefresh = true;
            showBadge();
            return;
        }
        showToast("جاري تحديث البيانات…");
        setTimeout(function () {
            refreshMarkup()
                .then(function () {
                    pendingRefresh = false;
                    hideBadge();
                })
                .catch(function () {
                    pendingRefresh = true;
                    showBadge();
                });
        }, 250);
    }

    function tryDeferredRefresh() {
        if (pendingRefresh && !isInteractive()) {
            pendingRefresh = false;
            hideBadge();
            performRefresh(false);
        }
    }

    function poll() {
        if (inFlight) {
            return;
        }
        if (document.hidden) {
            // Skip while tab is hidden; we'll catch up on visibility change.
            return;
        }
        inFlight = true;
        var url = buildUrl();
        var controller = ("AbortController" in window) ? new AbortController() : null;
        var timeoutId = setTimeout(function () {
            if (controller) {
                controller.abort();
            }
        }, 8000);

        var opts = {
            method: "GET",
            credentials: "same-origin",
            cache: "no-store",
            headers: { "Accept": "application/json" }
        };
        if (controller) {
            opts.signal = controller.signal;
        }

        fetch(url, opts)
            .then(function (r) {
                clearTimeout(timeoutId);
                if (r.status === 401) {
                    // Session expired — stop polling silently; the next user action
                    // will redirect them to the login page anyway.
                    stop();
                    return null;
                }
                if (!r.ok) {
                    throw new Error("HTTP " + r.status);
                }
                return r.json();
            })
            .then(function (data) {
                inFlight = false;
                consecutiveErrors = 0;
                if (!data) {
                    return;
                }
                var newLastId = parseInt(data.last_id, 10);
                if (isNaN(newLastId)) {
                    return;
                }
                if (lastId < 0) {
                    // Baseline. Just remember the head.
                    lastId = newLastId;
                    return;
                }
                if (newLastId > lastId) {
                    lastId = newLastId;
                    if (data.changed) {
                        performRefresh(false);
                    }
                }
            })
            .catch(function () {
                clearTimeout(timeoutId);
                inFlight = false;
                consecutiveErrors++;
                // Soft back-off on repeated errors (network blip, etc.).
            });
    }

    function start() {
        if (pollTimer) {
            return;
        }
        // Kick off a baseline immediately, then start interval.
        poll();
        pollTimer = setInterval(function () {
            // Light back-off after errors: skip every other tick after 3+ consecutive errors.
            if (consecutiveErrors >= 3 && (consecutiveErrors % 2 === 0)) {
                consecutiveErrors++;
                return;
            }
            poll();
        }, intervalMs);
    }

    function stop() {
        if (pollTimer) {
            clearInterval(pollTimer);
            pollTimer = null;
        }
    }

    function markActivity() {
        if (pendingRefresh) {
            setTimeout(tryDeferredRefresh, 600);
        }
    }

    document.addEventListener("visibilitychange", function () {
        if (!document.hidden) {
            poll();
        }
    });

    document.addEventListener("focusout", markActivity, true);
    document.addEventListener("click", markActivity, true);
    document.addEventListener("keyup", markActivity, true);

    setInterval(tryDeferredRefresh, 1500);

    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", start);
    } else {
        start();
    }
})();
