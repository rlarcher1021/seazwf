<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();

require_once 'includes/db_connect.php'; // Database connection
require_once 'includes/auth.php';       // Authentication functions
require_once 'includes/utils.php';      // Utility functions (CSRF, flash messages)
require_once 'includes/data_access/client_data.php'; // Client data functions
require_once 'includes/data_access/site_data.php';   // Site data functions
require_once 'includes/data_access/question_data.php'; // Question data functions
require_once 'includes/data_access/audit_log_data.php'; // Audit log functions

// --- Initial Permission Check (Page Access) ---
$is_global_admin_or_director = isset($_SESSION['active_role']) && in_array($_SESSION['active_role'], ['administrator', 'director']);
$is_site_admin = isset($_SESSION['is_site_admin']) && $_SESSION['is_site_admin'] == 1;
$session_site_id = isset($_SESSION['active_site_id']) && $_SESSION['active_site_id'] !== '' ? (int)$_SESSION['active_site_id'] : null;
$session_user_id = $_SESSION['user_id'] ?? null; // Needed for audit logging

if (!$is_global_admin_or_director && !$is_site_admin) {
    set_flash_message("Access Denied: You do not have permission to access the Client Editor.", "danger");
    header('Location: dashboard.php');
    exit;
}
// --- End Initial Permission Check ---

// --- CSRF Token Generation ---
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];
// --- End CSRF Token Generation ---

// --- Variable Initialization ---
$search_term = ''; // Initialize search term, default to empty
$search_results = []; // Initialize search results as an empty array
$edit_client_id = null;
$client_data = null;
$sites = []; // For site dropdown
$global_questions = []; // For dynamic questions
$form_errors = [];
$show_edit_form = false;
$submitted_answers = null; // Store submitted answers on POST failure
// --- End Variable Initialization ---

