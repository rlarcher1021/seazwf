<?php
// includes/data_access/site_data.php

/**
 * Fetches a specific configuration value for a given site.
 * Returns null if the key is not found or an error occurs.
 *
 * @param PDO $pdo The PDO database connection object.
 * @param int $site_id The ID of the site.
 * @param string $config_key The configuration key to retrieve.
 * @return string|null The configuration value, or null on failure/not found.
 */
function getSiteConfigurationValue(PDO $pdo, int $site_id, string $config_key): ?string
{
    $trimmed_config_key = trim($config_key);
    if (empty($trimmed_config_key)) {
        error_log("ERROR getSiteConfigurationValue: Config key cannot be empty.");
        return null;
    }

    try {
        $sql = "SELECT config_value FROM site_configurations WHERE site_id = :site_id AND config_key = :config_key";
        $stmt = $pdo->prepare($sql);

        if (!$stmt) {
            error_log("ERROR getSiteConfigurationValue: Prepare failed for key '{$trimmed_config_key}'. PDO Error: " . implode(" | ", $pdo->errorInfo()));
            return null;
        }

        $execute_success = $stmt->execute(['site_id' => $site_id, 'config_key' => $trimmed_config_key]);

        if (!$execute_success) {
            error_log("ERROR getSiteConfigurationValue: Execute failed for key '{$trimmed_config_key}'. Statement Error: " . implode(" | ", $stmt->errorInfo()));
            return null;
        }

        $result = $stmt->fetchColumn();
        // fetchColumn returns false if no rows are found, or the value if found.
        // We return null in case of 'false' to distinguish from a potential stored '0' or empty string value.
        return ($result !== false) ? (string)$result : null;

    } catch (PDOException $e) {
        error_log("EXCEPTION in getSiteConfigurationValue for site $site_id, key $trimmed_config_key: " . $e->getMessage());
        return null;
    }
}

/**
 * Fetches all active sites (id and name).
 *
 * @param PDO $pdo The PDO database connection object.
 * @return array An array of active sites [ ['id' => ..., 'name' => ...], ... ] or empty array on failure.
 */
function getAllActiveSites(PDO $pdo): array
{
    try {
        $stmt = $pdo->query("SELECT id, name FROM sites WHERE is_active = TRUE ORDER BY name ASC");
        if (!$stmt) {
             error_log("ERROR getAllActiveSites: Query failed. PDO Error: " . implode(" | ", $pdo->errorInfo()));
             return [];
        }
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("EXCEPTION in getAllActiveSites: " . $e->getMessage());
        return [];
    }
}

/**
 * Fetches a specific active site by its ID.
 *
 * @param PDO $pdo The PDO database connection object.
 * @param int $site_id The ID of the site to fetch.
 * @return array|null An associative array representing the site [ 'id' => ..., 'name' => ... ] or null if not found/inactive/error.
 */
function getActiveSiteById(PDO $pdo, int $site_id): ?array
{
    try {
        $sql = "SELECT id, name FROM sites WHERE id = :site_id AND is_active = TRUE";
        $stmt = $pdo->prepare($sql);
        if (!$stmt) {
             error_log("ERROR getActiveSiteById: Prepare failed for site ID {$site_id}. PDO Error: " . implode(" | ", $pdo->errorInfo()));
             return null;
        }
        $execute_success = $stmt->execute([':site_id' => $site_id]);
        if (!$execute_success) {
             error_log("ERROR getActiveSiteById: Execute failed for site ID {$site_id}. Statement Error: " . implode(" | ", $stmt->errorInfo()));
             return null;
        }
        $site = $stmt->fetch(PDO::FETCH_ASSOC);
        return $site ?: null; // Return the site array or null if fetch returned false
    } catch (PDOException $e) {
        error_log("EXCEPTION in getActiveSiteById for site ID {$site_id}: " . $e->getMessage());
        return null;
    }
}


/**
 * Fetches details (id, name, email_collection_desc) for a specific active site by its ID.
 * Used for check-in page context.
 *
 * @param PDO $pdo The PDO database connection object.
 * @param int $site_id The ID of the site to fetch.
 * @return array|null An associative array with site details or null if not found/inactive/error.
 */
