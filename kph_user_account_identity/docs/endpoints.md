# Module 1 — User Account & Identity API Reference (`docs/endpoints.md`)

**Base URL:** `http://localhost/kph_user_account_identity/api`  
**Content-Type:** `application/json; charset=utf-8`

---

## Authentication & Cross-Module Session Handoff

All endpoints support three authentication mechanisms (checked in order):

1. **Cross-Module Session Token (Primary):**
   - Header: `Authorization: Bearer <64-char-hex-token>`
   - Issued by `POST /login.php`, `POST /users.php` (registration), and `POST /social.php`.
   - Stored in the shared `verification_token` table (`Purpose = 'session'`, `TokenHash = SHA2(token, 256)`).
   - Validated identically by Module 1 (`kph_user_account_identity`), Module 5 (`kph_shopping_promotions`), and all other KameraPH modules.
   - Revoked on logout via `DELETE /login.php` (marks `UsedAt = NOW()` and `ExpiresAt = NOW()`).
2. **Standalone Test Header (Dev Fallback):**
   - Header: `X-Test-User-Id: <UserID>`
   - Used when no Bearer token is provided.
3. **Inter-Module Key (Admin / Privileged Read):**
   - Header: `X-Module-Key: <module_key>`

---

## 1. Authentication & Sessions (`/login.php`)

### `POST /login.php` — Log in with Email or Phone + Password
- **Request Body:**
  ```json
  {
    "Identifier": "juan.delacruz@email.com",
    "Password": "Password123"
  }
  ```
- **Response (`200 OK`):**
  ```json
  {
    "message": "Login successful.",
    "UserID": 1,
    "Token": "a8f5c2...",
    "User": {
      "UserID": 1,
      "FirstName": "Juan",
      "MiddleName": "Santos",
      "LastName": "Dela Cruz",
      "Email": "juan.delacruz@email.com",
      "PhoneNumber": "09171234567",
      "Status": "Active",
      "Username": "juandelacruz",
      "Role": "Buyer",
      "VerificationStatus": "Not verified"
    }
  }
  ```

### `GET /login.php` — Inspect Current Session
- **Headers:** `Authorization: Bearer <token>`
- **Response (`200 OK`):**
  ```json
  {
    "authenticated": true,
    "UserID": 1,
    "User": { "...full account view..." }
  }
  ```

### `DELETE /login.php` — Log Out (Revoke Session Token)
- **Headers:** `Authorization: Bearer <token>`
- **Response (`200 OK`):**
  ```json
  {
    "message": "Logged out."
  }
  ```

---

## 2. Users & Profiles (`/users.php`)

### `POST /users.php` — Register New User
- **Request Body:**
  ```json
  {
    "FirstName": "Maria",
    "MiddleName": "Reyes",
    "LastName": "Santos",
    "Email": "maria.santos@email.com",
    "PhoneNumber": "09171234567",
    "Password": "Password123"
  }
  ```
- **Response (`201 Created`):** Returns `UserID`, `Token` (session token), and full `User` object.

### `GET /users.php?id=<id>` — Get Single User
- Omit `?id=` to fetch the currently authenticated user.
- Owner, Admin, or callers with `X-Module-Key` receive the full account view; other callers receive the public profile view (`UserID`, `FirstName`, `LastName`, `Username`, `Bio`, `ProfilePictureURL`, `Role`, `VerificationStatus`, `DateJoined`).

### `GET /users.php?all=1` — List Users
- Optional query parameters: `status=Active|Suspended`, `search=<keyword>`, `limit=20`, `offset=0`.

### `PUT /users.php?id=<id>` — Update Account & Profile
- Updates `user` fields (`FirstName`, `MiddleName`, `LastName`, `Email`, `PhoneNumber`) and/or `user_profile` fields (`Username`, `Bio`, `ProfilePictureURL`, `DateOfBirth`) in a single request.

### `POST /users.php?action=change-password` — Change Password
- **Request Body:** `{ "CurrentPassword": "OldPassword1", "NewPassword": "NewPassword2" }`

### `POST /users.php?id=<id>&action=suspend` — Suspend Account (Admin)
- **Request Body:** `{ "Reason": "Repeated policy violations" }`

