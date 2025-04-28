<?php
// includes/data_access/grants_dal.php
// Data Access Layer for Grants

require_once __DIR__ . '/../db_connect.php'; // Adjust path as needed
require_once __DIR__ . '/../utils.php'; // For potential utility functions like checking roles

/**
 * Adds a new grant to the database.
 * Only Directors can perform this action.
 *
 * @param PDO $pdo The database connection object.
 * @param string $name The name of the grant.
 * @param string|null $grant_code The optional grant code.
 * @param string|null $description The optional grant description.
 * @param string|null $start_date The grant start date (YYYY-MM-DD).
 * @param string|null $end_date The grant end date (YYYY-MM-DD).
 * @return int|false The ID of the newly inserted grant, or false on failure or permission denied.
 */
function addGrant(PDO $pdo, string $name, ?string $grant_code, ?string $description, ?string $start_date, ?string $end_date): int|false {
    if (!isDirector()) {
        // Log permission error or handle appropriately
        error_log("Permission denied: User tried to add a grant without Director role.");
        return false;
    }

    $sql = "INSERT INTO grants (name, grant_code, description, start_date, end_date) VALUES (:name, :grant_code, :description, :start_date, :end_date)";
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(':name', $name, PDO::PARAM_STR);
        $stmt->bindParam(':grant_code', $grant_code, PDO::PARAM_STR);
        $stmt->bindParam(':description', $description, PDO::PARAM_STR);
        $stmt->bindParam(':start_date', $start_date); // PDO handles NULL correctly
        $stmt->bindParam(':end_date', $end_date);     // PDO handles NULL correctly

        if ($stmt->execute()) {
            return (int)$pdo->lastInsertId();
        } else {
            error_log("Error adding grant: " . implode(";", $stmt->errorInfo()));
            return false;
        }
    } catch (PDOException $e) {
        error_log("Database error in addGrant: " . $e->getMessage());
        return false;
    }
}

/**
 * Updates an existing grant.
 * Only Directors can perform this action.
 *
 * @param PDO $pdo The database connection object.
 * @param int $id The ID of the grant to update.
 * @param string $name The new name for the grant.
 * @param string|null $grant_code The new optional grant code.
 * @param string|null $description The new optional grant description.
 * @param string|null $start_date The new grant start date (YYYY-MM-DD).
 * @param string|null $end_date The new grant end date (YYYY-MM-DD).
 * @return bool True on success, false on failure or permission denied.
 */
function updateGrant(PDO $pdo, int $id, string $name, ?string $grant_code, ?string $description, ?string $start_date, ?string $end_date): bool {
    if (!isDirector()) {
        error_log("Permission denied: User tried to update grant ID {$id} without Director role.");
        return false;
    }

    // Ensure the grant exists and is not deleted before updating
    $grant = getGrantById($pdo, $id);
    if (!$grant) {
        error_log("Update failed: Grant ID {$id} not found or already deleted.");
        return false; // Grant doesn't exist or is soft-deleted
    }

    $sql = "UPDATE grants SET
                name = :name,
                grant_code = :grant_code,
                description = :description,
                start_date = :start_date,
                end_date = :end_date,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :id AND deleted_at IS NULL"; // Ensure we don't update a deleted record

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(':id', $id, PDO::PARAM_INT);
        $stmt->bindParam(':name', $name, PDO::PARAM_STR);
        $stmt->bindParam(':grant_code', $grant_code, PDO::PARAM_STR);
        $stmt->bindParam(':description', $description, PDO::PARAM_STR);
        $stmt->bindParam(':start_date', $start_date);
        $stmt->bindParam(':end_date', $end_date);

        $success = $stmt->execute();
        if (!$success) {
            error_log("Error updating grant ID {$id}: " . implode(";", $stmt->errorInfo()));
        }
        // Check if any row was actually affected
        return $success && $stmt->rowCount() > 0;
    } catch (PDOException $e) {
        error_log("Database error in updateGrant: " . $e->getMessage());
        return false;
    }
}

/**
 * Soft deletes a grant by setting the deleted_at timestamp.
 * Only Directors can perform this action.
 *
 * @param PDO $pdo The database connection object.
 * @param int $id The ID of the grant to soft delete.
 * @return bool True on success, false on failure or permission denied.
 */
function softDeleteGrant(PDO $pdo, int $id): bool {
    if (!isDirector()) {
        error_log("Permission denied: User tried to delete grant ID {$id} without Director role.");
        return false;
    }

    $sql = "UPDATE grants SET deleted_at = NOW() WHERE id = :id AND deleted_at IS NULL";
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(':id', $id, PDO::PARAM_INT);
        $success = $stmt->execute();
        if (!$success) {
            error_log("Error soft-deleting grant ID {$id}: " . implode(";", $stmt->errorInfo()));
        }
         // Check if any row was actually affected
        return $success && $stmt->rowCount() > 0;
    } catch (PDOException $e) {
        error_log("Database error in softDeleteGrant: " . $e->getMessage());
        return false;
    }
}

/**
 * Retrieves a single grant by its ID, ensuring it's not soft-deleted.
 * Accessible by any authenticated user (adjust if needed).
 *
 * @param PDO $pdo The database connection object.
 * @param int $id The ID of the grant to retrieve.
 * @return array|false An associative array representing the grant, or false if not found or deleted.
 */
function getGrantById(PDO $pdo, int $id): array|false {
    // Basic authentication check - adjust as needed
    // if (!isset($_SESSION['user_id'])) { return false; }

    $sql = "SELECT id, name, grant_code, description, start_date, end_date, created_at, updated_at
            FROM grants
            WHERE id = :id AND deleted_at IS NULL";
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Database error in getGrantById: " . $e->getMessage());
        return false;
    }
}

/**
 * Retrieves all non-deleted grants.
 * Accessible by any authenticated user (adjust if needed).
 * Useful for dropdowns or lists where Directors manage them.
 *
 * @param PDO $pdo The database connection object.
 * @return array An array of associative arrays representing the grants. Empty array if none found or error.
 */
function getAllGrants(PDO $pdo): array {
     // Basic authentication check - adjust as needed
    // if (!isset($_SESSION['user_id'])) { return []; }

    $sql = "SELECT id, name, grant_code, description, start_date, end_date, created_at, updated_at
            FROM grants
            WHERE deleted_at IS NULL
            ORDER BY name ASC"; // Order alphabetically by name
    try {
        $stmt = $pdo->query($sql); // No user input, query is safe
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Database error in getAllGrants: " . $e->getMessage());
        return []; // Return empty array on error
    }
}

?>