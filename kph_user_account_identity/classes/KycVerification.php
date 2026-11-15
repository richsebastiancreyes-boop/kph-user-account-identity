<?php
require_once __DIR__ . '/../config/Database.php';
require_once __DIR__ . '/Validator.php';
require_once __DIR__ . '/Crypto.php';

// The seller ID check (KYC). The ID number is saved encrypted and is NEVER returned.
// Statuses: Pending -> Approved or Rejected.
class KycVerification
{
    private PDO $db;

    public const PENDING = "Pending";
    public const APPROVED = "Approved";
    public const REJECTED = "Rejected";

    public const ID_TYPES = [
        "Philippine Passport", "Driver's License", "UMID", "PhilSys National ID", "PRC ID",
        "Postal ID", "Voter's ID", "SSS ID", "Other Government ID"
    ];

    // Map UI dropdown labels in kyc.html to the canonical ID_TYPES above
    private const ID_TYPE_ALIASES = [
        "philippine national id (philsys)" => "PhilSys National ID",
        "passport"                         => "Philippine Passport",
    ];

    // IDNumber is left out on purpose.
    private const COLUMNS = "KYCID, UserID, IDType, IDImageURL, SelfieImageURL, VerificationStatus,
                             SubmittedAt, VerifiedAt, ReviewedByAdminID, RejectionReason";

    public function __construct()
    {
        $this->db = Database::getConnection();
    }

