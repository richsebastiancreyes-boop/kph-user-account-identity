# Progress Report & Handoff Guide — Module 1: User Account & Identity (`kph_user_account_identity`)

**Prepared for:** Cristopher & Module 1 Group (User Account & Identity)  
**Prepared by:** Collaborator from Module 5 (`kph_shopping_promotions` / Coupons & Promotions)  
**Repository:** `kph_user_behavior_system` (`kph_user_account_identity`)

---

## 1. Executive Summary — What Was Already Done vs. What Was Completed

When we pulled your files from Google Drive (`BACKEND.zip`, `UI - IDK.zip`, `module_01_user_account_and_identity.sql`, `seed_roles.sql`, `BACKEND_LINE_BY_LINE.txt`, `SQL_EXPLAINED.txt`), your group had already completed **Part 1 (Database)**, **Part 2 (Backend PHP Classes)**, and the **Part 4 UI-first Frontend** (`USE_API = false` using `js/demo.js`).

Starting right where your progress left off, here is everything that was built, connected, and verified for you:

| Build Guide Stage | Previous Status (from Drive) | Completed Work |
| :--- | :--- | :--- |
| **Part 1: Database (`sql/`)** | Complete (`module_01_user_account_and_identity.sql` + `seed_roles.sql`) | Preserved 100% intact in `kph_user_account_identity/sql/`. |
| **Part 2: Backend (`config/` & `classes/`)** | Complete (10 classes + `config.php` + `Database.php`) | Preserved all your original classes/methods so your `BACKEND_LINE_BY_LINE.txt` script still matches, and added session token helpers (`createSession`, `verifySession`, `revokeSession`, `checkResetToken`), UI-bridge helpers (`getAccountView`, `deleteAccount`, `resetPasswordByToken`, `validateNewContact`, `changeVerifiedContact`, `normalizeUiData`), plus `config.local.php` support for Azure MySQL. |
| **Part 3: REST API (`api/` & `docs/`)** | Not started yet | Built all **10 REST API endpoints** (`helpers.php`, `login.php`, `users.php`, `social.php`, `addresses.php`, `verification.php`, `contact-change.php`, `password-reset.php`, `kyc.php`, `roles.php`, `activity.php`), sample JSON mocks (`api/mock/`), and full API docs (`docs/endpoints.md`). |
| **Part 4: Frontend Integration (`web/`)** | UI-first mode (`USE_API = false`) | Switched all pages to **`USE_API = true`**, upgraded `web/js/api.js`, `web/js/config.js`, `web/js/kph-ui.js`, and `web/js/demo.js` to store and send `Authorization: Bearer <token>`, and wired seamless cross-module session handoff with Module 5 (`kph_shopping_promotions`). |
| **Testing & Git Repo (`dev/tests/`)** | None | Added `dev/tests/backend_test.php` and `dev/tests/api_test.php` (including Module 1 → Module 5 session tests) and organized everything into a clean Git repository. |

---

## 2. Detailed Breakdown of What Was Built

### A. Part 3 — REST API Endpoints (`kph_user_account_identity/api/`)

Every endpoint includes `api/helpers.php`, sets CORS and JSON headers, maps your custom PHP exceptions (`InvalidArgumentException` -> `400`, `AuthenticationException` -> `401`, `ForbiddenException` -> `403`, `OutOfBoundsException` -> `404`), and authenticates requests via `Authorization: Bearer <token>` (with `X-Test-User-Id` fallback for standalone testing):

1. **[`api/helpers.php`](file:///c:/xampp/htdocs/kph_user_behavior_system/kph_user_account_identity/api/helpers.php):**
   - Shared JSON response helper (`respond()`), JSON body parser (`jsonBody()`), exception mapper (`handleApiException()`), Bearer token extractor (`getBearerToken()`), `currentUser()`, `requireUser()`, `isAdminUser()`, `hasModuleKey()`, and email/phone maskers.
2. **[`api/login.php`](file:///c:/xampp/htdocs/kph_user_behavior_system/kph_user_account_identity/api/login.php):**
   - `POST`: Authenticates via `User::login($identifier, $password)`, creates a 24-hour Bearer session token in `verification_token` (`VerificationToken::createSession($userId)`), and returns `{ UserID, Token, User }`.
   - `GET`: Verifies the current Bearer token and returns the logged-in user's full account view.
   - `DELETE`: Revokes the current Bearer session token (`VerificationToken::revokeSession($token)`) on logout so neither Module 1 nor Module 5 accepts it anymore.
3. **[`api/users.php`](file:///c:/xampp/htdocs/kph_user_behavior_system/kph_user_account_identity/api/users.php):**
   - `POST`: Registers a new user (`User::register()`), automatically issues a session token (`Token`), and returns `201 Created`.
   - `GET`: Returns full account view (`User::getAccountView()`) when viewing own profile (or admin/module key), public view (`User::toPublic()`) when viewing another user, or filtered user list when `?all=1` / `?status=` / `?search=` is passed.
   - `PUT`: Updates `user` fields (`FirstName`, `MiddleName`, `LastName`, `Email`, `PhoneNumber`) and `user_profile` fields (`Username`, `Bio`, `ProfilePictureURL`, `DateOfBirth`) together.
   - `POST ?action=change-password`, `POST ?id=<id>&action=suspend`, `POST ?id=<id>&action=reactivate`.
   - `DELETE`: Verifies password and deletes the user's account (`User::deleteAccount()`).
4. **[`api/social.php`](file:///c:/xampp/htdocs/kph_user_behavior_system/kph_user_account_identity/api/social.php):**
   - `POST`: Handles simulated Google/Facebook sign-in from `social.html` (`User::socialLogin()`), auto-deriving `ProviderUserID` if omitted by the UI form, and returns `{ UserID, Token, IsNewUser, User }`.
   - `GET`: Lists linked social accounts (`AuthIdentity::getByUser()`).
5. **[`api/addresses.php`](file:///c:/xampp/htdocs/kph_user_behavior_system/kph_user_account_identity/api/addresses.php):**
   - `GET`, `POST`, `PUT ?id=<id>`, `DELETE ?id=<id>` for `UserAddress`.
   - Bridges the UI form fields (`RecipientFullName`, combined `Street`, `Region`, `Province`, `City`, `Barangay`) with the database columns (`RecipientFirstName`, `RecipientLastName`, `HouseUnitNumber`, `Street`, etc.).
6. **[`api/verification.php`](file:///c:/xampp/htdocs/kph_user_behavior_system/kph_user_account_identity/api/verification.php):**
   - `POST`: Sends a 6-digit verification code for the logged-in user's email or phone (`{ "Type": "email" | "phone" }`) and returns the test `code` when `show_token_in_response` is enabled.
   - `PUT`: Verifies the 6-digit code (`User::verifyContact()`) and sets `EmailVerifiedAt` or `PhoneVerifiedAt`.
7. **[`api/contact-change.php`](file:///c:/xampp/htdocs/kph_user_behavior_system/kph_user_account_identity/api/contact-change.php):**
   - `POST`: Validates a new email or phone number (`User::validateNewContact()`) and sends a 6-digit verification code.
   - `PUT`: Verifies the code and updates the email/phone as verified (`User::changeVerifiedContact()`).
8. **[`api/password-reset.php`](file:///c:/xampp/htdocs/kph_user_behavior_system/kph_user_account_identity/api/password-reset.php):**
   - `POST`: Creates a password reset token (`User::sendToken($identifier, "reset_password")`) and returns the test link token in dev mode.
   - `GET ?token=<token>`: Validates whether a reset token is valid, expired, or already used (`VerificationToken::checkResetToken()`).
   - `PUT`: Resets the user's password using the token (`User::resetPasswordByToken()`).
9. **[`api/kyc.php`](file:///c:/xampp/htdocs/kph_user_behavior_system/kph_user_account_identity/api/kyc.php):**
   - `GET`: Returns current user's KYC status (`KycVerification::toStatusView()`), a specific submission (`?id=`), or all submissions (`?all=1&status=Pending`).
   - `POST`: Submits KYC verification (supports both JSON from `kyc.html` and `multipart/form-data` image uploads via `UploadHelper`), approves KYC (`?id=<id>&action=approve` + auto-grants `seller` role), or rejects KYC (`?id=<id>&action=reject`).
10. **[`api/roles.php`](file:///c:/xampp/htdocs/kph_user_behavior_system/kph_user_account_identity/api/roles.php) & [`api/activity.php`](file:///c:/xampp/htdocs/kph_user_behavior_system/kph_user_account_identity/api/activity.php):**
    - `roles.php`: Lists roles, lists user roles (`?user_id=`), assigns roles (`POST`), and removes roles (`DELETE`).
    - `activity.php`: Returns login/session history from `verification_token` (`?type=logins`) plus recently viewed products (`?type=viewed`) and search history (`?type=searches`).

---

### B. Cross-Module Session Handoff (Module 1 ↔ Module 5)

To allow Module 5 (`kph_shopping_promotions`) and all other KameraPH modules to share the logged-in user session with Module 1 without requiring PHP sticky sessions across different computers:

1. **Database-Backed Bearer Token (`verification_token`):**
   - On login/register/social sign-in, Module 1 generates a 64-character hex token (`bin2hex(random_bytes(32))`) and inserts `SHA-256(token)` into `verification_token` with `Purpose = 'session'` and a 24-hour expiry (`1440` minutes).
2. **Frontend Token Storage & URL Handoff (`web/js/api.js` & `web/js/kph-ui.js`):**
   - `KPHAuth.saveSession(token, user)` saves the token in `localStorage.getItem("kph-auth-token")` and the user object in `localStorage.getItem("kph-user")`.
   - Every `kphFetch()`, `apiGet()`, and `apiSend()` call automatically sends `Authorization: Bearer <token>`.
   - Clicking any cross-module navigation link in the header (e.g., **Deals** -> `kph_shopping_promotions/web/deals.html` or **Cart** -> `kph_shopping_promotions/web/cart.html`) appends `?token=<token>`, which `api.js` on the destination module captures into `localStorage` and strips from the URL bar using `history.replaceState`.
3. **Shared Logout Revocation:**
   - Clicking **Log out** calls `DELETE /api/login.php`, which sets `UsedAt = NOW(), ExpiresAt = NOW()` on the token in `verification_token` and clears `localStorage`, immediately logging the user out across all modules.

---

### C. Preserving Your Presentation Notes (`kph_reference_docs/`)

Your original study guides (`BACKEND_LINE_BY_LINE.txt`, `FUNCTION_LIST.txt`, and `SQL_EXPLAINED.txt`) are saved in `kph_reference_docs/` for easy reference during your defense/presentation. All original class names, constants, and method signatures in `classes/` were preserved so your line-by-line explanation remains accurate.
