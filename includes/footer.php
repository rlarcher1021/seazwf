<?php
/*
 * File: footer.php
 * Path: /includes/footer.php
 * Created: [Original Date]
 * Author: [Your Name] // Updated Author: Robert Archer
 * Updated: 2025-04-08 - Added Site Selection Modal HTML.
 *
 * Description: Site footer, closing tags, JS includes, copyright, modals.
 */

 // Ensure $pdo is available if needed for modal content generation
 // It should be available if included after db_connect.php in the main script
 // Using global might be necessary if $pdo was not passed or included in a function scope.
 global $pdo;

$current_page_basename = basename($_SERVER['PHP_SELF']);
?>

        </div> <!-- Close content-wrapper OR content-wrapper-full (opened in header.php) -->

    <?php // Only close main-content if sidebar was shown (it's outside the wrapper then)
    if ($current_page_basename !== 'index.php' && $current_page_basename !== 'checkin.php'):
    ?>
    </main> <!-- Close main-content (opened in header.php) -->
    <?php endif; ?>

</div> <!-- Close app-container (opened in header.php) -->


<!-- =============================================================== -->
<!--                MODALS (Add required modals here)                -->
<!-- =============================================================== -->

<!-- START: Site Selection Modal -->
<div class="modal fade" id="selectSiteModal" tabindex="-1" role="dialog" aria-labelledby="selectSiteModalLabel" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="selectSiteModalLabel">Select Site for Manual Check-in</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">×</span>
                </button>
            </div>
            <div class="modal-body">
                <p>Please choose the site you wish to perform a manual check-in for:</p>
                <ul class="list-group">
                    <?php
                    // Fetch active sites FOR THE MODAL
                    // Use a separate query here to ensure it always gets all active sites
                    // regardless of user's current filter or impersonation state.
                    $modal_sites = []; // Initialize array
                    try {
                        // Check if $pdo is valid before using it
                        if (isset($pdo) && $pdo instanceof PDO) {
                            $stmt_modal_sites = $pdo->query("SELECT id, name FROM sites WHERE is_active = TRUE ORDER BY name ASC");
                            if ($stmt_modal_sites) { // Check if query preparation was successful
                                $modal_sites = $stmt_modal_sites->fetchAll(PDO::FETCH_ASSOC);
                            } else {
                                error_log("Footer Modal Error: Failed to prepare site query.");
                            }
                        } else {
                             error_log("Footer Modal Error: PDO object not available or invalid.");
                        }
                    } catch (PDOException $e) { // Catch PDO specific exceptions
                        error_log("Footer Modal PDOException - Fetching sites: ".$e->getMessage());
                    } catch (Exception $e) { // Catch other potential exceptions
                        error_log("Footer Modal General Exception - Fetching sites: ".$e->getMessage());
                    }

                    // Display the fetched sites or an error message
                    if (!empty($modal_sites)) {
                        foreach ($modal_sites as $site) {
                            // Each list item links directly to checkin.php with the manual_site_id
                            echo '<li class="list-group-item list-group-item-action">';
                            // Link itself, covers the whole list item for easier clicking
                            echo '<a href="checkin.php?manual_site_id=' . htmlspecialchars($site['id']) . '" class="stretched-link" class="text-decoration-none text-reset">';
                            echo htmlspecialchars($site['name']);
                            echo '</a></li>';
                        }
                    } else {
                        echo '<li class="list-group-item">No active sites found or database error occurred.</li>';
                    }
                    ?>
                </ul>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
            </div>
        </div>
    </div>
</div>
<!-- END: Site Selection Modal -->

<!-- Add other modals here if needed -->

<!-- =============================================================== -->
<!--                 END MODALS                                      -->
<!-- =============================================================== -->



<!-- START: AI Chat Widget CSS -->
<style>
#chat-toggle-button {
    position: fixed;
    bottom: 20px;
    right: 20px;
    z-index: 1050; /* Above most elements */
    background-color: var(--primary-color, #007bff);
    color: white;
    border: none;
    border-radius: 50%;
    width: 50px;
    height: 50px;
    font-size: 24px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.2);
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
}

#ai-chat-widget {
    position: fixed;
    bottom: 80px; /* Above the toggle button */
    right: 20px;
    width: 350px;
    max-width: 90%;
    height: 450px;
    max-height: 70vh;
    background-color: white;
    border: 1px solid #ccc;
    border-radius: 8px;
    box-shadow: 0 4px 15px rgba(0,0,0,0.2);
    z-index: 1049; /* Just below toggle button when closed */
    display: none; /* Hidden by default */
    flex-direction: column;
    overflow: hidden;
}

