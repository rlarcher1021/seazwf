<?php
// File: get_recent_checkins_handler.php
// Path: /ajax_handlers/get_recent_checkins_handler.php
// Description: Handles AJAX requests to fetch recent check-ins for the dashboard.

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once '../includes/db_connect.php';
require_once '../includes/auth.php'; // Ensures user is logged in
require_once '../includes/utils.php';
require_once '../includes/data_access/site_data.php';
require_once '../includes/data_access/question_data.php';
require_once '../includes/data_access/checkin_data.php';

// --- Site Filter Logic (adapted from dashboard.php) ---
$site_filter_id = null;
$user_can_select_sites = (isset($_SESSION['active_role']) && ($_SESSION['active_role'] === 'administrator' || $_SESSION['active_role'] === 'director'));
$requested_site_id = $_GET['site_id'] ?? null;

if ($requested_site_id !== null) {
    if ($requested_site_id === 'all') {
        if ($user_can_select_sites) {
            $site_filter_id = 'all';
        } else {
            // Non-admin/director cannot request 'all', default to their site or error
            if (isset($_SESSION['active_site_id'])) {
                $site_filter_id = $_SESSION['active_site_id'];
            } else {
                echo '<tr><td colspan="4" class="text-center">Error: Site context unavailable for "all" sites request.</td></tr>';
                exit;
            }
        }
    } elseif (is_numeric($requested_site_id)) {
        $potential_site_id = intval($requested_site_id);
        if ($user_can_select_sites) {
            $site_filter_id = $potential_site_id; // Admin/Director can view any specific site
        } elseif (isset($_SESSION['active_site_id']) && $_SESSION['active_site_id'] == $potential_site_id) {
            $site_filter_id = $potential_site_id; // User's own site
        } else {
            // User requested a specific site they are not authorized for, or no active_site_id
            if (isset($_SESSION['active_site_id'])) {
                $site_filter_id = $_SESSION['active_site_id']; // Default to their own site
            } else {
                echo '<tr><td colspan="4" class="text-center">Error: Invalid site selection or no site assigned.</td></tr>';
                exit;
            }
        }
    } else {
        // Invalid format for requested_site_id, default based on role
        if ($user_can_select_sites) {
            $site_filter_id = 'all';
        } elseif (isset($_SESSION['active_site_id'])) {
            $site_filter_id = $_SESSION['active_site_id'];
        } else {
            echo '<tr><td colspan="4" class="text-center">Error: Site context not determinable from request.</td></tr>';
            exit;
        }
    }
} else {
    // No site_id provided in GET, default based on role (same as dashboard initial load)
    if ($user_can_select_sites) {
        $site_filter_id = 'all';
    } elseif (isset($_SESSION['active_site_id'])) {
        $site_filter_id = $_SESSION['active_site_id'];
    } else {
        echo '<tr><td colspan="4" class="text-center">Error: No site context available.</td></tr>';
        exit;
    }
}

// --- Fetch active question columns (adapted from dashboard.php) ---
$active_question_columns = [];
if ($site_filter_id !== null) { // Only fetch if site_filter_id is determined
    $question_base_titles = getActiveQuestionTitles($pdo, $site_filter_id);
    if ($question_base_titles !== []) {
        foreach ($question_base_titles as $base_title) {
            if (!empty($base_title)) {
                $sanitized_base = sanitize_title_to_base_name($base_title);
                if (!empty($sanitized_base)) {
                    $prefixed_col_name = 'q_' . $sanitized_base;
                    if (preg_match('/^q_[a-z0-9_]+$/', $prefixed_col_name) && strlen($prefixed_col_name) <= 64) {
                        $active_question_columns[] = $prefixed_col_name;
                    }
                }
            }
        }
    }
}

// --- Fetch Recent Check-ins List ---
$recent_checkins_list = [];
if ($site_filter_id !== null) {
    $recent_checkins_list = getRecentCheckins($pdo, $site_filter_id, $active_question_columns, 5); // Fetch 5, as in dashboard
}

// --- Generate HTML for table rows ---
if (!empty($recent_checkins_list)) {
    foreach ($recent_checkins_list as $checkin) {
        $details_parts = [];
        if (isset($checkin['dynamic_answers']) && is_array($checkin['dynamic_answers'])) {
            foreach ($checkin['dynamic_answers'] as $answer_item) {
                if (isset($answer_item['question_text']) && isset($answer_item['answer_text'])) {
                    if (!empty($answer_item['answer_text']) && strtoupper($answer_item['answer_text']) !== 'NO') {
                        $details_parts[] = htmlspecialchars($answer_item['question_text']) . ': ' . htmlspecialchars($answer_item['answer_text']);
                    }
                }
            }
        }
        $details_summary = !empty($details_parts) ? implode('<br>', $details_parts) : 'N/A';

        $row_class = '';
        if (isset($checkin['missing_data_status'])) {
            if ($checkin['missing_data_status'] === 'complete') {
                $row_class = 'table-success';
            } elseif ($checkin['missing_data_status'] === 'missing') {
                $row_class = 'table-danger';
            }
        }
        ?>
        <tr class="<?php echo $row_class; ?>">
            <td><?php echo htmlspecialchars($checkin['first_name'] . ' ' . $checkin['last_name']); ?></td>
            <?php if($site_filter_id === 'all'): ?>
                <td><?php echo htmlspecialchars($checkin['site_name']); ?></td>
            <?php endif; ?>
            <td><?php echo date('h:i A', strtotime($checkin['check_in_time'])); ?></td>
            <td class="small"><?php echo $details_summary; ?></td>
            <td><button type="button" class="btn btn-outline btn-sm view-checkin-details" data-toggle="modal" data-target="#checkinDetailsModal" data-checkin-id="<?php echo $checkin['id']; ?>"><i class="fas fa-eye"></i> View</button></td>
        </tr>
        <?php
    }
} else {
    $colspan = 4; // Name, Time, Summary, Actions
    if ($site_filter_id === 'all') {
        $colspan++; // Add Site column
    }
    echo '<tr><td colspan="' . $colspan . '" class="text-center">No recent check-ins found.</td></tr>';
}
?>