# KameraPH UI Kit  (v1.0)

A small set of ready-made files so all 10 modules look like one shop. It matches the KameraPH mockup
(orange accent, white or dark navy pages, cards, same header and footer, light and dark mode).

## What is inside

| File | What it is |
|---|---|
| `css/kph.css` | All colors and styles. **Do not change it.** |
| `js/config.js` | Your API address and the addresses of the other modules. **The only file you edit.** |
| `js/api.js` | `apiGet`, `apiSend`, `escapeHtml`, `formatPeso` |
| `js/kph-ui.js` | Makes the header work (menu, icons, search, light/dark button) |
| `template.html` | Starter page. Copy it for every new page. |
| `example-list.html` | A page that shows a list from your API |
| `example-form.html` | A page with a form that sends data to your API |
| `kit-demo.html` | Every style on one page. Open it to see what is available. |
| `partials/` | The header and footer on their own (already inside `template.html`) |

## How to use it

**What you need:** your module folder and Apache running (XAMPP).

**Steps:**
1. Copy the whole kit into your module's `web` folder, so you have `web/css`, `web/js` and `web/template.html`.
2. Open `js/config.js` and set `api` to your module's API address. Leave the rest for now.
3. Open `kit-demo.html` in your browser (`http://localhost/kph_<module>/web/kit-demo.html`).
4. For every new page: copy `template.html`, rename it, and write your content inside `<main>`.
5. Look at `example-list.html` and `example-form.html` to see how a page gets data from your API.

**How it should look:** the demo page shows the orange top bar, the header with the menu and icons, buttons, cards, a table and a form.
The moon button switches between light and dark.

**If it goes wrong:**
- The menu is empty: `config.js` is not loaded, or it is loaded after `kph-ui.js`. The order must be `config.js`, `api.js`, `kph-ui.js`.
- The page stays on "Loading...": open the browser console (F12). Check the API address in `config.js`.
- The header looks plain: the path to `css/kph.css` is wrong.

## Style cheat sheet

| I want... | Use |
|---|---|
| A section title with the orange bar | `<h2 class="section-title">` |
| A box | `<div class="card">` |
| A grid (4 columns on desktop, 2 on mobile) | `<div class="grid">` |
| Content with a side column | `<div class="layout-side">` |
| Orange button / outline button / full width / small | `btn` / `btn btn-outline` / `btn btn-block` / `btn btn-sm` |
| A form | `<form class="form">` with `<div class="field"><label>..</label><input></div>` |
| A status tag | `badge`, `badge-orange`, `badge-ok`, `badge-warn`, `badge-bad` |
| A message | `alert alert-ok`, `alert alert-warn`, `alert alert-error` |
| A table | `<div class="table-wrap"><table class="table">` |
| A product card | `product-card` (see `kit-demo.html`) |
| Text while loading or empty | `<p class="status-msg">` |
| Hide on phones / show only on phones | `hide-mobile` / `only-mobile` |

## Rules
- Use the color variables (`var(--card)`, `var(--text)`, `var(--orange)`). Never type a color like `#fff` or `black` in your pages.
- Always wrap text from the API in `escapeHtml(...)` before putting it into HTML.
- Copy the header and footer exactly as they are in `template.html`. Do not edit them.
- Put your API address and the other modules' addresses in `config.js` only.
- The logo is a simple placeholder. The project leader can replace the SVG in the header and footer with the official logo.
- The page names in `config.js` (`nav` and `links`) are examples. Agree the real ones with the project leader.
- Delete `kit-demo.html`, `example-*.html` and `partials/` before you upload to the web host.
