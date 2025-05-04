<?php

/**
 * Authenticates an API request based on the provided API key in headers.
 *
 * Checks 'Authorization: Bearer <key>' first, then 'X-API-Key: <key>'.
 * Verifies the key against the hashed key in the database using password_verify().
 *
 * @param PDO $pdo The database connection object (PDO).
 * @return array|false Returns the API key data (id, associated_permissions) if valid and active, otherwise false.
 */
function authenticateApiKey(PDO $pdo): array|false
{
    $apiKey = null;

    // 1. Extract API Key from headers
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? null;
    $apiKeyHeader = $_SERVER['HTTP_X_API_KEY'] ?? null;

    if ($authHeader && preg_match('/^Bearer\s+(.*)$/i', $authHeader, $matches)) {
        $apiKey = $matches[1];
    } elseif ($apiKeyHeader) {
        $apiKey = $apiKeyHeader;
    }

    if (!$apiKey) {
        return false; // No API key provided
    }

    // 2. Query the database for a matching *active* key hash
    // IMPORTANT: We fetch the HASH, not try to hash the input key here.
    // We select the hash to use with password_verify()
    try {
        // Using PDO as an example, adjust if using mysqli
        // Fetch associated IDs needed for scoped permissions
        $stmt = $pdo->prepare("SELECT id, api_key_hash, associated_permissions, associated_user_id, associated_site_id FROM api_keys WHERE api_keys.is_active = 1");
        // If performance becomes an issue with many keys, add a WHERE clause
        // that filters by a non-sensitive part of the key if possible, or implement caching.
        // For now, fetching all active keys and verifying in PHP is acceptable for moderate loads.
        $stmt->execute();
        $keys = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($keys as $keyRecord) {
            // 3. Verify the provided key against the stored hash
            if (password_verify($apiKey, $keyRecord['api_key_hash'])) {
                // Key is valid and active, return relevant data
                // Update last_used_at timestamp (optional, consider performance impact)
                 try {
                     $updateStmt = $pdo->prepare("UPDATE api_keys SET last_used_at = NOW() WHERE id = :id");
                     $updateStmt->bindParam(':id', $keyRecord['id'], PDO::PARAM_INT);
                     $updateStmt->execute();
                 } catch (PDOException $e) {
                     // Log error, but don't fail the authentication if the update fails
                     error_log("Failed to update last_used_at for API key ID {$keyRecord['id']}: " . $e->getMessage());
                 }

                return [
                    'id' => $keyRecord['id'],
                    'associated_permissions' => $keyRecord['associated_permissions'] ?? '[]', // Default to empty JSON array
                    'associated_user_id' => $keyRecord['associated_user_id'], // May be null
                    'associated_site_id' => $keyRecord['associated_site_id']  // May be null
                ];
            }
        }

        // If loop completes without finding a match
        return false; // Invalid or inactive key

    } catch (PDOException $e) {
        // Log database error
        error_log("API Key Authentication DB Error: " . $e->getMessage());
        // In a real scenario, you might want to trigger a 500 error here
        // via the error handler, but for authentication failure, false is appropriate.
        return false;
    }
}

/**
 * Checks if the authenticated API key has the required permission(s).
 *
 * @param string|array $requiredPermission The permission string (e.g., "create:note") or an array of required permissions.
 * @param array $apiKeyData The authenticated API key data from authenticateApiKey(), specifically ['associated_permissions'].
 * @return bool True if the key has the required permission(s), false otherwise.
 */
function checkApiKeyPermission(string|array $requiredPermission, array $apiKeyData): bool
{
    $permissionsJson = $apiKeyData['associated_permissions'] ?? '[]';
    $keyPermissions = json_decode($permissionsJson, true);

    // Handle potential JSON decode errors
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($keyPermissions)) {
        error_log("Failed to decode permissions JSON for API key ID {$apiKeyData['id']}: " . json_last_error_msg());
        return false; // Treat invalid JSON as no permissions
    }

    $requiredPermissions = is_array($requiredPermission) ? $requiredPermission : [$requiredPermission];

    // Check if all required permissions are present in the key's permissions
    foreach ($requiredPermissions as $reqPerm) {
        if (!in_array($reqPerm, $keyPermissions)) {
            return false; // Missing a required permission
        }
    }

    return true; // All required permissions found
}

?>