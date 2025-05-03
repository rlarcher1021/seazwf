<?php

/**
 * Data Access Layer for API Keys.
 * Handles database operations related to the api_keys table.
 */
class ApiKeyData {

    /**
     * Fetches all active API keys (where is_active = 1).
     *
     * @param PDO $pdo The database connection object.
     * @return array|false An array of active API key records (associative arrays)
     *                     containing id, name (aliased from description), associated_permissions, created_at,
     *                     last_used_at, associated_user_id, associated_site_id, is_active
     *                     or false on failure.
     */
    public static function getAllActiveApiKeys(PDO $pdo): array|false {
        // $originalErrorMode = $pdo->getAttribute(PDO::ATTR_ERRMODE); // DEBUG Removed
        // $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); // DEBUG Removed
        // error_log("ApiKeyData::getAllActiveApiKeys - Entered function."); // DEBUG Removed

        // Reverting: Using 'name' and 'revoked_at IS NULL' based on original logic/errors
        $sql = "SELECT
                    id,
                    name, /* Assuming 'name' is correct column */
                    associated_permissions,
                    created_at,
                    last_used_at,
                    associated_user_id,
                    associated_site_id
                    /* Removed is_active */
                FROM api_keys
                WHERE revoked_at IS NULL /* Reverted: Check revoked_at */
                ORDER BY created_at DESC";

        try {
            // error_log("ApiKeyData::getAllActiveApiKeys - Executing query (using name, revoked_at IS NULL)..."); // DEBUG Removed
            $stmt = $pdo->query($sql); // No user input, direct query is safe here
            $keys = $stmt->fetchAll(PDO::FETCH_ASSOC);
            // error_log("ApiKeyData::getAllActiveApiKeys - Query successful. Found " . count($keys) . " keys."); // DEBUG Removed
            // $pdo->setAttribute(PDO::ATTR_ERRMODE, $originalErrorMode); // DEBUG Removed
            return $keys;
        } catch (PDOException $e) {
            // Keep this error log for production issues
            error_log("ApiKeyData::getAllActiveApiKeys - Database error: " . $e->getMessage());
            // $pdo->setAttribute(PDO::ATTR_ERRMODE, $originalErrorMode); // DEBUG Removed
            return false;
        }
    }


