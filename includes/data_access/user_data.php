<?php
// includes/data_access/user_data.php

/**
 * Checks if a username already exists in the users table.
 *
 * @param PDO $pdo The PDO database connection object.
 * @param string $username The username to check.
 * @return bool True if the username exists, false otherwise.
 */
function isUsernameTaken(PDO $pdo, string $username): bool
{
    try {
        $stmt = $pdo->prepare("SELECT 1 FROM users WHERE username = :username AND deleted_at IS NULL LIMIT 1");
        if (!$stmt) {
            error_log("ERROR isUsernameTaken: Prepare failed for username '{$username}'. PDO Error: " . implode(" | ", $pdo->errorInfo()));
            return false; // Indicate error or uncertainty
        }
        $stmt->execute([':username' => $username]);
        return $stmt->fetchColumn() !== false;
    } catch (PDOException $e) {
        error_log("EXCEPTION in isUsernameTaken for username '{$username}': " . $e->getMessage());
        return false; // Indicate error or uncertainty
    }
}

/**
 * Adds a new user to the database.
 *
 * @param PDO $pdo The PDO database connection object.
 * @param array $userData Associative array containing user data:
 *        'username', 'full_name', 'email' (optional), 'job_title' (optional), 'password_hash', 'role', 'site_id' (nullable), 'is_active', 'department_id' (nullable)
 * @return int|false The ID of the newly inserted user, or false on failure.
 */
function addUser(PDO $pdo, array $userData): int|false
{
    // Handle optional job_title, store as NULL if empty/whitespace
    $jobTitleToSave = isset($userData['job_title']) && !empty(trim($userData['job_title'])) ? trim($userData['job_title']) : null;

    $sql = "INSERT INTO users (username, full_name, email, job_title, password_hash, role, site_id, department_id, is_active, created_at)
            VALUES (:username, :full_name, :email, :job_title, :password_hash, :role, :site_id, :department_id, :is_active, NOW())";
    try {
        $stmt = $pdo->prepare($sql);
        if (!$stmt) {
            error_log("ERROR addUser: Prepare failed. PDO Error: " . implode(" | ", $pdo->errorInfo()));
            return false;
        }
        $success = $stmt->execute([
            ':username' => $userData['username'],
            ':full_name' => $userData['full_name'],
            ':email' => $userData['email'] ?: null,
            ':job_title' => $jobTitleToSave,
            ':password_hash' => $userData['password_hash'],
            ':role' => $userData['role'],
            ':site_id' => $userData['site_id'], // Already determined if null needed based on role
            ':department_id' => $userData['department_id'] ?? null, // Add department_id, default to null if not provided
            ':is_active' => $userData['is_active']
        ]);

        if ($success) {
            return (int)$pdo->lastInsertId();
        } else {
            error_log("ERROR addUser: Execute failed for username '{$userData['username']}'. Statement Error: " . implode(" | ", $stmt->errorInfo()));
            return false;
        }
    } catch (PDOException $e) {
        error_log("EXCEPTION in addUser for username '{$userData['username']}': " . $e->getMessage());
        return false;
    }
}

/**
 * Updates an existing user's details.
 *
 * @param PDO $pdo The PDO database connection object.
 * @param int $userId The ID of the user to update.
 * @param array $userData Associative array containing user data to update:
 *        'full_name', 'email' (optional), 'job_title' (optional), 'role', 'site_id' (nullable), 'is_active', 'department_id' (nullable)
 * @return bool True on success, false on failure.
 */
function updateUser(PDO $pdo, int $userId, array $userData): bool
{
    // Handle optional job_title, store as NULL if empty/whitespace
    $jobTitleToSave = isset($userData['job_title']) && !empty(trim($userData['job_title'])) ? trim($userData['job_title']) : null;

    $sql = "UPDATE users SET full_name = :full_name, email = :email, job_title = :job_title, role = :role, site_id = :site_id, department_id = :department_id, is_active = :is_active
            WHERE id = :user_id";
    try {
        $stmt = $pdo->prepare($sql);
         if (!$stmt) {
            error_log("ERROR updateUser: Prepare failed for user ID {$userId}. PDO Error: " . implode(" | ", $pdo->errorInfo()));
            return false;
        }
        $success = $stmt->execute([
            ':full_name' => $userData['full_name'],
            ':email' => $userData['email'] ?: null,
            ':job_title' => $jobTitleToSave,
            ':role' => $userData['role'],
            ':site_id' => $userData['site_id'], // Already determined if null needed based on role
            ':department_id' => $userData['department_id'] ?? null, // Add department_id, default to null if not provided
            ':is_active' => $userData['is_active'],
            ':user_id' => $userId
        ]);
         if (!$success) {
            error_log("ERROR updateUser: Execute failed for user ID {$userId}. Statement Error: " . implode(" | ", $stmt->errorInfo()));
        }
        return $success;
    } catch (PDOException $e) {
        error_log("EXCEPTION in updateUser for user ID {$userId}: " . $e->getMessage());
        return false;
    }
}

