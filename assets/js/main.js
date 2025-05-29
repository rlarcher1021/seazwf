// assets/js/main.js

document.addEventListener('DOMContentLoaded', function() {

    // Check if we are on the check-in page (e.g., by checking body class or a specific element)
    if (document.body.classList.contains('checkin-page')) {
        // Inactivity Timeout Reset for Check-in Page
        let inactivityTimer;
        const resetTimeoutDuration = 90 * 1000; // 90 seconds

        function resetCheckinTimer() {
            clearTimeout(inactivityTimer);
            inactivityTimer = setTimeout(() => {
                window.location.href = 'checkin.php'; // Reload page
            }, resetTimeoutDuration);
        }

        // Initial setup and event listeners
        resetCheckinTimer(); // Start timer on load
        document.addEventListener('mousemove', resetCheckinTimer);
        document.addEventListener('keypress', resetCheckinTimer);
        document.addEventListener('click', resetCheckinTimer);
        document.addEventListener('scroll', resetCheckinTimer);
        document.addEventListener('touchstart', resetCheckinTimer);
    }

    // Add other general JS functions here if needed in the future
    // e.g., function setupModals() { ... }

    // --- Save Check-in Answers ---
    // Assuming your Save button in checkin_details_modal.php has id="saveCheckinAnswersButton"
    // And the modal itself has id="checkinDetailsModal"
    // And there's a hidden input with id="modalCheckInId" for the check_in_id
    $(document).on('click', '#saveCheckinAnswersButton', function() {
        const check_in_id = $('#modalCheckInId').val();
        const answers = {};
        let formIsValid = true;
        let validationErrorMessage = '';

        // Collect answers from dynamic questions
        // Assuming dynamic questions are select elements with IDs starting "dynamic_question_"
        // and names like "dynamic_answers[QUESTION_ID]"
        $('#checkinDetailsModal .modal-body-content select[id^="dynamic_question_"]').each(function() {
            const questionIdFull = $(this).attr('id'); // e.g., dynamic_question_123
            const questionId = questionIdFull.replace('dynamic_question_', '');
            const answerValue = $(this).val();

            // Only include answers that are 'Yes' or 'No'. Empty selection means no answer for that question.
            if (answerValue === "Yes" || answerValue === "No") {
                answers[questionId] = answerValue;
            } else if (answerValue !== "") {
                // If a value is selected but it's not Yes/No (and not empty string for "no selection")
                // This case should ideally not happen if selects only have Yes/No/"" options.
                // For robustness, you might want to flag this as an error.
                // For now, we only send valid 'Yes' or 'No'.
            }
        });

        if (!check_in_id) {
            alert('Error: Check-in ID is missing.');
            return;
        }

        // Optional: Add a loading state to the save button
        $(this).prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Saving...');
        const saveButton = $(this); // Reference to the button

        $.ajax({
            url: 'ajax_handlers/save_checkin_answers_handler.php',
            type: 'POST',
            data: {
                check_in_id: check_in_id,
                answers: answers // This will be an object like { '1': 'Yes', '2': 'No' }
            },
            dataType: 'json',
            success: function(response) {
                saveButton.prop('disabled', false).html('Save Changes'); // Reset button
                if (response.success) {
                    $('#checkinDetailsModal').modal('hide');
                    alert(response.message || 'Answers saved successfully!');
                    // Optionally, refresh the recent check-ins table on dashboard.php
                    // This depends on how your dashboard table is loaded.
                    // Example: if you have a function to reload it:
                    // if (typeof reloadRecentCheckins === 'function') {
                    //     reloadRecentCheckins();
                    // } else {
                    //     // Fallback: if on dashboard, reload the page to see changes
                    //     if (window.location.pathname.endsWith('dashboard.php')) {
                    //         window.location.reload();
                    //     }
                    // }
                    // For now, a simple page reload if on dashboard:
                    if (window.location.pathname.includes('dashboard.php')) {
                        window.location.reload();
                    }

                } else {
                    // Display error message, e.g., in an alert or a specific div in the modal
                    alert('Error saving answers: ' + (response.message || 'Unknown error.'));
                    // Example: $('#modalErrorMessage').text(response.message || 'Unknown error.').show();
                }
            },
            error: function(jqXHR, textStatus, errorThrown) {
                saveButton.prop('disabled', false).html('Save Changes'); // Reset button
                console.error("AJAX Error:", textStatus, errorThrown, jqXHR.responseText);
                alert('AJAX Error: Could not save answers. ' + (jqXHR.responseJSON && jqXHR.responseJSON.message ? jqXHR.responseJSON.message : 'Server error or connection issue.'));
            }
        });
    });

}); // End DOMContentLoaded

