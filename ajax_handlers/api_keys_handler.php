<?php
/**
 * AJAX Handler for API Key Management (Create/Revoke).
 * Handles requests from the API Keys configuration panel.
 * Requires administrator role and CSRF token validation for state changes.
 */

// Start session if not already started
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Set content type to JSON
header('Content-Type: application/json');

// Include necessary files
require_once '../includes/db_connect.php'; // Assuming path for DB connection
require_once '../includes/auth.php';       // For role checks
require_once '../includes/data_access/api_key_data.php'; // DAL for API keys

// --- Security Check: Role ---
// Ensure the user is logged in and is an administrator
if (!isset($_SESSION['user_id']) || !isset($_SESSION['active_role']) || $_SESSION['active_role'] !== 'administrator') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized: Administrator role required.']);
    exit();
}

// --- Determine Action ---
$action = $_POST['action'] ?? null;

// --- CSRF Token Validation Function ---
// Centralized function to avoid repetition
function validateCsrfToken() {
    $csrf_valid = isset($_POST['csrf_token']) && isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $_POST['csrf_token']);
    // error_log("API Key Handler - CSRF Check Result: " . ($csrf_valid ? 'Valid' : 'Invalid')); // DEBUG Removed
    if (!$csrf_valid) {
        echo json_encode(['success' => false, 'message' => 'Invalid CSRF token. Please refresh the page and try again.']);
        exit();
    }
    return true; // Indicate success
}

