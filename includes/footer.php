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
                            echo '<a href="checkin.php?manual_site_id=' . htmlspecialchars($site['id']) . '" class="stretched-link" style="text-decoration: none; color: inherit;">';
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
<!-- Google Translate Script (Keep if needed) -->
<script type="text/javascript" src="//translate.google.com/translate_a/element.js?cb=googleTranslateElementInit"></script>

<!-- Main Custom JS File (Uncomment if you create/need it) -->
<!-- <script src="assets/js/main.js?v=<?php // echo filemtime(__DIR__ . '/../assets/js/main.js'); // Path relative to /includes/ ?>"></script> -->


<!-- Footer Copyright -->
<footer style="text-align: center; padding: 15px; font-size: 12px; color: var(--color-gray, #6B7280); margin-top: 20px; border-top: 1px solid var(--color-border, #E5E7EB);">
    © <?php echo date("Y"); ?> Arizona@Work - Southeastern Arizona. All Rights Reserved.
</footer>

<!-- Bootstrap JS Dependencies - IMPORTANT: Keep this order -->

<!-- 1. jQuery (Full version needed for jQuery UI) -->
<script src="https://code.jquery.com/jquery-3.6.0.min.js" integrity="sha256-/xUj+3OJU5yExlq6GSYGSHk7tPXikynS7ogEvDej/m4=" crossorigin="anonymous"></script>

<!-- 2. jQuery UI (Core, Widget, Mouse, Resizable) -->
<link rel="stylesheet" href="https://code.jquery.com/ui/1.13.2/themes/base/jquery-ui.css">
<script src="https://code.jquery.com/ui/1.13.2/jquery-ui.min.js" integrity="sha256-lSjKY0/srUM9BE3dPm+c4fBo1dky2v27Gdjm2uoZaL0=" crossorigin="anonymous"></script>

<!-- 3. Bootstrap Bundle JS (Includes Popper.js) -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.5.2/dist/js/bootstrap.bundle.min.js" integrity="sha384-LtrjvnR4Twt/qOuYxE721u19sVFLVSA4hf/rRt6PrZTmiPltdZcI7q7PXQBYTKyf" crossorigin="anonymous"></script>