function getActiveSiteDetailsById(PDO $pdo, int $site_id): ?array
{
    try {
        $sql = "SELECT id, name, email_collection_desc FROM sites WHERE id = :site_id AND is_active = TRUE";
        $stmt = $pdo->prepare($sql);
        if (!$stmt) {
             error_log("ERROR getActiveSiteDetailsById: Prepare failed for site ID {$site_id}. PDO Error: " . implode(" | ", $pdo->errorInfo()));
             return null;
        }
        $execute_success = $stmt->execute([':site_id' => $site_id]);
        if (!$execute_success) {
             error_log("ERROR getActiveSiteDetailsById: Execute failed for site ID {$site_id}. Statement Error: " . implode(" | ", $stmt->errorInfo()));
             return null;
        }
        $site = $stmt->fetch(PDO::FETCH_ASSOC);
        return $site ?: null; // Return the site array or null if fetch returned false
    } catch (PDOException $e) {
        error_log("EXCEPTION in getActiveSiteDetailsById for site ID {$site_id}: " . $e->getMessage());
        return null;
    }
}


/**
 * Fetches specific boolean configuration flags for a site.
 * Returns an array with keys 'allow_email_collection' and 'allow_notifier',
 * defaulting to false if not found or on error.
 *
 * @param PDO $pdo The PDO database connection object.
 * @param int $site_id The ID of the site.
 * @return array Associative array ['allow_email_collection' => bool, 'allow_notifier' => bool].
 */
function getSiteCheckinConfigFlags(PDO $pdo, int $site_id): array
{
    $defaults = ['allow_email_collection' => false, 'allow_notifier' => false];
    $config_keys_to_fetch = ['allow_email_collection', 'allow_notifier'];

    try {
        $sql = "SELECT config_key, config_value
                FROM site_configurations
                WHERE site_id = :site_id
                  AND config_key IN ('allow_email_collection', 'allow_notifier')"; // Use IN clause

        $stmt = $pdo->prepare($sql);
        if (!$stmt) {
            error_log("ERROR getSiteCheckinConfigFlags: Prepare failed for site ID {$site_id}. PDO Error: " . implode(" | ", $pdo->errorInfo()));
            return $defaults;
        }

        $execute_success = $stmt->execute([':site_id' => $site_id]);
        if (!$execute_success) {
            error_log("ERROR getSiteCheckinConfigFlags: Execute failed for site ID {$site_id}. Statement Error: " . implode(" | ", $stmt->errorInfo()));
            return $defaults;
        }

        // Fetch key-value pairs directly
        $configs = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

        // Merge fetched values with defaults, casting to boolean
        $results = $defaults; // Start with defaults
        if (isset($configs['allow_email_collection'])) {
            $results['allow_email_collection'] = ((int)$configs['allow_email_collection'] === 1);
        }
        if (isset($configs['allow_notifier'])) {
            $results['allow_notifier'] = ((int)$configs['allow_notifier'] === 1);
        }
        return $results;

    } catch (PDOException $e) {
        error_log("EXCEPTION in getSiteCheckinConfigFlags for site ID {$site_id}: " . $e->getMessage());
        return $defaults; // Return defaults on exception
    }
}


/**
 * Fetches all sites (id, name, is_active), ordered by name.
 * Used for the configuration page dropdown.
 *
 * @param PDO $pdo The PDO database connection object.
 * @return array An array of all sites or empty array on failure.
 */
function getAllSitesWithStatus(PDO $pdo): array
{
    try {
        $stmt = $pdo->query("SELECT id, name, is_active FROM sites ORDER BY name ASC");
        if (!$stmt) {
             error_log("ERROR getAllSitesWithStatus: Query failed. PDO Error: " . implode(" | ", $pdo->errorInfo()));
             return [];
        }
        // Cast is_active to int for consistency if needed, though FETCH_ASSOC usually returns strings from DB
        $sites = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($sites as &$site) { // Use reference to modify in place
             $site['is_active'] = (int)$site['is_active'];
        }
        unset($site); // Break reference
        return $sites;
    } catch (PDOException $e) {
        error_log("EXCEPTION in getAllSitesWithStatus: " . $e->getMessage());
        return [];
    }
}

