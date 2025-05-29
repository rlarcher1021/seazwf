<?php
/*
 * File: alerts.php
 * Path: /alerts.php
 * Description: Administrator page to view and clear the PHP error log.
 */

// --- Initialization and Includes ---
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once 'includes/db_connect.php'; // Needed for header/footer which might use $pdo indirectly
require_once 'includes/auth.php';       // Ensures user is logged in

// --- Role Check ---
if (!isset($_SESSION['active_role']) || $_SESSION['active_role'] !== 'administrator') {
    $_SESSION['flash_message'] = "Access denied. Administrator privileges required.";
    $_SESSION['flash_type'] = 'error';
    header('Location: dashboard.php');
    exit;
}

// --- Configuration & Variables ---
$pageTitle = "System Alerts / Error Log";
$log_content = '';
$log_error = '';
// --- Determine Log File Path ---
$log_file_path = '';
$log_filename = 'error_log'; // The name of your log file

// 1. Check in the SAME directory as alerts.php (likely /public_html/)
$current_dir_path = __DIR__ . "/" . $log_filename;
// error_log("Alerts Debug: Checking current directory path: " . $current_dir_path);
if (is_file($current_dir_path) && is_readable($current_dir_path)) {
    $log_file_path = $current_dir_path;
    // error_log("Alerts Debug: Found log file in current directory.");
}

// 2. Check in the PARENT directory of alerts.php (if it wasn't in the current one)
//    This covers cases where alerts.php might be in an 'admin' subdir, but log is in public_html
if (empty($log_file_path)) {
    $parent_dir_path = dirname(__DIR__) . '/' . $log_filename;
    error_log("Alerts Debug: Checking parent directory path: " . $parent_dir_path);
    if (is_file($parent_dir_path) && is_readable($parent_dir_path)) {
        $log_file_path = $parent_dir_path;
        error_log("Alerts Debug: Found log file in parent directory.");
    }
}


// 3. Check ini_get as a fallback (less reliable for specific filename)
if (empty($log_file_path)) {
    $ini_log_path = ini_get('error_log');
    error_log("Alerts Debug: Checking ini_get('error_log'): " . $ini_log_path);
    if (!empty($ini_log_path) && $ini_log_path !== 'syslog' && $ini_log_path !== 'error_log') {
        if (is_file($ini_log_path) && is_readable($ini_log_path)) {
            $log_file_path = $ini_log_path;
            error_log("Alerts Debug: Found log file via ini_get absolute path.");
        } else {
             // Check if ini_get path is relative to project root (less likely but possible)
             $relative_ini_path = dirname(__DIR__) . '/' . $ini_log_path;
             if (is_file($relative_ini_path) && is_readable($relative_ini_path)) {
                 $log_file_path = $relative_ini_path;
                 error_log("Alerts Debug: Found log file via ini_get relative path check.");
             }
        }
    }
}


// Final Check and Error Logging if Still Not Found
if (empty($log_file_path)) {
    error_log("Alerts ERROR: Log file '{$log_filename}' not found in common locations (__DIR__ or parent) or via ini_get.");
    // $log_error variable will be set later in the script based on empty $log_file_path
} else {
     // error_log("Alerts INFO: Determined log file path: " . $log_file_path);
}


// --- Handle POST Request to Clear Log ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'clear_log') {
    // Re-verify role just in case
    if ($_SESSION['active_role'] !== 'administrator') {
        $_SESSION['flash_message'] = "Permission denied for action.";
        $_SESSION['flash_type'] = 'error';
    } elseif (empty($log_file_path)) {
        $_SESSION['flash_message'] = "Error: Log file path could not be determined.";
        $_SESSION['flash_type'] = 'error';
    } elseif (!is_file($log_file_path)) {
         $_SESSION['flash_message'] = "Error: Log file does not exist at determined path: " . htmlspecialchars($log_file_path);
         $_SESSION['flash_type'] = 'error';
    } elseif (!is_writable($log_file_path)) {
        $_SESSION['flash_message'] = "Error: Log file is not writable by the web server. Check file permissions for: " . htmlspecialchars($log_file_path);
        $_SESSION['flash_type'] = 'error';
        error_log("Attempt to clear log failed: PHP does not have write permission on " . $log_file_path);
    } else {
        // Attempt to clear the file
        if (file_put_contents($log_file_path, '') !== false) {
            $_SESSION['flash_message'] = "Error log file cleared successfully.";
            $_SESSION['flash_type'] = 'success';
            error_log("Admin action: Error log cleared by user ID: " . ($_SESSION['user_id'] ?? 'N/A')); // Log the action
        } else {
            $_SESSION['flash_message'] = "Error: Failed to clear the log file. Check server logs.";
            $_SESSION['flash_type'] = 'error';
            error_log("Attempt to clear log failed: file_put_contents returned false for " . $log_file_path);
        }
    }
    // Redirect to prevent form re-submission
    header('Location: alerts.php');
    exit;
}

