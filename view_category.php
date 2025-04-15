<?php
require_once 'includes/db_connect.php';
require_once 'includes/auth.php'; // Handles authentication and basic role access
require_once 'includes/data_access/forum_data.php';
require_once 'includes/utils.php'; // For formatTimestamp

// --- Configuration ---
$items_per_page = 15; // Number of topics per page

// --- Input Validation ---
if (!isset($_GET['id']) || !filter_var($_GET['id'], FILTER_VALIDATE_INT)) {
    $_SESSION['flash_message'] = "Invalid category specified.";
    $_SESSION['flash_type'] = 'warning';
    header('Location: forum_index.php');
    exit;
}
$category_id = (int)$_GET['id'];
$userRole = $_SESSION['active_role'] ?? 'Staff'; // Use active_role from auth.php
$userId = $_SESSION['user_id'] ?? null;

// --- Fetch Category Details ---
$category = getForumCategoryById($pdo, $category_id);

// --- Permission Check ---
if (!$category || !checkForumPermissions($category['view_role'], $userRole)) {
    $_SESSION['flash_message'] = "Category not found or you do not have permission to view it.";
    $_SESSION['flash_type'] = 'error';
    // Redirect to forum index if not found, or dashboard if they are logged in but lack permissions
    header('Location: ' . ($category ? 'dashboard.php?reason=access_denied' : 'forum_index.php'));
    exit;
}

// --- Pagination Logic ---
$page = isset($_GET['page']) && filter_var($_GET['page'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ? (int)$_GET['page'] : 1;
$totalTopics = getTopicCountByCategory($pdo, $category_id);
$totalPages = ceil($totalTopics / $items_per_page);
$offset = ($page - 1) * $items_per_page;

// Ensure page number is valid
if ($page > $totalPages && $totalTopics > 0) {
    header('Location: view_category.php?id=' . $category_id . '&page=' . $totalPages);
    exit;
}
if ($page < 1) {
     header('Location: view_category.php?id=' . $category_id . '&page=1');
    exit;
}


// --- Fetch Topics ---
$topics = getTopicsByCategory($pdo, $category_id, $items_per_page, $offset);

$pageTitle = "View Category: " . htmlspecialchars($category['name']);
include 'includes/header.php';
?>

<div class="container mt-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="forum_index.php">Forum</a></li>
            <li class="breadcrumb-item active" aria-current="page"><?php echo htmlspecialchars($category['name']); ?></li>
        </ol>
    </nav>

    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2><?php echo htmlspecialchars($category['name']); ?></h2>
        <?php if (checkForumPermissions($category['post_role'], $userRole)): ?>
            <a href="create_topic.php?category_id=<?php echo $category_id; ?>" class="btn btn-success">
                <i class="fas fa-plus"></i> Create New Topic
            </a>
        <?php endif; ?>
    </div>
    <p><?php echo htmlspecialchars($category['description']); ?></p>
    <hr>

    <?php if (empty($topics)): ?>
        <div class="alert alert-info" role="alert">
            There are no topics in this category yet.
            <?php if (checkForumPermissions($category['post_role'], $userRole)): ?>
                 Why not <a href="create_topic.php?category_id=<?php echo $category_id; ?>">create the first one</a>?
            <?php endif; ?>
        </div>
    <?php else: ?>
        <table class="table table-striped table-hover">
            <thead class="thead-light">
                <tr>
                    <th>Topic</th>
                    <th>Author</th>
                    <th>Last Post</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($topics as $topic): ?>
                    <tr>
                        <td>
                            <a href="view_topic.php?id=<?php echo htmlspecialchars($topic['id']); ?>">
                                <?php echo htmlspecialchars($topic['title']); ?>
                            </a>
                        </td>
                        <td><?php echo htmlspecialchars($topic['author_username'] ?? 'N/A'); ?></td>
                        <td>
                            <?php if ($topic['last_post_user_id']): ?>
                                By <?php echo htmlspecialchars($topic['last_post_username'] ?? 'N/A'); ?><br>
                                <small><?php echo formatTimestamp($topic['last_post_at']); ?></small>
                            <?php else: ?>
                                N/A
                            <?php endif; ?>
                        </td>
                         <td>
                            <?php if ($topic['is_sticky']): ?>
                                <span class="badge bg-info text-dark">Sticky</span>
                            <?php endif; ?>
                            <?php if ($topic['is_locked']): ?>
                                <span class="badge bg-warning text-dark">Locked</span>
                            <?php endif; ?>
                             <?php if (!$topic['is_sticky'] && !$topic['is_locked']): ?>
                                <span class="badge bg-light text-dark">Open</span>
                             <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
            <nav aria-label="Topic pagination">
                <ul class="pagination justify-content-center">
                    <?php if ($page > 1): ?>
                        <li class="page-item"><a class="page-link" href="?id=<?php echo $category_id; ?>&page=<?php echo $page - 1; ?>">Previous</a></li>
                    <?php else: ?>
                        <li class="page-item disabled"><span class="page-link">Previous</span></li>
                    <?php endif; ?>

                    <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                        <li class="page-item <?php echo ($i == $page) ? 'active' : ''; ?>">
                            <a class="page-link" href="?id=<?php echo $category_id; ?>&page=<?php echo $i; ?>"><?php echo $i; ?></a>
                        </li>
                    <?php endfor; ?>

                    <?php if ($page < $totalPages): ?>
                        <li class="page-item"><a class="page-link" href="?id=<?php echo $category_id; ?>&page=<?php echo $page + 1; ?>">Next</a></li>
                    <?php else: ?>
                        <li class="page-item disabled"><span class="page-link">Next</span></li>
                    <?php endif; ?>
                </ul>
            </nav>
        <?php endif; ?>

    <?php endif; ?>

</div>

<?php include 'includes/footer.php'; ?>