<?php
require_once __DIR__ . '/../config/Database.php';
require_once __DIR__ . '/Validator.php';

class UserProfile
{
    private PDO $db;

    private const COLUMNS = "ProfileID, UserID, Username, Bio, ProfilePictureURL, DateOfBirth, CreatedAt";

    public function __construct()
    {
        $this->db = Database::getConnection();
    }

    // Checks the profile fields. For a new profile the Username is made up when it is missing.
    // $baseForUsername is used for that (for example the part of the email before the @).
    public function validate(array $data, bool $isNew, int $userId = 0, string $baseForUsername = "user"): array
    {
        $clean = [];

        if (Validator::has($data, "Username") || $isNew) {
            if (Validator::has($data, "Username") && trim((string)$data["Username"]) !== "") {
                $username = Validator::text($data["Username"], "Username", 30);
                if (!preg_match('/^[A-Za-z0-9_.]{3,30}$/', $username)) {
                    throw new InvalidArgumentException("Username must be 3 to 30 characters: letters, numbers, dot or underscore.");
                }
                if ($this->usernameTaken($username, $userId)) {
                    throw new InvalidArgumentException("This username is already taken.");
                }
                $clean["Username"] = $username;
            } elseif ($isNew) {
                $clean["Username"] = $this->makeUsername($baseForUsername);
            } else {
                throw new InvalidArgumentException("Username is required.");
            }
        }
        if (Validator::has($data, "Bio")) {
            $clean["Bio"] = Validator::text($data["Bio"], "Bio", 1000, false);
        }
        if (Validator::has($data, "ProfilePictureURL")) {
            $clean["ProfilePictureURL"] = Validator::fileUrl($data["ProfilePictureURL"], "Profile picture", false);
        }
        if (Validator::has($data, "DateOfBirth")) {
            $clean["DateOfBirth"] = Validator::pastDate($data["DateOfBirth"], "Date of birth");
        }
        return $clean;
    }

    // Creates the one profile of a user. Returns the new ProfileID.
    public function create(int $userId, array $data, string $baseForUsername = "user"): int
    {
        if ($this->getByUserId($userId) !== null) {
            throw new InvalidArgumentException("This user already has a profile.");
        }
        $clean = $this->validate($data, true, $userId, $baseForUsername);

        $stmt = $this->db->prepare(
            "INSERT INTO `user_profile` (UserID, Username, Bio, ProfilePictureURL, DateOfBirth)
             VALUES (?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            $userId,
            $clean["Username"],
            $clean["Bio"] ?? null,
            $clean["ProfilePictureURL"] ?? null,
            $clean["DateOfBirth"] ?? null
        ]);
        return (int)$this->db->lastInsertId();
    }

    public function getByUserId(int $userId): ?array
    {
        $stmt = $this->db->prepare("SELECT " . self::COLUMNS . " FROM `user_profile` WHERE UserID = ?");
        $stmt->execute([$userId]);
        return $stmt->fetch() ?: null;
    }

    // Changes only the fields that were sent. Returns the updated profile.
    public function update(int $userId, array $data): array
    {
        if ($this->getByUserId($userId) === null) {
            throw new OutOfBoundsException("Profile not found.");
        }
        $clean = $this->validate($data, false, $userId);
        if (count($clean) === 0) {
            throw new InvalidArgumentException("Nothing to update. Send Username, Bio, ProfilePictureURL or DateOfBirth.");
        }

        $sets = [];
        $values = [];
        foreach ($clean as $column => $value) {   // column names come from validate(), never from the caller
            $sets[] = "$column = ?";
            $values[] = $value;
        }
        $values[] = $userId;

        $stmt = $this->db->prepare("UPDATE `user_profile` SET " . implode(", ", $sets) . " WHERE UserID = ?");
        $stmt->execute($values);
        return $this->getByUserId($userId);
    }

    // What other users may see: no birthday.
    public function toPublic(array $profile): array
    {
        unset($profile["DateOfBirth"]);
        return $profile;
    }

    private function usernameTaken(string $username, int $exceptUserId): bool
    {
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM `user_profile` WHERE Username = ? AND UserID <> ?");
        $stmt->execute([$username, $exceptUserId]);
        return (int)$stmt->fetchColumn() > 0;
    }

    // Builds a free username like "juan.delacruz" or "juan.delacruz4821".
    private function makeUsername(string $base): string
    {
        $base = strtolower(preg_replace('/[^A-Za-z0-9_.]/', '', $base));
        if (strlen($base) < 3) {
            $base = "user" . $base;
        }
        $base = substr($base, 0, 24);

        $username = $base;
        while ($this->usernameTaken($username, 0)) {
            $username = $base . random_int(1000, 9999);
        }
        return $username;
    }
}
