<?php
require_once 'includes/db_connect.php';
require_once 'includes/auth.php';
require_once 'includes/data_access/forum_data.php';
require_once 'includes/utils.php'; // For formatTimestamp, if needed

// Authentication is handled by includes/auth.php

// Fetch all categories
$categories = getAllForumCategories($pdo);
$userRole = $_SESSION['active_role'] ?? null; // Use active_role consistent with auth.php; checkForumPermissions handles null

$pageTitle = "Forum Categories";
include 'includes/header.php';
?>

<div class="container mt-4">
    <h2>Forum Categories</h2>
    <hr>

    <?php if (empty($categories)): ?>
        <div class="alert alert-info" role="alert">
            No forum categories have been created yet.
        </div>
    <?php else: ?>
        <div class="list-group">
            <?php
            $viewableCategories = 0;
            foreach ($categories as $category):
                // Check if the user has permission to view this category
                if (checkForumPermissions($category['view_role'], $userRole)):
                    $viewableCategories++;
            ?>
                    <a href="view_category.php?id=<?php echo htmlspecialchars($category['id']); ?>" class="list-group-item list-group-item-action flex-column align-items-start">
                        <div class="d-flex w-100 justify-content-between">
                            <h5 class="mb-1"><?php echo htmlspecialchars($category['name']); ?></h5>
                            <!-- Optional: Add last post info or topic count here later -->
                        </div>
                        <p class="mb-1"><?php echo htmlspecialchars($category['description']); ?></p>
                        <small>Required Role to View: <?php echo htmlspecialchars($category['view_role']); ?></small>
                    </a>
            <?php
                endif; // end permission check
            endforeach; // end category loop

            if ($viewableCategories === 0):
            ?>
                <div class="alert alert-warning" role="alert">
                    There are no forum categories available for you to view based on your role.
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

</div>

<?php include 'includes/footer.php'; ?>