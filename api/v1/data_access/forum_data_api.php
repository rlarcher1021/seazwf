<?php

/**
:start_line:6
-------
 * Checks if a forum topic exists and is not locked.
 *
 * @param PDO $pdo Database connection object (PDO).
 * @param int $topic_id The ID of the topic to check.
 * @return bool True if the topic exists and is not locked, false otherwise.
 */
function checkTopicExists(PDO $pdo, int $topic_id): bool
{
    try {
        $stmt = $pdo->prepare("SELECT id FROM forum_topics WHERE id = :topic_id AND is_locked = 0");
        $stmt->bindParam(':topic_id', $topic_id, PDO::PARAM_INT);
        $stmt->execute();
        // fetchColumn returns the value of the first column or false if no row found
        $exists = ($stmt->fetchColumn() !== false);
        return $exists;
    } catch (PDOException $e) {
        error_log("API Forum Data: DB Error in checkTopicExists: " . $e->getMessage());
        return false; // Return false on error
    }
}

/**
 * Creates a new forum post.
:start_line:28
-------
 * Assumes 'created_by_api_key_id' column exists in 'forum_posts'.
 *
 * @param PDO $pdo Database connection object (PDO).
 * @param int $topic_id The ID of the topic to post in.
 * @param string $content The content of the post.
 * @param int $api_key_id The ID of the API key creating the post.
 * @return int|false The ID of the newly created post, or false on failure.
 */
function createForumPost(PDO $pdo, int $topic_id, string $content, int $api_key_id): int|false
{
    // Note: Assumes created_by_api_key_id column exists due to task requirements.
    // Schema update needed: ALTER TABLE `forum_posts` ADD COLUMN `created_by_api_key_id` INT NULL DEFAULT NULL AFTER `user_id`, ADD CONSTRAINT `fk_forum_posts_api_key` FOREIGN KEY (`created_by_api_key_id`) REFERENCES `api_keys`(`id`) ON DELETE SET NULL ON UPDATE CASCADE;
    $sql = "INSERT INTO forum_posts (topic_id, content, user_id, created_by_api_key_id, created_at) VALUES (:topic_id, :content, NULL, :api_key_id, NOW())";
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(':topic_id', $topic_id, PDO::PARAM_INT);
        $stmt->bindParam(':content', $content, PDO::PARAM_STR);
        $stmt->bindParam(':api_key_id', $api_key_id, PDO::PARAM_INT);

        if ($stmt->execute()) {
            return (int)$pdo->lastInsertId(); // Return the new post ID
        } else {
            // Log error: execute failed (PDOException will likely be caught)
            error_log("API Forum Data: Execute failed for createForumPost (PDO)");
            return false;
        }
    } catch (PDOException $e) {
        error_log("API Forum Data: DB Error in createForumPost: " . $e->getMessage());
        return false;
    }
}

/**
 * Updates the last post timestamp for a forum topic.
:start_line:59
-------
 * Sets last_post_user_id to NULL as it's an API post.
 *
 * @param PDO $pdo Database connection object (PDO).
 * @param int $topic_id The ID of the topic to update.
 * @return bool True on success, false on failure.
 */
function updateTopicLastPost(PDO $pdo, int $topic_id): bool
{
    // Note: Setting last_post_user_id to NULL. If tracking last_post_api_key_id is desired,
    // schema update needed: ALTER TABLE `forum_topics` ADD COLUMN `last_post_api_key_id` INT NULL DEFAULT NULL AFTER `last_post_user_id`, ADD CONSTRAINT `fk_forum_topics_last_api_key` FOREIGN KEY (`last_post_api_key_id`) REFERENCES `api_keys`(`id`) ON DELETE SET NULL ON UPDATE CASCADE;
    // And the query would need modification.
    $sql = "UPDATE forum_topics SET last_post_at = NOW(), last_post_user_id = NULL WHERE id = :topic_id";
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(':topic_id', $topic_id, PDO::PARAM_INT);
        return $stmt->execute(); // Returns true on success, false on failure
    } catch (PDOException $e) {
        error_log("API Forum Data: DB Error in updateTopicLastPost: " . $e->getMessage());
        return false;
    }
}

/**
 * Retrieves a specific forum post by its ID.
:start_line:84
-------
 * Includes topic_id, content, created_at, user_id, created_by_api_key_id.
 *
 * @param PDO $pdo Database connection object (PDO).
 * @param int $post_id The ID of the post to retrieve.
 * @return array|null An associative array of the post data, or null if not found or error.
 */
function getForumPostById(PDO $pdo, int $post_id): ?array
{
    // Note: Selecting created_by_api_key_id based on task requirements.
    $sql = "SELECT id, topic_id, content, created_at, user_id, created_by_api_key_id FROM forum_posts WHERE id = :post_id";
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(':post_id', $post_id, PDO::PARAM_INT);
        $stmt->execute();
        $post = $stmt->fetch(PDO::FETCH_ASSOC); // Fetch a single row as associative array
        return $post ?: null; // Return null if fetch returns false (not found)
    } catch (PDOException $e) {
        error_log("API Forum Data: DB Error in getForumPostById: " . $e->getMessage());
        return null;
    }
}