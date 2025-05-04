<?php
// Ensure session is started for CSRF token
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Include necessary data access layer
require_once __DIR__ . '/../data_access/api_key_data.php';
require_once __DIR__ . '/../data_access/user_data.php'; // Added for user dropdown
require_once __DIR__ . '/../data_access/site_data.php'; // Added for site dropdown

// Generate CSRF token if it doesn't exist
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// Fetch existing active API keys
$apiKeys = [];
$errorMessage = '';
try {
    // Pass the PDO connection object
    $apiKeys = ApiKeyData::getAllActiveApiKeys($pdo);
    if ($apiKeys === false) {
        $errorMessage = "Could not retrieve API keys from the database.";
        // Optionally log the error here
    }
} catch (Exception $e) {
    $errorMessage = "An error occurred while fetching API keys: " . $e->getMessage();
    // Optionally log the error here
}

// Fetch users and sites for dropdowns
$allUsers = [];
$allSites = [];
try {
    // Use the correct function from user_data.php
    $allUsers = getAllActiveUsersForDropdown($pdo);
    if ($allUsers === false) { // getAllActiveUsersForDropdown returns [] on error, but check false just in case
        $errorMessage .= " Could not retrieve users."; // Append to existing error message if needed
    }
    // Use the correct function from site_data.php
    $allSites = getAllSites($pdo);
    if ($allSites === false) { // getAllSites returns [] on error, but check false just in case
        $errorMessage .= " Could not retrieve sites."; // Append to existing error message if needed
    }
} catch (Exception $e) {
    $errorMessage .= " Error fetching users/sites: " . $e->getMessage();
    // Optionally log the error here
}


// Define allowed permissions
$allowedPermissions = [
    'read:checkin_data',
    'create:checkin_note',
    'read:budget_allocations',
    'create:forum_post',
    'read:all_forum_posts',
    'generate:reports',
    'read:all_checkin_data',
    'read:site_checkin_data',
    'read:all_allocation_data',
    'read:own_allocation_data',
    'read:client_data' // Added permission for client data read access
];

?>

<div class="container-fluid mt-4">
    <h4>Manage API Keys</h4>
    <hr>

    <?php if (!empty($errorMessage)): ?>
        <div class="alert alert-danger" role="alert">
            <?= htmlspecialchars($errorMessage) ?>
        </div>
    <?php endif; ?>

    <h5>Existing API Keys</h5>
    <div class="table-responsive">
        <table class="table table-striped table-bordered table-sm">
            <thead class="thead-light">
                <tr>
                    <th>Name</th>
                    <th>Created</th>
                    <th>Permissions</th>
                    <th>User ID</th>
                    <th>Site ID</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody id="api-keys-table-body">
                <?php if (!empty($apiKeys)): ?>
                    <?php foreach ($apiKeys as $key): ?>
                        <tr id="api-key-row-<?= htmlspecialchars($key['id']) ?>">
                            <td><?= htmlspecialchars($key['name']) ?></td> <!-- Reverted back to 'name' -->
                            <td><?= htmlspecialchars(date('Y-m-d H:i', strtotime($key['created_at']))) ?></td>
                            <td>
                                <?php
                                $permissions = json_decode($key['associated_permissions'] ?? '[]', true);
                                if (json_last_error() === JSON_ERROR_NONE && is_array($permissions)) {
                                    if (empty($permissions)) {
                                        echo '<span class="badge badge-secondary">None</span>';
                                    } else {
                                        foreach ($permissions as $permission) {
                                            echo '<span class="badge badge-info mr-1">' . htmlspecialchars($permission) . '</span>';
                                        }
                                    }
                                } else {
                                    echo '<span class="badge badge-warning">Invalid Format</span>';
                                }
                                ?>
                            </td>
                            <td><?= htmlspecialchars($key['associated_user_id'] ?? 'N/A') ?></td>
                            <td><?= htmlspecialchars($key['associated_site_id'] ?? 'N/A') ?></td>
                            <td>
                                <button class="btn btn-danger btn-sm revoke-api-key-btn" data-key-id="<?= htmlspecialchars($key['id']) ?>">
                                    Revoke
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="6" class="text-center">No active API keys found.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <hr>

    <h4>Create New API Key</h4>
    <form id="create-api-key-form" class="mt-3">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">

        <div class="form-group row">
            <label for="key_name" class="col-sm-3 col-form-label">Key Name/Label:</label>
            <div class="col-sm-9">
                <input type="text" class="form-control" id="key_name" name="key_name" required placeholder="e.g., Reporting Script Key">
            </div>
        </div>

        <div class="form-group row">
            <label class="col-sm-3 col-form-label">Permissions:</label>
            <div class="col-sm-9">
                <fieldset>
                    <legend class="sr-only">Permissions</legend> <!-- Accessibility -->
                    <?php foreach ($allowedPermissions as $permission): ?>
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="checkbox" name="permissions[]" id="perm_<?= htmlspecialchars($permission) ?>" value="<?= htmlspecialchars($permission) ?>">
                            <label class="form-check-label" for="perm_<?= htmlspecialchars($permission) ?>">
                                <?= htmlspecialchars($permission) ?>
                            </label>
                        </div>
                    <?php endforeach; ?>
                </fieldset>
                 <small class="form-text text-muted">Select the permissions this key will grant.</small>
            </div>
        </div>

        <div class="form-group row">
            <label for="associated_user_id" class="col-sm-3 col-form-label">Associated User ID (Optional):</label>
            <div class="col-sm-9">
                <select class="form-control" id="associated_user_id" name="associated_user_id">
                    <option value="">None</option>
                    <?php if (!empty($allUsers)): ?>
                        <?php foreach ($allUsers as $user): ?>
                            <option value="<?= htmlspecialchars($user['id']) ?>">
                                <?= htmlspecialchars($user['full_name']) ?> (<?= htmlspecialchars($user['username']) ?>)
                            </option>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <option value="" disabled>Could not load users</option>
                    <?php endif; ?>
                </select>
                 <small class="form-text text-muted">Link this key to a specific user account.</small>
            </div>
        </div>

        <div class="form-group row">
            <label for="associated_site_id" class="col-sm-3 col-form-label">Associated Site ID (Optional):</label>
            <div class="col-sm-9">
                <select class="form-control" id="associated_site_id" name="associated_site_id">
                    <option value="">None</option>
                     <?php if (!empty($allSites)): ?>
                        <?php foreach ($allSites as $site): ?>
                            <option value="<?= htmlspecialchars($site['id']) ?>">
                                <?= htmlspecialchars($site['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <option value="" disabled>Could not load sites</option>
                    <?php endif; ?>
                </select>
                 <small class="form-text text-muted">Restrict this key to a specific site.</small>
            </div>
        </div>

        <div class="form-group row">
            <div class="col-sm-9 offset-sm-3">
                <button type="submit" class="btn btn-primary">Create Key</button>
            </div>
        </div>
    </form>

    <div id="api-key-result-display" class="mt-3">
        <!-- Results (new key details or errors) will be displayed here via JavaScript -->
    </div>

</div>

<!-- Note: JavaScript for form submission and revoke button functionality will be added in a separate task. -->