// --- POST Request Handling (Save Client) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_client') {
    // 1. CSRF Check
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        set_flash_message("Invalid request. Please try again.", "danger");
        // Optionally redirect or just show error on the form page
        // header('Location: client_editor.php'); exit;
        $form_errors['csrf'] = "Invalid request token.";
    } else {
        // 2. Retrieve and Sanitize Input
        $submitted_client_id = filter_input(INPUT_POST, 'client_id', FILTER_VALIDATE_INT);
        $first_name = trim(filter_input(INPUT_POST, 'first_name', FILTER_SANITIZE_STRING));
        $last_name = trim(filter_input(INPUT_POST, 'last_name', FILTER_SANITIZE_STRING));
        $site_id = filter_input(INPUT_POST, 'site_id', FILTER_VALIDATE_INT);
        // Handle checkbox/radio for email preference (ensure 0 or 1)
        $email_preference_jobs = isset($_POST['email_preference_jobs']) ? 1 : 0;

        // Retrieve dynamic answers (expecting format q_QUESTIONID)
        $submitted_answers = [];
        foreach ($_POST as $key => $value) {
            if (strpos($key, 'q_') === 0) {
                $question_id = substr($key, 2);
                if (filter_var($question_id, FILTER_VALIDATE_INT)) {
                    // Allow 'Yes', 'No', or empty string (for 'No Answer')
                    $submitted_answers[(int)$question_id] = in_array($value, ['Yes', 'No', '']) ? $value : null; // Store null if invalid input type
                }
            }
        }

        // 3. Input Validation
        if (empty($first_name)) $form_errors['first_name'] = "First name is required.";
        if (empty($last_name)) $form_errors['last_name'] = "Last name is required.";
        if ($site_id === false || $site_id <= 0) $form_errors['site_id'] = "Please select a valid site.";
        // Add more validation as needed

        // 4. Permission Check (Crucial - based on ORIGINAL client data)
        $can_edit_this_client = false;
        $originalClientData = null;
        if ($submitted_client_id && empty($form_errors)) {
            $originalClientData = getClientDetailsForEditing($pdo, $submitted_client_id);
            if ($originalClientData && $originalClientData['profile']) {
                $original_client_site_id = $originalClientData['profile']['site_id'];
                if ($is_global_admin_or_director) {
                    $can_edit_this_client = true;
                } elseif ($is_site_admin && $original_client_site_id === $session_site_id) {
                    $can_edit_this_client = true;
                }
            } else {
                 $form_errors['general'] = "Client not found."; // Should not happen if coming from edit form
            }
        } else if (!$submitted_client_id) {
             $form_errors['general'] = "Client ID missing.";
        }

        if (!$can_edit_this_client && empty($form_errors['general'])) {
            $form_errors['general'] = "Permission Denied: You do not have permission to modify this client's profile.";
            set_flash_message($form_errors['general'], "danger"); // Set flash immediately for permission denial
        }

        // 5. Process Updates if Validation and Permissions Pass
        if (empty($form_errors)) {
            $pdo->beginTransaction(); // Start transaction
            $update_success = true;
            $audit_log_success = true;

            // Prepare old values for audit log comparison
            $old_profile_values = $originalClientData['profile'];
            $old_answers = [];
            foreach ($originalClientData['answers'] as $ans) {
                $old_answers[$ans['question_id']] = $ans['answer'];
            }

            // --- Update Profile Fields ---
            $profileDataToUpdate = [];
            if ($first_name !== $old_profile_values['first_name']) $profileDataToUpdate['first_name'] = $first_name;
            if ($last_name !== $old_profile_values['last_name']) $profileDataToUpdate['last_name'] = $last_name;
            if ($site_id !== $old_profile_values['site_id']) $profileDataToUpdate['site_id'] = $site_id;
            if ($email_preference_jobs !== (int)$old_profile_values['email_preference_jobs']) $profileDataToUpdate['email_preference_jobs'] = $email_preference_jobs;

            if (!empty($profileDataToUpdate)) {
                if (!updateClientProfileFields($pdo, $submitted_client_id, $profileDataToUpdate)) {
                    $update_success = false;
                    $form_errors['general'] = "Failed to update client profile.";
                    error_log("Error updating profile for client ID: " . $submitted_client_id);
                } else {
                    // Log profile changes
                    foreach ($profileDataToUpdate as $field => $newValue) {
                         $oldValue = $old_profile_values[$field] ?? null;
                         // Special handling for boolean/int display in log
                         if ($field === 'email_preference_jobs') {
                             $oldValueText = ((int)$oldValue == 1) ? 'Opted In' : 'Opted Out';
                             $newValueText = ((int)$newValue == 1) ? 'Opted In' : 'Opted Out';
                         } elseif ($field === 'site_id') {
                             // Fetch site names for better logging (optional but nice)
                             // For simplicity now, just log IDs
                             $oldValueText = (string)$oldValue;
                             $newValueText = (string)$newValue;
                         } else {
                             $oldValueText = (string)$oldValue;
                             $newValueText = (string)$newValue;
                         }


                         if (!logClientProfileChange($pdo, $submitted_client_id, $session_user_id, $field, $oldValueText, $newValueText)) {
                             $audit_log_success = false; // Log failure but continue
                             error_log("Audit log failed for client {$submitted_client_id}, field {$field}");
                         }
                    }
                }
            }
            // --- End Update Profile Fields ---

            // --- Update Answers ---
            if ($update_success) { // Only proceed if profile update was okay
                // Filter out null answers (invalid input) before saving, but keep empty strings ('')
                $valid_submitted_answers = array_filter($submitted_answers, fn($v) => $v !== null);

                if (!saveClientAnswers($pdo, $submitted_client_id, $valid_submitted_answers)) {
                     $update_success = false;
                     $form_errors['general'] = "Failed to update client answers.";
                     error_log("Error updating answers for client ID: " . $submitted_client_id);
                } else {
                    // Log answer changes
                    $all_question_ids = array_unique(array_merge(array_keys($old_answers), array_keys($valid_submitted_answers)));
                    foreach ($all_question_ids as $qid) {
                        $oldValue = $old_answers[$qid] ?? ''; // Default to empty string if not set previously
                        $newValue = $valid_submitted_answers[$qid] ?? ''; // Default to empty string if not submitted

                        // Treat null and empty string as the same ('No Answer') for comparison
                        $oldValueComparable = ($oldValue === null || $oldValue === '') ? '' : $oldValue;
                        $newValueComparable = ($newValue === null || $newValue === '') ? '' : $newValue;


                        if ($oldValueComparable !== $newValueComparable) {
                             // Use 'No Answer' in log for clarity if value is empty string
                             $oldLogValue = ($oldValueComparable === '') ? 'No Answer' : $oldValueComparable;
                             $newLogValue = ($newValueComparable === '') ? 'No Answer' : $newValueComparable;

                             if (!logClientProfileChange($pdo, $submitted_client_id, $session_user_id, "question_id_{$qid}", $oldLogValue, $newLogValue)) {
                                 $audit_log_success = false; // Log failure but continue
                                 error_log("Audit log failed for client {$submitted_client_id}, field question_id_{$qid}");
                             }
                        }
                    }
                }
            }
            // --- End Update Answers ---

            // 6. Commit or Rollback Transaction
            if ($update_success) {
                $pdo->commit();
                set_flash_message("Client profile updated successfully." . (!$audit_log_success ? " (Warning: Some audit log entries may have failed)" : ""), "success");
                // Redirect to prevent form resubmission, maybe back to the edit page
                // Redirect back to the main client editor page (search view) after successful save
                header('Location: client_editor.php');
                exit;
            } else {
                $pdo->rollBack();
                // Error message already set in $form_errors['general']
                set_flash_message("Failed to update client profile. Please check errors below.", "danger");
                // Need to repopulate form with submitted data and errors
                $edit_client_id = $submitted_client_id; // Keep the ID for form display
                $show_edit_form = true; // Ensure form is shown despite redirect failure
                // Repopulate $client_data with submitted values for form display
                // Keep original read-only fields
                $username_orig = $originalClientData['profile']['username'] ?? 'N/A';
                $email_orig = $originalClientData['profile']['email'] ?? 'N/A';
                $client_data = [
                    'profile' => [
                        'id' => $submitted_client_id,
                        'first_name' => $first_name,
                        'last_name' => $last_name,
                        'site_id' => $site_id,
                        'email_preference_jobs' => $email_preference_jobs,
                        'username' => $username_orig,
                        'email' => $email_orig,
                    ],
                    // Answers will be populated later using $submitted_answers
                    'answers' => []
                ];
            }
        } else {
             // Validation or Permission failed, need to show form with errors
             if (empty($form_errors['general'])) { // Don't overwrite specific permission error
                set_flash_message("Please correct the errors below.", "warning");
             }
             $edit_client_id = $submitted_client_id; // Keep the ID for form display
             $show_edit_form = true; // Ensure form is shown
             // Repopulate $client_data with submitted values
             // Fetch original read-only fields if possible
             $originalProfile = getClientDetailsForEditing($pdo, $submitted_client_id)['profile'] ?? null;
             $client_data = [
                 'profile' => [
                     'id' => $submitted_client_id,
                     'first_name' => $first_name,
                     'last_name' => $last_name,
                     'site_id' => $site_id,
                     'email_preference_jobs' => $email_preference_jobs,
                     'username' => $originalProfile['username'] ?? 'N/A',
                     'email' => $originalProfile['email'] ?? 'N/A',
                 ],
                 'answers' => [] // Will be populated later
             ];
        }
    } // End CSRF check else
}
// --- End POST Request Handling ---