#ai-chat-widget.open {
    display: flex;
}

.chat-widget-header {
    background-color: #f8f9fa;
    padding: 10px 15px;
    border-bottom: 1px solid #dee2e6;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.chat-widget-header h5 {
    margin-bottom: 0;
    font-size: 1rem;
    font-weight: 600;
}

.chat-widget-header .close-chat {
    font-size: 1.2rem;
    opacity: 0.7;
    background: none;
    border: none;
    padding: 0;
}

#chat-messages {
    flex-grow: 1;
    overflow-y: auto;
    padding: 15px;
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.chat-message {
    padding: 8px 12px;
    border-radius: 15px;
    max-width: 80%;
    word-wrap: break-word;
}

.chat-message.user {
    background-color: #e9ecef;
    color: #333;
    align-self: flex-start;
    border-bottom-left-radius: 5px;
    text-align: left;
}

.chat-message.ai {
    background-color: var(--primary-color, #007bff);
    color: white;
    align-self: flex-end;
    border-bottom-right-radius: 5px;
    text-align: left; /* Keep text left-aligned within the right-aligned bubble */
}

.chat-message.error {
    background-color: #f8d7da;
    color: #721c24;
    align-self: center;
    font-style: italic;
    font-size: 0.9em;
    text-align: center;
    width: 90%;
}

.chat-message.loading {
    align-self: flex-end;
    font-style: italic;
    color: #6c757d;
    font-size: 0.9em;
}


    /* Fix for list bullets being cut off */
    .chat-message ul,
    .chat-message ol {
        margin-left: 20px; /* Provides space for bullets */
        padding-left: 0;   /* Resets default padding */
        margin-top: 0.5em; /* Adds space above list */
        margin-bottom: 0.5em; /* Adds space below list */
    }


.chat-input-area {
    border-top: 1px solid #dee2e6;
    padding: 10px;
    display: flex;
    gap: 10px;
}

#chat-input {
    flex-grow: 1;
    border-radius: 20px;
    padding: 8px 15px;
    border: 1px solid #ced4da;
}

#chat-send-button {
    border-radius: 50%;
    width: 40px;
    height: 40px;
    flex-shrink: 0;
}


/* Ensure jQuery UI Resizable handles are visible and have correct cursors */
.ui-resizable-handle {
    position: absolute;
    font-size: 0.1px;
    display: block;
    touch-action: none;
    z-index: 1051; /* Ensure handles are above the widget content */
}
.ui-resizable-disabled .ui-resizable-handle, .ui-resizable-autohide .ui-resizable-handle {
    display: none;
}
.ui-resizable-n { cursor: n-resize; height: 7px; width: 100%; top: -5px; left: 0; }
.ui-resizable-s { cursor: s-resize; height: 7px; width: 100%; bottom: -5px; left: 0; }
.ui-resizable-e { cursor: e-resize; width: 7px; right: -5px; top: 0; height: 100%; }
.ui-resizable-w { cursor: w-resize; width: 7px; left: -5px; top: 0; height: 100%; }
.ui-resizable-se { cursor: se-resize; width: 12px; height: 12px; right: 1px; bottom: 1px; }
.ui-resizable-sw { cursor: sw-resize; width: 9px; height: 9px; left: -5px; bottom: -5px; }
.ui-resizable-nw { cursor: nw-resize; width: 9px; height: 9px; left: -5px; top: -5px; }
.ui-resizable-ne { cursor: ne-resize; width: 9px; height: 9px; right: -5px; top: -5px; }
</style>
<!-- END: AI Chat Widget CSS -->

<!-- START: AI Chat Widget HTML -->
<?php
// Only show chat widget if user is logged in and NOT a kiosk
if (isset($_SESSION['user_id']) && (!isset($_SESSION['role']) || $_SESSION['role'] !== 'kiosk')):
?>
<button id="chat-toggle-button" title="AI Assistant">
    <i class="fas fa-comment-dots"></i>
</button>

