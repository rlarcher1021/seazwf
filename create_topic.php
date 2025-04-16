<?php
require_once 'includes/db_connect.php';
require_once 'includes/auth.php'; // Handles authentication and base role access
require_once 'includes/data_access/forum_data.php';
require_once 'includes/utils.php'; // For formatTimestamp (though not directly used here)

// --- Input Validation (Category ID) ---
if (!isset($_GET['category_id']) || !filter_var($_GET['category_id'], FILTER_VALIDATE_INT)) {
    $_SESSION['flash_message'] = "Invalid category specified for creating a topic.";
    $_SESSION['flash_type'] = 'warning';
    header('Location: forum_index.php');
    exit;
}
$category_id = (int)$_GET['category_id'];
$userRole = $_SESSION['active_role'] ?? 'Staff';
$userId = $_SESSION['user_id'] ?? null;

// --- Fetch Category Details ---
$category = getForumCategoryById($pdo, $category_id);
if (!$category) {
    $_SESSION['flash_message'] = "Category not found.";
    $_SESSION['flash_type'] = 'error';
    header('Location: forum_index.php');
    exit;
}

// --- Permission Check (Post Role) ---
if (!checkForumPermissions($category['post_role'], $userRole)) {
    $_SESSION['flash_message'] = "You do not have permission to create topics in this category.";
    $_SESSION['flash_type'] = 'error';
    header('Location: view_category.php?id=' . $category_id); // Redirect back to category
    exit;
}

// --- POST Handling (Create Topic) ---
$error_message = null;
$form_title = ''; // Preserve input on error
$form_content = ''; // Preserve input on error

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_topic') {
    // CSRF Check
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $error_message = "Invalid request. Please try again.";
    } else {
        // Input Validation
        $form_title = trim($_POST['title'] ?? '');
        $form_content = trim($_POST['content'] ?? '');

        if (empty($form_title)) {
            $error_message = "Topic title cannot be empty.";
        } elseif (empty($form_content)) {
            $error_message = "Topic content cannot be empty.";
        } elseif (strlen($form_title) > 255) { // Check title length (adjust DB limit if needed) - Using strlen as mbstring is unavailable
             $error_message = "Topic title is too long (maximum 255 characters).";
        } else {
            // Re-check permission just before creation (belt and suspenders)
            if (!checkForumPermissions($category['post_role'], $userRole)) {
                 $error_message = "Permission denied."; // Should have been caught earlier
            } else {
                // Attempt to create topic
                $newTopicId = createForumTopic($pdo, $category_id, $userId, $form_title, $form_content);

                if ($newTopicId) {
                    $_SESSION['flash_message'] = "Topic created successfully.";
                    $_SESSION['flash_type'] = 'success';
                    header('Location: view_topic.php?id=' . $newTopicId);
                    exit;
                } else {
                    $error_message = "Failed to create topic due to a server error. Please try again later.";
                }
            }
        }
    }
     // If there was an error, fall through to display the page with the error message and preserved input
}


$pageTitle = "Create Topic in " . htmlspecialchars($category['name']);
include 'includes/header.php';
?>

<div class="container mt-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="forum_index.php">Forum</a></li>
            <li class="breadcrumb-item"><a href="view_category.php?id=<?php echo $category_id; ?>"><?php echo htmlspecialchars($category['name']); ?></a></li>
            <li class="breadcrumb-item active" aria-current="page">Create New Topic</li>
        </ol>
    </nav>

    <h2>Create New Topic in "<?php echo htmlspecialchars($category['name']); ?>"</h2>
    <hr>

    <?php if ($error_message): ?>
        <div class="alert alert-danger"><?php echo htmlspecialchars($error_message); ?></div>
    <?php endif; ?>

    <form method="POST" action="create_topic.php?category_id=<?php echo $category_id; ?>">
        <input type="hidden" name="action" value="create_topic">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">

        <div class="mb-3">
            <label for="title" class="form-label">Topic Title:</label>
            <input type="text" class="form-control" id="title" name="title" value="<?php echo htmlspecialchars($form_title); ?>" required maxlength="255">
        </div>

        <div class="mb-3">
            <label for="content" class="form-label">Initial Post Content:</label>
            <textarea class="form-control" id="content" name="content" rows="10" required><?php echo htmlspecialchars($form_content); ?></textarea>
        </div>

        <button type="submit" class="btn btn-primary">Create Topic</button>
        <a href="view_category.php?id=<?php echo $category_id; ?>" class="btn btn-secondary">Cancel</a>
    </form>

</div>

<?php include 'includes/footer.php'; ?>