### `POST /users.php?id=<id>&action=reactivate` — Reactivate Account (Admin)

### `DELETE /users.php?id=<id>` — Delete Account
- **Request Body:** `{ "Password": "Password123" }`

---

## 3. Social Sign-In (`/social.php`)

### `POST /social.php` — Sign In or Register via Google / Facebook
- **Request Body:**
  ```json
  {
    "Provider": "Google",
    "FirstName": "Carlo",
    "LastName": "Mendoza",
    "Email": "carlo@gmail.com",
    "PhoneNumber": "09181234567"
  }
  ```
- **Response (`200 OK` or `201 Created`):** Returns `UserID`, `Token`, `IsNewUser`, and `User`.

### `GET /social.php` — List Linked Social Identities

---

## 4. Saved Addresses (`/addresses.php`)

### `GET /addresses.php` — List Addresses for Current User (or `?user_id=<id>`, or `?id=<addressId>`)
### `POST /addresses.php` — Create Address
- Accepts either backend fields (`RecipientFirstName`, `RecipientLastName`, `HouseUnitNumber`, `Street`, `Barangay`, `City`, `Province`, `PostalCode`, `AddressType`, `IsDefault`) or UI form fields (`RecipientFullName`, combined `Street`, `Region`, `Province`, `City`, `Barangay`).
### `PUT /addresses.php?id=<id>` — Update Address
### `DELETE /addresses.php?id=<id>` — Delete Address

---

## 5. Email & Phone Verification (`/verification.php`)

### `POST /verification.php` — Send 6-Digit Verification Code
- **Request Body:** `{ "Type": "email" }` or `{ "Type": "phone" }`
- **Response (`200 OK`):** `{ "message": "Verification code sent.", "to": "ju***@email.com", "minutes": 15, "code": "123456" }`

### `PUT /verification.php` — Confirm 6-Digit Code
- **Request Body:** `{ "Type": "email", "Code": "123456" }`

---

## 6. Contact Change (`/contact-change.php`)

### `POST /contact-change.php` — Validate New Email/Phone & Send Code
- **Request Body:** `{ "Type": "email", "Value": "new.email@example.com" }`

### `PUT /contact-change.php` — Confirm Code & Save New Verified Contact
- **Request Body:** `{ "Type": "email", "Value": "new.email@example.com", "Code": "123456" }`

---

## 7. Password Reset (`/password-reset.php`)

### `POST /password-reset.php` — Request Reset Token
- **Request Body:** `{ "Identifier": "juan.delacruz@email.com" }`
### `GET /password-reset.php?token=<token>` — Check Token Validity
- **Response (`200 OK`):** `{ "ok": true, "reason": null }`
### `PUT /password-reset.php` — Reset Password with Token
- **Request Body:** `{ "Token": "<token>", "Password": "NewPassword123" }`

---

## 8. Seller KYC Verification (`/kyc.php`)

### `GET /kyc.php` — Get Current User's KYC Status (or `?user_id=<id>`, or `?all=1&status=Pending`)
### `POST /kyc.php` — Submit KYC Verification
- **Request Body (JSON or `multipart/form-data`):**
  ```json
  {
    "IDType": "PhilSys National ID",
    "IDNumber": "1234-5678-9012-3456",
    "IDImageURL": "uploads/kyc/id_front.jpg",
    "SelfieImageURL": "uploads/kyc/selfie.jpg"
  }
  ```
### `POST /kyc.php?id=<kycId>&action=approve` — Approve KYC & Auto-Assign Seller Role
### `POST /kyc.php?id=<kycId>&action=reject` — Reject KYC with `RejectionReason`

---

## 9. Roles (`/roles.php`)

### `GET /roles.php` — List All Roles (or `?user_id=<id>` for a User's Roles)
### `POST /roles.php` — Assign Role (`{ "UserID": 1, "RoleName": "seller" }`)
### `DELETE /roles.php?user_id=<id>&role_id=<id>` — Remove Role

---

## 10. Activity History (`/activity.php`)

### `GET /activity.php?type=logins|viewed|searches` — Get Activity History
### `DELETE /activity.php?type=viewed|searches` — Clear Activity History