// --- Read Log File Content (if path found) ---
if (!empty($log_file_path)) {
    if (is_readable($log_file_path)) {
        // Read file into an array of lines
        $lines = file($log_file_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines !== false) {
            if (empty($lines)) {
                $log_content = '<p><em>(Log file is empty)</em></p>';
            } else {
                // Reverse the array to show newest first
                $reversed_lines = array_reverse($lines);
                // Use <pre> for preformatted text, escape content
                $log_content = '<pre>';
                foreach($reversed_lines as $line) {
                    // Basic filtering/highlighting (optional)
                    $line_escaped = htmlspecialchars($line, ENT_QUOTES, 'UTF-8');
                     if (stripos($line_escaped, 'fatal error') !== false) {
                         $log_content .= '<strong class="text-danger">' . $line_escaped . "</strong>\n";
                     } elseif (stripos($line_escaped, 'warning') !== false) {
                         $log_content .= '<em class="text-warning">' . $line_escaped . "</em>\n";
                     } else {
                        $log_content .= $line_escaped . "\n";
                     }
                }
                $log_content .= '</pre>';
            }
        } else {
            $log_error = "Error: Could not read log file content from: " . htmlspecialchars($log_file_path);
            error_log("Alerts page error: file() returned false for " . $log_file_path);
        }
    } else {
        $log_error = "Error: Log file found but is not readable by the web server. Check permissions for: " . htmlspecialchars($log_file_path);
        error_log("Alerts page error: PHP does not have read permission on " . $log_file_path);
    }
} else {
    $log_error = "Error: Could not automatically determine the PHP error log file path. Please check PHP configuration (error_log directive) or server setup.";
    error_log("Alerts page error: Log file path determination failed.");
}


// --- Flash Message Handling ---
$flash_message = $_SESSION['flash_message'] ?? null;
$flash_type = $_SESSION['flash_type'] ?? 'info';
unset($_SESSION['flash_message'], $_SESSION['flash_type']);

// --- Include Header ---
require_once 'includes/header.php';
?>

<div class="content-section"> <!-- Use a general content container class -->
    <!--<h1 class="page-title"><?php echo $pageTitle; ?></h1>-->

    <!-- Display Flash Messages -->
    <?php if ($flash_message): ?>
        <div class="message-area message-<?php echo htmlspecialchars($flash_type); ?>">
            <?php echo htmlspecialchars($flash_message, ENT_QUOTES, 'UTF-8'); ?>
        </div>
    <?php endif; ?>

    <div class="settings-section">
        <h2 class="settings-section-title">Log File Actions</h2>
        <p>Detected Log File Path:
           <code><?php echo !empty($log_file_path) ? htmlspecialchars($log_file_path) : '<em>Not Found</em>'; ?></code>
        </p>
        <?php if (!empty($log_file_path) && is_file($log_file_path)): ?>
            <form method="POST" action="alerts.php" onsubmit="return confirm('Are you SURE you want to permanently clear the error log? This action cannot be undone.');" class="d-inline-block mt-2">
                <input type="hidden" name="action" value="clear_log">
                <button type="submit" class="btn btn-outline delete-button">
                    <i class="fas fa-eraser"></i> Clear Error Log
                </button>
            </form>
             <?php if (!is_writable($log_file_path)): ?>
                 <p class="text-danger mt-2"><i class="fas fa-exclamation-triangle"></i> Warning: Clear button disabled. Log file is not writable by the web server.</p>
             <?php endif; ?>
        <?php else: ?>
            <p class="text-danger mt-2">Clear button disabled because the log file path could not be determined or the file does not exist.</p>
        <?php endif; ?>
    </div>


    <div class="settings-section">
         <h2 class="settings-section-title">Error Log Content (Newest First)</h2>
         <?php if ($log_error): ?>
            <div class="message-area message-error">
                <?php echo htmlspecialchars($log_error); ?>
            </div>
         <?php elseif (empty($log_content)): ?>
             <p><em>Could not read log file content.</em></p>
         <?php else: ?>
             <div class="error-log-display bg-light border p-2 small lh-sm alerts-log-container">
                 <?php echo $log_content; // Content includes <pre> tags and is htmlspecialchars'd ?>
             </div>
         <?php endif; ?>
    </div>

</div> <!-- /.content-section -->

<?php
// --- Include Footer ---
require_once 'includes/footer.php';
?>