// ==========================================================
// KameraPH UI Kit  |  config.js
// Change the addresses below when your API or another
// group's pages run on a different computer or folder.
// ==========================================================
window.KPH = {
  // This module's own PHP API folder
  api: "../api",

  // Fallback header when no Bearer token is present (for standalone endpoint testing)
  testUserId: "1",

  // Shortcut map
  users:      "http://localhost/kph_user_account_identity/web",
  catalog:    "http://localhost/kph_product_catalog/web",
  engagement: "http://localhost/kph_user_engagement/web",
  auctions:   "http://localhost/kph_auctions/web",
  shopping:   "http://localhost/kph_shopping_promotions/web",
  orders:     "http://localhost/kph_orders_payments/web",
  shipping:   "http://localhost/kph_shipping/web",
  reviews:    "http://localhost/kph_reviews_disputes/web",
  messaging:  "http://localhost/kph_messaging/web",
  sellers:    "http://localhost/kph_user_account_identity/web",
  admin:      "http://localhost/kph_user_account_identity/web",
  support:    "http://localhost/kph_reviews_disputes/web",

  // Where each module's web/ folder lives
  modules: {
    users:      "http://localhost/kph_user_account_identity/web",
    catalog:    "http://localhost/kph_product_catalog/web",
    engagement: "http://localhost/kph_user_engagement/web",
    auctions:   "http://localhost/kph_auctions/web",
    shopping:   "http://localhost/kph_shopping_promotions/web",
    orders:     "http://localhost/kph_orders_payments/web",
    shipping:   "http://localhost/kph_shipping/web",
    reviews:    "http://localhost/kph_reviews_disputes/web",
    messaging:  "http://localhost/kph_messaging/web",
    sellers:    "http://localhost/kph_user_account_identity/web",
    admin:      "http://localhost/kph_user_account_identity/web",
    support:    "http://localhost/kph_reviews_disputes/web"
  },

  // Top menu (the same on every module's pages)
  nav: [
    { label: "Home",     module: "users",    page: "home.html" },
    { label: "Browse",   module: "catalog",  page: "browse.html" },
    { label: "Auctions", module: "auctions", page: "auctions.html" },
    { label: "Deals",    module: "shopping", page: "deals.html" },
    { label: "Messages", module: "messaging",page: "inbox.html" }
  ],

  // Header icons + search destination
  links: {
    account:  { module: "users",    page: "profile.html" },
    wishlist: { module: "users",    page: "wishlist.html" },
    cart:     { module: "shopping", page: "cart.html" },
    search:   { module: "catalog",  page: "search.html" }
  }
};