<div id="ai-chat-widget">
    <div class="chat-widget-header">
        <h5>AI Assistant</h5>
        <button type="button" class="close close-chat" aria-label="Close">
            <span aria-hidden="true">&times;</span>
        </button>
    </div>
    <div id="chat-messages">
        <!-- Messages will appear here (dynamically added by JS) -->
    </div>
    <div class="chat-input-area">
        <input type="text" id="chat-input" class="form-control" placeholder="Type your message...">
        <button id="chat-send-button" class="btn btn-primary">
            <i class="fas fa-paper-plane"></i>
        </button>
    </div>
</div>
<?php endif; // End check for logged-in non-kiosk user ?>
<!-- END: AI Chat Widget HTML -->

<!-- JavaScript Includes -->
<!-- 1. jQuery (MUST be first) -->
<script src="https://code.jquery.com/jquery-3.6.0.min.js" integrity="sha256-/xUj+3OJU5yExlq6GSYGSHk7tPXikynS7ogEvDej/m4=" crossorigin="anonymous"></script>

<!-- Google Translate Script (Keep if needed) -->
<script type="text/javascript" src="//translate.google.com/translate_a/element.js?cb=googleTranslateElementInit"></script>

<!-- Main Custom JS File (Uncomment if you create/need it) -->
<!-- <script src="assets/js/main.js?v=<?php // echo filemtime(__DIR__ . '/../assets/js/main.js'); // Path relative to /includes/ ?>"></script> -->


<!-- Footer Copyright -->
<footer class="text-center p-3 small text-muted mt-4 border-top">
    © <?php echo date("Y"); ?> Arizona@Work - Southeastern Arizona. All Rights Reserved.
</footer>

<!-- Bootstrap JS Dependencies - IMPORTANT: Keep this order -->

<!-- jQuery loaded above -->

<!-- 2. jQuery UI (Core, Widget, Mouse, Resizable) -->
<link rel="stylesheet" href="https://code.jquery.com/ui/1.13.2/themes/base/jquery-ui.css">
<script src="https://code.jquery.com/ui/1.13.2/jquery-ui.min.js" integrity="sha256-lSjKY0/srUM9BE3dPm+c4fBo1dky2v27Gdjm2uoZaL0=" crossorigin="anonymous"></script>

<!-- 3. Bootstrap Bundle JS (Includes Popper.js for v5) -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js" integrity="sha384-Fy6S3B9q64WdZWQUiU+q4/2Lc9npb8tCaSX9FK7E8HnRr0Jz8D6OP9dO5Vg3Q9ct" crossorigin="anonymous"></script>

<!-- Output Page-Specific JavaScript -->
<?php
if (!empty($GLOBALS['footer_scripts'])) {
    echo $GLOBALS['footer_scripts']; // Output the collected JS
}
?>

<!-- 4. Select2 JS (Add after jQuery and Bootstrap Bundle) -->
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<!-- 5. Markdown-it (Markdown Parser) -->
<script src="https://cdn.jsdelivr.net/npm/markdown-it/dist/markdown-it.min.js"></script>


<!-- Pass PHP Session/User Data to JavaScript -->
<script>
    window.APP_DATA = {
        currentUserRole: <?= isset($_SESSION['active_role']) ? json_encode(strtolower($_SESSION['active_role'])) : 'null' ?>,
        currentUserId: <?= isset($_SESSION['user_id']) ? json_encode($_SESSION['user_id']) : 'null' ?>,
        currentUserDeptId: <?= isset($_SESSION['department_id']) ? json_encode($_SESSION['department_id']) : 'null' ?>,
        currentUserDeptSlug: null // Initialize as null, fetch below if needed
    };

    <?php
    // Fetch department slug if department ID is set in session
    // This ensures the slug is available even if not explicitly fetched on every page
    if (isset($_SESSION['department_id'])) {
        try {
            // Ensure db_connect.php was included and $pdo is available
            if (isset($pdo) && $pdo instanceof PDO) {
                $stmtDept = $pdo->prepare("SELECT slug FROM departments WHERE id = ? AND deleted_at IS NULL");
                $stmtDept->execute([$_SESSION['department_id']]);
                $deptResult = $stmtDept->fetch(PDO::FETCH_ASSOC);
                if ($deptResult && isset($deptResult['slug'])) {
                    // Assign the fetched slug to the JS object
                    echo 'window.APP_DATA.currentUserDeptSlug = ' . json_encode(strtolower($deptResult['slug'])) . ';';
                }
            }
        } catch (Exception $e) {
            error_log("Error fetching department slug in footer: " . $e->getMessage());
            // Keep slug as null in JS if error occurs
        }
    }
    ?>
    console.log('APP_DATA initialized:', window.APP_DATA); // Debugging line
