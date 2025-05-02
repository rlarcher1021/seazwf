<?php
// includes/data_access/ad_data.php

// This file will contain functions related to 'global_ads' and 'site_ads' tables.

/**
 * Fetches active ads assigned to a specific site, ordered randomly.
 *
 * @param PDO $pdo The PDO database connection object.
 * @param int $site_id The ID of the site.
 * @return array An array of associative arrays representing the ads, or empty array on failure.
 */
function getActiveAdsForSite(PDO $pdo, int $site_id): array
{
    $sql = "SELECT ga.ad_type, ga.ad_title, ga.ad_text, ga.image_path
            FROM site_ads sa
            JOIN global_ads ga ON sa.global_ad_id = ga.id
            WHERE sa.site_id = :site_id
              AND sa.is_active = 1
              AND ga.is_active = 1
            ORDER BY RAND()"; // Randomize selection

    try {
        $stmt = $pdo->prepare($sql);
        if (!$stmt) {
            error_log("ERROR getActiveAdsForSite: Prepare failed for site ID {$site_id}. PDO Error: " . implode(" | ", $pdo->errorInfo()));
            return [];
        }

        $execute_success = $stmt->execute([':site_id' => $site_id]);
        if (!$execute_success) {
            error_log("ERROR getActiveAdsForSite: Execute failed for site ID {$site_id}. Statement Error: " . implode(" | ", $stmt->errorInfo()));
            return [];
        }

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    } catch (PDOException $e) {
        error_log("EXCEPTION in getActiveAdsForSite for site ID {$site_id}: " . $e->getMessage());
        return [];
    }
}


/**
 * Reorders items (move up/down or renumber after delete) for 'site_ads'.
 * Handles both moving a specific item up/down and renumbering all items within a group.
 *
 * @param PDO $pdo The PDO database connection object.
 * @param string $table_name Currently hardcoded to 'site_ads'.
 * @param string $order_column The column storing the order (e.g., 'display_order').
 * @param string|null $group_column The column defining the group (e.g., 'site_id').
 * @param mixed|null $group_value The value of the group column.
 * @param int|null $item_id The ID of the item to move (required for 'up'/'down').
 * @param string|null $direction 'up', 'down', or null (for renumbering).
 * @return bool True on success, false on failure.
 */


/**
 * Fetches all global ads.
 *
 * @param PDO $pdo PDO connection object.
 * @return array Array of global ads or empty array on failure.
 */
