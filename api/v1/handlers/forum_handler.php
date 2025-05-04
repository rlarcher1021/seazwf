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
/**
 * Handles POST requests for /api/v1/forum/posts.
 * Creates a new forum post.
 *
 * @param PDO $pdo The PDO database connection object.
 * @param array $apiKeyData Authenticated API key data (including permissions and id).
 * @param array $requestBody Decoded JSON request body.
 * @return array Associative array representing the created post, suitable for JSON encoding.
 * @throws InvalidArgumentException For bad request parameters (missing/invalid fields).
 * @throws RuntimeException For database errors, non-existent topic, or other internal issues.
 */
function handleCreateForumPost(PDO $pdo, array $apiKeyData, array $requestBody): array
{
    // Note: Authorization (permission check 'create:forum_post') should be done in the router *before* calling this handler.

    // --- Input Validation ---
    if (!isset($requestBody['topic_id']) || !filter_var($requestBody['topic_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])) {
        throw new InvalidArgumentException("Missing or invalid 'topic_id'. Must be a positive integer.", 400);
    }
    $topicId = (int)$requestBody['topic_id'];

    if (!isset($requestBody['post_body']) || empty(trim($requestBody['post_body']))) {
        throw new InvalidArgumentException("Missing or empty 'post_body'.", 400);
    }
    $postBody = trim($requestBody['post_body']);

    // Extract API Key ID for associating the post
    if (!isset($apiKeyData['id'])) {
         error_log("API Key ID missing from authenticated data in handleCreateForumPost.");
         throw new RuntimeException("Internal server error: Unable to identify API key.", 500);
    }
    $apiKeyId = (int)$apiKeyData['id'];


    // --- Business Logic & Data Access ---
    try {
        // 1. Check if the topic exists using the function from forum_data.php
        if (!checkTopicExists($pdo, $topicId)) {
            // Throw a RuntimeException with a specific message and code for the router to map to 404
            throw new RuntimeException("Topic with ID {$topicId} not found.", 404);
        }

        // 2. Create the post using a DAL function (needs implementation/modification in forum_data.php)
        // This assumes a function `createForumPostApi` exists or `createForumPost` is modified
        // to accept an API key ID instead of/in addition to a user ID.
        // Let's assume it returns the created post details or its ID.
        $createdPost = createForumPostApi($pdo, $topicId, $postBody, $apiKeyId); // Placeholder DAL call

        if ($createdPost === false) {
            // DAL function indicated failure
            throw new RuntimeException("Failed to create forum post in the database.");
        }

        // --- Response Formatting ---
        // Assuming $createdPost contains the necessary details (e.g., id, topic_id, content, created_at, created_by_api_key_id)
        // If it only returns an ID, you might need another fetch here, but ideally, the create function returns the data.
        http_response_code(201); // Set HTTP status code to 201 Created
        return $createdPost; // Return the created post data

    } catch (PDOException $e) {
        error_log("Database error creating forum post for topic ($topicId): " . $e->getMessage());
        throw new RuntimeException("An internal error occurred while creating the forum post."); // Caught as 500
    } catch (RuntimeException $e) {
        // Re-throw RuntimeExceptions (like the 404 or DAL failure) for the main error handler
        throw $e;
    } catch (Exception $e) {
        // Catch any other unexpected exceptions
        error_log("Unexpected error creating forum post for topic ($topicId): " . $e->getMessage());
        throw new RuntimeException("An unexpected error occurred."); // Caught as 500
    }
}