</script>


<?php // Conditionally include budgets.js only on budgets.php
if ($current_page_basename === 'budgets.php'):
    // Construct the path relative to the document root or use a consistent base path if available
    $budgets_js_path = __DIR__ . '/../assets/js/budgets.js'; // Path relative to /includes/
    if (file_exists($budgets_js_path)) {
        $version = filemtime($budgets_js_path);
        echo '<script src="assets/js/budgets.js?v=' . $version . '"></script>' . "\n";
    } else {
        error_log("Error: budgets.js not found at " . $budgets_js_path);
        // Optionally echo a comment in HTML for debugging
        // echo "<!-- budgets.js not found -->\n";
    }
endif;
?>

<!-- START: Grants Panel Modal JS -->
<script>
// Wrap all jQuery dependent code in a ready handler
jQuery(function($) {
    // --- Edit Grant Modal Population ---
    const editGrantModalPanel = document.getElementById('editGrantModalPanel');

    if (editGrantModalPanel) {
        // Attach click listener directly to the edit buttons using delegation
        $(document).on('click', '.edit-grant-btn-panel', function (event) {
            const button = this; // 'this' is the clicked button element
            const $button = $(button); // jQuery object for the button
            const $modal = $('#editGrantModalPanel'); // Get modal jQuery object

            // Check if button is valid
            if (!button) {
                 // Optionally log error or display user message
                 return; // Stop if button not found
            }

            // Extract info using jQuery's .data() method
            // Using kebab-case keys matching the data-* attributes
            const grantId = $button.data('grant-id');
            const grantName = $button.data('grant-name');
            const grantCode = $button.data('grant-code');
            const grantDescription = $button.data('grant-description');
            const grantStart = $button.data('grant-start');
            const grantEnd = $button.data('grant-end');

            // Update the modal's content.
            $modal.find('.modal-title').text('Edit Grant: ' + grantName);
            $modal.find('#edit_grant_id_panel').val(grantId);
            $modal.find('#edit_grant_name_panel').val(grantName);
            $modal.find('#edit_grant_code_panel').val(grantCode);
            $modal.find('#edit_grant_description_panel').val(grantDescription);
            $modal.find('#edit_grant_start_date_panel').val(grantStart);
            $modal.find('#edit_grant_end_date_panel').val(grantEnd);
            $modal.find('#editGrantErrorPanel').text('').hide(); // Clear previous errors

            // Manually show the modal AFTER populating fields
            $modal.modal('show');

        }); // End delegated 'click' listener
    }


    // --- Add Grant Modal Reset ---
    const addGrantModalPanel = document.getElementById('addGrantModalPanel');
    if (addGrantModalPanel) {
         // Use shown.modal event to reset the form after it appears
         $(addGrantModalPanel).on('shown.modal', function(event) {
             const $addModal = $(this); // Renamed variable to avoid conflict
             $addModal.find('#addGrantFormPanel')[0].reset(); // Reset form
             $addModal.find('#addGrantErrorPanel').text('').hide(); // Clear errors
         });
    }

}); // End jQuery ready handler
</script>
<!-- END: Grants Panel Modal JS -->