// --- GET Request Handling (Display Edit Form or Search Results) ---
else if (isset($_GET['action']) && $_GET['action'] === 'edit' && isset($_GET['client_id'])) {
    $edit_client_id = filter_input(INPUT_GET, 'client_id', FILTER_VALIDATE_INT);
    if ($edit_client_id) {
        $client_data = getClientDetailsForEditing($pdo, $edit_client_id);

        if ($client_data && $client_data['profile']) {
            // Permission Check for viewing/displaying the edit form
            $can_view_this_client = false;
            $client_site_id = $client_data['profile']['site_id'];

            if ($is_global_admin_or_director) {
                $can_view_this_client = true;
            } elseif ($is_site_admin && $client_site_id === $session_site_id) {
                $can_view_this_client = true;
            }

            if ($can_view_this_client) {
                $show_edit_form = true;
                // Fetch necessary data for the form
                $sites = getAllSites($pdo); // Fetch all sites for dropdown
                $global_questions = getAllGlobalQuestions($pdo); // Fetch all questions

                // Prepare answers in a format easy for the form [question_id => answer]
                $current_answers = [];
                // If POST failed, $submitted_answers holds the values to display
                if ($submitted_answers !== null) {
                    foreach ($global_questions as $q) {
                         $current_answers[$q['id']] = $submitted_answers[$q['id']] ?? ''; // Use submitted or empty string
                    }
                } else { // Otherwise, use fetched answers from DB
                    foreach ($client_data['answers'] as $ans) {
                        $current_answers[$ans['question_id']] = $ans['answer'];
                    }
                     // Ensure all global questions have an entry (even if null/empty) for form display
                    foreach ($global_questions as $q) {
                        if (!isset($current_answers[$q['id']])) {
                             $current_answers[$q['id']] = ''; // Default to empty string if no answer saved
                        }
                    }
                }
                 // Ensure profile data reflects submitted data if POST failed
                 if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($form_errors)) {
                     // $client_data['profile'] should already be populated with submitted values above
                 }


            } else {
                set_flash_message("Permission Denied: You cannot edit this client's profile.", "danger");
                $client_data = null; // Don't show data
            }
        } else {
            set_flash_message("Client not found.", "warning");
        }
    } else {
        set_flash_message("Invalid Client ID specified.", "danger");
    }
}
// Handle Search GET request (Update search term if provided)
else if (isset($_GET['search_term'])) {
    $search_term = trim(filter_input(INPUT_GET, 'search_term', FILTER_SANITIZE_STRING));
    // The actual search happens below, after all request handling
}
// --- End GET Request Handling ---

