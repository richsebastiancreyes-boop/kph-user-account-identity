// ================================================================
// KameraPH UI Kit | api.js (v2.0 — Bearer Session Token + Cross-Module Handoff)
// Provides:
//   - Auto-capture of ?token= from URL into localStorage("kph-auth-token")
//   - KPHAuth helper for saving/reading/clearing session token & user
//   - kphFetch(path, options), apiGet(path), apiSend(method, path, body)
//   - escapeHtml(str), formatPeso(amount), formatDate(dateStr)
// ================================================================

// Auto-capture ?token= from URL when arriving via a cross-module link
(function () {
  try {
    var params = new URLSearchParams(window.location.search);
    var urlToken = params.get("token");
    if (urlToken) {
      localStorage.setItem("kph-auth-token", urlToken);
      params.delete("token");
      var clean = window.location.pathname + (params.toString() ? "?" + params.toString() : "") + window.location.hash;
      window.history.replaceState({}, "", clean);
    }
  } catch (e) {}
})();

window.KPHAuth = {
  getToken: function () {
    try { return localStorage.getItem("kph-auth-token") || ""; } catch (e) { return ""; }
  },
  getUser: function () {
    try {
      var raw = localStorage.getItem("kph-user");
      if (raw) return JSON.parse(raw);
    } catch (e) {}
    return null;
  },
  getUserId: function () {
    var u = this.getUser();
    if (u && u.UserID) return u.UserID;
    return (window.KPH && window.KPH.testUserId) ? window.KPH.testUserId : "1";
  },
  saveSession: function (token, user) {
    try {
      if (token) {
        localStorage.setItem("kph-auth-token", token);
      }
      if (user) {
        localStorage.setItem("kph-user", JSON.stringify(user));
        if (user.Email) {
          localStorage.setItem("kph-demo-session", JSON.stringify(String(user.Email).trim().toLowerCase()));
        }
      }
    } catch (e) {}
  },
  clearSession: function () {
    try {
      localStorage.removeItem("kph-auth-token");
      localStorage.removeItem("kph-user");
      localStorage.removeItem("kph-demo-session");
    } catch (e) {}
  }
};

async function kphFetch(path, options = {}) {
  const headers = { "Content-Type": "application/json", ...(options.headers || {}) };

  const token = window.KPHAuth ? KPHAuth.getToken() : localStorage.getItem("kph-auth-token");
  if (token) {
    headers["Authorization"] = "Bearer " + token;
  } else if (window.KPH && window.KPH.testUserId) {
    headers["X-Test-User-Id"] = window.KPH.testUserId;
  }

  const base = (window.KPH && window.KPH.api) ? window.KPH.api.replace(/\/$/, "") : "../api";
  const url = path.startsWith("http") ? path : base + (path.startsWith("/") ? path : "/" + path);

  const res = await fetch(url, { ...options, headers });
  const data = await res.json().catch(() => ({}));
  if (!res.ok) {
    throw new Error(data.error || data.message || ("HTTP " + res.status));
  }
  return data;
}

async function apiGet(path) {
  return kphFetch(path, { method: "GET" });
}

async function apiSend(method, path, body) {
  const opts = { method: method };
  if (body !== undefined && body !== null) {
    opts.body = typeof body === "string" ? body : JSON.stringify(body);
  }
  return kphFetch(path, opts);
}

function escapeHtml(str) {
  return String(str == null ? "" : str)
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;")
    .replace(/'/g, "&#39;");
}

function formatPeso(amount) {
  const n = Number(amount || 0);
  return "₱" + n.toLocaleString("en-PH", { minimumFractionDigits: 0, maximumFractionDigits: 2 });
}

function formatDate(dateStr) {
  if (!dateStr) return "";
  const d = new Date(String(dateStr).replace(" ", "T"));
  if (isNaN(d)) return String(dateStr);
  return d.toLocaleDateString("en-PH", { year: "numeric", month: "short", day: "numeric" });
}
