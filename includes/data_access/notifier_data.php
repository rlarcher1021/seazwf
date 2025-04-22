<?php
// includes/data_access/notifier_data.php

// This file will contain functions related to the 'staff_notifications' table.

/**
 * Fetches active staff notifiers for a specific site, ordered by name.
 * Returns an array keyed by staff ID for easy lookup.
 *
 * @param PDO $pdo The PDO database connection object.
 * @param int $site_id The ID of the site.
 * @return array An associative array [staff_id => ['id' => ..., 'staff_name' => ..., 'staff_email' => ...]], or empty array on failure.
 */
function getActiveStaffNotifiersForSite(PDO $pdo, int $site_id): array
{
    // Join with users table to get the actual user ID and ensure user is active
    // Assumes users table has id, email, name, is_active columns
    // Assumes staff_notifications.staff_email links to users.email
    // Join with users table to get the actual user ID and ensure user is active and not deleted
    // Assumes users table has id, email, full_name, is_active, deleted_at columns
    // Assumes staff_notifications.staff_email links to users.email
    $sql = "SELECT u.id, u.full_name as staff_name, u.email as staff_email
            FROM staff_notifications sn
            JOIN users u ON sn.staff_email = u.email
            WHERE sn.site_id = :site_id
              AND sn.is_active = TRUE
              AND u.is_active = TRUE      -- Ensure the linked user is active
              AND u.deleted_at IS NULL  -- Ensure the linked user is not soft-deleted
            ORDER BY u.full_name ASC";   // Removed trailing comment

    try {
        $stmt = $pdo->prepare($sql);
        if (!$stmt) {
            error_log("ERROR getActiveStaffNotifiersForSite: Prepare failed for site ID {$site_id}. PDO Error: " . implode(" | ", $pdo->errorInfo()));
            return [];
        }

        $execute_success = $stmt->execute([':site_id' => $site_id]);
        if (!$execute_success) {
            error_log("ERROR getActiveStaffNotifiersForSite: Execute failed for site ID {$site_id}. Statement Error: " . implode(" | ", $stmt->errorInfo()));
            return [];
        }

        // Fetch as associative array and manually key by user ID
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $keyed_results = [];
        foreach ($results as $row) {
            // Use the user's ID (aliased as 'id' in the SELECT) as the key
            if (isset($row['id'])) {
                 $keyed_results[$row['id']] = $row;
            }
        }
        return $keyed_results;

    } catch (PDOException $e) {
        error_log("EXCEPTION in getActiveStaffNotifiersForSite for site ID {$site_id}: " . $e->getMessage());
        return [];
    }
}


/**
 * Adds a new staff notifier for a site.
 *
 * @param PDO $pdo PDO connection object.
 * @param int $site_id The ID of the site.
 * @param string $staff_name The name of the staff member.
 * @param string $staff_email The email of the staff member (should be pre-validated).
 * @return bool True on success, false on failure.
 */
function addNotifier(PDO $pdo, int $site_id, string $staff_name, string $staff_email): bool
{
    $sql = "INSERT INTO staff_notifications (site_id, staff_name, staff_email, is_active) VALUES (:sid, :name, :email, 1)";
    try {
        $stmt = $pdo->prepare($sql);
        if (!$stmt) {
             error_log("ERROR addNotifier: Prepare failed for site ID {$site_id}. PDO Error: " . implode(" | ", $pdo->errorInfo()));
             return false;
        }
        $success = $stmt->execute([':sid' => $site_id, ':name' => $staff_name, ':email' => $staff_email]);
        if (!$success) {
             error_log("ERROR addNotifier: Execute failed for site ID {$site_id}. Statement Error: " . implode(" | ", $stmt->errorInfo()));
        }
        return $success;
    } catch (PDOException $e) {
        error_log("EXCEPTION in addNotifier for site ID {$site_id}: " . $e->getMessage());
        return false;
    }
}

/**
 * Updates an existing staff notifier.
 *
 * @param PDO $pdo PDO connection object.
 * @param int $notifier_id The ID of the notifier record to update.
 * @param int $site_id The ID of the site (for verification).
 * @param string $staff_name The new name.
 * @param string $staff_email The new email (should be pre-validated).
 * @return bool True on success, false on failure.
 */
function updateNotifier(PDO $pdo, int $notifier_id, int $site_id, string $staff_name, string $staff_email): bool
{
    $sql = "UPDATE staff_notifications SET staff_name = :name, staff_email = :email WHERE id = :id AND site_id = :sid";
    try {
        $stmt = $pdo->prepare($sql);
         if (!$stmt) {
             error_log("ERROR updateNotifier: Prepare failed for ID {$notifier_id}. PDO Error: " . implode(" | ", $pdo->errorInfo()));
             return false;
        }
        $success = $stmt->execute([':name' => $staff_name, ':email' => $staff_email, ':id' => $notifier_id, ':sid' => $site_id]);
         if (!$success) {
             error_log("ERROR updateNotifier: Execute failed for ID {$notifier_id}. Statement Error: " . implode(" | ", $stmt->errorInfo()));
        }
        return $success;
    } catch (PDOException $e) {
        error_log("EXCEPTION in updateNotifier for ID {$notifier_id}: " . $e->getMessage());
        return false;
    }
}