/**
 * Fetches the current active status of a user.
 *
 * @param PDO $pdo The PDO database connection object.
 * @param int $userId The ID of the user.
 * @return int|false The status (1 or 0) or false if user not found or error.
 */
function getUserActiveStatus(PDO $pdo, int $userId): int|false
{
     try {
        $stmt = $pdo->prepare("SELECT is_active FROM users WHERE id = :user_id AND deleted_at IS NULL");
         if (!$stmt) {
            error_log("ERROR getUserActiveStatus: Prepare failed for user ID {$userId}. PDO Error: " . implode(" | ", $pdo->errorInfo()));
            return false;
        }
        $stmt->execute([':user_id' => $userId]);
        $status = $stmt->fetchColumn();
        // fetchColumn returns false if not found, otherwise the value (which might be '0' or '1')
        return ($status !== false) ? (int)$status : false;
    } catch (PDOException $e) {
        error_log("EXCEPTION in getUserActiveStatus for user ID {$userId}: " . $e->getMessage());
        return false;
    }
}


/**
 * Toggles the active status of a user.
 *
 * @param PDO $pdo The PDO database connection object.
 * @param int $userId The ID of the user to toggle.
 * @return bool True on success, false on failure.
 */
function toggleUserActiveStatus(PDO $pdo, int $userId): bool
{
    // Fetch current status first
    $current_status = getUserActiveStatus($pdo, $userId);

    if ($current_status === false) {
        error_log("ERROR toggleUserActiveStatus: Could not retrieve current status or user {$userId} not found.");
        return false; // User not found or error fetching status
    }

    $new_status = ($current_status === 1) ? 0 : 1;
    $sql = "UPDATE users SET is_active = :new_status WHERE id = :user_id";

    try {
        $stmt = $pdo->prepare($sql);
         if (!$stmt) {
            error_log("ERROR toggleUserActiveStatus: Prepare failed for user ID {$userId}. PDO Error: " . implode(" | ", $pdo->errorInfo()));
            return false;
        }
        $success = $stmt->execute([':new_status' => $new_status, ':user_id' => $userId]);
         if (!$success) {
            error_log("ERROR toggleUserActiveStatus: Execute failed for user ID {$userId}. Statement Error: " . implode(" | ", $stmt->errorInfo()));
        }
        return $success;
    } catch (PDOException $e) {
        error_log("EXCEPTION in toggleUserActiveStatus for user ID {$userId}: " . $e->getMessage());
        return false;
    }
}

/**
 * Resets a user's password.
 *
 * @param PDO $pdo The PDO database connection object.
 * @param int $userId The ID of the user.
 * @param string $newPasswordHash The new hashed password.
 * @return bool True on success, false on failure.
 */
function resetUserPassword(PDO $pdo, int $userId, string $newPasswordHash): bool
{
    $sql = "UPDATE users SET password_hash = :password_hash WHERE id = :user_id";
    try {
        $stmt = $pdo->prepare($sql);
         if (!$stmt) {
            error_log("ERROR resetUserPassword: Prepare failed for user ID {$userId}. PDO Error: " . implode(" | ", $pdo->errorInfo()));
            return false;
        }
        $success = $stmt->execute([':password_hash' => $newPasswordHash, ':user_id' => $userId]);
         if (!$success) {
            error_log("ERROR resetUserPassword: Execute failed for user ID {$userId}. Statement Error: " . implode(" | ", $stmt->errorInfo()));
        }
        return $success;
    } catch (PDOException $e) {
        error_log("EXCEPTION in resetUserPassword for user ID {$userId}: " . $e->getMessage());
        return false;
    }
}

/**
 * Deletes a user from the database.
 *
 * @param PDO $pdo The PDO database connection object.
 * @param int $userId The ID of the user to delete.
 * @return bool True if deletion was successful (at least one row affected), false otherwise.
 */
function deleteUser(PDO $pdo, int $userId): bool
{
    $sql = "UPDATE users SET deleted_at = NOW() WHERE id = :user_id";
    try {
        $stmt = $pdo->prepare($sql);
         if (!$stmt) {
            error_log("ERROR deleteUser: Prepare failed for user ID {$userId}. PDO Error: " . implode(" | ", $pdo->errorInfo()));
            return false;
        }
        $success = $stmt->execute([':user_id' => $userId]);
        if (!$success) {
             error_log("ERROR deleteUser: Execute failed for user ID {$userId}. Statement Error: " . implode(" | ", $stmt->errorInfo()));
             return false;
        }
        // Check if any rows were actually deleted
        return $stmt->rowCount() > 0;
    } catch (PDOException $e) {
        error_log("EXCEPTION in deleteUser for user ID {$userId}: " . $e->getMessage());
        return false;
    }
}

