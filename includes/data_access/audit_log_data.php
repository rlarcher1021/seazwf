<?php
// includes/data_access/audit_log_data.php

/**
 * Logs a change made to a client's profile.
 *
 * @param PDO $pdo The database connection object.
 * @param int $clientId The ID of the client whose profile was changed.
 * @param int $changedByUserId The ID of the user who made the change.
 * @param string $fieldName The name of the field that was changed (e.g., 'first_name', 'question_id_5').
 * @param ?string $oldValue The value of the field before the change. Null if it was initially unset.
 * @param ?string $newValue The value of the field after the change. Null if it was unset.
 * @return bool True on successful logging, false otherwise.
 */
function logClientProfileChange(PDO $pdo, int $clientId, int $changedByUserId, string $fieldName, ?string $oldValue, ?string $newValue): bool
{
    // Avoid logging if the value hasn't actually changed.
    // Use strict comparison to differentiate null, empty string, '0', etc.
    if ($oldValue === $newValue) {
        return true; // No change, consider it a success in terms of logging (nothing needed).
    }

    $sql = "INSERT INTO client_profile_audit_log (client_id, changed_by_user_id, field_name, old_value, new_value, timestamp)
            VALUES (:client_id, :changed_by_user_id, :field_name, :old_value, :new_value, NOW())";

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(':client_id', $clientId, PDO::PARAM_INT);
        $stmt->bindParam(':changed_by_user_id', $changedByUserId, PDO::PARAM_INT);
        $stmt->bindParam(':field_name', $fieldName, PDO::PARAM_STR);
        $stmt->bindParam(':old_value', $oldValue, PDO::PARAM_STR);
        $stmt->bindParam(':new_value', $newValue, PDO::PARAM_STR);

        return $stmt->execute();
    } catch (PDOException $e) {
        // Optional: Log the error $e->getMessage()
        error_log("Error logging client profile change: " . $e->getMessage());
        return false;
    }
}

?>