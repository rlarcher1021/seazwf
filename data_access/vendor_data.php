<?php
// data_access/vendor_data.php

require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/auth.php'; // For check_permission

/**
 * Fetches all active, non-deleted vendors for dropdowns.
 * Returns only id, name, and client_name_required flag.
 * Accessible by multiple roles (Staff, Director, Finance) for populating forms.
 *
 * @param PDO $pdo Database connection object.
 * @return array List of active vendors or empty array on failure/no vendors.
 */
function getActiveVendors(PDO $pdo): array
{
    try {
        $sql = "SELECT id, name, client_name_required 
                FROM vendors 
                WHERE is_active = 1 AND deleted_at IS NULL 
                ORDER BY name ASC";
        $stmt = $pdo->query($sql);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        // Log error appropriately
        error_log("Error fetching active vendors: " . $e->getMessage());
        return [];
    }
}

/**
 * Fetches all vendors (including inactive/deleted) for Director management.
 * Requires 'director' role.
 *
 * @param PDO $pdo Database connection object.
 * @return array List of all vendors or empty array on failure.
 */
function getAllVendorsForAdmin(PDO $pdo): array
{
    if (!check_permission(['director'])) {
        // Optional: Log permission failure
        return []; // Or throw an exception
    }

    try {
        $sql = "SELECT id, name, client_name_required, is_active, deleted_at 
                FROM vendors 
                ORDER BY name ASC";
        $stmt = $pdo->query($sql);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error fetching all vendors for admin: " . $e->getMessage());
        return [];
    }
}

/**
 * Fetches a single vendor by ID.
 * Requires 'director' role as it's primarily for editing.
 *
 * @param PDO $pdo Database connection object.
 * @param int $vendor_id The ID of the vendor to fetch.
 * @return array|false Vendor data as an associative array, or false if not found or permission denied.
 */
