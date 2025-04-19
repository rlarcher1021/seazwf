<?php
require_once __DIR__ . '/../db_connect.php'; // Ensure the path is correct relative to this file

/**
 * Generates a URL-friendly slug from a string.
 *
 * @param string $name The string to slugify.
 * @return string The generated slug.
 */
function generateSlug(string $name): string
{
    // Convert to lowercase
    $slug = strtolower($name);
    // Replace non-alphanumeric characters (except hyphens) with hyphens
    $slug = preg_replace('/[^a-z0-9\-]+/', '-', $slug);
    // Replace multiple hyphens with a single hyphen
    $slug = preg_replace('/-+/', '-', $slug);
    // Trim leading/trailing hyphens
    $slug = trim($slug, '-');
    // Handle empty slugs (e.g., if the name was just symbols)
    if (empty($slug)) {
        return 'department-' . uniqid(); // Fallback to a unique ID
    }
    return $slug;
}

/**
 * Checks if a slug is unique in the departments table.
 *
 * @param PDO $pdo The PDO database connection object.
 * @param string $slug The slug to check.
 * @param int|null $excludeId Optional. The ID of the department to exclude from the check (used during updates).
 * @return bool True if the slug is unique, false otherwise.
 */
function isSlugUnique(PDO $pdo, string $slug, ?int $excludeId = null): bool
{
    $sql = "SELECT 1 FROM departments WHERE slug = ?";
    $params = [$slug];
    if ($excludeId !== null) {
        $sql .= " AND id != ?";
        $params[] = $excludeId;
    }
    $sql .= " LIMIT 1";

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn() === false; // True if no row found (slug is unique)
    } catch (PDOException $e) {
        error_log("Error checking slug uniqueness: " . $e->getMessage());
        // Fail safe: assume not unique if there's an error
        return false;
    }
}


/**
 * Adds a new department to the database with a unique slug.
 *
 * @param PDO $pdo The PDO database connection object.
 * @param string $name The name of the department to add.
 * @return bool True on success, false on failure (e.g., duplicate name or DB error).
 */
function addDepartment(PDO $pdo, string $name): bool
{
    // Optional: Check if department name already exists (case-insensitive)
    // $stmtCheck = $pdo->prepare("SELECT id FROM departments WHERE LOWER(name) = LOWER(?)");
    // $stmtCheck->execute([$name]);
    // if ($stmtCheck->fetch()) {
    //     // Department name already exists - decide if this is an error or allowed
    //     // return false; // Uncomment if duplicate names are strictly forbidden
    // }

    // Generate initial slug
    $baseSlug = generateSlug($name);
    $slug = $baseSlug;
    $counter = 2;

    // Ensure slug uniqueness
    while (!isSlugUnique($pdo, $slug)) {
        $slug = $baseSlug . '-' . $counter++;
    }

    $sql = "INSERT INTO departments (name, slug, created_at) VALUES (?, ?, NOW())";
    try {
        $stmt = $pdo->prepare($sql);
        return $stmt->execute([$name, $slug]);
    } catch (PDOException $e) {
        error_log("Error adding department: " . $e->getMessage());
        return false;
    }
}

/**
 * Retrieves all departments from the database, including slugs.
 *
 * @param PDO $pdo The PDO database connection object.
 * @return array An array of departments (associative arrays), ordered by name.
 */
function getAllDepartments(PDO $pdo): array
{
    $sql = "SELECT id, name, slug, created_at FROM departments ORDER BY name ASC"; // Added slug
    try {
        $stmt = $pdo->query($sql);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error fetching all departments: " . $e->getMessage());
        return [];
    }
}

/**
 * Checks if a department is currently assigned to any user.
 *
 * @param PDO $pdo The PDO database connection object.
 * @param int $departmentId The ID of the department to check.
 * @return bool True if the department is in use, false otherwise.
 */
function isDepartmentInUse(PDO $pdo, int $departmentId): bool
{
    $sql = "SELECT 1 FROM users WHERE department_id = ? LIMIT 1";
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$departmentId]);
        return $stmt->fetchColumn() !== false; // Returns true if a row is found
    } catch (PDOException $e) {
        error_log("Error checking if department is in use: " . $e->getMessage());
        // Fail safe: assume it's in use if there's an error checking
        return true;
    }
}

