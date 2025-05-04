<?php

/**
 * Fetches all forum categories ordered by display_order.
 *
 * @param PDO $pdo The PDO database connection object.
 * @return array An array of category objects or an empty array on failure.
 */
function getAllForumCategories(PDO $pdo): array
{
    try {
        $stmt = $pdo->query("SELECT * FROM forum_categories ORDER BY display_order ASC");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        // Log error (implement proper logging)
        error_log("Error fetching forum categories: " . $e->getMessage());
        return [];
    }
}

/**
 * Fetches details for a single forum category by its ID.
 *
 * @param PDO $pdo The PDO database connection object.
 * @param int $categoryId The ID of the category to fetch.
 * @return array|false The category details as an associative array, or false if not found or on error.
 */
function getForumCategoryById(PDO $pdo, int $categoryId)
{
    try {
        $stmt = $pdo->prepare("SELECT * FROM forum_categories WHERE id = :id");
        $stmt->bindParam(':id', $categoryId, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error fetching category by ID ($categoryId): " . $e->getMessage());
        return false;
    }
}

/**
 * Fetches topics for a given category with pagination.
 *
 * @param PDO $pdo The PDO database connection object.
 * @param int $categoryId The ID of the category.
 * @param int $limit The maximum number of topics per page.
 * @param int $offset The starting offset for pagination.
 * @return array An array of topic objects or an empty array on failure.
 */
function getTopicsByCategory(PDO $pdo, int $categoryId, int $limit, int $offset): array
{
    try {
        $sql = "SELECT t.*, u.username as author_username, lu.username as last_post_username
                FROM forum_topics t
                LEFT JOIN users u ON t.user_id = u.id
                LEFT JOIN users lu ON t.last_post_user_id = lu.id
                WHERE t.category_id = :category_id
                ORDER BY t.is_sticky DESC, t.last_post_at DESC
                LIMIT :limit OFFSET :offset";
        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(':category_id', $categoryId, PDO::PARAM_INT);
        $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindParam(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error fetching topics for category ($categoryId): " . $e->getMessage());
        return [];
    }
}

/**
 * Counts the total number of topics in a specific category.
 *
 * @param PDO $pdo The PDO database connection object.
 * @param int $categoryId The ID of the category.
 * @return int The total number of topics, or 0 on failure.
 */
function getTopicCountByCategory(PDO $pdo, int $categoryId): int
{
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM forum_topics WHERE category_id = :category_id");
        $stmt->bindParam(':category_id', $categoryId, PDO::PARAM_INT);
        $stmt->execute();
        return (int)$stmt->fetchColumn();
    } catch (PDOException $e) {
        error_log("Error counting topics for category ($categoryId): " . $e->getMessage());
        return 0;
    }
}

/**
 * Fetches details for a single topic by its ID.
 *
 * @param PDO $pdo The PDO database connection object.
 * @param int $topicId The ID of the topic to fetch.
 * @return array|false The topic details as an associative array, or false if not found or on error.
 */
function getTopicById(PDO $pdo, int $topicId)
{
    try {
        $sql = "SELECT t.*, u.username as author_username
                FROM forum_topics t
                LEFT JOIN users u ON t.user_id = u.id
                WHERE t.id = :id";
        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(':id', $topicId, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error fetching topic by ID ($topicId): " . $e->getMessage());
        return false;
    }
}
/**
 * Checks if a forum topic exists by its ID.
 *
 * @param PDO $pdo The PDO database connection object.
 * @param int $topicId The ID of the topic to check.
 * @return bool True if the topic exists, false otherwise.
 */
function checkTopicExists(PDO $pdo, int $topicId): bool
{
    try {
        $stmt = $pdo->prepare("SELECT 1 FROM forum_topics WHERE id = :id LIMIT 1");
        $stmt->bindParam(':id', $topicId, PDO::PARAM_INT);
        $stmt->execute();
        // fetchColumn() returns the value of the first column of the next row,
        // or false if there are no more rows. Since we selected '1',
        // it will return '1' (truthy) if found, and false otherwise.
        return $stmt->fetchColumn() !== false;
    } catch (PDOException $e) {
        error_log("Error checking if topic exists (ID: $topicId): " . $e->getMessage());
        return false; // Return false on database error
    }
}

/**
 * Fetches posts for a given topic with pagination.
 *
 * @param PDO $pdo The PDO database connection object.
 * @param int $topicId The ID of the topic.
 * @param int $limit The maximum number of posts per page.
 * @param int $offset The starting offset for pagination.
 * @return array An array of post objects or an empty array on failure.
 */
function getPostsByTopic(PDO $pdo, int $topicId, int $limit, int $offset): array
{
    try {
        $sql = "SELECT p.*, u.username as author_username, u.full_name as author_full_name
                FROM forum_posts p
                LEFT JOIN users u ON p.user_id = u.id
                WHERE p.topic_id = :topic_id
                ORDER BY p.created_at ASC
                LIMIT :limit OFFSET :offset";
        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(':topic_id', $topicId, PDO::PARAM_INT);
        $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindParam(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error fetching posts for topic ($topicId): " . $e->getMessage());
        return [];
    }
}

/**
 * Counts the total number of posts in a specific topic.
 *
 * @param PDO $pdo The PDO database connection object.
 * @param int $topicId The ID of the topic.
 * @return int The total number of posts, or 0 on failure.
 */
function getPostCountByTopic(PDO $pdo, int $topicId): int
{
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM forum_posts WHERE topic_id = :topic_id");
        $stmt->bindParam(':topic_id', $topicId, PDO::PARAM_INT);
        $stmt->execute();
        return (int)$stmt->fetchColumn();
    } catch (PDOException $e) {
        error_log("Error counting posts for topic ($topicId): " . $e->getMessage());
        return 0;
    }
}

/**
 * Fetches basic user details by user ID.
 * Note: Consider using a shared user_data function if available and suitable.
 *
 * @param PDO $pdo The PDO database connection object.
 * @param int $userId The ID of the user.
 * @return array|false User details (username, full_name) or false if not found/error.
 */
function getUserDetails(PDO $pdo, int $userId)
{
     // Check if user_data.php and getUserById exist, otherwise use a basic query
    if (function_exists('getUserById')) {
         // Assuming getUserById returns an array with 'username' and 'full_name'
        return getUserById($pdo, $userId); // Reuse existing function
    } else {
        // Basic fallback if getUserById is not available
        try {
            $stmt = $pdo->prepare("SELECT id, username, full_name FROM users WHERE id = :id");
            $stmt->bindParam(':id', $userId, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error fetching user details ($userId): " . $e->getMessage());
            return false;
        }
    }
}


/**
 * Creates a new forum topic and its initial post within a transaction.
 *
 * @param PDO $pdo The PDO database connection object.
 * @param int $categoryId The ID of the category for the new topic.
 * @param int $userId The ID of the user creating the topic.
 * @param string $title The title of the new topic.
 * @param string $content The content of the initial post.
 * @return int|false The ID of the newly created topic on success, false on failure.
 */
function createForumTopic(PDO $pdo, int $categoryId, int $userId, string $title, string $content)
{
    $pdo->beginTransaction();
    try {
        // Insert the topic
        // Use distinct placeholders for clarity, even if binding the same variable
        $stmtTopic = $pdo->prepare("INSERT INTO forum_topics (category_id, user_id, title, created_at, last_post_at, last_post_user_id) VALUES (:category_id, :user_id, :title, NOW(), NOW(), :last_post_user_id)");
        $stmtTopic->bindParam(':category_id', $categoryId, PDO::PARAM_INT);
        $stmtTopic->bindParam(':user_id', $userId, PDO::PARAM_INT); // Binds to the first :user_id
        $stmtTopic->bindParam(':title', $title, PDO::PARAM_STR);
        $stmtTopic->bindParam(':last_post_user_id', $userId, PDO::PARAM_INT); // Explicitly bind the second :user_id
        $stmtTopic->execute();

        $topicId = $pdo->lastInsertId();

        if (!$topicId) {
            throw new Exception("Failed to get last insert ID for topic.");
        }

        // Insert the initial post
        $stmtPost = $pdo->prepare("INSERT INTO forum_posts (topic_id, user_id, content, created_at) VALUES (:topic_id, :user_id, :content, NOW())");
        $stmtPost->bindParam(':topic_id', $topicId, PDO::PARAM_INT);
        $stmtPost->bindParam(':user_id', $userId, PDO::PARAM_INT);
        $stmtPost->bindParam(':content', $content, PDO::PARAM_STR);
        $stmtPost->execute();

        // No need to update last_post_* here as it was set during topic creation

        $pdo->commit();
        return (int)$topicId;

    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("Error creating forum topic: " . $e->getMessage());
        return false;
    }
}

/**
 * Creates a new reply post in a forum topic within a transaction.
 *
 * @param PDO $pdo The PDO database connection object.
 * @param int $topicId The ID of the topic to reply to.
 * @param int $userId The ID of the user posting the reply.
 * @param string $content The content of the reply.
 * @return bool True on success, false on failure.
 */
function createForumPost(PDO $pdo, int $topicId, int $userId, string $content): bool
{
    $pdo->beginTransaction();
    try {
        // Insert the new post
        $stmtPost = $pdo->prepare("INSERT INTO forum_posts (topic_id, user_id, content, created_at) VALUES (:topic_id, :user_id, :content, NOW())");
        $stmtPost->bindParam(':topic_id', $topicId, PDO::PARAM_INT);
        $stmtPost->bindParam(':user_id', $userId, PDO::PARAM_INT);
        $stmtPost->bindParam(':content', $content, PDO::PARAM_STR);
        $stmtPost->execute();

        // Update the topic's last post information
        $stmtUpdateTopic = $pdo->prepare("UPDATE forum_topics SET last_post_at = NOW(), last_post_user_id = :user_id WHERE id = :topic_id");
        $stmtUpdateTopic->bindParam(':user_id', $userId, PDO::PARAM_INT);
        $stmtUpdateTopic->bindParam(':topic_id', $topicId, PDO::PARAM_INT);
        $stmtUpdateTopic->execute();

        $pdo->commit();
        return true;

    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("Error creating forum post for topic ($topicId): " . $e->getMessage());
        return false;
    }
}

// Use the exact role string from your users table/session for Admin
define('ADMIN_ROLE_NAME', 'administrator');

/**
 * Checks if a user has the required role level for a forum action.
 * Admins always have permission. Higher roles satisfy lower requirements.
 *
 * @param string $requiredRole The minimum role required (e.g., 'azwk_staff', 'outside_staff', 'director', 'administrator').
 * @param ?string $userRole The user's current role from the session, or null if not logged in.
 * @return bool True if permission granted, false otherwise.
 */
function checkForumPermissions(string $requiredRole, ?string $userRole): bool {
    // Deny if user role is not provided
    if ($userRole === null) {
        return false;
    }

    // Grant immediate access if the user is an Admin
    if ($userRole === ADMIN_ROLE_NAME) {
        return true;
    }

    // Define the hierarchy using EXACT role names from DB/Session
    $roleHierarchy = [
        'azwk_staff' => 1,
        'outside_staff' => 1,
        'director' => 2,
         ADMIN_ROLE_NAME => 3 // 'administrator'
    ];

    // Get numeric level for user and requirement
    $userLevel = $roleHierarchy[$userRole] ?? 0; // Defaults to 0 if user role not in hierarchy
    $requiredLevel = $roleHierarchy[$requiredRole] ?? 0; // Defaults to 0 if required role not in hierarchy

    // If the required role isn't valid in our hierarchy, deny access
    if ($requiredLevel === 0) {
        // You might want to log this: error_log("Invalid required forum role specified: " . $requiredRole);
        return false;
    }
    // If the user's role isn't valid in our hierarchy (and they aren't Admin), deny access
     if ($userLevel === 0) {
         // You might want to log this: error_log("User role not found in forum hierarchy: " . $userRole);
         return false;
     }

    // User's level must be greater than or equal to the required level
    return $userLevel >= $requiredLevel;
}

/**
 * Fetches all non-deleted forum posts with pagination, joining topic and user information.
 *
 * @param PDO $pdo The PDO database connection object.
 * @param int $page The current page number (1-based).
 * @param int $limit The number of posts per page.
 * @return array An array containing 'posts' (array of post objects) and 'total_count' (int), or ['posts' => [], 'total_count' => 0] on failure.
 */
function getAllForumPosts(PDO $pdo, int $page = 1, int $limit = 25): array
{
// Sanitize pagination parameters
$page = max(1, $page);
$limit = max(1, $limit);
    $offset = ($page - 1) * $limit;

    $result = ['posts' => [], 'total_count' => 0];

    try {
// Query to get the paginated posts
$sqlPosts = "SELECT
                p.id AS post_id,
                        p.content,
                        p.created_at,
                        p.topic_id,
                        t.title AS topic_title,
                        p.user_id,
                        COALESCE(u.full_name, 'System/API User') AS author_full_name,
                        p.created_by_api_key_id
                    FROM
                        forum_posts p
                    LEFT JOIN
                        forum_topics t ON p.topic_id = t.id
                    LEFT JOIN
                        users u ON p.user_id = u.id
                    -- WHERE p.is_deleted = 0 -- is_deleted column not found in provided schema
                    ORDER BY
                        p.created_at DESC
                    LIMIT :limit OFFSET :offset";

        $stmtPosts = $pdo->prepare($sqlPosts);
        $stmtPosts->bindParam(':limit', $limit, PDO::PARAM_INT);
        $stmtPosts->bindParam(':offset', $offset, PDO::PARAM_INT);
        $stmtPosts->execute();
        $result['posts'] = $stmtPosts->fetchAll(PDO::FETCH_ASSOC);

        // Query to get the total count of non-deleted posts
        $sqlCount = "SELECT COUNT(*) FROM forum_posts -- WHERE is_deleted = 0"; // is_deleted column not found
        $stmtCount = $pdo->query($sqlCount);
        if ($stmtCount) {
            $result['total_count'] = (int)$stmtCount->fetchColumn();
        }

        return $result;

    } catch (PDOException $e) {
        error_log("Error fetching all forum posts: " . $e->getMessage()); // Restore original error log
        return $result; // Return default empty result on error
    }
}
/**
 * Fetches the most recent non-deleted forum posts, joining topic and user information.
 *
 * @param PDO $pdo The PDO database connection object.
 * @param int $limit The maximum number of recent posts to fetch.
 * @return array An array of post objects, or an empty array on failure.
 */
function getRecentForumPosts(PDO $pdo, int $limit = 10): array
{
    // Ensure limit is a positive integer
    $limit = max(1, $limit);

    try {
        $sql = "SELECT
                    p.id AS post_id,
                    p.content,
                    p.created_at,
                    p.topic_id,
                    t.title AS topic_title,
                    p.user_id,
                    COALESCE(u.full_name, 'System/API User') AS author_full_name,
                    p.created_by_api_key_id
                FROM
                    forum_posts p
                LEFT JOIN
                    forum_topics t ON p.topic_id = t.id
                LEFT JOIN
                    users u ON p.user_id = u.id
                ORDER BY
                    p.created_at DESC
                LIMIT :limit";

        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);

    } catch (PDOException $e) {
        error_log("Error fetching recent forum posts: " . $e->getMessage());
        return []; // Return empty array on error
    }
}
/**
 * Creates a new forum post via the API within a transaction.
 * Associates the post with an API key instead of a user.
 *
 * @param PDO $pdo The PDO database connection object.
 * @param int $topicId The ID of the topic to post in.
 * @param string $content The content of the post.
 * @param int $apiKeyId The ID of the API key creating the post.
 * @return array|false An associative array of the created post data on success, false on failure.
 */
function createForumPostApi(PDO $pdo, int $topicId, string $content, int $apiKeyId)
{
    $pdo->beginTransaction();
    try {
        // Insert the new post, linking to the API key
        $stmtPost = $pdo->prepare(
            "INSERT INTO forum_posts (topic_id, content, created_at, created_by_api_key_id)
             VALUES (:topic_id, :content, NOW(), :api_key_id)"
        );
        $stmtPost->bindParam(':topic_id', $topicId, PDO::PARAM_INT);
        $stmtPost->bindParam(':content', $content, PDO::PARAM_STR);
        $stmtPost->bindParam(':api_key_id', $apiKeyId, PDO::PARAM_INT);
        $stmtPost->execute();

        $postId = $pdo->lastInsertId();
        if (!$postId) {
            throw new Exception("Failed to get last insert ID for API post.");
        }

        // Update the topic's last post information - Set user to NULL for API posts
        $stmtUpdateTopic = $pdo->prepare(
            "UPDATE forum_topics SET last_post_at = NOW(), last_post_user_id = NULL
             WHERE id = :topic_id"
        );
        $stmtUpdateTopic->bindParam(':topic_id', $topicId, PDO::PARAM_INT);
        $stmtUpdateTopic->execute();

        // Fetch the created post data to return
        $stmtFetch = $pdo->prepare("SELECT * FROM forum_posts WHERE id = :post_id");
        $stmtFetch->bindParam(':post_id', $postId, PDO::PARAM_INT);
        $stmtFetch->execute();
        $createdPostData = $stmtFetch->fetch(PDO::FETCH_ASSOC);

        $pdo->commit();
        return $createdPostData ?: false; // Return fetched data or false if fetch failed

    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("Error creating API forum post for topic ($topicId): " . $e->getMessage());
        return false;
    }
}