function getAllGlobalAds(PDO $pdo): array
{
    try {
        $stmt = $pdo->query("SELECT id, ad_type, ad_title, ad_text, image_path, is_active, created_at, updated_at FROM global_ads ORDER BY ad_title ASC, created_at DESC");
        if (!$stmt) {
             error_log("ERROR getAllGlobalAds: Query failed. PDO Error: " . implode(" | ", $pdo->errorInfo()));
             return [];
        }
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (PDOException $e) {
        error_log("EXCEPTION in getAllGlobalAds: " . $e->getMessage());
        return [];
    }
}

/**
 * Adds a new global ad record. Does NOT handle file uploads.
 * File upload logic should be handled before calling this.
 *
 * @param PDO $pdo PDO connection object.
 * @param string $ad_type 'text' or 'image'.
 * @param string|null $ad_title Optional title.
 * @param string|null $ad_text Text content (for text ads).
 * @param string|null $image_path_db Path to image as stored in DB (for image ads).
 * @param int $is_active 1 for active, 0 for inactive.
 * @param string $session_role The role of the user performing the action.
 * @return int|false The new global ad ID on success, false on failure.
 */
function addGlobalAd(PDO $pdo, string $ad_type, ?string $ad_title, ?string $ad_text, ?string $image_path_db, int $is_active, string $session_role): int|false
{
    // Permission Check: Only administrators can add global ads
    if ($session_role !== 'administrator') {
        error_log("PERMISSION DENIED: User role '{$session_role}' attempted to add a global ad.");
        return false;
    }

    $sql = "INSERT INTO global_ads (ad_type, ad_title, ad_text, image_path, is_active, created_at, updated_at)
            VALUES (:type, :title, :text, :image, :active, NOW(), NOW())";
    try {
        $stmt = $pdo->prepare($sql);
        if (!$stmt) {
             error_log("ERROR addGlobalAd: Prepare failed. PDO Error: " . implode(" | ", $pdo->errorInfo()));
             return false;
        }
        $params = [
            ':type' => $ad_type,
            ':title' => $ad_title ?: null,
            ':text' => ($ad_type === 'text') ? $ad_text : null,
            ':image' => ($ad_type === 'image') ? $image_path_db : null,
            ':active' => $is_active
        ];
        $success = $stmt->execute($params);
        if ($success) {
            return (int)$pdo->lastInsertId();
        } else {
            error_log("ERROR addGlobalAd: Execute failed. Statement Error: " . implode(" | ", $stmt->errorInfo()));
            return false;
        }
    } catch (PDOException $e) {
        error_log("EXCEPTION in addGlobalAd: " . $e->getMessage());
        return false;
    }
}

/**
 * Fetches the image path for a specific global ad ID.
 *
 * @param PDO $pdo PDO connection object.
 * @param int $global_ad_id The ID of the global ad.
 * @return string|null The image path or null if not found, not an image ad, or error.
 */
function getGlobalAdImagePath(PDO $pdo, int $global_ad_id): ?string
{
     try {
        $stmt = $pdo->prepare("SELECT image_path FROM global_ads WHERE id = :id AND ad_type = 'image'");
         if (!$stmt) {
             error_log("ERROR getGlobalAdImagePath: Prepare failed for ID {$global_ad_id}. PDO Error: " . implode(" | ", $pdo->errorInfo()));
             return null;
        }
        $stmt->execute([':id' => $global_ad_id]);
        $path = $stmt->fetchColumn();
        return ($path !== false) ? (string)$path : null;
    } catch (PDOException $e) {
        error_log("EXCEPTION in getGlobalAdImagePath for ID {$global_ad_id}: " . $e->getMessage());
        return null;
    }
}


/**
 * Deletes a global ad record by ID. Does NOT handle file deletion.
 *
 * @param PDO $pdo PDO connection object.
 * @param int $global_ad_id The ID of the global ad to delete.
 * @param string $session_role The role of the user performing the action.
 * @return bool True on success, false on failure.
 */
function deleteGlobalAd(PDO $pdo, int $global_ad_id, string $session_role): bool
{
    // Permission Check: Only administrators can delete global ads
    if ($session_role !== 'administrator') {
        error_log("PERMISSION DENIED: User role '{$session_role}' attempted to delete global ad ID {$global_ad_id}.");
        return false;
    }

    // Consider deleting related site_ads entries first if FK constraints exist or desired
    // try { $pdo->prepare("DELETE FROM site_ads WHERE global_ad_id = :gid")->execute([':gid' => $global_ad_id]); } catch (PDOException $e) { /* Log */ }

    $sql = "DELETE FROM global_ads WHERE id = :id";
    try {
        $stmt = $pdo->prepare($sql);
         if (!$stmt) {
             error_log("ERROR deleteGlobalAd: Prepare failed for ID {$global_ad_id}. PDO Error: " . implode(" | ", $pdo->errorInfo()));
             return false;
        }
        $success = $stmt->execute([':id' => $global_ad_id]);
         if (!$success) {
             error_log("ERROR deleteGlobalAd: Execute failed for ID {$global_ad_id}. Statement Error: " . implode(" | ", $stmt->errorInfo()));
        }
        return ($success && $stmt->rowCount() > 0); // Ensure a row was actually deleted
    } catch (PDOException $e) {
        error_log("EXCEPTION in deleteGlobalAd for ID {$global_ad_id}: " . $e->getMessage());
        return false;
    }
}

/**
 * Toggles the active status of a global ad.
 *
 * @param PDO $pdo PDO connection object.
 * @param int $global_ad_id The ID of the global ad.
 * @param string $session_role The role of the user performing the action.
 * @return bool True on success, false on failure.
 */
function toggleGlobalAdActive(PDO $pdo, int $global_ad_id, string $session_role): bool
{
    // Permission Check: Only administrators can toggle global ads
    if ($session_role !== 'administrator') {
        error_log("PERMISSION DENIED: User role '{$session_role}' attempted to toggle global ad ID {$global_ad_id}.");
        return false;
    }

    $sql = "UPDATE global_ads SET is_active = NOT is_active WHERE id = :id";
    try {
        $stmt = $pdo->prepare($sql);
         if (!$stmt) {
             error_log("ERROR toggleGlobalAdActive: Prepare failed for ID {$global_ad_id}. PDO Error: " . implode(" | ", $pdo->errorInfo()));
             return false;
        }
        $success = $stmt->execute([':id' => $global_ad_id]);
         if (!$success) {
             error_log("ERROR toggleGlobalAdActive: Execute failed for ID {$global_ad_id}. Statement Error: " . implode(" | ", $stmt->errorInfo()));
        }
        return $success;
    } catch (PDOException $e) {
        error_log("EXCEPTION in toggleGlobalAdActive for ID {$global_ad_id}: " . $e->getMessage());
        return false;
    }
}

/**
 * Fetches full details for a specific global ad by ID.
 *
 * @param PDO $pdo PDO connection object.
 * @param int $global_ad_id The ID of the global ad.
 * @return array|null Associative array of ad data or null if not found/error.
 */
function getGlobalAdById(PDO $pdo, int $global_ad_id): ?array
{
    try {
        $stmt = $pdo->prepare("SELECT * FROM global_ads WHERE id = :id");
         if (!$stmt) {
             error_log("ERROR getGlobalAdById: Prepare failed for ID {$global_ad_id}. PDO Error: " . implode(" | ", $pdo->errorInfo()));
             return null;
        }
        $stmt->execute([':id' => $global_ad_id]);
        $ad = $stmt->fetch(PDO::FETCH_ASSOC);
        return $ad ?: null;
    } catch (PDOException $e) {
        error_log("EXCEPTION in getGlobalAdById for ID {$global_ad_id}: " . $e->getMessage());
        return null;
    }
}

/**
 * Updates an existing global ad record. Does NOT handle file uploads/deletions.
 *
 * @param PDO $pdo PDO connection object.
 * @param int $global_ad_id The ID of the ad to update.
 * @param string $ad_type 'text' or 'image'.
 * @param string|null $ad_title Optional title.
 * @param string|null $ad_text Text content (for text ads).
 * @param string|null $image_path_to_save Path to image as stored in DB (null if deleting/text ad).
 * @param int $is_active 1 for active, 0 for inactive.
 * @param string $session_role The role of the user performing the action.
 * @return bool True on success, false on failure.
 */
function updateGlobalAd(PDO $pdo, int $global_ad_id, string $ad_type, ?string $ad_title, ?string $ad_text, ?string $image_path_to_save, int $is_active, string $session_role): bool
{
    // Permission Check: Only administrators can update global ads
    if ($session_role !== 'administrator') {
        error_log("PERMISSION DENIED: User role '{$session_role}' attempted to update global ad ID {$global_ad_id}.");
        return false;
    }

    $sql = "UPDATE global_ads SET
                ad_type = :type,
                ad_title = :title,
                ad_text = :text,
                image_path = :image,
                is_active = :active,
                updated_at = NOW()
            WHERE id = :id";
    try {
        $stmt = $pdo->prepare($sql);
         if (!$stmt) {
             error_log("ERROR updateGlobalAd: Prepare failed for ID {$global_ad_id}. PDO Error: " . implode(" | ", $pdo->errorInfo()));
             return false;
        }
        $params = [
            ':type' => $ad_type,
            ':title' => $ad_title ?: null,
            ':text' => ($ad_type === 'text') ? $ad_text : null,
            ':image' => ($ad_type === 'image') ? $image_path_to_save : null,
            ':active' => $is_active,
            ':id' => $global_ad_id
        ];
        $success = $stmt->execute($params);
         if (!$success) {
             error_log("ERROR updateGlobalAd: Execute failed for ID {$global_ad_id}. Statement Error: " . implode(" | ", $stmt->errorInfo()));
        }
        return $success;
    } catch (PDOException $e) {
        error_log("EXCEPTION in updateGlobalAd for ID {$global_ad_id}: " . $e->getMessage());
        return false;
    }
}

/**
 * Assigns a global ad to a site with a calculated display order.
 * Handles its own transaction.
 *
 * @param PDO $pdo PDO connection object.
 * @param int $site_id The ID of the site.
 * @param int $global_ad_id The ID of the global ad.
 * @param int $is_active 1 if active, 0 if inactive for this site.
 * @param string $session_role The role of the user performing the action.
 * @param int $is_site_admin The site admin status of the user (1 or 0).
 * @param int|null $session_site_id The user's assigned site ID.
 * @return bool True on success, false on failure (e.g., already assigned).
 */
function assignAdToSite(PDO $pdo, int $site_id, int $global_ad_id, int $is_active, string $session_role, int $is_site_admin, ?int $session_site_id): bool
{
    // Permission Check
    $can_assign = false;
    if ($session_role === 'administrator' || $session_role === 'director') {
        $can_assign = true;
    } elseif ($is_site_admin === 1 && $site_id === $session_site_id) {
        $can_assign = true;
    }

    if (!$can_assign) {
        error_log("PERMISSION DENIED: User (Role: {$session_role}, SiteAdmin: {$is_site_admin}, SessionSite: {$session_site_id}) attempted to assign ad {$global_ad_id} to site {$site_id}.");
        return false;
    }

    try {
        $pdo->beginTransaction();
        // Get max display order
        $stmt_order = $pdo->prepare("SELECT MAX(display_order) FROM site_ads WHERE site_id = :id");
        if (!$stmt_order) { $pdo->rollBack(); error_log("ERROR assignAdToSite: Prepare failed (order)."); return false; }
        $stmt_order->execute([':id' => $site_id]);
        $max_order = $stmt_order->fetchColumn() ?? -1;
        $new_order = $max_order + 1;

        // Insert assignment
        $sql = "INSERT INTO site_ads (site_id, global_ad_id, display_order, is_active)
                VALUES (:sid, :gid, :order, :active)";
        $stmt_assign = $pdo->prepare($sql);
        if (!$stmt_assign) { $pdo->rollBack(); error_log("ERROR assignAdToSite: Prepare failed (insert)."); return false; }

        $success = $stmt_assign->execute([
            ':sid' => $site_id,
            ':gid' => $global_ad_id,
            ':order' => $new_order,
            ':active' => $is_active
        ]);

        if ($success) {
            $pdo->commit();
            return true;
        } else {
            $errorInfo = $stmt_assign->errorInfo();
            if ($errorInfo[0] === '23000') { // Duplicate entry
                 error_log("WARNING assignAdToSite: Attempted to assign duplicate ad (Site: {$site_id}, GlobalAd: {$global_ad_id}).");
            } else {
                 error_log("ERROR assignAdToSite: Execute failed. SQLSTATE[{$errorInfo[0]}] Driver Error[{$errorInfo[1]}]: {$errorInfo[2]}");
            }
            $pdo->rollBack();
            return false;
        }
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log("EXCEPTION in assignAdToSite (Site: {$site_id}, GlobalAd: {$global_ad_id}): " . $e->getMessage());
        return false;
    }
}

/**
 * Removes an ad assignment from a site by its site_ads ID.
 * Does NOT handle reordering; call reorder_items separately if needed.
 *
 * @param PDO $pdo PDO connection object.
 * @param int $site_ad_id The ID of the record in the site_ads table.
 * @param int $site_id The ID of the site (for verification and permission check).
 * @param string $session_role The role of the user performing the action.
 * @param int $is_site_admin The site admin status of the user (1 or 0).
 * @param int|null $session_site_id The user's assigned site ID.
 * @return bool True on success, false on failure.
 */
function removeAdFromSite(PDO $pdo, int $site_ad_id, int $site_id, string $session_role, int $is_site_admin, ?int $session_site_id): bool
{
    // Permission Check
    $can_remove = false;
    if ($session_role === 'administrator' || $session_role === 'director') {
        $can_remove = true;
    } elseif ($is_site_admin === 1 && $site_id === $session_site_id) {
        $can_remove = true;
    }

    if (!$can_remove) {
        error_log("PERMISSION DENIED: User (Role: {$session_role}, SiteAdmin: {$is_site_admin}, SessionSite: {$session_site_id}) attempted to remove site_ad {$site_ad_id} from site {$site_id}.");
        return false;
    }

     try {
        // We still verify site_id in the query for data integrity
        $stmt = $pdo->prepare("DELETE FROM site_ads WHERE id = :id AND site_id = :sid");
         if (!$stmt) {
             error_log("ERROR removeAdFromSite: Prepare failed for ID {$site_ad_id}. PDO Error: " . implode(" | ", $pdo->errorInfo()));
             return false;
        }
        $success = $stmt->execute([':id' => $site_ad_id, ':sid' => $site_id]);
         if (!$success) {
             error_log("ERROR removeAdFromSite: Execute failed for ID {$site_ad_id}. Statement Error: " . implode(" | ", $stmt->errorInfo()));
        }
        return ($success && $stmt->rowCount() > 0);
    } catch (PDOException $e) {
        error_log("EXCEPTION in removeAdFromSite for ID {$site_ad_id}: " . $e->getMessage());
        return false;
    }
}

/**
 * Toggles the active status of a site ad assignment.
 *
 * @param PDO $pdo PDO connection object.
 * @param int $site_ad_id The ID of the record in the site_ads table.
 * @param int $site_id The ID of the site (for verification and permission check).
 * @param string $session_role The role of the user performing the action.
 * @param int $is_site_admin The site admin status of the user (1 or 0).
 * @param int|null $session_site_id The user's assigned site ID.
 * @return bool True on success, false on failure.
 */
function toggleSiteAdActive(PDO $pdo, int $site_ad_id, int $site_id, string $session_role, int $is_site_admin, ?int $session_site_id): bool
{
    // Permission Check
    $can_toggle = false;
    if ($session_role === 'administrator' || $session_role === 'director') {
        $can_toggle = true;
    } elseif ($is_site_admin === 1 && $site_id === $session_site_id) {
        $can_toggle = true;
    }

    if (!$can_toggle) {
        error_log("PERMISSION DENIED: User (Role: {$session_role}, SiteAdmin: {$is_site_admin}, SessionSite: {$session_site_id}) attempted to toggle active status for site_ad {$site_ad_id} on site {$site_id}.");
        return false;
    }

    $sql = "UPDATE site_ads SET is_active = NOT is_active WHERE id = :id AND site_id = :sid";
    try {
        // We still verify site_id in the query for data integrity
        $stmt = $pdo->prepare($sql);
         if (!$stmt) {
             error_log("ERROR toggleSiteAdActive: Prepare failed for ID {$site_ad_id}. PDO Error: " . implode(" | ", $pdo->errorInfo()));
             return false;
        }
        $success = $stmt->execute([':id' => $site_ad_id, ':sid' => $site_id]);
         if (!$success) {
             error_log("ERROR toggleSiteAdActive: Execute failed for ID {$site_ad_id}. Statement Error: " . implode(" | ", $stmt->errorInfo()));
        }
        return $success;
    } catch (PDOException $e) {
        error_log("EXCEPTION in toggleSiteAdActive for ID {$site_ad_id}: " . $e->getMessage());
        return false;
    }
}

/**
 * Fetches all ads assigned to a specific site (active and inactive), ordered by display order.
 * Joins with global_ads to get ad details.
 *
 * @param PDO $pdo The PDO database connection object.
 * @param int $site_id The ID of the site.
 * @return array An array of associative arrays representing the assigned ads, or empty array on failure.
 */
function getSiteAdsAssigned(PDO $pdo, int $site_id): array
{
    $sql = "SELECT sa.id as site_ad_id, sa.global_ad_id, sa.display_order, sa.is_active as site_is_active,
                   ga.ad_type, ga.ad_title, ga.ad_text, ga.image_path, ga.is_active as global_is_active
            FROM site_ads sa
            JOIN global_ads ga ON sa.global_ad_id = ga.id
            WHERE sa.site_id = :site_id
            ORDER BY sa.display_order ASC";
    try {
        $stmt = $pdo->prepare($sql);
        if (!$stmt) {
            error_log("ERROR getSiteAdsAssigned: Prepare failed for site ID {$site_id}. PDO Error: " . implode(" | ", $pdo->errorInfo()));
            return [];
        }
        $stmt->execute([':site_id' => $site_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (PDOException $e) {
        error_log("EXCEPTION in getSiteAdsAssigned for site ID {$site_id}: " . $e->getMessage());
        return [];
    }
}

?>