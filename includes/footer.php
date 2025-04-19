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
        <!-- Messages will appear here -->
        <div class="chat-message ai">Hello! How can I assist you today?</div>
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

<!-- 1. jQuery (Correct CDN) -->
<script src="https://code.jquery.com/jquery-3.5.1.slim.min.js" integrity="sha384-DfXdz2htPH0lsSSs5nCTpuj/zy4C+OGpamoFVy38MVBnE+IbbVYUew+OrCXaRkfj" crossorigin="anonymous"></script>

<!-- 2. Bootstrap Bundle JS (Includes Popper.js) -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.5.2/dist/js/bootstrap.bundle.min.js" integrity="sha384-LtrjvnR4Twt/qOuYxE721u19sVFLVSA4hf/rRt6PrZTmiPltdZcI7q7PXQBYTKyf" crossorigin="anonymous"></script>

<!-- Output Page-Specific JavaScript -->
<?php
if (!empty($GLOBALS['footer_scripts'])) {
    echo $GLOBALS['footer_scripts']; // Output the collected JS
}
?>

<!-- START: AI Chat Widget JavaScript -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    const chatToggleButton = document.getElementById('chat-toggle-button');
    const chatWidget = document.getElementById('ai-chat-widget');
    const closeChatButton = chatWidget ? chatWidget.querySelector('.close-chat') : null;
    const messagesContainer = document.getElementById('chat-messages');
    const inputField = document.getElementById('chat-input');
    const sendButton = document.getElementById('chat-send-button');
    const csrfTokenMeta = document.querySelector('meta[name="csrf-token"]');

    // Ensure elements exist before adding listeners (widget might not be rendered for kiosk/logged out)
    if (!chatToggleButton || !chatWidget || !closeChatButton || !messagesContainer || !inputField || !sendButton || !csrfTokenMeta) {
        // console.log('Chat widget elements not found, skipping JS setup.');
        return; // Exit if essential elements are missing
    }

    const csrfToken = csrfTokenMeta.getAttribute('content');

    // --- Toggle Chat Widget --- 
    chatToggleButton.addEventListener('click', () => {
        chatWidget.classList.toggle('open');
        // Optional: Focus input when opening
        if (chatWidget.classList.contains('open')) {
            inputField.focus();
        }
    });

    closeChatButton.addEventListener('click', () => {
        chatWidget.classList.remove('open');
    });

    // --- Send Message --- 
    const sendMessage = () => {
        const messageText = inputField.value.trim();
        if (messageText === '') return; // Don't send empty messages

        // 1. Display user message immediately
        addMessage(messageText, 'user');
        inputField.value = ''; // Clear input
        inputField.focus();

        // 2. Show loading indicator
        const loadingIndicator = addMessage('...', 'loading'); // Add temporary loading message

        // 3. Send AJAX request
        fetch('ajax_chat_handler.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded', // PHP expects form data
                'X-Requested-With': 'XMLHttpRequest' // Standard header for AJAX
            },
            body: new URLSearchParams({
                'message': messageText,
                'csrf_token': csrfToken
            })
        })
        .then(response => {
            if (!response.ok) {
                 // Try to parse error from JSON body for specific cases
                 return response.json().then(errData => {
                    throw new Error(errData.error || `HTTP error ${response.status}`);
                 }).catch(() => {
                    // If JSON parsing fails or no error key, throw generic error
                    throw new Error(`HTTP error ${response.status}`);
                 });
            }
            return response.json();
        })
        .then(data => {
            // 4. Remove loading indicator
            loadingIndicator.remove();

            // 5. Handle response
            if (data.success && data.reply) {
                addMessage(data.reply, 'ai');
            } else {
                const errorMessage = data.error || 'An unknown error occurred.';
                addMessage(errorMessage, 'error');
            }
        })
        .catch(error => {
            console.error('Chat Error:', error);
            // 4. Remove loading indicator even on error
            loadingIndicator.remove();
            // 5. Display error message
            addMessage(`Error: ${error.message || 'Could not connect to the assistant.'}`, 'error');
        });
    };

    // --- Add Message Helper --- 
    const addMessage = (text, type) => {
        const messageDiv = document.createElement('div');
        messageDiv.classList.add('chat-message', type); // type = 'user', 'ai', 'error', 'loading'
        messageDiv.textContent = text;
        messagesContainer.appendChild(messageDiv);

        // Scroll to bottom
        messagesContainer.scrollTop = messagesContainer.scrollHeight;
        return messageDiv; // Return the element (useful for removing loading indicator)
    };

    // --- Event Listeners for Sending --- 
    sendButton.addEventListener('click', sendMessage);

    inputField.addEventListener('keypress', (event) => {
        if (event.key === 'Enter') {
            event.preventDefault(); // Prevent default form submission (if any)
            sendMessage();
        }
    });

});
</script>
<!-- END: AI Chat Widget JavaScript -->


</body>
</html>