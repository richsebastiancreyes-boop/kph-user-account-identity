// ==========================================================
// KameraPH UI Kit  |  demo.js   (YOUR file, not part of the kit)
//
// A PRETEND backend for the UI-first stage (no API, no database).
// Accounts, logins, Google/Facebook sign-in, 6-digit codes, password reset
// links and everything an account saves (wishlist, cart dot, addresses,
// profile edits, ID status) are remembered in THIS browser only.
//
// NOT SECURE: passwords are kept as plain text in the browser so the demo can
// check them. Never use real passwords here. When the real API is ready, delete
// this file and use the real endpoints instead.
// ==========================================================
const KPHDemo = (function () {
  const ACCOUNTS = "kph-demo-accounts";     // every registered account            (table: user)
  const SESSION = "kph-demo-session";       // the email of whoever is logged in
  const IDENTITIES = "kph-demo-identities"; // Google / Facebook links             (table: auth_identity)
  const RESETS = "kph-demo-resets";         // password reset tokens               (table: verification_token)
  const CODE_MINUTES = 10;                  // a 6-digit code works for 10 minutes
  const RESET_MINUTES = 15;                 // a reset link works for 15 minutes, once
  const MAX_TRIES = 5;                      // wrong tries allowed for one code

  function read(key, fallback) {
    try { const s = localStorage.getItem(key); return s ? JSON.parse(s) : fallback; } catch (e) { return fallback; }
  }
  function write(key, value) { try { localStorage.setItem(key, JSON.stringify(value)); } catch (e) { /* storage not available */ } }
  function remove(key) { try { localStorage.removeItem(key); } catch (e) { /* storage not available */ } }

  function norm(email) { return String(email || "").trim().toLowerCase(); }
  function me() {
    const apiUser = read("kph-user", null);
    if (apiUser && apiUser.Email) return norm(apiUser.Email);
    const s = read(SESSION, null);
    if (s && typeof s === "object" && s.Email) return norm(s.Email);
    return typeof s === "string" ? s : null;
  }
  // Each account keeps its own data under its own email
  function key(name) { return "kph-demo:" + (me() || "guest") + ":" + name; }
  function accounts() { return read(ACCOUNTS, []); }
  function find(email) { return accounts().find(function (a) { return norm(a.Email) === norm(email); }); }

  // Phone numbers: "0917 111 2222", "+63 917 111 2222" and "09171112222" are the same number
  function normPhone(p) {
    let d = String(p || "").replace(/\D/g, "");
    if (d.indexOf("63") === 0 && d.length >= 12) d = "0" + d.slice(2);
    return d;
  }
  function findByPhone(p) {
    const n = normPhone(p);
    return n ? accounts().find(function (a) { return normPhone(a.PhoneNumber) === n; }) : undefined;
  }
  // Log in with an email OR a phone number
  function findByIdentifier(id) {
    const s = String(id || "").trim();
    return s.indexOf("@") >= 0 ? find(s) : findByPhone(s);
  }

  function updateAccount(email, changes) {
    const list = accounts();
    const i = list.findIndex(function (a) { return norm(a.Email) === norm(email); });
    if (i < 0) return false;
    Object.assign(list[i], changes);
    write(ACCOUNTS, list);
    return true;
  }

  // "Maria Reyes" -> "maria_reyes"  (falls back to the start of the email if the name has no letters or numbers)
  function nameToUsername(first, last, email) {
    const u = [first, last].join(" ").toLowerCase()
      .normalize("NFD").replace(/[̀-ͯ]/g, "")      // José -> jose
      .replace(/[^a-z0-9]+/g, "_").replace(/^_+|_+$/g, "");   // spaces and symbols -> one underscore
    return u || String(email || "").split("@")[0];
  }
  function now() { return new Date().toISOString().slice(0, 19).replace("T", " "); }

  function randomCode() {
    const a = new Uint32Array(1); crypto.getRandomValues(a);
    return String(a[0] % 1000000).padStart(6, "0");
  }
  function randomToken() {
    const a = new Uint8Array(16); crypto.getRandomValues(a);
    return Array.prototype.map.call(a, function (b) { return b.toString(16).padStart(2, "0"); }).join("");
  }
  function maskEmail(e) {
    const p = String(e || "").split("@");
    return p[0] ? p[0].charAt(0) + "***@" + (p[1] || "") : "";
  }
  function maskPhone(ph) {
    const s = String(ph || "").replace(/\s/g, "");
    return s ? "*".repeat(Math.max(s.length - 2, 0)) + s.slice(-2) : "";
  }

  // A short label for this browser, for the login history ("Chrome", "Firefox" ...)
  function browserName() {
    const u = navigator.userAgent;
    return /Edg\//.test(u) ? "Edge" : /OPR\//.test(u) ? "Opera" : /Firefox\//.test(u) ? "Firefox" :
      /Chrome\//.test(u) ? "Chrome" : /Safari\//.test(u) ? "Safari" : "Browser";
  }
  // Remembers a login (newest first, the last 20). Call it AFTER the session is set.
  function logLogin(method) {
    const list = read(key("logins"), []);
    list.unshift({ At: new Date().toISOString(), Method: method, Browser: browserName() });
    write(key("logins"), list.slice(0, 20));
  }

  // Makes a new account. The username starts as the person's name (a number is added if it is taken).
  function createAccount(data, extra) {
    const list = accounts();
    const nextId = list.reduce(function (m, a) { return Math.max(m, a.UserID || 0); }, 0) + 1;
    const wanted = nameToUsername(data.FirstName, data.LastName, data.Email);
    let username = wanted, n = 1;
    while (list.some(function (a) { return a.Username === username; })) { n++; username = wanted + n; }
    const account = Object.assign({
      UserID: nextId, Username: username, FirstName: data.FirstName, MiddleName: data.MiddleName || "", LastName: data.LastName,
      Email: String(data.Email).trim(), PhoneNumber: data.PhoneNumber, Password: data.Password || "",
      EmailVerifiedAt: null, PhoneVerifiedAt: null, DateJoined: now()
    }, extra || {});
    list.push(account);
    write(ACCOUNTS, list);
    return account;
  }

  const api = {
    // ===== accounts and login =====
    isLoggedIn: function () {
      if (localStorage.getItem("kph-auth-token")) return true;
      return !!(me() && find(me()));
    },

    register: function (data) {
      if (find(data.Email)) return { ok: false, error: "This email is already registered. Please log in." };
      if (findByPhone(data.PhoneNumber)) return { ok: false, error: "This phone number is already registered. Please log in." };
      const a = createAccount(data);
      write(SESSION, norm(a.Email));
      logLogin("Registered");
      return { ok: true };
    },

    // identifier = an email OR a phone number
    login: function (identifier, password) {
      const a = findByIdentifier(identifier);
      if (!a) return { ok: false, error: "No account found with this email or phone number. Please sign up first." };
      if (!a.Password) return { ok: false, error: "This account signs in with Google or Facebook. Use that button, or reset your password to set one." };
      if (a.Password !== password) return { ok: false, error: "Wrong password." };
      write(SESSION, norm(a.Email));
      logLogin(String(identifier).indexOf("@") >= 0 ? "Email and password" : "Phone and password");
      return { ok: true };
    },

    // ===== Google and Facebook =====
    // provider: "Google" or "Facebook".  person: { FirstName, LastName, Email, PhoneNumber }
    // Same person again -> the same account. Same email as an existing account -> the two are linked.
    socialLogin: function (provider, person) {
      const providerUserId = provider + ":" + norm(person.Email);
      const ids = read(IDENTITIES, []);
      const linked = ids.find(function (i) { return i.Provider === provider && i.ProviderUserID === providerUserId; });
      let a = linked ? find(linked.Email) : find(person.Email);
      let isNew = false;

      if (!a) {
        if (findByPhone(person.PhoneNumber)) return { ok: false, error: "This phone number is already used by another account." };
        a = createAccount(person, { EmailVerifiedAt: now() });   // the provider has already confirmed the email
        isNew = true;
      } else if (!a.EmailVerifiedAt) {
        updateAccount(a.Email, { EmailVerifiedAt: now() });
      }
      if (!linked) {
        ids.push({ Provider: provider, ProviderUserID: providerUserId, Email: a.Email, LinkedAt: now() });
        write(IDENTITIES, ids);
      }
      write(SESSION, norm(a.Email));
      logLogin(provider);
      return { ok: true, isNew: isNew };
    },

    // ===== password reset (a token that expires and works only once) =====
    // identifier = an email OR a phone number. Returns { token } only when an account exists.
    requestReset: function (identifier) {
      const a = findByIdentifier(identifier);
      if (!a) return { found: false };
      // a new request cancels the older links
      const list = read(RESETS, []).map(function (r) {
        return norm(r.Email) === norm(a.Email) && !r.UsedAt ? Object.assign(r, { UsedAt: "cancelled" }) : r;
      });
      const token = randomToken();
      list.push({ Token: token, Email: a.Email, ExpiresAt: Date.now() + RESET_MINUTES * 60000, UsedAt: null });
      write(RESETS, list);
      return { found: true, token: token, minutes: RESET_MINUTES };
    },

    // { ok: true } or { ok: false, reason: "invalid" | "expired" | "used" }
    checkResetToken: function (token) {
      const r = read(RESETS, []).find(function (x) { return x.Token === token; });
      if (!token || !r) return { ok: false, reason: "invalid" };
      if (r.UsedAt) return { ok: false, reason: "used" };
      if (Date.now() > r.ExpiresAt) return { ok: false, reason: "expired" };
      return { ok: true };
    },

    resetPassword: function (token, newPassword) {
      const check = this.checkResetToken(token);
      if (!check.ok) return check;
      const list = read(RESETS, []);
      const r = list.find(function (x) { return x.Token === token; });
      updateAccount(r.Email, { Password: newPassword });
      r.UsedAt = now();                 // works only once
      write(RESETS, list);
      return { ok: true };
    },

    // ===== 6-digit codes to verify the email and the phone =====
    // type: "email" or "phone". Sending a new code cancels the old one.
    sendCode: function (type) {
      const a = find(me());
      if (!a) return { ok: false, error: "Please log in first." };
      const codes = read(key("codes"), {});
      codes[type] = { Code: randomCode(), ExpiresAt: Date.now() + CODE_MINUTES * 60000, UsedAt: null, Tries: 0 };
      write(key("codes"), codes);
      return { ok: true, code: codes[type].Code, to: type === "email" ? maskEmail(a.Email) : maskPhone(a.PhoneNumber), minutes: CODE_MINUTES };
    },

    // The code that is waiting to be typed in (not used, not expired), or null
    pendingCode: function (type) {
      const c = read(key("codes"), {})[type];
      return c && !c.UsedAt && Date.now() <= c.ExpiresAt && c.Tries < MAX_TRIES ? c : null;
    },

    verifyCode: function (type, code) {
      const codes = read(key("codes"), {});
      const c = codes[type];
      if (!c) return { ok: false, error: "Please ask for a code first." };
      if (c.UsedAt) return { ok: false, error: "This code was already used. Ask for a new one." };
      if (Date.now() > c.ExpiresAt) return { ok: false, error: "This code has expired. Ask for a new one." };
      if (c.Tries >= MAX_TRIES) return { ok: false, error: "Too many wrong tries. Ask for a new code." };
      if (String(code).trim() !== c.Code) {
        c.Tries++;
        write(key("codes"), codes);
        return { ok: false, error: c.Tries >= MAX_TRIES ? "Too many wrong tries. Ask for a new code." : "Wrong code. Please try again." };
      }
      c.UsedAt = now();                 // works only once
      write(key("codes"), codes);
      updateAccount(me(), type === "email" ? { EmailVerifiedAt: now() } : { PhoneVerifiedAt: now() });
      return { ok: true };
    },

    // ===== changing the email or phone number (the NEW value must be verified with a 6-digit code) =====
    // type: "email" or "phone". The change is only saved after verifyChange() gets the right code.
    startChange: function (type, newValue) {
      const a = find(me());
      if (!a) return { ok: false, error: "Please log in first." };
      const value = String(newValue || "").trim();
      if (type === "email") {
        if (!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(value)) return { ok: false, error: "Please enter a valid email." };
        if (norm(value) === norm(a.Email)) return { ok: false, error: "This is already your email." };
        if (find(value)) return { ok: false, error: "This email is already used by another account." };
      } else {
        if (normPhone(value).length < 10) return { ok: false, error: "Please enter a valid phone number." };
        if (normPhone(value) === normPhone(a.PhoneNumber)) return { ok: false, error: "This is already your phone number." };
        if (findByPhone(value)) return { ok: false, error: "This phone number is already used by another account." };
      }
      const code = randomCode();
      write(key("change-" + type), { Value: value, Code: code, ExpiresAt: Date.now() + CODE_MINUTES * 60000, UsedAt: null, Tries: 0 });
      return { ok: true, code: code, to: type === "email" ? maskEmail(value) : maskPhone(value), minutes: CODE_MINUTES };
    },

    verifyChange: function (type, code) {
      const k = key("change-" + type);
      const c = read(k, null);
      if (!c) return { ok: false, error: "Please ask for a code first." };
      if (c.UsedAt) return { ok: false, error: "This code was already used. Ask for a new one." };
      if (Date.now() > c.ExpiresAt) return { ok: false, error: "This code has expired. Ask for a new one." };
      if (c.Tries >= MAX_TRIES) return { ok: false, error: "Too many wrong tries. Ask for a new code." };
      if (String(code).trim() !== c.Code) {
        c.Tries++;
        write(k, c);
        return { ok: false, error: c.Tries >= MAX_TRIES ? "Too many wrong tries. Ask for a new code." : "Wrong code. Please try again." };
      }
      // Right code: save the new value, verified. The account is stored under its email, so move it when the email changes.
      const oldEmail = me();
      remove(k);
      if (type === "phone") {
        updateAccount(oldEmail, { PhoneNumber: c.Value, PhoneVerifiedAt: now() });
        return { ok: true };
      }
      const newEmail = norm(c.Value);
      updateAccount(oldEmail, { Email: c.Value, EmailVerifiedAt: now() });
      const prefix = "kph-demo:" + oldEmail + ":";
      try {
        Object.keys(localStorage).filter(function (n) { return n.indexOf(prefix) === 0; }).forEach(function (n) {
          localStorage.setItem("kph-demo:" + newEmail + ":" + n.slice(prefix.length), localStorage.getItem(n));
          localStorage.removeItem(n);
        });
      } catch (e) { /* storage not available */ }
      write(IDENTITIES, read(IDENTITIES, []).map(function (i) { return norm(i.Email) === oldEmail ? Object.assign(i, { Email: c.Value }) : i; }));
      write(RESETS, read(RESETS, []).map(function (r) { return norm(r.Email) === oldEmail ? Object.assign(r, { Email: c.Value }) : r; }));
      write(SESSION, newEmail);
      return { ok: true };
    },

    maskEmail: maskEmail,
    maskPhone: maskPhone,

    logout: function () {
      const token = localStorage.getItem("kph-auth-token");
      if (token && window.KPH && window.KPH.api) {
        fetch(window.KPH.api + "/login.php", {
          method: "DELETE",
          headers: { "Authorization": "Bearer " + token }
        }).catch(function () {});
      }
      remove("kph-auth-token");
      remove("kph-user");
      remove(SESSION);
    },

    checkPassword: function (password) {
      if (localStorage.getItem("kph-auth-token")) return !!password;
      const a = find(me());
      return !!a && !!a.Password && a.Password === password;
    },
    hasPassword: function () {
      if (localStorage.getItem("kph-auth-token")) return true;
      const a = find(me());
      return !!a && !!a.Password;
    },

    // Removes the logged-in account and everything it saved, then logs out
    deleteAccount: function () {
      const email = me();
      write(ACCOUNTS, accounts().filter(function (a) { return norm(a.Email) !== norm(email); }));
      write(IDENTITIES, read(IDENTITIES, []).filter(function (i) { return norm(i.Email) !== norm(email); }));
      write(RESETS, read(RESETS, []).filter(function (r) { return norm(r.Email) !== norm(email); }));
      ["wishlist", "cart", "profile", "addresses", "codes", "change-email", "change-phone", "viewed", "searches", "logins", "seller"].forEach(function (n) { remove(key(n)); });
      remove("kph-auth-token");
      remove("kph-user");
      remove(SESSION);
    },
    clearAll: function () { this.deleteAccount(); },

    // The logged-in person, ready to show on any page (the password is left out)
    currentUser: function () {
      const apiUser = read("kph-user", null);
      if (apiUser && apiUser.UserID) {
        const roles = Array.isArray(apiUser.Roles) && apiUser.Roles.length ? apiUser.Roles : ["Buyer"];
        const role = roles.indexOf("Admin") >= 0 ? "Admin" : (roles.indexOf("Seller") >= 0 ? "Seller" : roles[0]);
        return Object.assign({
          Status: apiUser.AccountStatus || "Active",
          Username: apiUser.Username || nameToUsername(apiUser.FirstName, apiUser.LastName, apiUser.Email),
          Bio: apiUser.Bio || "",
          DateOfBirth: apiUser.DateOfBirth || "",
          ProfilePictureURL: apiUser.ProfilePictureURL || "",
          VerificationStatus: apiUser.KYCStatus === "Approved" ? "Verified" : (apiUser.KYCStatus || "Not verified"),
          Role: role,
          DateJoined: apiUser.CreatedAt || ""
        }, apiUser, read(key("profile"), {}));
      }
      const a = Object.assign({}, find(me()) || {});
      delete a.Password;
      const base = {
        Status: "Active", Username: nameToUsername(a.FirstName, a.LastName, a.Email), Bio: "", DateOfBirth: "",
        ProfilePictureURL: "", VerificationStatus: "Not verified", Role: "Buyer"
      };
      return Object.assign(base, a, read(key("profile"), {}));
    },

    // Role: "Buyer", "Seller" or "Admin". Every new account is a Buyer.
    setRole: function (role) {
      if (["Buyer", "Seller", "Admin"].indexOf(role) < 0) return false;
      write(key("profile"), Object.assign(read(key("profile"), {}), { Role: role }));
      return true;
    },

    // ===== what the profile pages saved (name, username, ID status ...) =====
    user: function () { return read(key("profile"), {}); },
    saveUser: function (changes) { write(key("profile"), Object.assign(read(key("profile"), {}), changes)); },

    // ===== addresses =====
    addresses: function () { return read(key("addresses"), []); },
    saveAddresses: function (list) { write(key("addresses"), list); },

    // ===== wishlist (a list of products) =====
    wishlist: function () { return read(key("wishlist"), []); },
    inWishlist: function (id) { return read(key("wishlist"), []).some(function (p) { return String(p.id) === String(id); }); },
    // adds the product if it is not there, removes it if it is. Returns true when it is now in the wishlist.
    toggleWishlist: function (product) {
      const list = read(key("wishlist"), []);
      const i = list.findIndex(function (p) { return String(p.id) === String(product.id); });
      if (i >= 0) list.splice(i, 1); else list.push(product);
      write(key("wishlist"), list);
      this.syncBadges();
      return i < 0;
    },
    removeFromWishlist: function (id) {
      write(key("wishlist"), read(key("wishlist"), []).filter(function (p) { return String(p.id) !== String(id); }));
      this.syncBadges();
    },

    // ===== activity history: recently viewed, searches and logins =====
    logins: function () { return read(key("logins"), []); },

    // product = { id, name, price, image ... }. The newest view goes first and an old view of it is replaced.
    recordView: function (product) {
      const list = read(key("viewed"), []).filter(function (v) { return String(v.product.id) !== String(product.id); });
      list.unshift({ product: product, At: new Date().toISOString() });
      write(key("viewed"), list.slice(0, 30));
    },
    viewed: function () { return read(key("viewed"), []); },
    clearViewed: function () { remove(key("viewed")); },

    recordSearch: function (text) {
      const q = String(text || "").trim();
      if (!q || !me()) return;
      const list = read(key("searches"), []).filter(function (s) { return s.Query.toLowerCase() !== q.toLowerCase(); });
      list.unshift({ Query: q, At: new Date().toISOString() });
      write(key("searches"), list.slice(0, 20));
    },
    searches: function () { return read(key("searches"), []); },
    removeSearch: function (q) { write(key("searches"), read(key("searches"), []).filter(function (s) { return s.Query !== q; })); },
    clearSearches: function () { remove(key("searches")); },

    // ===== seller application (the first step of becoming a seller) =====
    // Returns the saved application or null. Status starts as "Pending".
    sellerApplication: function () { return read(key("seller"), null); },
    applyAsSeller: function (data) {
      write(key("seller"), Object.assign({}, data, { Status: "Pending", SubmittedAt: now() }));
    },

    // ===== cart dot (only a counter) =====
    addToCart: function () { write(key("cart"), read(key("cart"), 0) + 1); this.syncBadges(); },

    // shows or hides the orange dots on the header icons
    syncBadges: function () {
      if (!window.KPHUI) return;
      KPHUI.setBadge("wishlist", read(key("wishlist"), []).length);
      KPHUI.setBadge("cart", read(key("cart"), 0));
    }
  };

  // Every search typed in the header search box is remembered for the history page
  document.addEventListener("submit", function (e) {
    if (e.target && e.target.id === "kph-search-form") {
      const box = e.target.querySelector("input");
      if (box) api.recordSearch(box.value);
    }
  }, true);

  // Any link or button marked data-logout asks "Do you want to log out?" first.
  // "Log out" ends the session and opens the link. "Cancel" (or Esc, or a click outside) stays on the page.
  function askLogout(go) {
    if (document.getElementById("kph-logout-dialog")) return;
    const style = document.createElement("style");
    style.id = "kph-logout-style";
    style.textContent =
      ".kph-dlg{position:fixed;inset:0;z-index:1000;display:grid;place-items:center;padding:16px}" +
      ".kph-dlg-bg{position:absolute;inset:0;background:var(--text);opacity:.5}" +
      ".kph-dlg-box{position:relative;width:100%;max-width:360px;padding:24px;text-align:center}" +
      ".kph-dlg-box h3{margin:0 0 6px}.kph-dlg-box p{margin:0 0 18px;color:var(--muted)}" +
      ".kph-dlg-row{display:flex;gap:12px}.kph-dlg-row .btn{flex:1}";
    document.head.appendChild(style);

    const dlg = document.createElement("div");
    dlg.id = "kph-logout-dialog";
    dlg.className = "kph-dlg";
    dlg.setAttribute("role", "dialog");
    dlg.setAttribute("aria-modal", "true");
    dlg.setAttribute("aria-labelledby", "kph-dlg-title");
    dlg.innerHTML =
      '<div class="kph-dlg-bg"></div>' +
      '<div class="card kph-dlg-box">' +
      '<h3 id="kph-dlg-title">Do you want to log out?</h3>' +
      '<p>You will need to log in again to use your account.</p>' +
      '<div class="kph-dlg-row">' +
      '<button class="btn btn-outline" type="button" data-cancel>Cancel</button>' +
      '<button class="btn" type="button" data-yes>Log out</button>' +
      "</div></div>";
    document.body.appendChild(dlg);

    function close() {
      document.removeEventListener("keydown", onKey);
      dlg.remove();
      style.remove();
    }
    function onKey(e) { if (e.key === "Escape") close(); }
    document.addEventListener("keydown", onKey);
    dlg.querySelector(".kph-dlg-bg").addEventListener("click", close);
    dlg.querySelector("[data-cancel]").addEventListener("click", close);
    dlg.querySelector("[data-yes]").addEventListener("click", function () { api.logout(); go(); });
    dlg.querySelector("[data-cancel]").focus();
  }

  document.addEventListener("click", function (e) {
    const link = e.target.closest("[data-logout]");
    if (!link) return;
    e.preventDefault();
    askLogout(function () { location.href = link.getAttribute("href") || "login.html"; });
  });

  // Every page needs a login, except the ones marked <body data-public> (Login, Register, Forgot password ...)
  if (document.body && !document.body.hasAttribute("data-public") && !api.isLoggedIn()) {
    document.documentElement.style.display = "none";   // do not flash the page
    location.replace("login.html");
  }

  return api;
})();