<?php
require_once __DIR__ . '/../config/Database.php';
require_once __DIR__ . '/Validator.php';

class UserAddress
{
    private PDO $db;

    private const ADDRESS_TYPES = ["Home", "Work", "Other"];

    public function __construct()
    {
        $this->db = Database::getConnection();
    }

    // Saves a new address for the user. The first address is always the default.
    // Returns the new AddressID.
    public function create(int $userId, array $data): int
    {
        $data = $this->normalizeUiData($data);
        $clean = $this->validate($data, true);

        $stmt = $this->db->prepare("SELECT COUNT(*) FROM `user_address` WHERE UserID = ?");
        $stmt->execute([$userId]);
        $count = (int)$stmt->fetchColumn();

        $maxAddresses = Database::getConfig()["max_addresses_per_user"];
        if ($count >= $maxAddresses) {
            throw new InvalidArgumentException("You can save at most $maxAddresses addresses. Delete one first.");
        }

        $makeDefault = ($count === 0) || ($clean["IsDefault"] ?? 0) === 1;

        $this->db->beginTransaction();
        if ($makeDefault) {
            $this->clearDefault($userId);
        }
        $stmt = $this->db->prepare(
            "INSERT INTO `user_address`
             (UserID, RecipientFirstName, RecipientMiddleName, RecipientLastName, PhoneNumber,
              HouseUnitNumber, Street, Barangay, City, Province, PostalCode, AddressType, IsDefault)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            $userId,
            $clean["RecipientFirstName"],
            $clean["RecipientMiddleName"] ?? null,
            $clean["RecipientLastName"],
            $clean["PhoneNumber"],
            $clean["HouseUnitNumber"],
            $clean["Street"],
            $clean["Barangay"],
            $clean["City"],
            $clean["Province"],
            $clean["PostalCode"],
            $clean["AddressType"],
            $makeDefault ? 1 : 0
        ]);
        $addressId = (int)$this->db->lastInsertId();
        $this->db->commit();