function getVendorById(PDO $pdo, int $vendor_id)
{
    if (!check_permission(['director'])) {
        return false;
    }

    try {
        $sql = "SELECT id, name, client_name_required, is_active, deleted_at
                FROM vendors
                WHERE id = :id AND deleted_at IS NULL"; // Typically only edit non-deleted
        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(':id', $vendor_id, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error fetching vendor by ID ($vendor_id): " . $e->getMessage());
        return false;
    }
}

/**
 * Adds a new vendor.
 * Requires 'director' role.
 *
 * @param PDO $pdo Database connection object.
 * @param string $name Vendor name.
 * @param bool $client_name_required Whether client name is required for this vendor.
 * @return int|false The ID of the newly created vendor, or false on failure.
 */
function addVendor(PDO $pdo, string $name, bool $client_name_required): int|false
{
    if (!check_permission(['director'])) {
        return false;
    }

    // Basic Validation
    if (empty(trim($name))) {
        // Consider throwing an exception or returning a specific error code/message
        return false; 
    }

    try {
        $sql = "INSERT INTO vendors (name, client_name_required, is_active) 
                VALUES (:name, :client_name_required, 1)";
        $stmt = $pdo->prepare($sql);
        
        $client_req_int = $client_name_required ? 1 : 0; // Convert boolean to TINYINT(1)

        $stmt->bindParam(':name', $name, PDO::PARAM_STR);
        $stmt->bindParam(':client_name_required', $client_req_int, PDO::PARAM_INT);

        if ($stmt->execute()) {
            return (int)$pdo->lastInsertId();
        } else {
            // Log error details from $stmt->errorInfo()
            error_log("Error adding vendor: " . implode(", ", $stmt->errorInfo()));
            return false;
        }
    } catch (PDOException $e) {
        // Catch potential unique constraint violation on name
        error_log("PDOException adding vendor: " . $e->getMessage());
        // Check for duplicate entry error code (e.g., 23000 for SQLSTATE)
        if ($e->getCode() == '23000') { 
             // Handle duplicate name error specifically if needed (e.g., return specific error code)
        }
        return false;
    }
}

/**
 * Updates an existing vendor.
 * Requires 'director' role.
 *
 * @param PDO $pdo Database connection object.
 * @param int $vendor_id The ID of the vendor to update.
 * @param string $name The new vendor name.
 * @param bool $client_name_required The new client name requirement status.
 * @param bool $is_active The new active status.
 * @return bool True on success, false on failure.
 */
function updateVendor(PDO $pdo, int $vendor_id, string $name, bool $client_name_required, bool $is_active): bool
{
    if (!check_permission(['director'])) {
        return false;
    }

    // Basic Validation
    if (empty(trim($name)) || $vendor_id <= 0) {
         return false;
    }

    try {
        $sql = "UPDATE vendors 
                SET name = :name, 
                    client_name_required = :client_name_required, 
                    is_active = :is_active
                WHERE id = :id AND deleted_at IS NULL"; // Prevent updating deleted vendors directly
        
        $stmt = $pdo->prepare($sql);

        $client_req_int = $client_name_required ? 1 : 0;
        $is_active_int = $is_active ? 1 : 0;

        $stmt->bindParam(':name', $name, PDO::PARAM_STR);
        $stmt->bindParam(':client_name_required', $client_req_int, PDO::PARAM_INT);
        $stmt->bindParam(':is_active', $is_active_int, PDO::PARAM_INT);
        $stmt->bindParam(':id', $vendor_id, PDO::PARAM_INT);

        return $stmt->execute();
        // Consider checking $stmt->rowCount() to see if any rows were actually updated

    } catch (PDOException $e) {
        error_log("Error updating vendor ($vendor_id): " . $e->getMessage());
         // Check for duplicate entry error code (e.g., 23000 for SQLSTATE) if name is updated
        if ($e->getCode() == '23000') {
             // Handle duplicate name error
        }
        return false;
    }
}

/**
 * Soft deletes a vendor by setting the deleted_at timestamp.
 * Requires 'director' role.
 *
 * @param PDO $pdo Database connection object.
 * @param int $vendor_id The ID of the vendor to soft delete.
 * @return bool True on success, false on failure.
 */
function softDeleteVendor(PDO $pdo, int $vendor_id): bool
{
    if (!check_permission(['director'])) {
        return false;
    }

    if ($vendor_id <= 0) {
        return false;
    }

    try {
        $sql = "UPDATE vendors 
                SET deleted_at = NOW(), is_active = 0 
                WHERE id = :id AND deleted_at IS NULL"; // Prevent double-deleting
        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(':id', $vendor_id, PDO::PARAM_INT);

        return $stmt->execute();
        // Consider checking rowCount > 0

    } catch (PDOException $e) {
        error_log("Error soft deleting vendor ($vendor_id): " . $e->getMessage());
        return false;
    }
}

/**
 * Restores a soft-deleted vendor.
 * Requires 'director' role.
 *
 * @param PDO $pdo Database connection object.
 * @param int $vendor_id The ID of the vendor to restore.
 * @return bool True on success, false on failure.
 */
function restoreVendor(PDO $pdo, int $vendor_id): bool
{
     if (!check_permission(['director'])) {
        return false;
    }
    
    if ($vendor_id <= 0) {
        return false;
    }

    try {
        // Decide if restoring should automatically make it active again
        $sql = "UPDATE vendors 
                SET deleted_at = NULL, is_active = 1 
                WHERE id = :id AND deleted_at IS NOT NULL"; 
        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(':id', $vendor_id, PDO::PARAM_INT);

        return $stmt->execute();
        // Consider checking rowCount > 0

    } catch (PDOException $e) {
        error_log("Error restoring vendor ($vendor_id): " . $e->getMessage());
        return false;
    }
}

/**
 * Checks if a vendor requires a client name.
 * Used for server-side validation in allocation handler.
 * Accessible by roles that can add/edit allocations.
 *
 * @param PDO $pdo Database connection object.
 * @param int $vendor_id The ID of the vendor to check.
 * @return bool|null True if client name is required, false if not, null on error or vendor not found.
 */
function doesVendorRequireClientName(PDO $pdo, int $vendor_id): ?bool
{
    // Permissions check might be needed depending on usage context, 
    // but likely okay if called internally after other permission checks.
    
    if ($vendor_id <= 0) {
        return null;
    }

    try {
        $sql = "SELECT client_name_required 
                FROM vendors 
                WHERE id = :id AND is_active = 1 AND deleted_at IS NULL";
        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(':id', $vendor_id, PDO::PARAM_INT);
        $stmt->execute();
        
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($result === false) {
            return null; // Vendor not found or inactive/deleted
        }

        return (bool)$result['client_name_required'];

    } catch (PDOException $e) {
        error_log("Error checking vendor client name requirement ($vendor_id): " . $e->getMessage());
        return null;
    }
}

?>