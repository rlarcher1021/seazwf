<?php

/**
 * Forum API Request Handler
 */

// Ensure the main DAL file is included to access getAllForumPosts
require_once __DIR__ . '/../../../includes/data_access/forum_data.php';
// Error handler might be included globally, but include here for clarity if needed elsewhere
// require_once __DIR__ . '/../includes/error_handler.php'; // Already included globally in index.php

/**
 * Handles GET requests for /api/v1/forum/posts.
 * Fetches a paginated list of all non-deleted forum posts.
 *
 * @param PDO $pdo The PDO database connection object.
 * @param array $apiKeyData Authenticated API key data (including permissions).
 * @param array $queryParams Request query parameters ($_GET).
 * @return array Associative array containing pagination info and posts, suitable for JSON encoding.
 * @throws InvalidArgumentException For bad request parameters (e.g., invalid page/limit).
 * @throws RuntimeException For database errors or other internal issues.
 */
function handleGetAllForumPosts(PDO $pdo, array $apiKeyData, array $queryParams): array
{
    // Note: Authorization (permission check) should be done in the router *before* calling this handler.

    // --- Parameter Parsing & Validation ---
    $page = filter_var($queryParams['page'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'default' => 1]]);
    $limit = filter_var($queryParams['limit'] ?? 25, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100, 'default' => 25]]); // Added max_range

    if ($page === false || $limit === false) {
        throw new InvalidArgumentException("Invalid 'page' or 'limit' parameter. Must be positive integers.", 400);
    }

    // --- Data Fetching ---
    try {
        // Call the DAL function from includes/data_access/forum_data.php
        $forumData = getAllForumPosts($pdo, $page, $limit);

        if ($forumData === null) {
             // getAllForumPosts returns ['posts'=>[], 'total_count'=>0] on error, shouldn't be null
             throw new RuntimeException("Failed to retrieve forum posts from database.");
        }

    } catch (Exception $e) {
        // Log the original error
        error_log("Error in handleGetAllForumPosts calling DAL: " . $e->getMessage());
        // Re-throw as a RuntimeException for the router to catch as 500
        throw new RuntimeException("An error occurred while fetching forum posts.");
    }

    // --- Pagination Calculation ---
    $totalItems = $forumData['total_count'];
    $totalPages = ($limit > 0) ? (int)ceil($totalItems / $limit) : 0;

    // --- Response Formatting ---
    $response = [
        'pagination' => [
            'total_items' => $totalItems,
            'total_pages' => $totalPages,
            'current_page' => $page,
            'limit' => $limit,
        ],
        'posts' => $forumData['posts'] ?? [], // Ensure posts key exists
    ];

    return $response;
}

?>
/**
 * Handles GET requests for /api/v1/forum/posts/recent.
 * Fetches a list of the most recent non-deleted forum posts.
 *
 * @param PDO $pdo The PDO database connection object.
 * @param array $apiKeyData Authenticated API key data (included for consistency, though not used directly here).
 * @param array $queryParams Request query parameters ($_GET).
 * @return array An array of recent post objects, suitable for JSON encoding.
 * @throws InvalidArgumentException For bad request parameters (e.g., invalid limit).
 * @throws RuntimeException For database errors or other internal issues.
 */
function handleGetRecentForumPosts(PDO $pdo, array $apiKeyData, array $queryParams): array
{
    // Note: Authorization (permission check) should be done in the router *before* calling this handler.

    // --- Parameter Parsing & Validation ---
    // Default limit to 10, allow up to 50 recent posts.
    $limit = filter_var($queryParams['limit'] ?? 10, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 50, 'default' => 10]]);

    if ($limit === false) {
        throw new InvalidArgumentException("Invalid 'limit' parameter. Must be a positive integer between 1 and 50.", 400);
    }

    // --- Data Fetching ---
    try {
        // Call the DAL function from includes/data_access/forum_data.php
        $recentPosts = getRecentForumPosts($pdo, $limit);

        // getRecentForumPosts returns [] on error, so no need to check for null explicitly.
        // The try-catch block handles PDOExceptions thrown by the DAL.

    } catch (Exception $e) {
        // Log the original error
        error_log("Error in handleGetRecentForumPosts calling DAL: " . $e->getMessage());
        // Re-throw as a RuntimeException for the router to catch as 500
        throw new RuntimeException("An error occurred while fetching recent forum posts.");
    }

    // --- Response Formatting ---
    // Directly return the array of posts. No pagination structure needed.
    return $recentPosts;
}