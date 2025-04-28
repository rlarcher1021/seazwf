<?php
// Set header to return JSON
header('Content-Type: application/json');

// Start session and include necessary files
require_once __DIR__ . '/../includes/auth.php'; // Handles session start and authentication
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../data_access/vendor_data.php'; // Vendor DAL
require_once __DIR__ . '/../includes/utils.php'; // For CSRF check etc.

// --- Initial Setup & Security ---

// 1. Check Authentication & Permissions (Director/Admin only)
if (!check_permission(['director', 'administrator'])) {
    echo json_encode(['success' => false, 'message' => 'Permission denied.']);
    exit;
}
$currentUserId = $_SESSION['user_id']; // Needed? Maybe for logging later.

// 2. Determine Action and Method (Only POST expected for CRUD)
$action = null;
$requestData = [];
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'POST' && isset($_POST['action'])) {
    // 3. CSRF Check for POST requests
    if (!isset($_POST['csrf_token']) || empty($_POST['csrf_token']) || !verifyCsrfToken($_POST['csrf_token'])) {
        echo json_encode(['success' => false, 'message' => 'Invalid request (CSRF token mismatch). Please refresh the page and try again.']);
        exit;
    }
    $action = $_POST['action'];
    $requestData = $_POST;
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request method or action not specified.']);
    exit;
}

// --- Action Handling ---

$response = ['success' => false, 'message' => 'An unknown error occurred.'];

try {
    switch ($action) {
        case 'add_vendor':
            $name = trim($requestData['vendor_name'] ?? '');
            $client_name_required = isset($requestData['client_name_required']) && $requestData['client_name_required'] == '1'; // Checkbox value

            if (empty($name)) {
                throw new Exception('Vendor Name is required.');
            }

            $newVendorId = addVendor($pdo, $name, $client_name_required);

            if ($newVendorId) {
                // Optionally fetch the newly added vendor data to return
                 $newVendorData = getVendorById($pdo, $newVendorId); // Fetch details if needed by frontend
                $response = ['success' => true, 'message' => 'Vendor added successfully.', 'vendor' => $newVendorData];
            } else {
                 // Check for duplicate error (addVendor might need modification to return specific error codes)
                 // For now, use a generic message, DAL logs details.
                 throw new Exception('Failed to add vendor. The name might already exist.');
            }
            break;

        case 'update_vendor':
            $vendor_id = filter_var($requestData['vendor_id'] ?? null, FILTER_VALIDATE_INT);
            $name = trim($requestData['vendor_name'] ?? '');
            $client_name_required = isset($requestData['client_name_required']) && $requestData['client_name_required'] == '1';
            $is_active = isset($requestData['is_active']) && $requestData['is_active'] == '1';

            if (!$vendor_id) {
                throw new Exception('Invalid Vendor ID.');
            }
            if (empty($name)) {
                throw new Exception('Vendor Name is required.');
            }

            $success = updateVendor($pdo, $vendor_id, $name, $client_name_required, $is_active);

            if ($success) {
                 $updatedVendorData = getVendorById($pdo, $vendor_id); // Fetch updated details
                $response = ['success' => true, 'message' => 'Vendor updated successfully.', 'vendor' => $updatedVendorData];
            } else {
                 // Check for duplicate error etc.
                 throw new Exception('Failed to update vendor. The name might already exist or no changes were made.');
            }
            break;

        case 'delete_vendor': // Soft delete
            $vendor_id = filter_var($requestData['vendor_id'] ?? null, FILTER_VALIDATE_INT);
            if (!$vendor_id) {
                throw new Exception('Invalid Vendor ID.');
            }

            $success = softDeleteVendor($pdo, $vendor_id);

            if ($success) {
                $response = ['success' => true, 'message' => 'Vendor deleted successfully.'];
            } else {
                throw new Exception('Failed to delete vendor.');
            }
            break;

         case 'restore_vendor':
            $vendor_id = filter_var($requestData['vendor_id'] ?? null, FILTER_VALIDATE_INT);
            if (!$vendor_id) {
                throw new Exception('Invalid Vendor ID.');
            }

            $success = restoreVendor($pdo, $vendor_id);

            if ($success) {
                 $restoredVendorData = getVendorById($pdo, $vendor_id); // Fetch details
                $response = ['success' => true, 'message' => 'Vendor restored successfully.', 'vendor' => $restoredVendorData];
            } else {
                throw new Exception('Failed to restore vendor.');
            }
            break;

        // Optional: Add a 'get_vendor' action if needed for populating edit modal via AJAX later
        // case 'get_vendor':
        //     $vendor_id = filter_var($requestData['vendor_id'] ?? null, FILTER_VALIDATE_INT);
        //     if (!$vendor_id) { throw new Exception('Invalid Vendor ID.'); }
        //     $vendorData = getVendorById($pdo, $vendor_id); // Assumes getVendorById checks permissions
        //     if ($vendorData) {
        //         $response = ['success' => true, 'vendor' => $vendorData];
        //     } else {
        //         throw new Exception('Vendor not found or permission denied.');
        //     }
        //     break;

        default:
            throw new Exception('Invalid vendor action specified.');
    }

} catch (PDOException $e) {
    error_log("PDOException in ajax_vendor_handler.php (Action: {$action}): " . $e->getMessage());
    // Check for specific DB errors like duplicate entry
    if ($e->getCode() == '23000') { // Integrity constraint violation
        $response = ['success' => false, 'message' => 'Error: A vendor with this name might already exist.'];
    } else {
        $response = ['success' => false, 'message' => 'A database error occurred. Please check logs.'];
    }
} catch (Exception $e) {
    error_log("Error in ajax_vendor_handler.php (Action: {$action}): " . $e->getMessage());
    $response = ['success' => false, 'message' => $e->getMessage()]; // Send specific error back to client
}

// --- Output JSON Response ---
echo json_encode($response);
exit;
?>