    // A user sends the ID check. Returns the new KYCID. The status starts as Pending.
    public function submit(int $userId, array $data): int
    {
        $rawType = isset($data["IDType"]) ? trim((string)$data["IDType"]) : null;
        if ($rawType !== null && isset(self::ID_TYPE_ALIASES[strtolower($rawType)])) {
            $rawType = self::ID_TYPE_ALIASES[strtolower($rawType)];
        }
        $idType = Validator::oneOf($rawType, self::ID_TYPES, "ID type");
        $idNumber = Validator::text($data["IDNumber"] ?? null, "ID number", 50);
        if (!preg_match('/^[A-Za-z0-9\- ]{4,50}$/', $idNumber)) {
            throw new InvalidArgumentException("ID number must be 4 to 50 characters: letters, numbers, spaces or hyphens.");
        }
        $idImage = Validator::fileUrl($data["IDImageURL"] ?? "uploads/kyc/id_front.jpg", "ID photo");
        $selfie = Validator::fileUrl($data["SelfieImageURL"] ?? "uploads/kyc/selfie.jpg", "Selfie");

        $stmt = $this->db->prepare("SELECT COUNT(*) FROM `user` WHERE UserID = ?");
        $stmt->execute([$userId]);
        if ((int)$stmt->fetchColumn() === 0) {
            throw new OutOfBoundsException("User not found.");
        }

        $latest = $this->getLatestByUser($userId);
        if ($latest !== null && $latest["VerificationStatus"] === self::PENDING) {
            throw new InvalidArgumentException("You already have an ID check waiting for review.");
        }
        if ($latest !== null && $latest["VerificationStatus"] === self::APPROVED) {
            throw new InvalidArgumentException("Your ID is already verified.");
        }

        $stmt = $this->db->prepare(
            "INSERT INTO `kyc_verification` (UserID, IDType, IDNumber, IDImageURL, SelfieImageURL, VerificationStatus)
             VALUES (?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([$userId, $idType, Crypto::encrypt($idNumber), $idImage, $selfie, self::PENDING]);
        return (int)$this->db->lastInsertId();
    }

    public function getById(int $kycId): ?array
    {
        $stmt = $this->db->prepare("SELECT " . self::COLUMNS . " FROM `kyc_verification` WHERE KYCID = ?");
        $stmt->execute([$kycId]);
        return $this->format($stmt->fetch() ?: null);
    }

    // The newest request of a user, or null if the user never sent one.
    public function getLatestByUser(int $userId): ?array
    {
        $stmt = $this->db->prepare("SELECT " . self::COLUMNS . " FROM `kyc_verification` WHERE UserID = ? ORDER BY KYCID DESC LIMIT 1");
        $stmt->execute([$userId]);
        return $this->format($stmt->fetch() ?: null);
    }

    // For the admin: all requests, optionally only one status. Oldest first, so the longest waiting is on top.
    public function getAll(?string $status = null, int $limit = 20, int $offset = 0): array
    {
        $limit = max(1, min($limit, 100));
        $offset = max(0, $offset);

        $sql = "SELECT " . self::COLUMNS . " FROM `kyc_verification`";
        $values = [];
        if ($status !== null && $status !== "") {
            $status = Validator::oneOf($status, [self::PENDING, self::APPROVED, self::REJECTED], "Status");
            $sql .= " WHERE VerificationStatus = ?";
            $values[] = $status;
        }
        $sql .= " ORDER BY KYCID LIMIT $limit OFFSET $offset";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($values);
        return array_map([$this, "format"], $stmt->fetchAll());
    }

    // The admin approves a Pending request. $reviewedByAdminID is the AdminID from Module 10, saved as given.
    public function approve(int $kycId, $reviewedByAdminId): array
    {
        $reviewedByAdminId = Validator::id($reviewedByAdminId, "ReviewedByAdminID");
        $this->requirePending($kycId);

        $stmt = $this->db->prepare(
            "UPDATE `kyc_verification`
             SET VerificationStatus = ?, VerifiedAt = NOW(), ReviewedByAdminID = ?, RejectionReason = NULL
             WHERE KYCID = ? AND VerificationStatus = ?"
        );
        $stmt->execute([self::APPROVED, $reviewedByAdminId, $kycId, self::PENDING]);
        return $this->getById($kycId);
    }

    // The admin rejects a Pending request. A reason is needed.
    public function reject(int $kycId, $reviewedByAdminId, $reason): array
    {
        $reviewedByAdminId = Validator::id($reviewedByAdminId, "ReviewedByAdminID");
        $reason = Validator::text($reason, "Rejection reason", 1000);
        $this->requirePending($kycId);

        $stmt = $this->db->prepare(
            "UPDATE `kyc_verification`
             SET VerificationStatus = ?, VerifiedAt = NULL, ReviewedByAdminID = ?, RejectionReason = ?
             WHERE KYCID = ? AND VerificationStatus = ?"
        );
        $stmt->execute([self::REJECTED, $reviewedByAdminId, $reason, $kycId, self::PENDING]);
        return $this->getById($kycId);
    }

    // The short version for other modules: only the status, no photos.
    public function toStatusView(int $userId, ?array $kyc): array
    {
        if ($kyc === null) {
            return ["UserID" => $userId, "VerificationStatus" => "NotSubmitted"];
        }
        return [
            "UserID"             => $kyc["UserID"],
            "KYCID"              => $kyc["KYCID"],
            "VerificationStatus" => $kyc["VerificationStatus"],
            "SubmittedAt"        => $kyc["SubmittedAt"],
            "VerifiedAt"         => $kyc["VerifiedAt"],
            "RejectionReason"    => $kyc["RejectionReason"]
        ];
    }

    private function requirePending(int $kycId): void
    {
        $kyc = $this->getById($kycId);
        if ($kyc === null) {
            throw new OutOfBoundsException("KYC request not found.");
        }
        if ($kyc["VerificationStatus"] !== self::PENDING) {
            throw new InvalidArgumentException("This request was already " . $kyc["VerificationStatus"] . ".");
        }
    }

    private function format(?array $row): ?array
    {
        if ($row === null) {
            return null;
        }
        $row["KYCID"] = (int)$row["KYCID"];
        $row["UserID"] = (int)$row["UserID"];
        if ($row["ReviewedByAdminID"] !== null) {
            $row["ReviewedByAdminID"] = (int)$row["ReviewedByAdminID"];
        }
        return $row;
    }
}
