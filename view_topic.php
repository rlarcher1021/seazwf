<?php
require_once 'includes/db_connect.php';
require_once 'includes/auth.php'; // Handles authentication and base role access
require_once 'includes/data_access/forum_data.php';
require_once 'includes/utils.php'; // For formatTimestamp

// --- Configuration ---
$posts_per_page = 10; // Number of posts per page

// --- Input Validation (Topic ID) ---
if (!isset($_GET['id']) || !filter_var($_GET['id'], FILTER_VALIDATE_INT)) {
    $_SESSION['flash_message'] = "Invalid topic specified.";
    $_SESSION['flash_type'] = 'warning';
    header('Location: forum_index.php');
    exit;
}
$topic_id = (int)$_GET['id'];
$userRole = $_SESSION['active_role'] ?? 'Staff';
$userId = $_SESSION['user_id'] ?? null;

// --- Fetch Topic Details ---
$topic = getTopicById($pdo, $topic_id);
if (!$topic) {
    $_SESSION['flash_message'] = "Topic not found.";
    $_SESSION['flash_type'] = 'error';
    header('Location: forum_index.php'); // Or maybe the category page if we could determine it?
    exit;
}

// --- Fetch Category Details (for permissions) ---
$category = getForumCategoryById($pdo, $topic['category_id']);
if (!$category) {
    // Should not happen if topic exists, but good practice
    $_SESSION['flash_message'] = "Associated category not found.";
    $_SESSION['flash_type'] = 'error';
    header('Location: forum_index.php');
    exit;
}

// --- Permission Check (View) ---
if (!checkForumPermissions($category['view_role'], $userRole)) {
    $_SESSION['flash_message'] = "You do not have permission to view this topic.";
    $_SESSION['flash_type'] = 'error';
    header('Location: view_category.php?id=' . $topic['category_id']); // Redirect back to category
    exit;
}

// --- POST Handling (Create Reply) ---
$reply_error = null;
$reply_success = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_reply') {
    // CSRF Check
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $reply_error = "Invalid request. Please try again.";
    } else {
        // Input Validation
        $content = trim($_POST['content'] ?? '');
        if (empty($content)) {
            $reply_error = "Reply content cannot be empty.";
        } else {
            // Permission Check (Reply)
            if ($topic['is_locked']) {
                $reply_error = "This topic is locked and does not allow new replies.";
            } elseif (!checkForumPermissions($category['reply_role'], $userRole)) {
                $reply_error = "You do not have permission to reply in this category.";
            } else {
                // Attempt to create post
                if (createForumPost($pdo, $topic_id, $userId, $content)) {
                    // Success - Redirect to the last page of the topic
                    $totalPosts = getPostCountByTopic($pdo, $topic_id); // Recalculate after adding
                    $lastPage = ceil($totalPosts / $posts_per_page);
                    $_SESSION['flash_message'] = "Reply posted successfully.";
                    $_SESSION['flash_type'] = 'success';
                    header('Location: view_topic.php?id=' . $topic_id . '&page=' . $lastPage . '#post-' . $pdo->lastInsertId()); // Redirect to last page and new post anchor
                    exit;
                } else {
                    $reply_error = "Failed to post reply due to a server error. Please try again later.";
                }
            }
        }
    }
     // If there was an error, fall through to display the page with the error message
}


