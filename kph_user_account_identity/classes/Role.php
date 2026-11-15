<?php
require_once __DIR__ . '/../config/Database.php';

class Role
{
    private PDO $db;

    // The three roles every KameraPH install needs.
    private const DEFAULT_ROLES = [
        "buyer"  => "Can browse, buy, and review items.",
        "seller" => "Can list items for sale after passing the seller check.",
        "admin"  => "KPH staff who manage users, listings, disputes and reports."
    ];

    public function __construct()
    {
        $this->db = Database::getConnection();
    }

    // Adds buyer, seller and admin if they are missing. Safe to run again and again.
    public function ensureDefaults(): void
    {
        foreach (self::DEFAULT_ROLES as $name => $description) {
            if ($this->getByName($name) === null) {
                $stmt = $this->db->prepare("INSERT INTO `role` (RoleName, Description) VALUES (?, ?)");
                $stmt->execute([$name, $description]);
            }
        }
    }

    public function getAll(): array
    {
        return $this->db->query("SELECT RoleID, RoleName, Description FROM `role` ORDER BY RoleID")->fetchAll();
    }

    public function getById(int $roleId): ?array
    {
        $stmt = $this->db->prepare("SELECT RoleID, RoleName, Description FROM `role` WHERE RoleID = ?");
        $stmt->execute([$roleId]);
        return $stmt->fetch() ?: null;
    }

    public function getByName(string $roleName): ?array
    {
        $stmt = $this->db->prepare("SELECT RoleID, RoleName, Description FROM `role` WHERE RoleName = ?");
        $stmt->execute([strtolower(trim($roleName))]);
        return $stmt->fetch() ?: null;
    }
}