    /**
     * Creates a new API key, hashes it, and stores it in the database.
     *
     * @param PDO $pdo The database connection object.
     * @param string $name User-defined name/description for the key.
     * @param array $permissionsArray Array of permission strings.
     * @param int|null $userId Optional associated user ID.
     * @param int|null $siteId Optional associated site ID.
     * @return string|null The *unhashed* generated API key string on success, or null on failure or invalid permissions.
     */
    public static function createApiKey(PDO $pdo, string $name, array $permissionsArray, ?int $userId, ?int $siteId): ?string {
        // error_log("ApiKeyData::createApiKey - Entered function."); // DEBUG Removed
        // error_log("  Parameters received:"); // DEBUG Removed
        // error_log("    name: " . var_export($name, true)); // DEBUG Removed
        // error_log("    permissionsArray: " . var_export($permissionsArray, true)); // DEBUG Removed
        // error_log("    userId: " . var_export($userId, true)); // DEBUG Removed
        // error_log("    siteId: " . var_export($siteId, true)); // DEBUG Removed

        // $originalErrorMode = $pdo->getAttribute(PDO::ATTR_ERRMODE); // DEBUG Removed
        // $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); // DEBUG Removed - Assume global setting
        // error_log("ApiKeyData::createApiKey - PDO error mode set to EXCEPTION."); // DEBUG Removed

        // Basic check if PDO is valid (optional, depends on how $pdo is guaranteed)
        if (!$pdo) {
            error_log("ApiKeyData::createApiKey - Error: PDO connection object is invalid/null."); // Keep this check
            return null;
        }
        // else { // DEBUG Removed
        //      error_log("ApiKeyData::createApiKey - PDO connection object appears valid."); // DEBUG Removed
        // }


        $allowedPermissions = [
            'read:checkin_data', 'create:checkin_note', 'read:budget_allocations',
            'create:forum_post', 'read:all_forum_posts', 'read:recent_forum_posts', 'generate:reports',
            'read:all_checkin_data', 'read:site_checkin_data',
            'read:all_allocation_data', 'read:own_allocation_data'
            // Add any other valid permissions here
        ];

        // Validate permissions
        foreach ($permissionsArray as $permission) {
            if (!in_array($permission, $allowedPermissions)) {
                error_log("ApiKeyData::createApiKey - Invalid permission provided: " . $permission); // Keep this log
                // $pdo->setAttribute(PDO::ATTR_ERRMODE, $originalErrorMode); // DEBUG Removed
                return null; // Or throw an Exception
            }
        }
        // error_log("ApiKeyData::createApiKey - Permissions validated successfully."); // DEBUG Removed

        // Generate secure key
        try {
            $unhashedKey = bin2hex(random_bytes(32));
            // error_log("ApiKeyData::createApiKey - Generated unhashed key."); // DEBUG Removed
        } catch (Exception $e) {
            error_log("ApiKeyData::createApiKey - Failed to generate random bytes for API key: " . $e->getMessage()); // Keep this log
            // $pdo->setAttribute(PDO::ATTR_ERRMODE, $originalErrorMode); // DEBUG Removed
            return null;
        }

        // Hash the key
        $hashedKey = password_hash($unhashedKey, PASSWORD_DEFAULT);
        if ($hashedKey === false) {
            error_log("ApiKeyData::createApiKey - Failed to hash API key."); // Keep this log
            // $pdo->setAttribute(PDO::ATTR_ERRMODE, $originalErrorMode); // DEBUG Removed
            return null;
        }
         // error_log("ApiKeyData::createApiKey - Hashed key successfully."); // DEBUG Removed

        // Format permissions (JSON)
        $permissionsJson = json_encode($permissionsArray);
        if ($permissionsJson === false) {
             error_log("ApiKeyData::createApiKey - Failed to encode permissions to JSON. JSON Error: " . json_last_error_msg()); // Keep this log
             // $pdo->setAttribute(PDO::ATTR_ERRMODE, $originalErrorMode); // DEBUG Removed
             return null;
        }
        // error_log("ApiKeyData::createApiKey - Permissions encoded to JSON: " . $permissionsJson); // DEBUG Removed


        // Correcting: Use 'name' for description, 'api_api_key_hash' for hash column, insert revoked_at = NULL
        $sql = "INSERT INTO api_keys (name, api_key_hash, associated_permissions, associated_user_id, associated_site_id, created_at, revoked_at)
                VALUES (:name, :api_key_hash, :permissions, :user_id, :site_id, NOW(), NULL)"; // Changed api_api_key_hash to api_key_hash

        try {
            $stmt = $pdo->prepare($sql);
            // error_log("ApiKeyData::createApiKey - SQL prepared successfully (using name, api_api_key_hash, revoked_at)."); // DEBUG Removed

            // Bind parameters
            $stmt->bindParam(':name', $name);
            $stmt->bindParam(':api_key_hash', $hashedKey); // Changed :api_api_key_hash to :api_key_hash
            $stmt->bindParam(':permissions', $permissionsJson);
            $stmt->bindParam(':user_id', $userId, PDO::PARAM_INT|PDO::PARAM_NULL);
            $stmt->bindParam(':site_id', $siteId, PDO::PARAM_INT|PDO::PARAM_NULL);

            // error_log("ApiKeyData::createApiKey - Parameters bound. Attempting execute..."); // DEBUG Removed
            $result = $stmt->execute(); // Execute wrapped in try/catch below

            if ($result) {
                // error_log("ApiKeyData::createApiKey - Execute successful. Returning unhashed key."); // DEBUG Removed
                // $pdo->setAttribute(PDO::ATTR_ERRMODE, $originalErrorMode); // DEBUG Removed
                return $unhashedKey; // Return the unhashed key on success
            } else {
                // This part might be less likely reached if PDO exceptions are on, but kept for safety
                $errorInfo = $stmt->errorInfo();
                error_log("ApiKeyData::createApiKey - Execute returned false. PDO Error Info: " . implode(", ", $errorInfo)); // Keep this log
                // $pdo->setAttribute(PDO::ATTR_ERRMODE, $originalErrorMode); // DEBUG Removed
                return null;
            }
        } catch (PDOException $e) {
            // Log specific PDO error during prepare or execute - Keep this block
             error_log("ApiKeyData::createApiKey - Database Error during prepare/execute: " . $e->getMessage());
             // error_log("  SQLSTATE: " . $e->getCode()); // DEBUG Removed - Optional
             // $pdo->setAttribute(PDO::ATTR_ERRMODE, $originalErrorMode); // DEBUG Removed
            return null; // Explicitly return null on DB exception
        } catch (Exception $e) {
             // Catch any other potential exceptions during the process - Keep this block
             error_log("ApiKeyData::createApiKey - General Error: " . $e->getMessage());
             // $pdo->setAttribute(PDO::ATTR_ERRMODE, $originalErrorMode); // DEBUG Removed
             return null;
        }
    }