// Function to open and populate the check-in details modal
// Moved outside DOMContentLoaded to be globally accessible
function openCheckinDetailsModal(check_in_id) {
    console.log('[DEBUG] openCheckinDetailsModal called with check_in_id:', check_in_id); // DEBUG
    // Show a loading indicator in the modal body if desired
    $('#checkinDetailsModal .modal-body-content').html('<p class="text-center"><i class="fas fa-spinner fa-spin"></i> Loading details...</p>');
    // Store check_in_id in a hidden field or data attribute
    $('#checkinDetailsModal').data('check_in_id', check_in_id); // Using data attribute on modal
    $('#modalCheckInId').val(check_in_id); // Assuming a hidden input with id="modalCheckInId"

    $.ajax({
        url: 'ajax_handlers/get_checkin_details_handler.php',
        type: 'GET',
        data: { check_in_id: check_in_id },
        dataType: 'json',
        success: function(response) {
            console.log('[DEBUG] AJAX success. Response:', response); // DEBUG
            if (response.success && response.data) { // Corrected: && to &&
                const details = response.data.check_in_details;
console.log('[DEBUG] Client data from AJAX:', details); // DEBUG - Added to inspect client data
                const questions = response.data.dynamic_questions;
                console.log('[DEBUG] Parsed details:', details); // DEBUG
                console.log('[DEBUG] Parsed questions:', questions); // DEBUG
                let modalContentHtml = '';

                // Populate standard check-in and client info
                modalContentHtml += '<h5>Check-in Information</h5>';
                modalContentHtml += '<p><strong>Check-in ID:</strong> ' + details.check_in_id + '</p>';
                modalContentHtml += '<p><strong>Site:</strong> ' + (details.site_name || 'N/A') + '</p>';
                modalContentHtml += '<p><strong>Time:</strong> ' + new Date(details.check_in_time).toLocaleString() + '</p>';
                
                // Display check-in name (if available, typically for manual check-ins)
                if (details.checkin_first_name || details.checkin_last_name) {
                    modalContentHtml += '<p><strong>Checked-in As:</strong> ' + (details.checkin_first_name || '') + ' ' + (details.checkin_last_name || '') + '</p>';
                }
                 if (details.checkin_client_email) {
                    modalContentHtml += '<p><strong>Check-in Email:</strong> ' + details.checkin_client_email + '</p>';
                }


                modalContentHtml += '<hr><h5>Client Information</h5>';
console.log('[DEBUG] Client Information Check. Details object:', details); // DEBUG
console.log('[DEBUG] Value of details.client_id:', details.client_id); // DEBUG: Added to check client_id value
                if (details.client_id) {
                    modalContentHtml += '<p><strong>Client ID:</strong> ' + details.client_id + '</p>';
                    modalContentHtml += '<p><strong>Name:</strong> ' + (details.client_first_name || 'N/A') + ' ' + (details.client_last_name || 'N/A') + '</p>';
                    modalContentHtml += '<p><strong>Username:</strong> ' + (details.client_username || 'N/A') + '</p>';
                    modalContentHtml += '<p><strong>Email:</strong> ' + (details.client_email_primary || 'N/A') + '</p>';
                } else {
                    modalContentHtml += '<p>No registered client associated with this check-in (manual check-in).</p>';
                }

                // Populate dynamic questions and answers
                modalContentHtml += '<hr><h5>Dynamic Questions & Answers</h5>';
                if (questions && questions.length > 0) { // Corrected: && to &&
                    questions.forEach(function(q) {
                        modalContentHtml += '<div class="form-group">';
                        modalContentHtml += '  <label for="dynamic_question_' + q.question_id + '">' + q.question_text + ' (' + q.question_title + ')</label>';
                        // For now, using select for Yes/No. Could be text inputs or other types based on question_type if available.
                        modalContentHtml += '  <select class="form-control" id="dynamic_question_' + q.question_id + '" name="dynamic_answers[' + q.question_id + ']">';
                        modalContentHtml += '    <option value="">-- Select Answer --</option>';
                        modalContentHtml += '    <option value="Yes"' + (q.client_answer === 'Yes' ? ' selected' : '') + '>Yes</option>';
                        modalContentHtml += '    <option value="No"' + (q.client_answer === 'No' ? ' selected' : '') + '>No</option>';
                        modalContentHtml += '  </select>';
                        modalContentHtml += '</div>';
                    });
                } else {
                    modalContentHtml += '<p>No dynamic questions configured for this site or no answers recorded.</p>';
                }
                
                // Add a hidden input for check_in_id within the form if not already present globally for the modal
                // This is redundant if $('#modalCheckInId').val(check_in_id); is used and modalCheckInId is inside the form.
                // modalContentHtml += '<input type="hidden" name="check_in_id" value="' + check_in_id + '" />';


                console.log('[DEBUG] Generated modalContentHtml:', modalContentHtml); // DEBUG
                $('#checkinDetailsModal .modal-body-content').html(modalContentHtml);
                $('#checkinDetailsModal').modal('show');
            } else {
                console.log('[DEBUG] AJAX response.success was false or response.data was missing. Response:', response); // DEBUG
                alert('Error: Could not fetch check-in details. ' + (response.message || 'Unknown error.'));
                $('#checkinDetailsModal .modal-body-content').html('<p class="text-danger">Failed to load details. Please try again.</p>');
            }
        },
        error: function(jqXHR, textStatus, errorThrown) {
            console.error("AJAX Error:", textStatus, errorThrown, jqXHR.responseText); // DEBUG: Added jqXHR.responseText
            alert('AJAX Error: Could not connect to the server or an error occurred. Check console for details.'); // DEBUG: Modified alert
            $('#checkinDetailsModal .modal-body-content').html('<p class="text-danger">Failed to load details due to a network or server error. Check console and try again.</p>'); // DEBUG: Modified message
        }
    });
}