// Always perform the search after handling POST/GET, using the current $search_term (which might be empty)
$search_results = searchClients($pdo, $search_term, $_SESSION['active_role'] ?? 'guest', $session_site_id, $is_site_admin);


// --- Page Display ---
$pageTitle = "Client Profile Editor";
require_once 'includes/header.php'; // Include the standard site header
?>

<div class="container mt-4 content-section">
    <h2><?php echo htmlspecialchars($pageTitle); ?></h2>
    <hr>

    <?php display_all_flash_messages(); // Display any flash messages ?>

    <p>This page allows authorized staff (Administrators, Directors, and Site Administrators) to search for and edit client profiles. Site Administrators can only search/edit clients associated with their assigned site.</p>

    <!-- Client Search Area -->
    <div class="mt-4 mb-4 card">
        <div class="card-header">
            <h4>Search for Client</h4>
        </div>
        <div class="card-body">
             <form action="client_editor.php" method="GET" class="form-inline">
                 <div class="form-group mr-sm-3 mb-2">
                    <label for="search_term" class="sr-only">Search Term</label>
                    <input type="text" class="form-control input-min-width-250 <?php echo isset($form_errors['search']) ? 'is-invalid' : ''; ?>" id="search_term" name="search_term" placeholder="Enter name or username" value="<?php echo htmlspecialchars($search_term); ?>">
                 </div>
                 <button type="submit" class="btn btn-primary mb-2">Search</button>
                 <?php if ($search_term): // Show clear button only if a search was made ?>
                     <a href="client_editor.php" class="btn btn-secondary mb-2 ml-2">Clear Search</a>
                 <?php endif; ?>
            </form>
             <small class="form-text text-muted">Search by first name, last name, or username.</small>
             <?php if ($is_site_admin && !$is_global_admin_or_director && isset($_SESSION['active_site_name'])): ?>
                <p class="mt-2 mb-0"><small><em>Site Administrator Scope: Searching within site '<?php echo htmlspecialchars($_SESSION['active_site_name']); ?>'.</em></small></p>
             <?php endif; ?>
        </div>
    </div>
    <!-- End Client Search Area -->

    <!-- Search Results Area -->
    <div class="mt-4 card">
        <div class="card-header">
            <h4>Search Results</h4>
        </div>
        <div class="card-body">
            <?php if (!empty($search_results)): ?>
                <div class="table-responsive">
                    <table class="table table-striped table-bordered table-hover">
                        <thead class="thead-light">
                            <tr>
                                <th>Name</th>
                                <th>Username</th>
                                <th>Site</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($search_results as $client): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($client['first_name'] . ' ' . $client['last_name']); ?></td>
                                    <td><?php echo htmlspecialchars($client['username']); ?></td>
                                    <td><?php echo htmlspecialchars($client['site_name'] ?? 'N/A'); ?></td>
                                    <td>
                                        <button type="button" class="btn btn-sm btn-info edit-client-btn" data-toggle="modal" data-target="#editClientModal" data-client-id="<?php echo htmlspecialchars($client['id']); ?>">
                                            <i class="fas fa-edit"></i> Edit
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="alert alert-warning" role="alert">
                    <?php if ($search_term): ?>
                        No clients found matching your criteria "<strong><?php echo htmlspecialchars($search_term); ?></strong>".
                    <?php else: ?>
                        No clients found within your scope. Use the search bar to find specific clients.
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <!-- End Search Results Area -->

    <hr>

    <!-- Client Edit Form Area -->
    <?php if ($show_edit_form && $client_data && $client_data['profile']): ?>
    <div class="mt-4 card">
         <div class="card-header">
            <h4>Edit Client Profile: <?php echo htmlspecialchars($client_data['profile']['first_name'] . ' ' . $client_data['profile']['last_name']); ?></h4>
        </div>
        <div class="card-body">
            <?php if (!empty($form_errors['general'])): ?>
                <div class="alert alert-danger"><?php echo htmlspecialchars($form_errors['general']); ?></div>
            <?php endif; ?>

            <form action="client_editor.php" method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <input type="hidden" name="action" value="save_client">
                <input type="hidden" name="client_id" value="<?php echo htmlspecialchars($client_data['profile']['id']); ?>">

                <h5>Client Details</h5>
                <div class="form-row">
                    <div class="form-group col-md-6">
                        <label for="first_name">First Name</label>
                        <input type="text" class="form-control <?php echo isset($form_errors['first_name']) ? 'is-invalid' : ''; ?>" id="first_name" name="first_name" value="<?php echo htmlspecialchars($client_data['profile']['first_name']); ?>" required>
                        <?php if (isset($form_errors['first_name'])): ?>
                            <div class="invalid-feedback"><?php echo htmlspecialchars($form_errors['first_name']); ?></div>
                        <?php endif; ?>
                    </div>
                    <div class="form-group col-md-6">
                        <label for="last_name">Last Name</label>
                        <input type="text" class="form-control <?php echo isset($form_errors['last_name']) ? 'is-invalid' : ''; ?>" id="last_name" name="last_name" value="<?php echo htmlspecialchars($client_data['profile']['last_name']); ?>" required>
                         <?php if (isset($form_errors['last_name'])): ?>
                            <div class="invalid-feedback"><?php echo htmlspecialchars($form_errors['last_name']); ?></div>
                        <?php endif; ?>
                    </div>
                </div>
                 <div class="form-row">
                    <div class="form-group col-md-6">
                        <label for="username">Username</label>
                        <input type="text" class="form-control" id="username" name="username" value="<?php echo htmlspecialchars($client_data['profile']['username']); ?>" readonly>
                        <small class="form-text text-muted">Username cannot be changed.</small>
                    </div>
                    <div class="form-group col-md-6">
                        <label for="email">Email</label>
                        <input type="email" class="form-control" id="email" name="email" value="<?php echo htmlspecialchars($client_data['profile']['email']); ?>" readonly>
                         <small class="form-text text-muted">Email cannot be changed.</small>
                    </div>
                </div>
                 <div class="form-row">
                    <div class="form-group col-md-6">
                        <label for="site_id">Primary Site</label>
                        <select class="form-control <?php echo isset($form_errors['site_id']) ? 'is-invalid' : ''; ?>" id="site_id" name="site_id" required>
                            <option value="">-- Select Site --</option>
                            <?php foreach ($sites as $site): ?>
                                <option value="<?php echo htmlspecialchars($site['id']); ?>" <?php echo ($client_data['profile']['site_id'] == $site['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($site['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                         <?php if (isset($form_errors['site_id'])): ?>
                            <div class="invalid-feedback"><?php echo htmlspecialchars($form_errors['site_id']); ?></div>
                        <?php endif; ?>
                    </div>
                     <div class="form-group col-md-6 d-flex align-items-center pt-3">
                         <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="email_preference_jobs" name="email_preference_jobs" value="1" <?php echo ($client_data['profile']['email_preference_jobs'] == 1) ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="email_preference_jobs">
                                Opt-in to receive job/event emails
                            </label>
                        </div>
                    </div>
                </div>

                <hr>
                <h5>Dynamic Questions</h5>
                 <?php if (!empty($global_questions)): ?>
                     <?php foreach ($global_questions as $question):
                         $question_id = $question['id'];
                         // Use $current_answers prepared earlier which considers failed POST attempts
                         $current_answer = $current_answers[$question_id] ?? '';
                         $input_name = "q_" . $question_id;
                     ?>
                         <div class="form-group">
                             <label><?php echo htmlspecialchars($question['question_title']); ?>: <?php echo htmlspecialchars($question['question_text']); ?></label>
                             <div>
                                 <div class="form-check form-check-inline">
                                     <input class="form-check-input" type="radio" name="<?php echo $input_name; ?>" id="<?php echo $input_name; ?>_yes" value="Yes" <?php echo ($current_answer === 'Yes') ? 'checked' : ''; ?>>
                                     <label class="form-check-label" for="<?php echo $input_name; ?>_yes">Yes</label>
                                 </div>
                                 <div class="form-check form-check-inline">
                                     <input class="form-check-input" type="radio" name="<?php echo $input_name; ?>" id="<?php echo $input_name; ?>_no" value="No" <?php echo ($current_answer === 'No') ? 'checked' : ''; ?>>
                                     <label class="form-check-label" for="<?php echo $input_name; ?>_no">No</label>
                                 </div>
                                 <div class="form-check form-check-inline">
                                     <input class="form-check-input" type="radio" name="<?php echo $input_name; ?>" id="<?php echo $input_name; ?>_na" value="" <?php echo ($current_answer === '') ? 'checked' : ''; ?>>
                                     <label class="form-check-label" for="<?php echo $input_name; ?>_na">No Answer</label>
                                 </div>
                             </div>
                             <?php if (isset($form_errors[$input_name])): ?>
                                <div class="text-danger small"><?php echo htmlspecialchars($form_errors[$input_name]); ?></div>
                             <?php endif; ?>
                         </div>
                     <?php endforeach; ?>
                 <?php else: ?>
                    <p>No global questions configured.</p>
                 <?php endif; ?>

                <hr>
                <button type="submit" class="btn btn-primary">Save Changes</button>
                <a href="client_editor.php" class="btn btn-secondary">Cancel</a>
            </form>
        </div>
    </div>
    <?php else: ?>
        <?php if (isset($_GET['action']) && $_GET['action'] === 'edit'): ?>
            <?php // If action=edit was requested but form isn't shown (due to error or permission) ?>
            <div class="alert alert-info mt-4">
                Client edit form is not displayed. This might be due to missing client data or insufficient permissions. Use the search above to find a client.
            </div>
        <?php endif; ?>
    <?php endif; // End of $show_edit_form check ?>
    <!-- End Client Edit Form Area -->

</div><!-- /.container -->

<!-- Edit Client Modal -->
<div class="modal fade" id="editClientModal" tabindex="-1" role="dialog" aria-labelledby="editClientModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="editClientModalLabel">Edit Client Profile</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <!-- Form content will be loaded here by JavaScript -->
                <div id="editClientModalFormContent">
                    <p class="text-center"><i class="fas fa-spinner fa-spin"></i> Loading client data...</p>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
                <button type="button" class="btn btn-primary" id="saveClientChangesBtn">Save Changes</button> <!-- Save functionality is out of scope for this task, but button is here -->
            </div>
        </div>
    </div>
</div>
<!-- End Edit Client Modal -->

<?php
require_once 'includes/footer.php'; // Include the standard site footer
?>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Handle Edit Client Modal
    $('#editClientModal').on('show.bs.modal', function (event) {
        var button = $(event.relatedTarget); // Button that triggered the modal
        var clientId = button.data('client-id'); // Extract info from data-* attributes
        var modal = $(this);

        // Clear previous content and show loading
        modal.find('#editClientModalFormContent').html('<p class="text-center"><i class="fas fa-spinner fa-spin"></i> Loading client data...</p>');
        modal.find('#editClientModalLabel').text('Edit Client Profile');


        // AJAX request to get client details
        $.ajax({
            url: 'ajax_handlers/get_client_details_handler.php',
            type: 'GET',
            data: { client_id: clientId },
            dataType: 'json',
            success: function(response) {
                if (response.success && response.data) {
                    var client = response.data.profile;
                    var clientAnswers = response.data.answers; // This is an array of objects: {question_id: x, answer: 'Y/N'}
                    var allQuestions = response.data.all_questions;
                    var sites = response.data.sites;

                    modal.find('#editClientModalLabel').text('Edit Client Profile: ' + escapeHtml(client.first_name) + ' ' + escapeHtml(client.last_name));

                    var formHtml = '<form id="editClientFormInModal">'; // Add an ID for potential future use
                    formHtml += '<input type="hidden" name="client_id" value="' + escapeHtml(client.id) + '">';
                    formHtml += '<input type="hidden" name="csrf_token" value="<?php echo $csrf_token; // Ensure CSRF token is available in JS scope or add it here if needed ?>">';
                    formHtml += '<input type="hidden" name="action" value="save_client">';


                    // Basic Info
                    formHtml += '<h5>Client Details</h5>';
                    formHtml += '<div class="form-row">';
                    formHtml += '  <div class="form-group col-md-6">';
                    formHtml += '    <label for="modal_first_name">First Name</label>';
                    formHtml += '    <input type="text" class="form-control" id="modal_first_name" name="first_name" value="' + escapeHtml(client.first_name) + '" required>';
                    formHtml += '  </div>';
                    formHtml += '  <div class="form-group col-md-6">';
                    formHtml += '    <label for="modal_last_name">Last Name</label>';
                    formHtml += '    <input type="text" class="form-control" id="modal_last_name" name="last_name" value="' + escapeHtml(client.last_name) + '" required>';
                    formHtml += '  </div>';
                    formHtml += '</div>';

                    formHtml += '<div class="form-row">';
                    formHtml += '  <div class="form-group col-md-6">';
                    formHtml += '    <label for="modal_username">Username</label>';
                    formHtml += '    <input type="text" class="form-control" id="modal_username" name="username" value="' + escapeHtml(client.username) + '" readonly>';
                    formHtml += '    <small class="form-text text-muted">Username cannot be changed.</small>';
                    formHtml += '  </div>';
                    formHtml += '  <div class="form-group col-md-6">';
                    formHtml += '    <label for="modal_email">Email</label>';
                    formHtml += '    <input type="email" class="form-control" id="modal_email" name="email" value="' + escapeHtml(client.email) + '" readonly>';
                    formHtml += '    <small class="form-text text-muted">Email cannot be changed.</small>';
                    formHtml += '  </div>';
                    formHtml += '</div>';

                    formHtml += '<div class="form-row">';
                    formHtml += '  <div class="form-group col-md-6">';
                    formHtml += '    <label for="modal_site_id">Primary Site</label>';
                    formHtml += '    <select class="form-control" id="modal_site_id" name="site_id" required>';
                    formHtml += '      <option value="">-- Select Site --</option>';
                    if (sites) {
                        sites.forEach(function(site) {
                            formHtml += '<option value="' + escapeHtml(site.id) + '"' + (client.site_id == site.id ? ' selected' : '') + '>' + escapeHtml(site.name) + '</option>';
                        });
                    }
                    formHtml += '    </select>';
                    formHtml += '  </div>';
                    formHtml += '  <div class="form-group col-md-6 d-flex align-items-center pt-3">';
                    formHtml += '    <div class="form-check">';
                    formHtml += '      <input class="form-check-input" type="checkbox" id="modal_email_preference_jobs" name="email_preference_jobs" value="1" ' + (client.email_preference_jobs == 1 ? 'checked' : '') + '>';
                    formHtml += '      <label class="form-check-label" for="modal_email_preference_jobs">Opt-in to receive job/event emails</label>';
                    formHtml += '    </div>';
                    formHtml += '  </div>';
                    formHtml += '</div>';

                    // Dynamic Questions
                    formHtml += '<hr><h5>Dynamic Questions</h5>';
                    if (allQuestions && allQuestions.length > 0) {
                        allQuestions.forEach(function(question) {
                            var questionId = question.id;
                            var currentAnswer = '';
                            // Find if client has an answer for this question
                            var foundAnswer = clientAnswers.find(ans => ans.question_id == questionId);
                            if (foundAnswer) {
                                currentAnswer = foundAnswer.answer;
                            }

                            var inputName = "q_" + questionId;
                            formHtml += '<div class="form-group">';
                            formHtml += '  <label>' + escapeHtml(question.question_title) + ': ' + escapeHtml(question.question_text) + '</label>';
                            formHtml += '  <div>';
                            formHtml += '    <div class="form-check form-check-inline">';
                            formHtml += '      <input class="form-check-input" type="radio" name="' + inputName + '" id="modal_' + inputName + '_yes" value="Yes" ' + (currentAnswer === 'Yes' ? 'checked' : '') + '>';
                            formHtml += '      <label class="form-check-label" for="modal_' + inputName + '_yes">Yes</label>';
                            formHtml += '    </div>';
                            formHtml += '    <div class="form-check form-check-inline">';
                            formHtml += '      <input class="form-check-input" type="radio" name="' + inputName + '" id="modal_' + inputName + '_no" value="No" ' + (currentAnswer === 'No' ? 'checked' : '') + '>';
                            formHtml += '      <label class="form-check-label" for="modal_' + inputName + '_no">No</label>';
                            formHtml += '    </div>';
                            formHtml += '    <div class="form-check form-check-inline">';
                            formHtml += '      <input class="form-check-input" type="radio" name="' + inputName + '" id="modal_' + inputName + '_na" value="" ' + (currentAnswer === '' ? 'checked' : '') + '>'; // Assuming empty string for 'No Answer'
                            formHtml += '      <label class="form-check-label" for="modal_' + inputName + '_na">No Answer</label>';
                            formHtml += '    </div>';
                            formHtml += '  </div>';
                            formHtml += '</div>';
                        });
                    } else {
                        formHtml += '<p>No global questions configured.</p>';
                    }

                    formHtml += '</form>';
                    modal.find('#editClientModalFormContent').html(formHtml);

                } else {
                    modal.find('#editClientModalFormContent').html('<div class="alert alert-danger">Error: Could not load client details. ' + escapeHtml(response.message || 'Unknown error.') + '</div>');
                }
            },
            error: function(xhr, status, error) {
                console.error("AJAX Error fetching client details:", status, error, xhr.responseText);
                modal.find('#editClientModalFormContent').html('<div class="alert alert-danger">Error: Could not connect to the server to load client details. Please try again.</div>');
            }
        });
    });

    // Save Changes button functionality
    $('#saveClientChangesBtn').on('click', function() {
        var form = $('#editClientFormInModal');
        var formData = form.serialize(); // Collects all form data

        // Basic client-side validation (example: check required fields)
        var isValid = true;
        form.find('input[required], select[required]').each(function() {
            if (!$(this).val()) {
                isValid = false;
                $(this).addClass('is-invalid'); // Add Bootstrap error class
                // You could add a more specific error message next to the field
            } else {
                $(this).removeClass('is-invalid');
            }
        });

        if (!isValid) {
            // Add a general error message at the top of the modal form
            if ($('#editClientModalFormContent .alert-danger').length === 0) {
                $('#editClientModalFormContent').prepend('<div class="alert alert-danger" role="alert">Please fill in all required fields.</div>');
            } else {
                $('#editClientModalFormContent .alert-danger').html('Please fill in all required fields.');
            }
            return; // Stop if validation fails
        } else {
            // Clear previous validation error messages if any
            $('#editClientModalFormContent .alert-danger').remove();
            form.find('.is-invalid').removeClass('is-invalid');
        }


        // AJAX POST to the new handler
        $.ajax({
            url: 'ajax_handlers/update_client_details_handler.php',
            type: 'POST',
            data: formData, // Includes client_id, csrf_token, action, and all form fields
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    $('#editClientModal').modal('hide');
                    // Display success message (e.g., using a toast or a temporary alert on the main page)
                    // For simplicity, we'll use a standard alert and then reload.
                    alert(response.message || 'Client details updated successfully!');
                    window.location.reload(); // Reload the page to see changes
                } else {
                    // Display error message within the modal
                    var errorMsg = 'Error updating client: ' + (response.message || 'Unknown error.');
                    if (response.errors) {
                        errorMsg += '<ul>';
                        for (var field in response.errors) {
                            errorMsg += '<li>' + escapeHtml(field) + ': ' + escapeHtml(response.errors[field]) + '</li>';
                        }
                        errorMsg += '</ul>';
                    }
                    // Add or update an alert div at the top of the modal form content
                    if ($('#editClientModalFormContent .alert-danger').length === 0) {
                        $('#editClientModalFormContent').prepend('<div class="alert alert-danger" role="alert">' + errorMsg + '</div>');
                    } else {
                        $('#editClientModalFormContent .alert-danger').html(errorMsg);
                    }
                }
            },
            error: function(xhr, status, error) {
                console.error("AJAX Error saving client details:", status, error, xhr.responseText);
                var errorText = 'AJAX error: Could not save client details. Please check the console for more information.';
                 if ($('#editClientModalFormContent .alert-danger').length === 0) {
                        $('#editClientModalFormContent').prepend('<div class="alert alert-danger" role="alert">' + errorText + '</div>');
                    } else {
                        $('#editClientModalFormContent .alert-danger').html(errorText);
                    }
            }
        });
    });

    function escapeHtml(unsafe) {
        if (typeof unsafe !== 'string') {
            if (unsafe === null || typeof unsafe === 'undefined') return '';
            unsafe = String(unsafe);
        }
        return unsafe
.replace(/&amp;/g, "&amp;")
.replace(/&lt;/g, "&lt;")
.replace(/&gt;/g, "&gt;")
.replace(/"/g, "&amp;quot;")
             .replace(/'/g, "&#039;");
    }
});
</script>