<!-- START: AI Chat Widget JavaScript -->
<script>
jQuery(document).ready(function($) { // Use jQuery wrapper
    const chatToggleButton = $('#chat-toggle-button');
    const chatWidget = $('#ai-chat-widget');
    const closeChatButton = chatWidget.find('.close-chat');
    const messagesContainer = $('#chat-messages');
    const inputField = $('#chat-input');
    const sendButton = $('#chat-send-button');
    const csrfTokenMeta = $('meta[name="csrf-token"]');

    // Ensure elements exist before adding listeners (widget might not be rendered for kiosk/logged out)
    if (!chatToggleButton.length || !chatWidget.length || !closeChatButton.length || !messagesContainer.length || !inputField.length || !sendButton.length || !csrfTokenMeta.length) {
        // console.log('Chat widget elements not found, skipping JS setup.');
        return; // Exit if essential elements are missing
    }

    const csrfToken = csrfTokenMeta.attr('content');
    let chatHistory = []; // Array to hold message objects { sender: '...', text: '...' }

    // --- Storage Keys ---
    const historyKey = 'aiChatHistory';
    const stateKey = 'aiChatState';
    const sizeKey = 'aiChatSize';

    // --- Load from Session Storage ---
    const loadState = () => {
        // 1. Load Size (Apply first)
        try {
            const savedSize = sessionStorage.getItem(sizeKey);
            if (savedSize) {
                const size = JSON.parse(savedSize);
                // Basic validation
                if (size && size.width && size.height) {
                    chatWidget.css({
                        width: size.width,
                        height: size.height
                    });
                }
            }
        } catch (e) {
            console.error("Error parsing saved chat size:", e);
            sessionStorage.removeItem(sizeKey); // Clear corrupted data
        }

        // 2. Load History & Initialize if needed
        chatHistory = []; // Start with empty history
        try {
            const savedHistory = sessionStorage.getItem(historyKey);
            if (savedHistory) {
                const parsedHistory = JSON.parse(savedHistory);
                // Basic validation
                if (Array.isArray(parsedHistory)) {
                    chatHistory = parsedHistory; // Load existing history
                } else {
                     console.warn("Saved chat history is not an array. Resetting.");
                     sessionStorage.removeItem(historyKey); // Clear corrupted data
                }
            }
        } catch (e) {
            console.error("Error parsing saved chat history:", e);
            sessionStorage.removeItem(historyKey); // Clear corrupted data
        }

        // If history is empty after attempting load, add default greeting and save
        if (chatHistory.length === 0) {
            const defaultGreeting = { sender: 'ai', text: 'Hello! How can I assist you today?' };
            chatHistory.push(defaultGreeting);
            saveHistory(); // Save the initial state with the greeting
        }

        // Render the final chat history (either loaded or default)
        messagesContainer.empty(); // Clear display first
        chatHistory.forEach(msg => addMessage(msg.text, msg.sender, false)); // Render messages without saving again
        messagesContainer.scrollTop(messagesContainer[0].scrollHeight); // Scroll to bottom after loading


        // 3. Load Open/Closed State
        const savedState = sessionStorage.getItem(stateKey);
        if (savedState === 'open') {
            chatWidget.addClass('open');
            inputField.focus();
        } else {
            chatWidget.removeClass('open'); // Ensure it's closed if not explicitly 'open'
        }
    };

    // --- Save to Session Storage ---
    const saveHistory = () => {
        try {
            sessionStorage.setItem(historyKey, JSON.stringify(chatHistory));
        } catch (e) {
            console.error("Error saving chat history:", e);
            // Potentially notify user or implement more robust error handling
        }
    };

    const saveState = (state) => { // state = 'open' or 'closed'
        try {
            sessionStorage.setItem(stateKey, state);
        } catch (e) {
            console.error("Error saving chat state:", e);
        }
    };

    const saveSize = (width, height) => {
        try {
            const size = { width: width, height: height };
            sessionStorage.setItem(sizeKey, JSON.stringify(size));
        } catch (e) {
            console.error("Error saving chat size:", e);
        }
    };

    // --- Add Message to UI ---
    const addMessage = (text, sender, save = true) => {
        const messageClass = `chat-message ${sender}`; // e.g., 'chat-message user' or 'chat-message ai'

        // Create the message element
        const messageElement = $('<div></div>').addClass(messageClass);

        // Use .text() for all message types to prevent XSS
        messageElement.text(text);

        messagesContainer.append(messageElement);
        messagesContainer.scrollTop(messagesContainer[0].scrollHeight); // Scroll to bottom

        if (save) {
            chatHistory.push({ sender: sender, text: text });
            saveHistory(); // Save history after adding a new message
        }
    };

     // --- Toggle Chat Widget ---
    chatToggleButton.on('click', function() {
        chatWidget.toggleClass('open');
        if (chatWidget.hasClass('open')) {
            inputField.focus(); // Focus input when opened
            saveState('open');
        } else {
            saveState('closed');
        }
    });

    closeChatButton.on('click', function() {
        chatWidget.removeClass('open');
        saveState('closed');
    });


    // --- Send Message Functionality ---
    const sendMessage = () => {
        const userMessage = inputField.val().trim();
        if (userMessage === '') return; // Don't send empty messages

        addMessage(userMessage, 'user'); // Display user message immediately
        inputField.val(''); // Clear input field

        // Add loading indicator
        const loadingIndicator = $('<div></div>').addClass('chat-message loading').text('AI is thinking...');
        messagesContainer.append(loadingIndicator);
        messagesContainer.scrollTop(messagesContainer[0].scrollHeight);

        // Send message to backend via AJAX
        $.ajax({
            url: 'ajax_chat_handler.php', // Your backend endpoint
            type: 'POST',
            contentType: 'application/json', // Send as JSON
            data: JSON.stringify({
                message: userMessage,
                history: chatHistory.slice(-10) // Send recent history for context (adjust count as needed)
            }),
            headers: {
                'X-CSRF-Token': csrfToken // Include CSRF token
            },
            success: function(response) {
                loadingIndicator.remove(); // Remove loading indicator
                // jQuery likely already parsed the JSON response because of the Content-Type header
                // The 'response' argument should be the data object directly.
                const data = response; // Use the response directly

                if (data && data.success && data.response) { // Check for success flag and response key
                    addMessage(data.response, 'ai');
                } else if (data && data.error) { // Check for error key
                     addMessage(`Error: ${data.error}`, 'error');
                     console.error("AI Chat Error (from backend):", data.error);
                } else {
                     // Handle cases where response might not be the expected object or lacks keys
                     addMessage('Error: Received an unexpected response format from the AI assistant.', 'error');
                     console.error("AI Chat Error: Unexpected response format", response);
                }
                // Removed the try...catch block as JSON.parse is no longer used here.
                // Error handling for network issues is in the main ajax 'error' callback.
            },
            error: function(jqXHR, textStatus, errorThrown) {
                loadingIndicator.remove(); // Remove loading indicator
                console.error("Chat AJAX Error:", textStatus, errorThrown, jqXHR.responseText);
                addMessage(`Error: Could not reach the AI assistant (${textStatus}). Please try again later.`, 'error');
            }
        });
    };

    // --- Event Listeners for Sending ---
    sendButton.on('click', sendMessage);
    inputField.on('keypress', function(e) {
        if (e.key === 'Enter' || e.keyCode === 13) { // Handle Enter key
            e.preventDefault(); // Prevent default form submission/newline
            sendMessage();
        }
    });

    // --- Make Widget Resizable (using jQuery UI) ---
    if ($.ui && $.ui.resizable) { // Check if jQuery UI resizable is loaded
        chatWidget.resizable({
            handles: "n, e, s, w, ne, nw, se, sw", // Allow resizing from all directions/corners
            minHeight: 200,
            minWidth: 250,
            maxHeight: $(window).height() * 0.8, // Limit max height
            maxWidth: $(window).width() * 0.8,  // Limit max width
            stop: function(event, ui) {
                // Save the new size when resizing stops
                saveSize(ui.size.width + 'px', ui.size.height + 'px'); // Save with 'px' unit
            }
        });
    } else {
        console.warn("jQuery UI Resizable not loaded. Chat widget will not be resizable.");
    }


    // --- Initial Load ---
    loadState();

});
</script>
<!-- END: AI Chat Widget JavaScript -->