        return $addressId;
    }

    // Gets one address. Returns null if it does not exist.
    public function getById(int $addressId): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM `user_address` WHERE AddressID = ?");
        $stmt->execute([$addressId]);
        return $this->format($stmt->fetch() ?: null);
    }

    // All addresses of a user. The default address comes first.
    public function getByUser(int $userId): array
    {
        $stmt = $this->db->prepare("SELECT * FROM `user_address` WHERE UserID = ? ORDER BY IsDefault DESC, AddressID");
        $stmt->execute([$userId]);
        return array_map([$this, "format"], $stmt->fetchAll());
    }

    // Changes only the fields that were sent. The address must belong to $userId.
    public function update(int $addressId, int $userId, array $data): array
    {
        $current = $this->getOwned($addressId, $userId);
        $data = $this->normalizeUiData($data);
        $clean = $this->validate($data, false);
        if (count($clean) === 0) {
            throw new InvalidArgumentException("Nothing to update.");
        }
        if (isset($clean["IsDefault"]) && $clean["IsDefault"] === 0 && $current["IsDefault"] === 1) {
            // In UI edit mode, unchecking default on an already-default address when it's the only address should keep it default or error if explicit
            $stmtCount = $this->db->prepare("SELECT COUNT(*) FROM `user_address` WHERE UserID = ?");
            $stmtCount->execute([$userId]);
            if ((int)$stmtCount->fetchColumn() <= 1 && isset($data["RecipientFullName"])) {
                unset($clean["IsDefault"]);
            } else {
                throw new InvalidArgumentException("An address cannot stop being the default by itself. Make another address the default instead.");
            }
        }

        $this->db->beginTransaction();
        if (isset($clean["IsDefault"]) && $clean["IsDefault"] === 1) {
            $this->clearDefault($userId);
        }
        $sets = [];
        $values = [];
        foreach ($clean as $column => $value) {
            $sets[] = "$column = ?";
            $values[] = $value;
        }
        $values[] = $addressId;
        $stmt = $this->db->prepare("UPDATE `user_address` SET " . implode(", ", $sets) . " WHERE AddressID = ?");
        $stmt->execute($values);
        $this->db->commit();

        return $this->getById($addressId);
    }

    // Removes an address. If it was the default, the newest remaining address becomes the default.
    public function delete(int $addressId, int $userId): void
    {
        $current = $this->getOwned($addressId, $userId);

        $this->db->beginTransaction();
        $stmt = $this->db->prepare("DELETE FROM `user_address` WHERE AddressID = ?");
        $stmt->execute([$addressId]);

        if ($current["IsDefault"] === 1) {
            $stmt = $this->db->prepare(
                "UPDATE `user_address` SET IsDefault = 1 WHERE UserID = ? ORDER BY AddressID DESC LIMIT 1"
            );
            $stmt->execute([$userId]);
        }
        $this->db->commit();
    }

    // Bridges the UI form (which sends RecipientFullName and combined Street) with the database columns.
    private function normalizeUiData(array $data): array
    {
        if (!Validator::has($data, "RecipientFirstName") && !Validator::has($data, "RecipientLastName") && Validator::has($data, "RecipientFullName")) {
            $full = trim((string)$data["RecipientFullName"]);
            if ($full !== "") {
                $parts = preg_split('/\s+/', $full);
                if (count($parts) === 1) {
                    $data["RecipientFirstName"] = $parts[0];
                    $data["RecipientLastName"] = $parts[0];
                } else {
                    $data["RecipientLastName"] = array_pop($parts);
                    $data["RecipientFirstName"] = implode(" ", $parts);
                }
            }
        }
        if (!Validator::has($data, "HouseUnitNumber") && Validator::has($data, "Street")) {
            $streetRaw = trim((string)$data["Street"]);
            if (strpos($streetRaw, ",") !== false) {
                [$unit, $st] = array_map("trim", explode(",", $streetRaw, 2));
                $data["HouseUnitNumber"] = $unit !== "" ? $unit : $streetRaw;
                $data["Street"] = $st !== "" ? $st : $streetRaw;
            } elseif ($streetRaw !== "") {
                $data["HouseUnitNumber"] = $streetRaw;
            }
        }
        return $data;
    }

    // Finds an address that belongs to this user. Other people's addresses look like "not found".
    private function getOwned(int $addressId, int $userId): array
    {
        $address = $this->getById($addressId);
        if ($address === null || $address["UserID"] !== $userId) {
            throw new OutOfBoundsException("Address not found.");
        }
        return $address;
    }

    private function clearDefault(int $userId): void
    {
        $stmt = $this->db->prepare("UPDATE `user_address` SET IsDefault = 0 WHERE UserID = ?");
        $stmt->execute([$userId]);
    }

    // Checks the fields. A new address needs all of them (except the middle name).
    // When changing an address, only the fields that were sent are checked.
    private function validate(array $data, bool $isNew): array
    {
        $clean = [];

        if ($isNew || Validator::has($data, "RecipientFirstName")) {
            $clean["RecipientFirstName"] = Validator::name($data["RecipientFirstName"] ?? null, "Recipient first name");
        }
        if (Validator::has($data, "RecipientMiddleName")) {
            $clean["RecipientMiddleName"] = Validator::name($data["RecipientMiddleName"], "Recipient middle name", false);
        }
        if ($isNew || Validator::has($data, "RecipientLastName")) {
            $clean["RecipientLastName"] = Validator::name($data["RecipientLastName"] ?? null, "Recipient last name");
        }
        if ($isNew || Validator::has($data, "PhoneNumber")) {
            $clean["PhoneNumber"] = Validator::phone($data["PhoneNumber"] ?? null);
        }
        if ($isNew || Validator::has($data, "HouseUnitNumber")) {
            $clean["HouseUnitNumber"] = Validator::text($data["HouseUnitNumber"] ?? null, "House/unit number", 100);
        }
        if ($isNew || Validator::has($data, "Street")) {
            $clean["Street"] = Validator::text($data["Street"] ?? null, "Street", 255);
        }
        if ($isNew || Validator::has($data, "Barangay")) {
            $clean["Barangay"] = Validator::text($data["Barangay"] ?? null, "Barangay", 100);
        }
        if ($isNew || Validator::has($data, "City")) {
            $clean["City"] = Validator::text($data["City"] ?? null, "City", 100);
        }
        if ($isNew || Validator::has($data, "Province")) {
            $clean["Province"] = Validator::text($data["Province"] ?? null, "Province", 100);
        }
        if ($isNew || Validator::has($data, "PostalCode")) {
            $postalCode = Validator::text($data["PostalCode"] ?? null, "Postal code", 10);
            if (!preg_match('/^\d{4}$/', $postalCode)) {
                throw new InvalidArgumentException("Postal code must be 4 digits.");
            }
            $clean["PostalCode"] = $postalCode;
        }
        if ($isNew || Validator::has($data, "AddressType")) {
            $clean["AddressType"] = Validator::oneOf($data["AddressType"] ?? null, self::ADDRESS_TYPES, "Address type");
        }
        if (Validator::has($data, "IsDefault")) {
            $flag = filter_var($data["IsDefault"], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($flag === null) {
                throw new InvalidArgumentException("IsDefault must be true or false.");
            }
            $clean["IsDefault"] = $flag ? 1 : 0;
        }
        return $clean;
    }

    // Makes numbers real numbers in the JSON (UserID 5, not "5") and includes RecipientFullName for UI convenience.
    private function format(?array $row): ?array
    {
        if ($row === null) {
            return null;
        }
        $row["AddressID"] = (int)$row["AddressID"];
        $row["UserID"] = (int)$row["UserID"];
        $row["IsDefault"] = (int)$row["IsDefault"];
        $parts = array_values(array_filter([$row["RecipientFirstName"] ?? "", $row["RecipientMiddleName"] ?? "", $row["RecipientLastName"] ?? ""], fn($p) => trim((string)$p) !== ""));
        if (count($parts) === 2 && $parts[0] === $parts[1]) {
            $row["RecipientFullName"] = $parts[0];
        } else {
            $row["RecipientFullName"] = implode(" ", $parts);
        }
        if (!isset($row["Region"])) {
            $row["Region"] = $row["Province"] === "Metro Manila" ? "National Capital Region (NCR)" : "";
        }
        return $row;
    }
}