/**
 * Deletes a staff notifier.
 *
 * @param PDO $pdo PDO connection object.
 * @param int $notifier_id The ID of the notifier record to delete.
 * @param int $site_id The ID of the site (for verification).
 * @return bool True on success, false on failure.
 */
function deleteNotifier(PDO $pdo, int $notifier_id, int $site_id): bool
{
    $sql = "DELETE FROM staff_notifications WHERE id = :id AND site_id = :sid";
    try {
        $stmt = $pdo->prepare($sql);
         if (!$stmt) {
             error_log("ERROR deleteNotifier: Prepare failed for ID {$notifier_id}. PDO Error: " . implode(" | ", $pdo->errorInfo()));
             return false;
        }
        $success = $stmt->execute([':id' => $notifier_id, ':sid' => $site_id]);
         if (!$success) {
             error_log("ERROR deleteNotifier: Execute failed for ID {$notifier_id}. Statement Error: " . implode(" | ", $stmt->errorInfo()));
        }
        // Check if a row was actually deleted
        return ($success && $stmt->rowCount() > 0);
    } catch (PDOException $e) {
        error_log("EXCEPTION in deleteNotifier for ID {$notifier_id}: " . $e->getMessage());
        return false;
    }
}

/**
 * Toggles the active status of a staff notifier.
 *
 * @param PDO $pdo PDO connection object.
 * @param int $notifier_id The ID of the notifier record.
 * @param int $site_id The ID of the site (for verification).
 * @return bool True on success, false on failure.
 */
function toggleNotifierActive(PDO $pdo, int $notifier_id, int $site_id): bool
{
    $sql = "UPDATE staff_notifications SET is_active = NOT is_active WHERE id = :id AND site_id = :sid";
    try {
        $stmt = $pdo->prepare($sql);
         if (!$stmt) {
             error_log("ERROR toggleNotifierActive: Prepare failed for ID {$notifier_id}. PDO Error: " . implode(" | ", $pdo->errorInfo()));
             return false;
        }
        $success = $stmt->execute([':id' => $notifier_id, ':sid' => $site_id]);
         if (!$success) {
             error_log("ERROR toggleNotifierActive: Execute failed for ID {$notifier_id}. Statement Error: " . implode(" | ", $stmt->errorInfo()));
        }
        return $success;
    } catch (PDOException $e) {
        error_log("EXCEPTION in toggleNotifierActive for ID {$notifier_id}: " . $e->getMessage());
        return false;
    }
}

/**
 * Fetches a specific notifier by its ID and site ID.
 * Used for the edit form.
 *
 * @param PDO $pdo PDO connection object.
 * @param int $notifier_id The ID of the notifier record.
 * @param int $site_id The ID of the site.
 * @return array|null Associative array of notifier data or null if not found/error.
 */
function getNotifierByIdAndSite(PDO $pdo, int $notifier_id, int $site_id): ?array
{
    $sql = "SELECT * FROM staff_notifications WHERE id = :id AND site_id = :site_id";
    try {
        $stmt = $pdo->prepare($sql);
         if (!$stmt) {
             error_log("ERROR getNotifierByIdAndSite: Prepare failed for ID {$notifier_id}. PDO Error: " . implode(" | ", $pdo->errorInfo()));
             return null;
        }
        $stmt->execute([':id' => $notifier_id, ':site_id' => $site_id]);
        $notifier = $stmt->fetch(PDO::FETCH_ASSOC);
        return $notifier ?: null;
    } catch (PDOException $e) {
        error_log("EXCEPTION in getNotifierByIdAndSite for ID {$notifier_id}: " . $e->getMessage());
        return null;
    }
}

/**
 * Fetches all staff notifiers for a specific site (active and inactive).
 * Used for the list view on the configurations page.
 *
 * @param PDO $pdo The PDO database connection object.
 * @param int $site_id The ID of the site.
 * @return array An array of associative arrays representing the notifiers, or empty array on failure.
 */
