// ==========================================================
// KameraPH UI Kit  |  kph-ui.js
// Makes the header work: menu links, icon links, search box,
// light/dark button, and cross-module ?token= handoff.
// Needs config.js loaded first.
// ==========================================================
(function () {
  function pageUrl(module, page) {
    var base = (KPH.modules[module] || "").replace(/\/$/, "");
    if (!base) return "#";
    var target = base + "/" + page;
    try {
      var token = localStorage.getItem("kph-auth-token");
      if (token && module !== "users") {
        target += (target.indexOf("?") >= 0 ? "&" : "?") + "token=" + encodeURIComponent(token);
      }
    } catch (e) {}
    return target;
  }

  document.addEventListener("DOMContentLoaded", function () {
    // Menu links
    var nav = document.getElementById("kph-nav");
    if (nav && KPH.nav) {
      var here = location.pathname.split("/").pop();
      nav.innerHTML = KPH.nav.map(function (n) {
        var active = n.page === here ? ' class="active" aria-current="page"' : "";
        return '<a href="' + pageUrl(n.module, n.page) + '"' + active + ">" + n.label + "</a>";
      }).join("");
    }

    // Icon links (account, wishlist, cart)
    document.querySelectorAll("[data-kph-link]").forEach(function (a) {
      var l = KPH.links && KPH.links[a.getAttribute("data-kph-link")];
      if (l) a.href = pageUrl(l.module, l.page);
    });

    // Logo goes to the first menu item (Home)
    var logo = document.querySelector(".kph-header .kph-logo");
    if (logo && KPH.nav && KPH.nav[0]) logo.href = pageUrl(KPH.nav[0].module, KPH.nav[0].page);

    // Search box -> search page with ?q=
    var form = document.getElementById("kph-search-form");
    if (form) {
      form.addEventListener("submit", function (e) {
        e.preventDefault();
        var q = form.querySelector("input").value.trim();
        if (!q || !KPH.links || !KPH.links.search) return;
        var searchUrl = pageUrl(KPH.links.search.module, KPH.links.search.page);
        location.href = searchUrl + (searchUrl.indexOf("?") >= 0 ? "&" : "?") + "q=" + encodeURIComponent(q);
      });
    }

    // Search button on phones
    var toggle = document.getElementById("kph-search-toggle");
    var bar = document.getElementById("kph-search");
    if (toggle && bar) {
      toggle.addEventListener("click", function () {
        bar.classList.toggle("open");
        if (bar.classList.contains("open")) bar.querySelector("input").focus();
      });
    }

    // Light / dark button (remembered in this browser)
    var root = document.documentElement;
    var btn = document.getElementById("kph-theme-toggle");
    if (btn) {
      btn.addEventListener("click", function () {
        var current = root.getAttribute("data-theme");
        var isDark = current === "dark" || (!current && window.matchMedia("(prefers-color-scheme: dark)").matches);
        var next = isDark ? "light" : "dark";
        root.setAttribute("data-theme", next);
        try { localStorage.setItem("kph-theme", next); } catch (e) { /* storage not available */ }
      });
    }
  });

  // Show or hide the orange dot on the wishlist or cart icon:  KPHUI.setBadge("cart", 3)
  window.KPHUI = {
    setBadge: function (name, count) {
      var dot = document.querySelector('[data-kph-link="' + name + '"] .dot');
      if (dot) dot.hidden = !(count > 0);
    }
  };
})();
