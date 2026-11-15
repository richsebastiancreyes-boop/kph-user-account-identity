-- ============================================================
-- KameraPH | Module 1 | Starting data (run AFTER module_01_user_account_and_identity.sql)
-- Safe to run twice: it only adds roles that are missing.
-- The PHP code also adds these three roles by itself, so this file is optional.
-- ============================================================

INSERT INTO `role` (RoleName, Description)
SELECT 'buyer', 'Can browse, buy, and review items.' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `role` WHERE RoleName = 'buyer');

INSERT INTO `role` (RoleName, Description)
SELECT 'seller', 'Can list items for sale after passing the seller check.' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `role` WHERE RoleName = 'seller');

INSERT INTO `role` (RoleName, Description)
SELECT 'admin', 'KPH staff who manage users, listings, disputes and reports.' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `role` WHERE RoleName = 'admin');

-- ------------------------------------------------------------
-- MAKE YOUR FIRST ADMIN (do this once, by hand).
-- Register a user first (POST api/users.php), then replace 1 with that UserID.
-- The API cannot create the very first admin, on purpose.
-- ------------------------------------------------------------
-- INSERT INTO `user_role` (UserID, RoleID)
-- SELECT 1, RoleID FROM `role` WHERE RoleName = 'admin';
