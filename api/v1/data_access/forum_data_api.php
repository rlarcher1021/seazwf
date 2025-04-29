<?php

/**
 * Checks if a forum topic exists and is not locked.
 *
 * @param mysqli $db Database connection object.
 * @param int $topic_id The ID of the topic to check.
 * @return bool True if the topic exists and is not locked, false otherwise.
 */
function checkTopicExists(mysqli $db, int $topic_id): bool
{
    $stmt = $db->prepare("SELECT id FROM forum_topics WHERE id = ? AND is_locked = 0");
    if (!$stmt) {
        // Log error: prepare failed
        error_log("API Forum Data: Prepare failed for checkTopicExists: " . $db->error);
        return false;
    }
    $stmt->bind_param("i", $topic_id);
    $stmt->execute();
    $stmt->store_result();
    $exists = $stmt->num_rows > 0;
    $stmt->close();
    return $exists;
}

/**
 * Creates a new forum post.
 * Assumes 'created_by_api_key_id' column exists in 'forum_posts'.
 *
 * @param mysqli $db Database connection object.
 * @param int $topic_id The ID of the topic to post in.
 * @param string $content The content of the post.
 * @param int $api_key_id The ID of the API key creating the post.
 * @return int|false The ID of the newly created post, or false on failure.
 */
function createForumPost(mysqli $db, int $topic_id, string $content, int $api_key_id): int|false
{
    // Note: Assumes created_by_api_key_id column exists due to task requirements.
    // Schema update needed: ALTER TABLE `forum_posts` ADD COLUMN `created_by_api_key_id` INT NULL DEFAULT NULL AFTER `user_id`, ADD CONSTRAINT `fk_forum_posts_api_key` FOREIGN KEY (`created_by_api_key_id`) REFERENCES `api_keys`(`id`) ON DELETE SET NULL ON UPDATE CASCADE;
    $stmt = $db->prepare("INSERT INTO forum_posts (topic_id, content, user_id, created_by_api_key_id, created_at) VALUES (?, ?, NULL, ?, NOW())");
    if (!$stmt) {
        // Log error: prepare failed
        error_log("API Forum Data: Prepare failed for createForumPost: " . $db->error);
        return false;
    }
    $stmt->bind_param("isi", $topic_id, $content, $api_key_id);
    if ($stmt->execute()) {
        $new_post_id = $db->insert_id;
        $stmt->close();
        return $new_post_id;
    } else {
        // Log error: execute failed
        error_log("API Forum Data: Execute failed for createForumPost: " . $stmt->error);
        $stmt->close();
        return false;
    }
}

/**
 * Updates the last post timestamp for a forum topic.
 * Sets last_post_user_id to NULL as it's an API post.
 *
 * @param mysqli $db Database connection object.
 * @param int $topic_id The ID of the topic to update.
 * @return bool True on success, false on failure.
 */
function updateTopicLastPost(mysqli $db, int $topic_id): bool
{
    // Note: Setting last_post_user_id to NULL. If tracking last_post_api_key_id is desired,
    // schema update needed: ALTER TABLE `forum_topics` ADD COLUMN `last_post_api_key_id` INT NULL DEFAULT NULL AFTER `last_post_user_id`, ADD CONSTRAINT `fk_forum_topics_last_api_key` FOREIGN KEY (`last_post_api_key_id`) REFERENCES `api_keys`(`id`) ON DELETE SET NULL ON UPDATE CASCADE;
    // And the query would need modification.
    $stmt = $db->prepare("UPDATE forum_topics SET last_post_at = NOW(), last_post_user_id = NULL WHERE id = ?");
     if (!$stmt) {
        // Log error: prepare failed
        error_log("API Forum Data: Prepare failed for updateTopicLastPost: " . $db->error);
        return false;
    }
    $stmt->bind_param("i", $topic_id);
    $success = $stmt->execute();
     if (!$success) {
        // Log error: execute failed
        error_log("API Forum Data: Execute failed for updateTopicLastPost: " . $stmt->error);
    }
    $stmt->close();
    return $success;
}

/**
 * Retrieves a specific forum post by its ID.
 * Includes topic_id, content, created_at, user_id, created_by_api_key_id.
 *
 * @param mysqli $db Database connection object.
 * @param int $post_id The ID of the post to retrieve.
 * @return array|null An associative array of the post data, or null if not found or error.
 */
function getForumPostById(mysqli $db, int $post_id): ?array
{
    // Note: Selecting created_by_api_key_id based on task requirements.
    $stmt = $db->prepare("SELECT id, topic_id, content, created_at, user_id, created_by_api_key_id FROM forum_posts WHERE id = ?");
     if (!$stmt) {
        // Log error: prepare failed
        error_log("API Forum Data: Prepare failed for getForumPostById: " . $db->error);
        return null;
    }
    $stmt->bind_param("i", $post_id);
    if ($stmt->execute()) {
        $result = $stmt->get_result();
        $post = $result->fetch_assoc();
        $stmt->close();
        return $post ?: null; // Return null if fetch_assoc returns false (not found)
    } else {
         // Log error: execute failed
        error_log("API Forum Data: Execute failed for getForumPostById: " . $stmt->error);
        $stmt->close();
        return null;
    }
}