/**
 * Fetches all users with their assigned site names.
 *
 * @param PDO $pdo The PDO database connection object.
 * @return array An array of user data, or empty array on failure.
 */
function getAllUsersWithSiteNames(PDO $pdo): array
{
    // Added u.department_id and LEFT JOIN for department name
    $sql = "SELECT u.id, u.username, u.full_name, u.email, u.role, u.site_id, u.department_id, u.last_login, u.is_active,
                   s.name as site_name, d.name as department_name
            FROM users u
            LEFT JOIN sites s ON u.site_id = s.id
            LEFT JOIN departments d ON u.department_id = d.id
            WHERE u.deleted_at IS NULL
            ORDER BY u.username ASC";
    try {
        $stmt = $pdo->query($sql);
        if (!$stmt) {
             error_log("ERROR getAllUsersWithSiteNames: Query failed. PDO Error: " . implode(" | ", $pdo->errorInfo()));
             return [];
        }
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
        // Ensure boolean/int types are correct if needed (PDO often returns strings)
        foreach ($users as &$user) {
            $user['is_active'] = (int)$user['is_active'];
            $user['site_id'] = ($user['site_id'] !== null) ? (int)$user['site_id'] : null;
            $user['department_id'] = ($user['department_id'] !== null) ? (int)$user['department_id'] : null; // Cast department_id
            // department_name will be null if no department is assigned or join fails
        }
        unset($user); // Break reference
        return $users;
    } catch (PDOException $e) {
        error_log("EXCEPTION in getAllUsersWithSiteNames: " . $e->getMessage());
        return [];
    }
}

/**
 * Fetches a single user's complete data by ID.
 *
 * @param PDO $pdo The PDO database connection object.
 * @param int $userId The ID of the user to fetch.
 * @return array|null An associative array of the user's data, or null if not found or error.
 */