// --- Process Actions ---
switch ($action) {
    case 'create_key':
        // error_log("API Key Handler - create_key action started. Raw POST data: " . print_r($_POST, true)); // DEBUG Removed

        if (!validateCsrfToken()) { // Call validation function
             // Exit happens inside validateCsrfToken if invalid
             break; // Should not be reached if invalid, but good practice
        }
        // error_log("API Key Handler - CSRF token validated successfully."); // DEBUG Removed

        // --- Input Validation & Sanitization ---
        $key_name = isset($_POST['key_name']) ? trim(filter_var($_POST['key_name'], FILTER_SANITIZE_STRING)) : null;
        $permissions = $_POST['permissions'] ?? null; // Expecting an array
        $associated_user_id = isset($_POST['associated_user_id']) && $_POST['associated_user_id'] !== '' ? filter_var($_POST['associated_user_id'], FILTER_VALIDATE_INT) : null;
        $associated_site_id = isset($_POST['associated_site_id']) && $_POST['associated_site_id'] !== '' ? filter_var($_POST['associated_site_id'], FILTER_VALIDATE_INT) : null;

        // Basic validation
        if (empty($key_name)) {
            // error_log("API Key Handler - Validation failed: Key name is empty."); // DEBUG Removed
            echo json_encode(['success' => false, 'message' => 'Key name is required.']);
            exit();
        }
        // Updated check: Ensure permissions is an array, even if empty is allowed by DAL
        if (!is_array($permissions)) {
             // error_log("API Key Handler - Validation failed: Permissions is not an array. Value: " . var_export($permissions, true)); // DEBUG Removed
             echo json_encode(['success' => false, 'message' => 'Permissions must be provided as an array.']);
             exit();
        }
        // Ensure IDs are valid integers or null
        if ($associated_user_id === false && $associated_user_id !== null) { // false means validation failed, null is allowed
            // error_log("API Key Handler - Validation failed: Invalid Associated User ID."); // DEBUG Removed
            echo json_encode(['success' => false, 'message' => 'Invalid Associated User ID.']);
            exit();
        }
         if ($associated_site_id === false && $associated_site_id !== null) { // false means validation failed, null is allowed
            // error_log("API Key Handler - Validation failed: Invalid Associated Site ID."); // DEBUG Removed
            echo json_encode(['success' => false, 'message' => 'Invalid Associated Site ID.']);
            exit();
        }

        // --- Call DAL ---
        try {
            // Convert permissions array to JSON string for storage if needed by DAL
            // $permissionsJson = json_encode($permissions); // Uncomment if DAL expects JSON string
            // $unhashedKey = ApiKeyData::createApiKey($key_name, $permissionsJson, $associated_user_id, $associated_site_id);

            // Assuming DAL handles the array directly or converts internally
            // Pass the $pdo connection object as the first argument
            // error_log("API Key Handler - Attempting to call ApiKeyData::createApiKey..."); // DEBUG Removed
            $unhashedKey = ApiKeyData::createApiKey($pdo, $key_name, $permissions, $associated_user_id, $associated_site_id);
            // error_log("API Key Handler - ApiKeyData::createApiKey returned: " . var_export($unhashedKey, true)); // DEBUG Removed

            if ($unhashedKey) {
                $newKeyId = $pdo->lastInsertId();
                // error_log("API Key Handler - Key creation successful in DAL. New Key ID: " . $newKeyId); // DEBUG Removed

                // Fetch the newly created key's details to return to the frontend
                $newKeyData = null;
                if ($newKeyId) {
                    try {
                        $newKeyData = ApiKeyData::getApiKeyById($pdo, (int)$newKeyId);
                        if (!$newKeyData) {
                             // error_log("API Key Handler - Warning: Could not fetch details for newly created key ID: " . $newKeyId); // DEBUG Removed
                        }
                    } catch (Exception $fetchEx) {
                         // error_log("API Key Handler - Exception fetching new key details: " . $fetchEx->getMessage()); // DEBUG Removed
                    }
                }

                echo json_encode([
                    'success' => true,
                    'message' => 'API Key created successfully. Store this key securely, it will not be shown again.',
                    'apiKey' => $unhashedKey, // Send the unhashed key back ONCE
                    'newKeyData' => $newKeyData // Send back the details for dynamic row add
                ]);
            } else {
                 // error_log("API Key Handler - ApiKeyData::createApiKey returned a falsy value (likely null or false)."); // DEBUG Removed
                echo json_encode(['success' => false, 'message' => 'Failed to create API key in DAL.']);
            }
        } catch (Exception $e) {
            // Log the exception details internally if possible - Keep this one for production errors
            error_log("API Key Handler - Exception caught during ApiKeyData::createApiKey call: " . $e->getMessage());
            // error_log("Stack Trace: " . $e->getTraceAsString()); // DEBUG Removed - Maybe keep for production?
            echo json_encode(['success' => false, 'message' => 'An error occurred while creating the API key. Please check server logs.']);
        }
        break;

    case 'revoke_key':
        // error_log("API Key Handler - revoke_key action started. Raw POST data: " . print_r($_POST, true)); // DEBUG Removed

        if (!validateCsrfToken()) { // Call validation function
             break;
        }
         // error_log("API Key Handler - CSRF token validated successfully for revoke_key."); // DEBUG Removed

        // --- Input Validation ---
        $key_id = isset($_POST['key_id']) ? filter_var($_POST['key_id'], FILTER_VALIDATE_INT) : null;
        // error_log("API Key Handler - Revoke Key ID received: " . var_export($key_id, true)); // DEBUG Removed

        if (!$key_id || $key_id <= 0) {
            // error_log("API Key Handler - Validation failed: Invalid Key ID for revocation."); // DEBUG Removed
            echo json_encode(['success' => false, 'message' => 'Invalid Key ID provided for revocation.']);
            exit();
        }

        // --- Call DAL ---
        try {
            // Pass the $pdo connection object as the first argument
            // error_log("API Key Handler - Attempting to call ApiKeyData::revokeApiKey for ID: " . $key_id); // DEBUG Removed
            $success = ApiKeyData::revokeApiKey($pdo, $key_id);
            // error_log("API Key Handler - ApiKeyData::revokeApiKey returned: " . var_export($success, true)); // DEBUG Removed

            if ($success) {
                // error_log("API Key Handler - Key revocation successful in DAL."); // DEBUG Removed
                echo json_encode(['success' => true, 'message' => 'API Key revoked successfully.']);
            } else {
                // error_log("API Key Handler - ApiKeyData::revokeApiKey returned false."); // DEBUG Removed
                echo json_encode(['success' => false, 'message' => 'Failed to revoke API key. It might already be inactive or does not exist.']);
            }
        } catch (Exception $e) {
            // Log the exception details internally if possible - Keep this one for production errors
            error_log("API Key Handler - Exception caught during ApiKeyData::revokeApiKey call: " . $e->getMessage());
             // error_log("Stack Trace: " . $e->getTraceAsString()); // DEBUG Removed - Maybe keep for production?
            echo json_encode(['success' => false, 'message' => 'An error occurred while revoking the API key. Please check server logs.']);
        }
        break;

    default:
        // --- Invalid Action ---
         // error_log("API Key Handler - Invalid action specified: " . var_export($action, true)); // DEBUG Removed
        echo json_encode(['success' => false, 'message' => 'Invalid action specified.']);
        break;
}

exit(); // Ensure script termination
?>