<!-- START: Activate Tab from URL Hash & Update Hash on Tab Change -->
<script>
jQuery(document).ready(function($) {
    // This script now ONLY updates the hash and cookie when a tab is clicked.
    // The initial active tab is set by the server-side PHP in budget_settings.php reading the cookie.

    // Update hash and cookie when a tab is shown (without causing page jump)
    // Using Bootstrap 4's 'shown.bs.tab' event
    $('#budgetSettingsTabs button[data-toggle="tab"]').on('shown.bs.tab', function (e) {
        var newTabId = $(e.target).attr('id'); // Get the ID of the activated tab button (e.g., 'vendors-tab')
        console.log("Tab shown, new target ID:", newTabId); // Debug log

        // Set cookie (expires when browser closes, path=/ for site-wide access)
        // Use SameSite=Lax for modern browser compatibility
        document.cookie = "activeBudgetSettingsTab=" + newTabId + "; path=/; SameSite=Lax";
        console.log("Cookie set: activeBudgetSettingsTab=" + newTabId); // Debug log

        // Update hash (keep this for bookmarking/linking, though server uses cookie now)
        var newHash = '#' + newTabId;
        if (history.pushState) {
            // Update the hash in the URL without reloading the page
            history.pushState(null, null, newHash);
             console.log("Hash updated via pushState to:", newHash); // Debug log
        } else {
            // Fallback for older browsers (might cause page jump)
            window.location.hash = newHash;
             console.log("Hash updated via fallback to:", newHash); // Debug log
        }
    });

});
</script>
<!-- END: Activate Tab from URL Hash & Update Hash on Tab Change -->


