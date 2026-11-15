<?php
require_once __DIR__ . '/../config/Database.php';
require_once __DIR__ . '/Validator.php';
require_once __DIR__ . '/Role.php';

class UserRole
{
    private PDO $db;
    private Role $roles;

    public function __construct()
    {
        $this->db = Database::getConnection();
        $this->roles = new Role();
    }

    // Gives a role to a user. Returns the new UserRoleID.
    public function assign(int $userId, int $roleId): int
    {
        $this->requireUserExists($userId);
        if ($this->roles->getById($roleId) === null) {
            throw new OutOfBoundsException("Role not found.");
        }
        if ($this->userHasRoleId($userId, $roleId)) {
            throw new InvalidArgumentException("This user already has this role.");
        }

        $stmt = $this->db->prepare("INSERT INTO `user_role` (UserID, RoleID) VALUES (?, ?)");
        $stmt->execute([$userId, $roleId]);
        return (int)$this->db->lastInsertId();
    }

    // Same as assign(), but with the role name (buyer, seller, admin).
    public function assignByName(int $userId, string $roleName): int
    {
        $this->roles->ensureDefaults();
        $role = $this->roles->getByName($roleName);
        if ($role === null) {
            throw new OutOfBoundsException("Role not found.");
        }
        return $this->assign($userId, (int)$role["RoleID"]);
    }

    // Used by the API: reads UserID and RoleName (or RoleID) from the request body.
    public function assignFromData(array $data): int
    {
        $userId = Validator::id($data["UserID"] ?? null, "UserID");

        if (isset($data["RoleName"])) {
            return $this->assignByName($userId, Validator::text($data["RoleName"], "RoleName", 100));
        }
        return $this->assign($userId, Validator::id($data["RoleID"] ?? null, "RoleID or RoleName"));
    }

    public function getRolesByUser(int $userId): array
    {
        $stmt = $this->db->prepare(
            "SELECT r.RoleID, r.RoleName, r.Description, ur.AssignedAt
             FROM `user_role` ur
             JOIN `role` r ON r.RoleID = ur.RoleID
             WHERE ur.UserID = ?
             ORDER BY r.RoleID"
        );
        $stmt->execute([$userId]);
        return $stmt->fetchAll();
    }

    public function hasRole(int $userId, string $roleName): bool
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM `user_role` ur
             JOIN `role` r ON r.RoleID = ur.RoleID
             WHERE ur.UserID = ? AND r.RoleName = ?"
        );
        $stmt->execute([$userId, strtolower(trim($roleName))]);
        return (int)$stmt->fetchColumn() > 0;
    }

    // Takes a role away. An admin cannot remove their own admin role (so the site never loses all admins by accident).
    public function remove(int $userId, int $roleId, int $actingUserId): void
    {
        if (!$this->userHasRoleId($userId, $roleId)) {
            throw new OutOfBoundsException("This user does not have this role.");
        }
        $role = $this->roles->getById($roleId);
        if ($userId === $actingUserId && $role["RoleName"] === "admin") {
            throw new InvalidArgumentException("You cannot remove your own admin role.");
        }

        $stmt = $this->db->prepare("DELETE FROM `user_role` WHERE UserID = ? AND RoleID = ?");
        $stmt->execute([$userId, $roleId]);
    }

    private function userHasRoleId(int $userId, int $roleId): bool
    {
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM `user_role` WHERE UserID = ? AND RoleID = ?");
        $stmt->execute([$userId, $roleId]);
        return (int)$stmt->fetchColumn() > 0;
    }

    private function requireUserExists(int $userId): void
    {
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM `user` WHERE UserID = ?");
        $stmt->execute([$userId]);
        if ((int)$stmt->fetchColumn() === 0) {
            throw new OutOfBoundsException("User not found.");
        }
    }
}