    /**
     * Revokes an API key by setting its is_active flag to 0.
     *
     * @param PDO $pdo The database connection object.
     * @param int $apiKeyId The ID of the API key to revoke.
     * @return bool True on successful update, false otherwise.
     */
    public static function revokeApiKey(PDO $pdo, int $apiKeyId): bool {
         // $originalErrorMode = $pdo->getAttribute(PDO::ATTR_ERRMODE); // DEBUG Removed
        // $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); // DEBUG Removed
        // error_log("ApiKeyData::revokeApiKey - Entered function for key ID: " . $apiKeyId); // DEBUG Removed

        // Correcting: Set revoked_at = NOW()
        $sql = "UPDATE api_keys
                SET revoked_at = NOW()
                WHERE id = :api_key_id AND revoked_at IS NULL"; // Ensure we don't re-revoke

        try {
            $stmt = $pdo->prepare($sql);
            // error_log("ApiKeyData::revokeApiKey - SQL prepared (using revoked_at)."); // DEBUG Removed
            $result = $stmt->execute([':api_key_id' => $apiKeyId]);
            $rowCount = $stmt->rowCount();
            // error_log("ApiKeyData::revokeApiKey - Execute result (using revoked_at): " . var_export($result, true) . ", Row count: " . $rowCount); // DEBUG Removed

            // $pdo->setAttribute(PDO::ATTR_ERRMODE, $originalErrorMode); // DEBUG Removed
            // Check if any row was actually updated
            return $result && $rowCount > 0;

        } catch (PDOException $e) {
            error_log("ApiKeyData::revokeApiKey - Database error: " . $e->getMessage()); // Keep this log
            // $pdo->setAttribute(PDO::ATTR_ERRMODE, $originalErrorMode); // DEBUG Removed
            return false;
        }
    }