/**
 * Fetches details (id, name, email_collection_desc, is_active) for a specific site by ID,
 * regardless of its active status. Used for displaying settings on config page.
 *
 * @param PDO $pdo The PDO database connection object.
 * @param int $site_id The ID of the site to fetch.
 * @return array|null An associative array with site details or null if not found/error.
 */
function getSiteDetailsById(PDO $pdo, int $site_id): ?array
{
    try {
        $sql = "SELECT id, name, email_collection_desc, is_active FROM sites WHERE id = :site_id";
        $stmt = $pdo->prepare($sql);
        if (!$stmt) {
             error_log("ERROR getSiteDetailsById: Prepare failed for site ID {$site_id}. PDO Error: " . implode(" | ", $pdo->errorInfo()));
             return null;
        }
        $execute_success = $stmt->execute([':site_id' => $site_id]);
        if (!$execute_success) {
             error_log("ERROR getSiteDetailsById: Execute failed for site ID {$site_id}. Statement Error: " . implode(" | ", $stmt->errorInfo()));
             return null;
        }
        $site = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($site) {
            $site['is_active'] = (int)$site['is_active']; // Cast for consistency
        }
        return $site ?: null;
    } catch (PDOException $e) {
        error_log("EXCEPTION in getSiteDetailsById for site ID {$site_id}: " . $e->getMessage());
        return null;
    }
}

/**
 * Updates the is_active status and email description for a specific site.
 *
 * @param PDO $pdo PDO connection object.
 * @param int $site_id The ID of the site to update.
 * @param int $is_active The new active status (1 for active, 0 for inactive).
 * @param string $email_desc The new email collection description.
 * @return bool True on success, false on failure.
 */
function updateSiteStatusAndDesc(PDO $pdo, int $site_id, int $is_active, string $email_desc): bool
{
    $sql = "UPDATE sites SET is_active = :is_active, email_collection_desc = :email_desc WHERE id = :site_id";
    try {
        $stmt = $pdo->prepare($sql);
        if (!$stmt) {
             error_log("ERROR updateSiteStatusAndDesc: Prepare failed for site ID {$site_id}. PDO Error: " . implode(" | ", $pdo->errorInfo()));
             return false;
        }
        $success = $stmt->execute([
            ':is_active' => $is_active,
            ':email_desc' => $email_desc,
            ':site_id' => $site_id
        ]);
        if (!$success) {
             error_log("ERROR updateSiteStatusAndDesc: Execute failed for site ID {$site_id}. Statement Error: " . implode(" | ", $stmt->errorInfo()));
        }
        // Could check rowCount if needed, but execute returning true is usually sufficient for UPDATE success
        return $success;
    } catch (PDOException $e) {
        error_log("EXCEPTION in updateSiteStatusAndDesc for site ID {$site_id}: " . $e->getMessage());
        return false;
    }
}

/**
 * Inserts or updates a specific configuration setting for a site.
 * Uses INSERT ... ON DUPLICATE KEY UPDATE.
 *
 * @param PDO $pdo PDO connection object.
 * @param int $site_id The ID of the site.
 * @param string $config_key The configuration key (e.g., 'allow_email_collection').
 * @param mixed $config_value The configuration value (will be cast appropriately if needed).
 * @return bool True on success, false on failure.
 */
