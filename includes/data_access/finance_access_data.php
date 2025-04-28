<?php
// includes/data_access/finance_access_data.php
// Data Access Layer for Finance Department Access Rules

require_once __DIR__ . '/../db_connect.php'; // Adjust path as needed

/**
 * Retrieves the IDs of departments that a specific Finance user is allowed to access.
 *
 * @param PDO $pdo The PDO database connection object.
 * @param int $userId The ID of the Finance user (must have 'Finance' role).
 * @return array An array of integer department IDs the user can access. Returns an empty array if none found or on error.
 */
function getAccessibleDepartmentIdsForFinanceUser(PDO $pdo, int $userId): array
{
    // Optional: Add a check here or in the calling code to ensure the $userId actually corresponds to a user with the 'Finance' role.
    // $userRoleCheckStmt = $pdo->prepare("SELECT role FROM users WHERE id = ?");
    // $userRoleCheckStmt->execute([$userId]);
    // $role = $userRoleCheckStmt->fetchColumn();
    // if ($role !== 'Finance') {
    //     error_log("Attempted to get accessible departments for non-finance user ID: {$userId}");
    //     return [];
    // }

    $sql = "SELECT accessible_department_id
            FROM finance_department_access
            WHERE finance_user_id = :user_id";

    try {
        $stmt = $pdo->prepare($sql);
        if (!$stmt) {
            error_log("ERROR getAccessibleDepartmentIdsForFinanceUser: Prepare failed for user ID {$userId}. PDO Error: " . implode(" | ", $pdo->errorInfo()));
            return [];
        }
        $stmt->execute([':user_id' => $userId]);

        // Fetch all department IDs into a simple array
        $departmentIds = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);

        // Ensure all IDs are integers (fetchAll PDO::FETCH_COLUMN usually returns strings)
        return array_map('intval', $departmentIds);

    } catch (PDOException $e) {
        error_log("EXCEPTION in getAccessibleDepartmentIdsForFinanceUser for user ID {$userId}: " . $e->getMessage());
        return []; // Return empty array on error
    }
}

// NOTE: Phase 1 excludes the UI for managing these access rules.
// Functions like addFinanceAccess(), removeFinanceAccess() would go here if needed later.

?>