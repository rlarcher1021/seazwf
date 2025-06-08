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

// --- Initialize Password Toggle Functionality ---
    // function initializePasswordToggle() {
    //     const passwordToggleGroups = document.querySelectorAll('.password-toggle-group');
    //
    //     passwordToggleGroups.forEach(group => {
    //         const passwordInput = group.querySelector('input[type="password"], input[type="text"]'); // Also select text if already toggled
    //         const toggleButton = group.querySelector('.toggle-password-button');
    //         const icon = toggleButton ? toggleButton.querySelector('i') : null;
    //
    //         if (passwordInput && toggleButton && icon) {
    //             toggleButton.addEventListener('click', function() {
    //                 // Toggle the type attribute
    //                 const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
    //                 passwordInput.setAttribute('type', type);
    //
    //                 // Toggle the icon
    //                 if (type === 'password') {
    //                     icon.classList.remove('fa-eye-slash');
    //                     icon.classList.add('fa-eye');
    //                     toggleButton.setAttribute('aria-label', 'Show password');
    //                 } else {
    //                     icon.classList.remove('fa-eye');
    //                     icon.classList.add('fa-eye-slash');
    //                     toggleButton.setAttribute('aria-label', 'Hide password');
    //                 }
    //             });
    //         }
    //     });
    // }
    //
    // // Call the function to set up password toggles
    // initializePasswordToggle();
}); // End DOMContentLoaded

// Function to open and populate the check-in details modal
// Moved outside DOMContentLoaded to be globally accessible
function openCheckinDetailsModal(check_in_id) {
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
            if (response.success && response.data) { // Corrected: && to &&
                const details = response.data.check_in_details;
                const questions = response.data.dynamic_questions;
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


                $('#checkinDetailsModal .modal-body-content').html(modalContentHtml);
                $('#checkinDetailsModal').modal('show');
            } else {
                alert('Error: Could not fetch check-in details. ' + (response.message || 'Unknown error.'));
                $('#checkinDetailsModal .modal-body-content').html('<p class="text-danger">Failed to load details. Please try again.</p>');
            }
        },
        error: function(jqXHR, textStatus, errorThrown) {
            console.error("AJAX Error:", textStatus, errorThrown, jqXHR.responseText);
            alert('AJAX Error: Could not connect to the server or an error occurred.');
            $('#checkinDetailsModal .modal-body-content').html('<p class="text-danger">Failed to load details due to a network or server error.</p>');
        }
    });
}