/**
 * Deletes a department from the database, only if it's not in use.
 *
 * @param PDO $pdo The PDO database connection object.
 * @param int $departmentId The ID of the department to delete.
 * @return bool True on successful deletion, false otherwise (e.g., department in use or DB error).
 */
function deleteDepartment(PDO $pdo, int $departmentId): bool
{
    // CRITICAL: Check if the department is in use before attempting deletion
    if (isDepartmentInUse($pdo, $departmentId)) {
        return false; // Prevent deletion if in use
    }

    $sql = "DELETE FROM departments WHERE id = ?";
    try {
        $stmt = $pdo->prepare($sql);
        return $stmt->execute([$departmentId]);
    } catch (PDOException $e) {
        error_log("Error deleting department: " . $e->getMessage());
        return false;
    }
}

/**
 * Retrieves a single department by its ID.
 * (Optional but good practice)
 *
 * @param PDO $pdo The PDO database connection object.
 * @param int $departmentId The ID of the department.
 * @return array|false The department data as an associative array, or false if not found.
 */
function getDepartmentById(PDO $pdo, int $departmentId): array|false
{
    $sql = "SELECT id, name, slug, created_at FROM departments WHERE id = ?"; // Added slug
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$departmentId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error fetching department by ID: " . $e->getMessage());
        return false;
    }
}

/**
 * Updates the name of a department. The slug remains unchanged.
 *
 * @param PDO $pdo The PDO database connection object.
 * @param int $id The ID of the department to update.
 * @param string $name The new name for the department.
 * @return bool True on success, false on failure.
 */
function updateDepartment(PDO $pdo, int $id, string $name): bool
{
    // Optional: Check if the new name already exists for another department
    // $stmtCheck = $pdo->prepare("SELECT id FROM departments WHERE LOWER(name) = LOWER(?) AND id != ?");
    // $stmtCheck->execute([$name, $id]);
    // if ($stmtCheck->fetch()) {
    //     // Another department with this name exists
    //     return false; // Or handle as needed
    // }

    $sql = "UPDATE departments SET name = ? WHERE id = ?";
    try {
        $stmt = $pdo->prepare($sql);
        return $stmt->execute([$name, $id]);
    } catch (PDOException $e) {
        error_log("Error updating department name: " . $e->getMessage());
        return false;
    }
}

/**
 * Generates and saves a slug for a department if it doesn't have one.
 * Intended for populating slugs for existing records.
 *
 * @param PDO $pdo The PDO database connection object.
 * @param int $departmentId The ID of the department.
 * @return string|false The generated and saved slug, or false on failure.
 */
function ensureDepartmentSlug(PDO $pdo, int $departmentId): string|false
{
    $department = getDepartmentById($pdo, $departmentId);
    if (!$department) {
        error_log("Ensure slug: Department not found with ID: " . $departmentId);
        return false; // Department not found
    }

    // If slug already exists and is not empty, return it
    if (!empty($department['slug'])) {
        return $department['slug'];
    }

    // Generate initial slug from the current name
    $baseSlug = generateSlug($department['name']);
    $slug = $baseSlug;
    $counter = 2;

    // Ensure slug uniqueness (excluding the current department ID itself)
    while (!isSlugUnique($pdo, $slug, $departmentId)) {
        $slug = $baseSlug . '-' . $counter++;
    }

    // Save the generated slug
    $sql = "UPDATE departments SET slug = ? WHERE id = ?";
    try {
        $stmt = $pdo->prepare($sql);
        if ($stmt->execute([$slug, $departmentId])) {
            return $slug; // Return the newly generated and saved slug
        } else {
            error_log("Ensure slug: Failed to update slug for department ID: " . $departmentId);
            return false;
        }
    } catch (PDOException $e) {
        error_log("Ensure slug: DB error updating slug for department ID: " . $departmentId . " - " . $e->getMessage());
        return false;
    }
}


?>