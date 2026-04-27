(function () {
    "use strict";

    if (window.__PORTAL_LIVE_NOTIFICATIONS_CLIENT__) {
        return;
    }
    window.__PORTAL_LIVE_NOTIFICATIONS_CLIENT__ = true;

    var cfg = window.__PORTAL_LIVE_NOTIFICATIONS__ || {};
    if (!cfg || !cfg.endpoint || !cfg.storageKey) {
        return;
    }

    var pollIntervalMs = Math.max(7000, parseInt(cfg.pollIntervalMs, 10) || 10000);
    var reloadDelayMs = Math.max(800, parseInt(cfg.reloadDelayMs, 10) || 1400);
    var noticeDurationMs = Math.max(3500, parseInt(cfg.noticeDurationMs, 10) || 8000);
    var topOffsetPx = Math.max(56, parseInt(cfg.topOffsetPx, 10) || 88);
    var inFlight = false;
    var pollTimer = null;
    var pendingReload = false;
    var noticeTimer = null;
    var reloadTimer = null;
    var latestNotification = normalizeNotification(cfg.latestNotification);
    var lastKnownNotificationId = 0;

    function getStorage() {
        try {
            if (window.localStorage) {
                return window.localStorage;
            }
        } catch (e) {}
        try {
            if (window.sessionStorage) {
                return window.sessionStorage;
            }
        } catch (e) {}
        return null;
    }

    var storage = getStorage();

    function readStoredId() {
        if (!storage) {
            return 0;
        }
        var rawValue = storage.getItem(cfg.storageKey) || "0";
        var value = parseInt(rawValue, 10);
        return isNaN(value) ? 0 : value;
    }

    function writeStoredId(notificationId) {
        if (!storage || !notificationId) {
            return;
        }
        storage.setItem(cfg.storageKey, String(notificationId));
    }

    function normalizeText(value) {
        return typeof value === "string" ? value.trim() : "";
    }

    function buildEndpointUrl() {
        return cfg.endpoint + (cfg.endpoint.indexOf("?") === -1 ? "?" : "&") + "_t=" + Date.now();
    }

    function normalizeNotification(notification) {
        if (!notification || typeof notification !== "object") {
            return null;
        }
        var id = parseInt(notification.id, 10);
        if (isNaN(id) || id <= 0) {
            return null;
        }
        return {
            id: id,
            title: normalizeText(notification.title),
            message: normalizeText(notification.message)
        };
    }

    function resolveTitle(notification) {
        return normalizeText(notification && notification.title) || "📣 إشعار جديد";
    }

    function resolveMessage(notification) {
        return normalizeText(notification && notification.message);
    }

    function syncAndroidState(notification) {
        if (!window.AndroidBridge || typeof window.AndroidBridge.syncPortalState !== "function") {
            return;
        }
        try {
            window.AndroidBridge.syncPortalState(
                cfg.sessionKey || "",
                notification && notification.id ? String(notification.id) : ""
            );
        } catch (e) {}
    }

    function notifyAndroid(notification) {
        if (!notification || !window.AndroidBridge || typeof window.AndroidBridge.showNotification !== "function") {
            return;
        }
        try {
            window.AndroidBridge.showNotification(resolveTitle(notification), resolveMessage(notification));
        } catch (e) {}
    }

    function ensureDynamicNoticeStyles() {
        if (document.getElementById("portal-live-notice-style")) {
            return;
        }
        var style = document.createElement("style");
        style.id = "portal-live-notice-style";
        style.textContent =
            ".portal-live-notice{position:fixed;top:" + topOffsetPx + "px;left:24px;width:min(380px,calc(100vw - 24px));" +
            "background:linear-gradient(135deg,#0f172a,#1e293b);color:#fff;border-radius:20px;padding:18px 18px 16px;" +
            "box-shadow:0 22px 50px rgba(15,23,42,.28);z-index:1400;opacity:0;transform:translateY(-12px) scale(.98);" +
            "transition:opacity .25s ease,transform .25s ease;pointer-events:none}" +
            ".portal-live-notice.show{opacity:1;transform:translateY(0) scale(1);pointer-events:auto}" +
            ".portal-live-notice-h{display:flex;align-items:flex-start;justify-content:space-between;gap:10px;margin-bottom:10px}" +
            ".portal-live-notice-title{font-size:1rem;font-weight:800;line-height:1.7}" +
            ".portal-live-notice-close{border:0;background:rgba(255,255,255,.14);color:#fff;border-radius:999px;width:34px;height:34px;cursor:pointer;font-size:1rem}" +
            ".portal-live-notice-msg{font-size:.92rem;line-height:1.85;white-space:pre-line;color:rgba(255,255,255,.92)}" +
            ".portal-live-notice-actions{display:flex;justify-content:flex-end;gap:10px;margin-top:14px}" +
            ".portal-live-notice-btn{display:inline-flex;align-items:center;justify-content:center;border:0;border-radius:999px;padding:10px 16px;" +
            "background:#38bdf8;color:#082f49;font-weight:800;text-decoration:none;cursor:pointer}" +
            "@media (max-width: 768px){.portal-live-notice{top:76px;left:12px;width:calc(100vw - 24px)}}";
        document.head.appendChild(style);
    }

    function buildDynamicNotice() {
        ensureDynamicNoticeStyles();
        var notice = document.getElementById("portalLiveNotice");
        if (notice) {
            return {
                container: notice,
                title: document.getElementById("portalLiveNoticeTitle"),
                message: document.getElementById("portalLiveNoticeMessage"),
                close: document.getElementById("portalLiveNoticeClose"),
                action: document.getElementById("portalLiveNoticeAction")
            };
        }

        notice = document.createElement("div");
        notice.className = "portal-live-notice";
        notice.id = "portalLiveNotice";
        notice.setAttribute("aria-live", "polite");
        notice.setAttribute("aria-atomic", "true");
        notice.innerHTML =
            '<div class="portal-live-notice-h">' +
            '<div class="portal-live-notice-title" id="portalLiveNoticeTitle">📣 إشعار جديد</div>' +
            '<button type="button" class="portal-live-notice-close" id="portalLiveNoticeClose" aria-label="إغلاق">✕</button>' +
            "</div>" +
            '<div class="portal-live-notice-msg" id="portalLiveNoticeMessage"></div>' +
            '<div class="portal-live-notice-actions"><button type="button" class="portal-live-notice-btn" id="portalLiveNoticeAction">عرض الإشعارات</button></div>';
        document.body.appendChild(notice);

        return {
            container: notice,
            title: document.getElementById("portalLiveNoticeTitle"),
            message: document.getElementById("portalLiveNoticeMessage"),
            close: document.getElementById("portalLiveNoticeClose"),
            action: document.getElementById("portalLiveNoticeAction")
        };
    }

    function resolveNoticeElements() {
        if (
            cfg.notice &&
            cfg.notice.containerId &&
            cfg.notice.titleId &&
            cfg.notice.messageId &&
            cfg.notice.closeId
        ) {
            return {
                container: document.getElementById(cfg.notice.containerId),
                title: document.getElementById(cfg.notice.titleId),
                message: document.getElementById(cfg.notice.messageId),
                close: document.getElementById(cfg.notice.closeId),
                action: cfg.notice.actionSelector ? document.querySelector(cfg.notice.actionSelector) : null
            };
        }

        return buildDynamicNotice();
    }

    var noticeEls = resolveNoticeElements();

    function hideNotice() {
        if (!noticeEls.container) {
            return;
        }
        noticeEls.container.classList.remove("show");
    }

    function openNotificationView() {
        if (cfg.notificationTab) {
            var tabBtn = document.querySelector('.portal-tab-btn[data-tab="' + cfg.notificationTab + '"]');
            if (tabBtn) {
                tabBtn.click();
                return;
            }
        }
        if (cfg.noticeLinkHref) {
            window.location.href = cfg.noticeLinkHref;
        }
    }

    function bindNoticeControls() {
        if (noticeEls.close && !noticeEls.close.__portalLiveBound) {
            noticeEls.close.__portalLiveBound = true;
            noticeEls.close.addEventListener("click", hideNotice);
        }
        if (noticeEls.action && !noticeEls.action.__portalLiveBound && (cfg.notificationTab || cfg.noticeLinkHref)) {
            noticeEls.action.__portalLiveBound = true;
            noticeEls.action.addEventListener("click", function (event) {
                if (cfg.notificationTab) {
                    event.preventDefault();
                }
                openNotificationView();
            });
        }
    }

    bindNoticeControls();

    function showNotice(notification) {
        if (!noticeEls.container || !noticeEls.title || !noticeEls.message) {
            return;
        }
        if (noticeTimer) {
            clearTimeout(noticeTimer);
        }
        noticeEls.title.textContent = resolveTitle(notification);
        noticeEls.message.textContent = resolveMessage(notification);
        noticeEls.container.classList.add("show");
        noticeTimer = setTimeout(hideNotice, noticeDurationMs);
    }

    function isInteractive() {
        var openDialog = document.querySelector(
            'dialog[open], .modal.show, .modal.in, .swal2-container, .sweet-alert.showSweetAlert, [aria-modal="true"]'
        );
        if (openDialog) {
            return true;
        }

        var activeElement = document.activeElement;
        if (!activeElement) {
            return false;
        }
        var tagName = (activeElement.tagName || "").toUpperCase();
        if (tagName === "INPUT" || tagName === "TEXTAREA" || tagName === "SELECT") {
            var inputType = (activeElement.type || "").toLowerCase();
            if (inputType !== "button" && inputType !== "submit" && inputType !== "reset") {
                return true;
            }
        }
        return !!activeElement.isContentEditable;
    }

    function reloadPortalPage() {
        if (window.AndroidBridge && typeof window.AndroidBridge.reloadPortal === "function") {
            window.AndroidBridge.reloadPortal();
            return;
        }
        window.location.reload();
    }

    function scheduleReload() {
        if (isInteractive()) {
            pendingReload = true;
            return;
        }
        pendingReload = false;
        if (reloadTimer) {
            clearTimeout(reloadTimer);
        }
        reloadTimer = window.setTimeout(reloadPortalPage, reloadDelayMs);
    }

    function handleFreshNotification(notification) {
        latestNotification = notification;
        lastKnownNotificationId = notification.id;
        writeStoredId(notification.id);
        syncAndroidState(notification);
        showNotice(notification);
        notifyAndroid(notification);
        scheduleReload();
    }

    function poll() {
        if (inFlight || document.hidden) {
            return;
        }
        inFlight = true;
        fetch(buildEndpointUrl(), {
            method: "GET",
            credentials: "same-origin",
            cache: "no-store",
            headers: { "Accept": "application/json" }
        })
            .then(function (response) {
                if (response.status === 401) {
                    stop();
                    return null;
                }
                if (!response.ok) {
                    throw new Error("HTTP " + response.status);
                }
                return response.json();
            })
            .then(function (payload) {
                if (!payload) {
                    return;
                }
                if (typeof payload.session_key === "string" && payload.session_key.trim() !== "") {
                    cfg.sessionKey = payload.session_key.trim();
                }
                var notification = normalizeNotification(payload.notification);
                syncAndroidState(notification || latestNotification);
                if (!notification) {
                    return;
                }

                var storedId = readStoredId();
                var baselineId = Math.max(lastKnownNotificationId, storedId);
                if (notification.id > baselineId) {
                    handleFreshNotification(notification);
                    return;
                }

                latestNotification = notification;
                lastKnownNotificationId = Math.max(baselineId, notification.id);
            })
            .catch(function () {})
            .then(function () {
                inFlight = false;
            });
    }

    function stop() {
        if (pollTimer) {
            clearInterval(pollTimer);
            pollTimer = null;
        }
    }

    function markActivity() {
        if (pendingReload && !isInteractive()) {
            scheduleReload();
        }
    }

    function start() {
        var storedId = readStoredId();
        lastKnownNotificationId = Math.max(storedId, latestNotification ? latestNotification.id : 0);
        syncAndroidState(latestNotification);

        if (
            cfg.showInitialLatest === true &&
            latestNotification &&
            latestNotification.id &&
            latestNotification.id > storedId
        ) {
            showNotice(latestNotification);
            notifyAndroid(latestNotification);
            writeStoredId(latestNotification.id);
            lastKnownNotificationId = latestNotification.id;
        } else if (latestNotification && latestNotification.id > storedId) {
            writeStoredId(latestNotification.id);
        }

        poll();
        pollTimer = setInterval(poll, pollIntervalMs);
    }

    document.addEventListener("visibilitychange", function () {
        if (!document.hidden) {
            poll();
            markActivity();
        }
    });
    document.addEventListener("focusout", markActivity, true);
    document.addEventListener("click", markActivity, true);
    document.addEventListener("keyup", markActivity, true);

    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", start);
    } else {
        start();
    }
})();