function getUserById(PDO $pdo, int $userId): ?array
{
    try {
        // Select specific columns including job_title and department_id
        $stmt = $pdo->prepare("SELECT id, username, full_name, email, job_title, role, site_id, department_id, password_hash, last_login, created_at, is_active FROM users WHERE id = :user_id AND deleted_at IS NULL");
         if (!$stmt) {
            error_log("ERROR getUserById: Prepare failed for user ID {$userId}. PDO Error: " . implode(" | ", $pdo->errorInfo()));
            return null;
        }
        $stmt->execute([':user_id' => $userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($user) {
             $user['is_active'] = (int)$user['is_active'];
             $user['site_id'] = ($user['site_id'] !== null) ? (int)$user['site_id'] : null;
             $user['department_id'] = ($user['department_id'] !== null) ? (int)$user['department_id'] : null; // Cast department_id
        }
        return $user ?: null;
    } catch (PDOException $e) {
        error_log("EXCEPTION in getUserById for user ID {$userId}: " . $e->getMessage());
        return null;
    }
}

/**
 * Fetches limited user details (username, full_name) needed for the reset password confirmation.
 *
 * @param PDO $pdo The PDO database connection object.
 * @param int $userId The ID of the user.
 * @return array|null Associative array ['username' => ..., 'full_name' => ...] or null if not found/error.
 */
function getUserDetailsForReset(PDO $pdo, int $userId): ?array
{
     try {
        $stmt = $pdo->prepare("SELECT username, full_name FROM users WHERE id = :user_id AND deleted_at IS NULL");
         if (!$stmt) {
            error_log("ERROR getUserDetailsForReset: Prepare failed for user ID {$userId}. PDO Error: " . implode(" | ", $pdo->errorInfo()));
            return null;
        }
        $stmt->execute([':user_id' => $userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        return $user ?: null;
    } catch (PDOException $e) {
        error_log("EXCEPTION in getUserDetailsForReset for user ID {$userId}: " . $e->getMessage());
        return null;
    }
}



/**
 * Verifies the user's current password.
 *
 * @param PDO $pdo The PDO database connection object.
 * @param int $userId The ID of the user.
 * @param string $currentPassword The password provided by the user.
 * @return bool True if the password matches, false otherwise.
 */
function verifyUserPassword(PDO $pdo, int $userId, string $currentPassword): bool
{
    try {
        $stmt = $pdo->prepare("SELECT password_hash FROM users WHERE id = :user_id AND deleted_at IS NULL");
        if (!$stmt) {
            error_log("ERROR verifyUserPassword: Prepare failed for user ID {$userId}. PDO Error: " . implode(" | ", $pdo->errorInfo()));
            return false;
        }
        $stmt->execute([':user_id' => $userId]);
        $hash_from_db = $stmt->fetchColumn();

        if ($hash_from_db === false) {
            error_log("ERROR verifyUserPassword: User ID {$userId} not found.");
            return false; // User not found
        }

        return password_verify($currentPassword, $hash_from_db);

    } catch (PDOException $e) {
        error_log("EXCEPTION in verifyUserPassword for user ID {$userId}: " . $e->getMessage());
        return false;
    }
}

/**
 * Updates the user's password.
 *
 * @param PDO $pdo The PDO database connection object.
 * @param int $userId The ID of the user.
 * @param string $newPassword The new password (plain text).
 * @return bool True on success, false on failure.
 */
function updateUserPassword(PDO $pdo, int $userId, string $newPassword): bool
{
    try {
        $newPasswordHash = password_hash($newPassword, PASSWORD_DEFAULT);
        if ($newPasswordHash === false) {
             error_log("ERROR updateUserPassword: password_hash failed for user ID {$userId}.");
             return false;
        }

        $stmt = $pdo->prepare("UPDATE users SET password_hash = :password_hash WHERE id = :user_id");
         if (!$stmt) {
            error_log("ERROR updateUserPassword: Prepare failed for user ID {$userId}. PDO Error: " . implode(" | ", $pdo->errorInfo()));
            return false;
        }
        $success = $stmt->execute([':password_hash' => $newPasswordHash, ':user_id' => $userId]);
         if (!$success) {
            error_log("ERROR updateUserPassword: Execute failed for user ID {$userId}. Statement Error: " . implode(" | ", $stmt->errorInfo()));
        }
        return $success;
    } catch (PDOException $e) {
        error_log("EXCEPTION in updateUserPassword for user ID {$userId}: " . $e->getMessage());
        return false;
    }
}

/**
 * Updates the user's profile information (full name and job title).
 *
 * @param PDO $pdo The PDO database connection object.
 * @param int $userId The ID of the user.
 * @param string $fullName The user's full name.
 * @param string|null $jobTitle The user's job title (can be null or empty).
 * @return bool True on success, false on failure.
 */
function updateUserProfile(PDO $pdo, int $userId, string $fullName, ?string $jobTitle): bool
{
    // Treat empty string job title as NULL in the database
    $jobTitleToSave = (empty(trim($jobTitle ?? ''))) ? null : trim($jobTitle);

    $sql = "UPDATE users SET full_name = :full_name, job_title = :job_title WHERE id = :user_id";
    try {
        $stmt = $pdo->prepare($sql);
         if (!$stmt) {
            error_log("ERROR updateUserProfile: Prepare failed for user ID {$userId}. PDO Error: " . implode(" | ", $pdo->errorInfo()));
            return false;
        }
        $success = $stmt->execute([
            ':full_name' => $fullName,
            ':job_title' => $jobTitleToSave,
            ':user_id' => $userId
        ]);
         if (!$success) {
            error_log("ERROR updateUserProfile: Execute failed for user ID {$userId}. Statement Error: " . implode(" | ", $stmt->errorInfo()));
        }
        return $success;
    } catch (PDOException $e) {
        error_log("EXCEPTION in updateUserProfile for user ID {$userId}: " . $e->getMessage());
        return false;
    }
}

// Removed closing PHP tag here to include the function below

/**
 * Fetches active users belonging to a specific department.
 * Used for populating dropdowns, e.g., in budget setup.
 *
 * @param PDO $pdo The PDO database connection object.
 * @param int $departmentId The ID of the department to filter users by.
 * @return array An array of associative arrays (id, full_name) for users in the department. Empty array if none found or error.
 */
function getActiveUsersByDepartment(PDO $pdo, int $departmentId): array
{
    $sql = "SELECT id, full_name
            FROM users
            WHERE department_id = :department_id
              AND deleted_at IS NULL
              AND is_active = 1
            ORDER BY full_name ASC";
    try {
        $stmt = $pdo->prepare($sql);
        if (!$stmt) {
            error_log("ERROR getActiveUsersByDepartment: Prepare failed for department ID {$departmentId}. PDO Error: " . implode(" | ", $pdo->errorInfo()));
            return [];
        }
        $stmt->execute([':department_id' => $departmentId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("EXCEPTION in getActiveUsersByDepartment for department ID {$departmentId}: " . $e->getMessage());
        return [];
    }
}