// --- Client Editor Modal ---
    $(document).on('click', '.edit-client-btn', function() {
        const clientId = $(this).data('client-id');
        const modal = $('#editClientModal');
        const modalBody = modal.find('#editClientModalFormContent');
        const modalTitle = modal.find('.modal-title');

        // Show loading state
        modalTitle.text('Loading Client...');
        modalBody.html('<p class="text-center"><i class="fas fa-spinner fa-spin"></i> Loading details...</p>');

        $.ajax({
            url: 'ajax_handlers/get_client_details_handler.php',
            type: 'GET',
            data: { client_id: clientId },
            dataType: 'json',
            success: function(response) {
                if (response.success && response.data) {
                    const profile = response.data.profile;
                    const answers = response.data.answers; // This is now an object like { question_id: "Yes" }
                    const questions = response.data.questions;
                    const sites = response.data.sites;

                    // --- Build Form HTML ---
                    let formHtml = `
                        <form id="editClientForm" action="client_editor.php" method="POST">
                            <input type="hidden" name="action" value="save_client">
                            <input type="hidden" name="client_id" value="${profile.id}">

                            <h5>Client Details</h5>
                            <div class="form-row">
                                <div class="form-group col-md-6">
                                    <label for="first_name">First Name</label>
                                    <input type="text" class="form-control" id="first_name" name="first_name" value="${escapeHTML(profile.first_name)}" required>
                                </div>
                                <div class="form-group col-md-6">
                                    <label for="last_name">Last Name</label>
                                    <input type="text" class="form-control" id="last_name" name="last_name" value="${escapeHTML(profile.last_name)}" required>
                                </div>
                            </div>
                            <div class="form-row">
                                <div class="form-group col-md-6">
                                    <label>Username</label>
                                    <input type="text" class="form-control" value="${escapeHTML(profile.username)}" readonly>
                                </div>
                                <div class="form-group col-md-6">
                                    <label>Email</label>
                                    <input type="email" class="form-control" value="${escapeHTML(profile.email)}" readonly>
                                </div>
                            </div>
                            <div class="form-row">
                                <div class="form-group col-md-6">
                                    <label for="site_id">Primary Site</label>
                                    <select class="form-control" id="site_id" name="site_id" required>
                                        <option value="">-- Select Site --</option>
                                        ${sites.map(site => `<option value="${site.id}" ${profile.site_id == site.id ? 'selected' : ''}>${escapeHTML(site.name)}</option>`).join('')}
                                    </select>
                                </div>
                                <div class="form-group col-md-6 d-flex align-items-center pt-3">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="email_preference_jobs" name="email_preference_jobs" value="1" ${profile.email_preference_jobs == 1 ? 'checked' : ''}>
                                        <label class="form-check-label" for="email_preference_jobs">Opt-in to emails</label>
                                    </div>
                                </div>
                            </div>
                            <hr>
                            <h5>Dynamic Questions</h5>
                    `;

                    if (Array.isArray(questions) && questions.length > 0) {
                        questions.forEach(q => {
                            const currentAnswer = answers[q.id] || ''; // Directly use the answers object
                            const inputName = `dynamic_answers[${q.id}]`;
                            formHtml += `
                                <div class="form-group">
                                    <label>${escapeHTML(q.question_title)}: ${escapeHTML(q.question_text)}</label>
                                    <div>
                                        <div class="form-check form-check-inline">
                                            <input class="form-check-input" type="radio" name="${inputName}" id="q_${q.id}_yes" value="Yes" ${currentAnswer === 'Yes' ? 'checked' : ''}>
                                            <label class="form-check-label" for="q_${q.id}_yes">Yes</label>
                                        </div>
                                        <div class="form-check form-check-inline">
                                            <input class="form-check-input" type="radio" name="${inputName}" id="q_${q.id}_no" value="No" ${currentAnswer === 'No' ? 'checked' : ''}>
                                            <label class="form-check-label" for="q_${q.id}_no">No</label>
                                        </div>
                                    </div>
                                </div>
                            `;
                        });
                    } else {
                        formHtml += '<p>No site-specific questions configured.</p>';
                    }

                    formHtml += '</form>'; // Close form tag

                    // --- Update Modal ---
                    modalTitle.text(`Edit Client: ${escapeHTML(profile.first_name)} ${escapeHTML(profile.last_name)}`);
                    modalBody.html(formHtml);

                    // --- Update Footer ---
                    // Correctly target the buttons in the modal footer by their actual IDs
                    const qrButton = $('#btnViewClientQrCode');
                    const saveButton = $('#saveClientChangesBtn');

                    // The QR Code button is now handled by a dedicated click event listener.
                    // Remove any previous click handlers and attach a new one for saving
                    saveButton.off('click').on('click', function() {
                        const form = $('#editClientForm');
                        
                        // Manually build the data object to ensure CSRF is included correctly
                        const formData = form.serializeArray();
                        const dataObj = {};
                        $(formData).each(function(i, field){
                            dataObj[field.name] = field.value;
                        });
                        
                        // Explicitly read the CSRF token from the hidden input on the main page
                        const csrfToken = $('#csrf_token_hidden').val();
                        dataObj['csrf_token'] = csrfToken;

                        // Optional: Add loading state to the save button
                        $(this).prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Saving...');

                        $.ajax({
                            url: 'ajax_handlers/update_client_details_handler.php',
                            type: 'POST',
                            data: dataObj,
                            dataType: 'json',
                            success: function(response) {
                                saveButton.prop('disabled', false).html('Save Changes'); // Reset button
                                if (response.success) {
                                    $('#editClientModal').modal('hide');
                                    // Use a more noticeable success message if possible (e.g., a toast notification)
                                    alert(response.message || 'Client details updated successfully!');
                                    // Refresh the page to show the updated data in the table
                                    location.reload();
                                } else {
                                    // Display error message inside the modal for better context
                                    alert('Error: ' + (response.message || 'Could not save changes.'));
                                }
                            },
                            error: function(jqXHR) {
                                saveButton.prop('disabled', false).html('Save Changes'); // Reset button
                                console.error("AJAX Error:", jqXHR.responseText);
                                alert('An AJAX error occurred. Please check the console for details.');
                            }
                        });
                    });


                } else {
                    modalTitle.text('Error');
                    modalBody.html(`<div class="alert alert-danger">${response.message || 'Could not retrieve client details.'}</div>`);
                }
            },
            error: function(jqXHR) {
                modalTitle.text('Error');
                modalBody.html(`<div class="alert alert-danger">An AJAX error occurred. ${jqXHR.responseJSON ? jqXHR.responseJSON.message : 'Please check console.'}</div>`);
                console.error("AJAX Error:", jqXHR.responseText);
            }
        });
    });

    // --- Client Editor QR Code Button ---
    $(document).on('click', '#btnViewClientQrCode', function(e) {
        e.preventDefault(); // Prevent default link behavior

        // Find the client_id from the hidden input within the currently visible modal form
        const clientId = $('#editClientForm input[name="client_id"]').val();

        if (clientId) {
            const qrUrl = `client_portal/qr_code.php?client_id=${clientId}`;
            window.open(qrUrl, '_blank'); // Open the URL in a new tab
        } else {
            console.error('Could not find client ID in the form.');
            alert('Error: Could not find the Client ID to generate a QR code.');
        }
    });

    // Helper function to prevent XSS
    function escapeHTML(str) {
        if (str === null || str === undefined) {
            return '';
        }
        return str.toString()
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }
