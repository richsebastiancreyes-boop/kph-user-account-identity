# KameraPH — Module 1: User Account & Identity (`kph_user_account_identity`)

Full-stack implementation of **Module 1 (User Account & Identity)** for the KameraPH peer-to-peer camera & photography gear marketplace, following the **KameraPH 4-Part Build Guide**:

1. **Part 1 — Database (`sql/`):** 7 tables (`user`, `user_profile`, `user_address`, `role`, `user_role`, `kyc_verification`, `auth_identity`, `verification_token`) + default role seeds.
2. **Part 2 — Backend PHP Classes (`config/`, `classes/`):** PDO + prepared statements, AES-256-GCM encryption for KYC ID numbers, HMAC-SHA256 for OTP/reset codes, and SHA-256 cross-module Bearer session tokens.
3. **Part 3 — REST API (`api/`, `docs/endpoints.md`):** 10 JSON endpoints (`login.php`, `users.php`, `social.php`, `addresses.php`, `verification.php`, `contact-change.php`, `password-reset.php`, `kyc.php`, `roles.php`, `activity.php`) + sample mocks (`api/mock/`).
4. **Part 4 — Web UI (`web/`):** 13 responsive HTML/CSS/JS pages connected to the live PHP REST API (`USE_API = true`) with cross-module Bearer token handoff to **Module 5 (`kph_shopping_promotions`)** and all other KameraPH modules.

---

## Quick Start (XAMPP + Azure MySQL or Local MySQL)

1. **Place or clone repository into XAMPP `htdocs`:**
   - Either clone directly to `C:\xampp\htdocs\kph_user_account_identity`, **or** keep this repo at `C:\xampp\htdocs\kph_user_behavior_system` and create a directory junction:
     ```powershell
     cmd /c mklink /J "C:\xampp\htdocs\kph_user_account_identity" "C:\xampp\htdocs\kph_user_behavior_system\kph_user_account_identity"
     ```
2. **Configure Database Connection:**
   - Default `config/config.php` points to `localhost` (`kameraph` database, `root` user).
   - To connect to the shared **Azure MySQL** database (or customize credentials without modifying Git-tracked files), copy `config/config.local.example.php` to `config/config.local.php` and fill in your credentials.
3. **Import SQL Schema (if using a fresh local database):**
   - Import `sql/module_01_user_account_and_identity.sql` and `sql/seed_roles.sql` via phpMyAdmin or MySQL CLI.
4. **Run Automated Tests:**
   - **Backend Class Tests:**
     ```powershell
     C:\xampp\php\php.exe kph_user_account_identity/dev/tests/backend_test.php
     ```
   - **REST API + Cross-Module Session Tests (requires Apache running):**
     ```powershell
     C:\xampp\php\php.exe kph_user_account_identity/dev/tests/api_test.php
     ```
5. **Open in Browser:**
   - Login page: `http://localhost/kph_user_account_identity/web/login.html`
   - Register page: `http://localhost/kph_user_account_identity/web/register.html`
   - Home page: `http://localhost/kph_user_account_identity/web/home.html`

---

## Directory Structure

```text
kph_user_behavior_system/
├── README.md
├── PROGRESS_REPORT.md                  # Detailed handoff & progress report for Module 1 group
├── kph_reference_docs/                 # Original SQL & Backend line-by-line presentation notes
│   ├── BACKEND_LINE_BY_LINE.txt
│   ├── FUNCTION_LIST.txt
│   └── SQL_EXPLAINED.txt
└── kph_user_account_identity/          # Module 1 runtime folder
    ├── config/
    │   ├── config.php
    │   ├── config.local.example.php
    │   └── Database.php
    ├── classes/                        # Part 2: PHP OOP classes
    ├── api/                            # Part 3: REST API endpoints + api/mock/
    ├── docs/
    │   └── endpoints.md                # Full REST API documentation
    ├── sql/                            # Part 1: SQL schema & seed scripts
    ├── web/                            # Part 4: Responsive frontend pages (USE_API = true)
    └── dev/tests/                      # Automated backend & cross-module API test suites
```