</body>
</html>

<!-- START: Budget Panel Modal JS (Moved from budgets_panel.php) -->
<script>
// --- Edit Budget Modal Population ---
const editBudgetModalPanel = document.getElementById('editBudgetModalPanel');
if (editBudgetModalPanel) {
    // Use jQuery to attach the event listener (since libraries are loaded now)
    $(editBudgetModalPanel).on('show.bs.modal', function (event) {
        const button = event.relatedTarget; // Button that triggered the modal
        if (!button) return; // Exit if no related target

        // Extract info from data-* attributes
        const budgetId = button.getAttribute('data-budget-id');
        const budgetName = button.getAttribute('data-budget-name');
        const userId = button.getAttribute('data-user-id');
        const grantId = button.getAttribute('data-grant-id');
        const departmentId = button.getAttribute('data-department-id');
        const fyStart = button.getAttribute('data-fy-start');
        const fyEnd = button.getAttribute('data-fy-end');
        const budgetType = button.getAttribute('data-budget-type');
        const budgetNotes = button.getAttribute('data-budget-notes');

        // Update the modal's content.
        const modalTitle = editBudgetModalPanel.querySelector('.modal-title');
        const inputBudgetId = editBudgetModalPanel.querySelector('#edit_budget_id_panel');
        const inputName = editBudgetModalPanel.querySelector('#edit_budget_name_panel');
        const selectUser = editBudgetModalPanel.querySelector('#edit_budget_user_id_panel');
        const selectGrant = editBudgetModalPanel.querySelector('#edit_budget_grant_id_panel');
        const selectDept = editBudgetModalPanel.querySelector('#edit_budget_department_id_panel');
        const inputFyStart = editBudgetModalPanel.querySelector('#edit_budget_fiscal_year_start_panel');
        const inputFyEnd = editBudgetModalPanel.querySelector('#edit_budget_fiscal_year_end_panel');
        const selectBudgetType = editBudgetModalPanel.querySelector('#edit_budget_type_panel');
        const textareaNotes = editBudgetModalPanel.querySelector('#edit_budget_notes_panel');
        const errorDiv = editBudgetModalPanel.querySelector('#editBudgetErrorPanel');

        // Check if elements exist before setting values
        if (modalTitle) modalTitle.textContent = 'Edit Budget: ' + (budgetName || '');
        if (inputBudgetId) inputBudgetId.value = budgetId || '';
        if (inputName) inputName.value = budgetName || '';
        if (selectUser) selectUser.value = userId || '';
        if (selectGrant) selectGrant.value = grantId || '';
        if (selectDept) selectDept.value = departmentId || '';
        if (inputFyStart) inputFyStart.value = fyStart || '';
        if (inputFyEnd) inputFyEnd.value = fyEnd || '';
        if (selectBudgetType) selectBudgetType.value = budgetType || '';
        if (textareaNotes) textareaNotes.value = budgetNotes || '';
        if (errorDiv) {
            errorDiv.style.display = 'none'; // Clear previous errors
            errorDiv.textContent = '';
        }
    });
}

// --- Add Budget Modal Reset ---
const addBudgetModalPanel = document.getElementById('addBudgetModalPanel');
if (addBudgetModalPanel) {
    // Use vanilla JS listener as it was before
    addBudgetModalPanel.addEventListener('show.bs.modal', function(event) {
        const form = addBudgetModalPanel.querySelector('#addBudgetFormPanel');
        const errorDiv = addBudgetModalPanel.querySelector('#addBudgetErrorPanel');
        if (form) form.reset();
        // Pre-select Arizona@Work department if possible
        const deptSelect = form.querySelector('#add_budget_department_id_panel');
        // Use PHP variable directly if available in the scope where footer is included
        <?php if (isset($azWorkDeptId) && $azWorkDeptId): ?>
        if (deptSelect) {
            deptSelect.value = '<?php echo $azWorkDeptId; ?>';
        }
        <?php endif; ?>
        if (errorDiv) {
            errorDiv.style.display = 'none';
            errorDiv.textContent = '';
        }
    });
}
</script>
<!-- END: Budget Panel Modal JS -->
</body>
</html>