function upsertSiteConfiguration(PDO $pdo, int $site_id, string $config_key, $config_value): bool
{
    // Basic validation
    if (empty(trim($config_key))) {
        error_log("ERROR upsertSiteConfiguration: Config key cannot be empty.");
        return false;
    }
    // Determine type for binding (simple check for boolean-like keys)
    $value_to_bind = $config_value;
    $bind_type = PDO::PARAM_STR;
    if (in_array($config_key, ['allow_email_collection', 'allow_notifier'])) {
         $value_to_bind = ($config_value == 1 || $config_value === true || strtoupper((string)$config_value) === 'TRUE') ? 1 : 0;
         $bind_type = PDO::PARAM_INT;
    }

    $sql = "INSERT INTO site_configurations (site_id, config_key, config_value, created_at, updated_at)
            VALUES (:site_id, :config_key, :config_value, NOW(), NOW())
            ON DUPLICATE KEY UPDATE config_value = VALUES(config_value), updated_at = NOW()";

    try {
        $stmt = $pdo->prepare($sql);
         if (!$stmt) {
             error_log("ERROR upsertSiteConfiguration: Prepare failed for site ID {$site_id}, key '{$config_key}'. PDO Error: " . implode(" | ", $pdo->errorInfo()));
             return false;
        }
        // Bind values
        $stmt->bindParam(':site_id', $site_id, PDO::PARAM_INT);
        $stmt->bindParam(':config_key', $config_key, PDO::PARAM_STR);
        $stmt->bindParam(':config_value', $value_to_bind, $bind_type);

        $success = $stmt->execute();
        if (!$success) {
             error_log("ERROR upsertSiteConfiguration: Execute failed for site ID {$site_id}, key '{$config_key}'. Statement Error: " . implode(" | ", $stmt->errorInfo()));
        }
        // UPSERT can affect 0, 1, or 2 rows. Success is just if execute didn't fail.
        return $success;

    } catch (PDOException $e) {
        error_log("EXCEPTION in upsertSiteConfiguration for site ID {$site_id}, key '{$config_key}': " . $e->getMessage());
        return false;
    }
}



/**
 * Fetches the name of a specific site by its ID.
 *
 * @param PDO $pdo The PDO database connection object.
 * @param int $site_id The ID of the site.
 * @return string|null The site name, or null if not found or error.
 */
function getSiteNameById(PDO $pdo, int $site_id): ?string
{
    try {
        $sql = "SELECT name FROM sites WHERE id = :site_id";
        $stmt = $pdo->prepare($sql);
        if (!$stmt) {
             error_log("ERROR getSiteNameById: Prepare failed for site ID {$site_id}. PDO Error: " . implode(" | ", $pdo->errorInfo()));
             return null;
        }
        $execute_success = $stmt->execute([':site_id' => $site_id]);
        if (!$execute_success) {
             error_log("ERROR getSiteNameById: Execute failed for site ID {$site_id}. Statement Error: " . implode(" | ", $stmt->errorInfo()));
             return null;
        }
        $name = $stmt->fetchColumn();
        return ($name !== false) ? (string)$name : null;
    } catch (PDOException $e) {
        error_log("EXCEPTION in getSiteNameById for site ID {$site_id}: " . $e->getMessage());
        return null;
    }

}

/**
 * Checks if a site exists and is currently active.
 *
 * @param PDO $pdo The PDO database connection object.
 * @param int $site_id The ID of the site to check.
 * @return bool True if the site exists and is active, false otherwise or on error.
 */
function isActiveSite(PDO $pdo, int $site_id): bool
{
    try {
        $sql = "SELECT 1 FROM sites WHERE id = :site_id AND is_active = TRUE LIMIT 1";
        $stmt = $pdo->prepare($sql);
        if (!$stmt) {
             error_log("ERROR isActiveSite: Prepare failed for site ID {$site_id}. PDO Error: " . implode(" | ", $pdo->errorInfo()));
             return false; // Indicate error or uncertainty
        }
        $execute_success = $stmt->execute([':site_id' => $site_id]);
        if (!$execute_success) {
             error_log("ERROR isActiveSite: Execute failed for site ID {$site_id}. Statement Error: " . implode(" | ", $stmt->errorInfo()));
             return false; // Indicate error or uncertainty
        }
        return $stmt->fetchColumn() !== false;
    } catch (PDOException $e) {
        error_log("EXCEPTION in isActiveSite for site ID {$site_id}: " . $e->getMessage());
        return false; // Indicate error or uncertainty
    }
}


// Add other site and site_configuration related data access functions here later...
// For example:
// function getSiteConfigurations(PDO $pdo, int $site_id): array { ... } // Fetches all configs for a site
// function addSite(PDO $pdo, array $site_data): int|false { ... } // Returns new site ID or false
// function updateSite(PDO $pdo, int $site_id, array $site_data): bool { ... } // Update name etc.
// function deleteSite(PDO $pdo, int $site_id): bool { ... } // Consider implications (foreign keys)

?>