     /**
      * Validates an API key against the stored hash and checks if active.
      * Updates last_used_at timestamp on successful validation.
      *
      * @param PDO $pdo The database connection object.
      * @param string $apiKey The unhashed API key provided by the client.
      * @return array|false Returns key details (id, permissions, user_id, site_id) if valid and active, otherwise false.
      */
     public static function validateApiKey(PDO $pdo, string $apiKey): array|false {
         // $originalErrorMode = $pdo->getAttribute(PDO::ATTR_ERRMODE); // DEBUG Removed
         // $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); // DEBUG Removed
         // error_log("ApiKeyData::validateApiKey - Entered function."); // DEBUG Removed

         // We need to fetch potential matches first, as we don't know the hash yet.
         // This is inefficient but necessary without storing the key itself.
         // Correcting: Check revoked_at IS NULL and select 'api_api_key_hash' for hash
         $sql_fetch = "SELECT id, api_key_hash, associated_permissions, associated_user_id, associated_site_id
                       FROM api_keys
                       WHERE revoked_at IS NULL"; // Changed api_api_key_hash to api_key_hash

         try {
             $stmt_fetch = $pdo->query($sql_fetch);
             $activeKeys = $stmt_fetch->fetchAll(PDO::FETCH_ASSOC);
             // error_log("ApiKeyData::validateApiKey - Fetched " . count($activeKeys) . " non-revoked keys for comparison."); // DEBUG Removed

             $validKeyData = false;
             foreach ($activeKeys as $keyRecord) {
                 // Verify against the 'api_key_hash' column
                 if (isset($keyRecord['api_key_hash']) && password_verify($apiKey, $keyRecord['api_key_hash'])) { // Changed api_api_key_hash to api_key_hash
                     // error_log("ApiKeyData::validateApiKey - Key match found for ID: " . $keyRecord['id']); // DEBUG Removed
                     $validKeyData = [
                         'id' => $keyRecord['id'],
                         'permissions' => json_decode($keyRecord['associated_permissions'], true) ?: [], // Decode permissions
                         'user_id' => $keyRecord['associated_user_id'],
                         'site_id' => $keyRecord['associated_site_id']
                     ];

                     // Update last_used_at timestamp
                     $sql_update = "UPDATE api_keys SET last_used_at = NOW() WHERE id = :id";
                     $stmt_update = $pdo->prepare($sql_update);
                     $stmt_update->execute([':id' => $keyRecord['id']]);
                     // error_log("ApiKeyData::validateApiKey - Updated last_used_at for key ID: " . $keyRecord['id']); // DEBUG Removed
                     break; // Found the matching key
                 }
             }

             if (!$validKeyData) {
                 // error_log("ApiKeyData::validateApiKey - No matching active key found for the provided key."); // DEBUG Removed
             }

             // $pdo->setAttribute(PDO::ATTR_ERRMODE, $originalErrorMode); // DEBUG Removed
             return $validKeyData; // Return the found data or false

         } catch (PDOException $e) {
             error_log("ApiKeyData::validateApiKey - Database error: " . $e->getMessage()); // Keep this log
             // $pdo->setAttribute(PDO::ATTR_ERRMODE, $originalErrorMode); // DEBUG Removed
             return false;
         }
     }

    /**
     * Fetches a single API key's details by its ID.
     * Used to return data for dynamic table updates after creation.
     *
     * @param PDO $pdo The database connection object.
     * @param int $apiKeyId The ID of the API key to fetch.
     * @return array|false An associative array of the key's details or false if not found/error.
     */
    public static function getApiKeyById(PDO $pdo, int $apiKeyId): array|false {
        // $originalErrorMode = $pdo->getAttribute(PDO::ATTR_ERRMODE); // DEBUG Removed
        // $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); // DEBUG Removed
        // error_log("ApiKeyData::getApiKeyById - Entered function for ID: " . $apiKeyId); // DEBUG Removed

        // Select relevant columns, using 'name' as confirmed earlier
        $sql = "SELECT
                    id,
                    name,
                    associated_permissions,
                    created_at,
                    last_used_at,
                    revoked_at,
                    associated_user_id,
                    associated_site_id
                FROM api_keys
                WHERE id = :id";

        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':id' => $apiKeyId]);
            $keyData = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($keyData) {
                // error_log("ApiKeyData::getApiKeyById - Found key data for ID: " . $apiKeyId); // DEBUG Removed
            } else {
                // error_log("ApiKeyData::getApiKeyById - No key found for ID: " . $apiKeyId); // DEBUG Removed
            }

            // $pdo->setAttribute(PDO::ATTR_ERRMODE, $originalErrorMode); // DEBUG Removed
            return $keyData; // Returns the array or false if fetch failed

        } catch (PDOException $e) {
            error_log("ApiKeyData::getApiKeyById - Database error for ID {$apiKeyId}: " . $e->getMessage()); // Keep this log
            // $pdo->setAttribute(PDO::ATTR_ERRMODE, $originalErrorMode); // DEBUG Removed
            return false;
        }
    }
}