// --- Pagination Logic (for GET request) ---
$page = isset($_GET['page']) && filter_var($_GET['page'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ? (int)$_GET['page'] : 1;
$totalPosts = getPostCountByTopic($pdo, $topic_id);
$totalPages = ceil($totalPosts / $posts_per_page);
$offset = ($page - 1) * $posts_per_page;

// Ensure page number is valid
if ($page > $totalPages && $totalPosts > 0) {
    header('Location: view_topic.php?id=' . $topic_id . '&page=' . $totalPages);
    exit;
}
if ($page < 1) {
     header('Location: view_topic.php?id=' . $topic_id . '&page=1');
    exit;
}

// --- Fetch Posts ---
$posts = getPostsByTopic($pdo, $topic_id, $posts_per_page, $offset);

$pageTitle = "View Topic: " . htmlspecialchars($topic['title']);
include 'includes/header.php';
?>

<div class="container mt-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="forum_index.php">Forum</a></li>
            <li class="breadcrumb-item"><a href="view_category.php?id=<?php echo $topic['category_id']; ?>"><?php echo htmlspecialchars($category['name']); ?></a></li>
            <li class="breadcrumb-item active" aria-current="page"><?php echo htmlspecialchars($topic['title']); ?></li>
        </ol>
    </nav>

    <h2 class="mb-3"><?php echo htmlspecialchars($topic['title']); ?></h2>
     <?php if ($topic['is_locked']): ?>
        <div class="alert alert-warning" role="alert">
            <i class="fas fa-lock"></i> This topic is locked. No new replies can be added.
        </div>
    <?php endif; ?>

    <!-- Display Posts -->
    <?php if (empty($posts)): ?>
        <div class="alert alert-info">No posts found in this topic yet.</div>
    <?php else: ?>
        <?php foreach ($posts as $post): ?>
            <div class="card mb-3" id="post-<?php echo $post['id']; ?>">
                <div class="card-header d-flex justify-content-between">
                    <span>
                        <strong><?php echo htmlspecialchars($post['author_username'] ?? 'Unknown User'); ?></strong>
                        (<?php echo htmlspecialchars($post['author_full_name'] ?? 'N/A'); ?>)
                    </span>
                    <small class="text-muted">Posted: <?php echo formatTimestamp($post['created_at']); ?></small>
                </div>
                <div class="card-body">
                    <p class="card-text"><?php echo nl2br(htmlspecialchars($post['content'])); ?></p>
                </div>
                <!-- Add post controls (edit/delete) here later if needed -->
            </div>
        <?php endforeach; ?>
    <?php endif; ?>

    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
        <nav aria-label="Post pagination">
            <ul class="pagination justify-content-center">
                <?php if ($page > 1): ?>
                    <li class="page-item"><a class="page-link" href="?id=<?php echo $topic_id; ?>&page=<?php echo $page - 1; ?>">Previous</a></li>
                <?php else: ?>
                    <li class="page-item disabled"><span class="page-link">Previous</span></li>
                <?php endif; ?>

                <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                    <li class="page-item <?php echo ($i == $page) ? 'active' : ''; ?>">
                        <a class="page-link" href="?id=<?php echo $topic_id; ?>&page=<?php echo $i; ?>"><?php echo $i; ?></a>
                    </li>
                <?php endfor; ?>

                <?php if ($page < $totalPages): ?>
                    <li class="page-item"><a class="page-link" href="?id=<?php echo $topic_id; ?>&page=<?php echo $page + 1; ?>">Next</a></li>
                <?php else: ?>
                    <li class="page-item disabled"><span class="page-link">Next</span></li>
                <?php endif; ?>
            </ul>
        </nav>
    <?php endif; ?>

    <hr>

    <!-- Reply Form -->
    <?php if (!$topic['is_locked'] && checkForumPermissions($category['reply_role'], $userRole)): ?>
        <div class="card">
            <div class="card-header">Post a Reply</div>
            <div class="card-body">
                <?php if ($reply_error): ?>
                    <div class="alert alert-danger"><?php echo htmlspecialchars($reply_error); ?></div>
                <?php endif; ?>
                <form method="POST" action="view_topic.php?id=<?php echo $topic_id; ?>&page=<?php echo $page; /* Keep current page context for form resubmission */ ?>">
                    <input type="hidden" name="action" value="create_reply">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                    <div class="mb-3">
                        <label for="content" class="form-label">Your Reply:</label>
                        <textarea class="form-control" id="content" name="content" rows="5" required><?php echo isset($_POST['content']) && $reply_error ? htmlspecialchars($_POST['content']) : ''; // Preserve content on error ?></textarea>
                    </div>
                    <button type="submit" class="btn btn-primary">Submit Reply</button>
                </form>
            </div>
        </div>
    <?php elseif ($topic['is_locked']): ?>
        <!-- Message already shown above -->
    <?php else: ?>
         <div class="alert alert-warning" role="alert">
            You do not have permission to reply in this category.
        </div>
    <?php endif; ?>

</div>

<?php include 'includes/footer.php'; ?>