<!-- Output Page-Specific JavaScript -->
<?php
if (!empty($GLOBALS['footer_scripts'])) {
    echo $GLOBALS['footer_scripts']; // Output the collected JS
}
?>

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

        // 2. Load History
        try {
            const savedHistory = sessionStorage.getItem(historyKey);
            if (savedHistory) {
                chatHistory = JSON.parse(savedHistory);
                // Basic validation
                if (!Array.isArray(chatHistory)) {
                    chatHistory = []; // Reset if not an array
                    throw new Error("Saved history is not an array.");
                }
                messagesContainer.empty(); // Clear default message
                chatHistory.forEach(msg => addMessage(msg.text, msg.sender, false)); // Add messages without saving again
                messagesContainer.scrollTop(messagesContainer[0].scrollHeight); // Scroll to bottom after loading
            } else {
                 // If no history, add default greeting and save it
                 const defaultGreeting = { sender: 'ai', text: 'Hello! How can I assist you today?' };
                 chatHistory = [defaultGreeting];
                 messagesContainer.empty(); // Clear potential static message in HTML
                 addMessage(defaultGreeting.text, defaultGreeting.sender, true); // Save this initial state
            }
        } catch (e) {
            console.error("Error parsing saved chat history:", e);
            sessionStorage.removeItem(historyKey); // Clear corrupted data
            chatHistory = []; // Reset history
            messagesContainer.empty(); // Clear potentially corrupted display
             // Add and save default greeting after error
            const defaultGreeting = { sender: 'ai', text: 'Hello! How can I assist you today?' };
            chatHistory = [defaultGreeting];
            addMessage(defaultGreeting.text, defaultGreeting.sender, true);
        }

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

    // --- Add Message Helper (Modified) ---
    // Added 'sender' parameter, renamed 'type' to 'sender' for clarity
    // Added 'save' parameter to control saving (avoid double saving on load)
    const addMessage = (text, sender, save = true) => {
        const messageDiv = $('<div></div>') // Use jQuery to create element
            .addClass('chat-message')
            .addClass(sender) // sender = 'user', 'ai', 'error', 'loading', 'system'
            .text(text); // Use .text() for security against XSS

        messagesContainer.append(messageDiv);

        // Add to history array (only for user, ai, error, system messages, not loading)
        if (sender !== 'loading' && save) {
            chatHistory.push({ sender: sender, text: text });
            saveHistory(); // Save history after adding a message
        }

        // Scroll to bottom
        messagesContainer.scrollTop(messagesContainer[0].scrollHeight);
        return messageDiv; // Return the jQuery element
    };


    // --- Toggle Chat Widget ---
    chatToggleButton.on('click', () => {
        chatWidget.toggleClass('open');
        const isOpen = chatWidget.hasClass('open');
        saveState(isOpen ? 'open' : 'closed');
        if (isOpen) {
            inputField.focus();
            messagesContainer.scrollTop(messagesContainer[0].scrollHeight); // Ensure scrolled down when opening
        }
    });

    closeChatButton.on('click', () => {
        chatWidget.removeClass('open');
        saveState('closed');
    });

    // --- Send Message ---
    const sendMessage = () => {
        const messageText = inputField.val().trim();
        if (messageText === '') return;

        // 1. Display user message & save
        addMessage(messageText, 'user'); // Will also save history
        inputField.val(''); // Clear input
        inputField.focus();

        // 2. Show loading indicator (don't save loading message to history)
        const loadingIndicator = addMessage('...', 'loading', false);

        // 3. Send AJAX request (Using jQuery AJAX for consistency)
        $.ajax({
            url: 'ajax_chat_handler.php',
            method: 'POST',
            data: {
                message: messageText,
                csrf_token: csrfToken
            },
            dataType: 'json', // Expect JSON response
            beforeSend: function(xhr) {
                xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
            },
            success: function(data) {
                loadingIndicator.remove();
                if (data.success && data.reply) {
                    addMessage(data.reply, 'ai'); // Will save history
                } else {
                    const errorMessage = data.error || 'An unknown error occurred.';
                    addMessage(errorMessage, 'error'); // Will save history
                }
            },
            error: function(jqXHR, textStatus, errorThrown) {
                console.error('Chat AJAX Error:', textStatus, errorThrown, jqXHR.responseText);
                loadingIndicator.remove();
                let errorMessage = 'Could not connect to the assistant.';
                 // Try to parse response text if available
                 try {
                    const errorData = JSON.parse(jqXHR.responseText);
                    if (errorData && errorData.error) {
                        errorMessage = `Error: ${errorData.error}`;
                    } else {
                         errorMessage = `Error: ${errorThrown || textStatus}`;
                    }
                 } catch(e) {
                    // Stick with the generic error if parsing fails
                    errorMessage = `Error: ${errorThrown || textStatus}`;
                 }
                addMessage(errorMessage, 'error'); // Will save history
            }
        });
    };

    // --- Event Listeners for Sending ---
    sendButton.on('click', sendMessage);

    inputField.on('keypress', (event) => {
        if (event.key === 'Enter') {
            event.preventDefault();
            sendMessage();
        }
    });

    // --- Initialize Resizable ---
    chatWidget.resizable({
        handles: "n, e, s, w, ne, se, sw, nw", // Allow resizing from all sides/corners
        minHeight: 300,
        minWidth: 250,
        stop: function(event, ui) {
            // Save the size when resizing stops
            saveSize(ui.size.width + 'px', ui.size.height + 'px');
        },
        // Optional: Containment if needed, e.g., containment: "document"
        // Optional: Ghost or helper for visual feedback during resize
        // ghost: true
    });

    // --- Initial Load ---
    loadState(); // Load history, state, and size on page load

});
</script>
<!-- END: AI Chat Widget JavaScript -->


</body>
</html>