-- ============================================================
-- KameraPH (KPH)  |  Module 1: User Account and Identity
-- Source: KameraPH_ERD_v5  |  MySQL / MariaDB (InnoDB, utf8mb4)
--
-- HOW TO USE: create an empty database for this module on your host,
-- open it in phpMyAdmin, go to the SQL tab, paste this whole file, click Go.
--
-- Tables in this module (8): user, role, user_role, user_profile, user_address, auth_identity, verification_token, kyc_verification
--
-- Columns owned by OTHER modules are plain ID columns (no FOREIGN KEY),
-- because each module has its own database. Get those records from the
-- other module's API instead of joining tables.
--
-- Outside IDs stored in this module:
--   kyc_verification.ReviewedByAdminID  ->  Module 10 (admin_user.AdminID)
-- ============================================================

-- USER
CREATE TABLE IF NOT EXISTS `user` (
  UserID INT UNSIGNED NOT NULL AUTO_INCREMENT,
  FirstName VARCHAR(100) NOT NULL,
  MiddleName VARCHAR(100) NULL,
  LastName VARCHAR(100) NOT NULL,
  Email VARCHAR(191) NOT NULL,
  PhoneNumber VARCHAR(20) NOT NULL,
  PasswordHash VARCHAR(255) NULL,
  EmailVerifiedAt DATETIME NULL,
  PhoneVerifiedAt DATETIME NULL,
  DateJoined DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  LastLoginAt DATETIME NULL,
  Status VARCHAR(30) NOT NULL,
  SuspensionReason TEXT NULL,
  PRIMARY KEY (UserID),
  UNIQUE KEY uq_user_Email (Email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ROLE
CREATE TABLE IF NOT EXISTS `role` (
  RoleID INT UNSIGNED NOT NULL AUTO_INCREMENT,
  RoleName VARCHAR(100) NOT NULL,
  Description TEXT NULL,
  PRIMARY KEY (RoleID),
  UNIQUE KEY uq_role_RoleName (RoleName)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- USER ROLE
CREATE TABLE IF NOT EXISTS `user_role` (
  UserRoleID INT UNSIGNED NOT NULL AUTO_INCREMENT,
  UserID INT UNSIGNED NOT NULL,
  RoleID INT UNSIGNED NOT NULL,
  AssignedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (UserRoleID),
  UNIQUE KEY uq_user_role_UserID_RoleID (UserID, RoleID),
  CONSTRAINT fk_user_role_UserID FOREIGN KEY (UserID) REFERENCES `user` (UserID),
  CONSTRAINT fk_user_role_RoleID FOREIGN KEY (RoleID) REFERENCES `role` (RoleID)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- USER PROFILE
CREATE TABLE IF NOT EXISTS `user_profile` (
  ProfileID INT UNSIGNED NOT NULL AUTO_INCREMENT,
  UserID INT UNSIGNED NOT NULL,
  Username VARCHAR(100) NOT NULL,
  Bio TEXT NULL,
  ProfilePictureURL VARCHAR(500) NULL,
  DateOfBirth DATE NULL,
  CreatedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (ProfileID),
  UNIQUE KEY uq_user_profile_UserID (UserID),
  CONSTRAINT fk_user_profile_UserID FOREIGN KEY (UserID) REFERENCES `user` (UserID)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- USER ADDRESS
CREATE TABLE IF NOT EXISTS `user_address` (
  AddressID INT UNSIGNED NOT NULL AUTO_INCREMENT,
  UserID INT UNSIGNED NOT NULL,
  RecipientFirstName VARCHAR(100) NOT NULL,
  RecipientMiddleName VARCHAR(100) NULL,
  RecipientLastName VARCHAR(100) NOT NULL,
  PhoneNumber VARCHAR(20) NOT NULL,
  HouseUnitNumber VARCHAR(100) NOT NULL,
  Street VARCHAR(255) NOT NULL,
  Barangay VARCHAR(100) NOT NULL,
  City VARCHAR(100) NOT NULL,
  Province VARCHAR(100) NOT NULL,
  PostalCode VARCHAR(10) NOT NULL,
  AddressType VARCHAR(30) NOT NULL,
  IsDefault TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (AddressID),
  CONSTRAINT fk_user_address_UserID FOREIGN KEY (UserID) REFERENCES `user` (UserID)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- AUTH IDENTITY
CREATE TABLE IF NOT EXISTS `auth_identity` (
  AuthIdentityID INT UNSIGNED NOT NULL AUTO_INCREMENT,
  UserID INT UNSIGNED NOT NULL,
  Provider VARCHAR(100) NOT NULL,
  ProviderUserID VARCHAR(255) NOT NULL,
  LinkedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (AuthIdentityID),
  CONSTRAINT fk_auth_identity_UserID FOREIGN KEY (UserID) REFERENCES `user` (UserID)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- VERIFICATION TOKEN
CREATE TABLE IF NOT EXISTS `verification_token` (
  TokenID INT UNSIGNED NOT NULL AUTO_INCREMENT,
  UserID INT UNSIGNED NOT NULL,
  Purpose VARCHAR(100) NOT NULL,
  TokenHash VARCHAR(255) NOT NULL,
  ExpiresAt DATETIME NOT NULL,
  UsedAt DATETIME NULL,
  PRIMARY KEY (TokenID),
  CONSTRAINT fk_verification_token_UserID FOREIGN KEY (UserID) REFERENCES `user` (UserID)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- KYC VERIFICATION
CREATE TABLE IF NOT EXISTS `kyc_verification` (
  KYCID INT UNSIGNED NOT NULL AUTO_INCREMENT,
  UserID INT UNSIGNED NOT NULL,
  IDType VARCHAR(100) NOT NULL,
  IDNumber VARCHAR(255) NOT NULL COMMENT 'stored encrypted',
  IDImageURL VARCHAR(500) NOT NULL,
  SelfieImageURL VARCHAR(500) NOT NULL,
  VerificationStatus VARCHAR(30) NOT NULL,
  SubmittedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  VerifiedAt DATETIME NULL,
  ReviewedByAdminID INT UNSIGNED NULL COMMENT 'Module 10: admin_user.AdminID (plain ID, no FK)',
  RejectionReason TEXT NULL,
  PRIMARY KEY (KYCID),
  KEY idx_kyc_verification_ReviewedByAdminID (ReviewedByAdminID),
  CONSTRAINT fk_kyc_verification_UserID FOREIGN KEY (UserID) REFERENCES `user` (UserID)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