function getAllNotifiersForSite(PDO $pdo, int $site_id): array
{
    $sql = "SELECT id, staff_name, staff_email, is_active FROM staff_notifications WHERE site_id = :site_id ORDER BY staff_name ASC";
    try {
        $stmt = $pdo->prepare($sql);
         if (!$stmt) {
             error_log("ERROR getAllNotifiersForSite: Prepare failed for site ID {$site_id}. PDO Error: " . implode(" | ", $pdo->errorInfo()));
             return [];
        }
        $stmt->execute([':site_id' => $site_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (PDOException $e) {
        error_log("EXCEPTION in getAllNotifiersForSite for site ID {$site_id}: " . $e->getMessage());
        return [];
    }
}


// Example function signatures (to be implemented during page refactoring):
// function addNotification(PDO $pdo, int $user_id, string $message, string $type = 'info', ?int $site_id = null): int|false { ... } // User-facing notifications
// function getNotificationsForUser(PDO $pdo, int $user_id, bool $include_read = false): array { ... }
// function getUnreadNotificationsForUser(PDO $pdo, int $user_id): array { ... }
// function markNotificationAsRead(PDO $pdo, int $notification_id, int $user_id): bool { ... }
// function markAllNotificationsAsRead(PDO $pdo, int $user_id): bool { ... }
// function deleteNotification(PDO $pdo, int $notification_id, int $user_id): bool { ... }
// function getNotificationById(PDO $pdo, int $notification_id): ?array { ... }



/**
 * Counts the number of recent staff notifications for a specific site within a given time limit.
 *
 * @param PDO $pdo PDO connection object.
 * @param int $site_id The ID of the site.
 * @param int $days_limit The number of past days to include.
 * @return int|false The count of notifications, or false on error.
 */
function countRecentNotificationsForSite(PDO $pdo, int $site_id, int $days_limit): int|false
{
    $date_limit_string = date('Y-m-d H:i:s', strtotime('-' . $days_limit . ' days'));
    $sql = "SELECT COUNT(*)
            FROM check_ins ci
            JOIN staff_notifications sn ON ci.notified_staff_id = sn.id
            WHERE ci.site_id = :site_id
              AND ci.notified_staff_id IS NOT NULL
              AND ci.check_in_time >= :start_date_limit";

    try {
        $stmt = $pdo->prepare($sql);
        if (!$stmt) {
            error_log("ERROR countRecentNotificationsForSite: Prepare failed for site ID {$site_id}. PDO Error: " . implode(" | ", $pdo->errorInfo()));
            return false;
        }
        $stmt->bindParam(':site_id', $site_id, PDO::PARAM_INT);
        $stmt->bindParam(':start_date_limit', $date_limit_string, PDO::PARAM_STR);
        $execute_success = $stmt->execute();
        if (!$execute_success) {
            error_log("ERROR countRecentNotificationsForSite: Execute failed for site ID {$site_id}. Statement Error: " . implode(" | ", $stmt->errorInfo()));
            return false;
        }
        return (int) $stmt->fetchColumn();
    } catch (PDOException $e) {
        error_log("EXCEPTION in countRecentNotificationsForSite for site ID {$site_id}: " . $e->getMessage());
        return false;
    }
}

/**
 * Fetches a paginated list of recent staff notifications for a specific site within a given time limit.
 *
 * @param PDO $pdo PDO connection object.
 * @param int $site_id The ID of the site.
 * @param int $days_limit The number of past days to include.
 * @param int $limit The maximum number of records to return.
 * @param int $offset The starting offset for pagination.
 * @return array|false An array of notification records, or false on error.
 */
function getRecentNotificationsForSite(PDO $pdo, int $site_id, int $days_limit, int $limit, int $offset): array|false
{
    $date_limit_string = date('Y-m-d H:i:s', strtotime('-' . $days_limit . ' days'));
    $sql = "SELECT ci.first_name, ci.last_name, ci.check_in_time, sn.staff_name AS notified_staff_name
            FROM check_ins ci
            JOIN staff_notifications sn ON ci.notified_staff_id = sn.id
            WHERE ci.site_id = :site_id
              AND ci.notified_staff_id IS NOT NULL
              AND ci.check_in_time >= :start_date_limit
            ORDER BY ci.check_in_time DESC
            LIMIT :limit OFFSET :offset";

    try {
        $stmt = $pdo->prepare($sql);
        if (!$stmt) {
            error_log("ERROR getRecentNotificationsForSite: Prepare failed for site ID {$site_id}. PDO Error: " . implode(" | ", $pdo->errorInfo()));
            return false;
        }
        $stmt->bindParam(':site_id', $site_id, PDO::PARAM_INT);
        $stmt->bindParam(':start_date_limit', $date_limit_string, PDO::PARAM_STR);
        $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindParam(':offset', $offset, PDO::PARAM_INT);
        $execute_success = $stmt->execute();
        if (!$execute_success) {
            error_log("ERROR getRecentNotificationsForSite: Execute failed for site ID {$site_id}. Statement Error: " . implode(" | ", $stmt->errorInfo()));
            return false;
        }
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (PDOException $e) {
        error_log("EXCEPTION in getRecentNotificationsForSite for site ID {$site_id}: " . $e->getMessage());
        return false;
    }
}

?>