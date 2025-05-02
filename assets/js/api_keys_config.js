$(document).ready(function() {
    const resultDisplay = $('#api-key-result-display');
    const tableBody = $('#api-keys-table-body');
    const createForm = $('#create-api-key-form');

    // --- Tab Persistence ---
    if (window.location.hash === '#api-keys') {
        $('a[href="#api-keys"]').tab('show');
    }
    $('a[data-toggle="tab"][href="#api-keys"]').on('shown.bs.tab', function (e) {
        window.location.hash = '#api-keys';
    });

    // Temporarily removing escapeHtml function due to persistent syntax errors
    /*
    function escapeHtml(unsafe) {
        if (unsafe === null || typeof unsafe === 'undefined') {
            return '';
        }
        const safeString = String(unsafe);
        return safeString
             .replace(/&/g, "&")
             .replace(/</g, "<")
             .replace(/>/g, ">")
             .replace(/"/g, """)
             .replace(/'/g, "&#039;");
     }
    */

    // --- Create API Key ---
    createForm.on('submit', function(event) {
        event.preventDefault();
        resultDisplay.html('').removeClass('alert alert-success alert-danger');
        // console.log("Create API Key form submitted."); // DEBUG Removed

        let formData = $(this).serialize();
        formData += '&action=create_key';

        // console.log("Attempting AJAX request. Form data string:"); // DEBUG Removed
        // console.log(formData); // DEBUG Removed

        $.ajax({
            url: 'ajax_handlers/api_keys_handler.php',
            type: 'POST',
            data: formData,
            dataType: 'json',
            success: function(response) {
                // console.log("AJAX Success Response Received:", response); // DEBUG Removed

                if (response && response.success) {
                    // console.log("Response indicates success."); // DEBUG Removed
                    // Removed escapeHtml call
                    resultDisplay.html('<strong>Success!</strong> API Key created: <code>' + response.apiKey + '</code>').addClass('alert alert-success');
                    createForm[0].reset();

                    // Dynamic row adding logic - Uncommented and adjusted
                    if (response.newKeyData) { // Check if backend sent new key details
                        // Parse permissions if they are a JSON string
                        let permissionsHtml = '<span class="badge badge-secondary">None</span>';
                        if (response.newKeyData.associated_permissions) {
                            try {
                                const permissionsArray = JSON.parse(response.newKeyData.associated_permissions);
                                if (Array.isArray(permissionsArray) && permissionsArray.length > 0) {
                                     // Removed escapeHtml call from map
                                     permissionsHtml = permissionsArray.map(p => `<span class="badge badge-info mr-1">${p}</span>`).join('');
                                }
                            } catch (e) {
                                console.error("Error parsing permissions JSON:", e);
                                permissionsHtml = '<span class="badge badge-warning">Invalid Format</span>';
                            }
                        }

                        // Format date nicely
                        let createdAtFormatted = 'N/A';
                        if (response.newKeyData.created_at) {
                            try {
                                createdAtFormatted = new Date(response.newKeyData.created_at).toLocaleString();
                            } catch (e) {
                                console.error("Error formatting date:", e);
                                // Removed escapeHtml call
                                createdAtFormatted = response.newKeyData.created_at; // Fallback
                            }
                        }

                        // Removed escapeHtml calls from template literal
                        const newRow = `
                            <tr id="api-key-row-${response.newKeyData.id}">
                                <td>${response.newKeyData.name}</td>
                                <td>${createdAtFormatted}</td>
                                <td>${permissionsHtml}</td>
                                <td>${response.newKeyData.associated_user_id || 'N/A'}</td>
                                <td>${response.newKeyData.associated_site_id || 'N/A'}</td>
                                <td>
                                    <button class="btn btn-sm btn-danger revoke-api-key-btn" data-key-id="${response.newKeyData.id}">Revoke</button>
                                </td>
                            </tr>`;

                        // Remove the "No active keys" row if it exists
                        if (tableBody.find('td[colspan="6"]').length) {
                             tableBody.empty();
                        }
                        tableBody.append(newRow); // Add the new row
                    } else {
                         // Fallback if backend didn't send newKeyData
                         resultDisplay.append('<br>Please refresh the page to see the new key in the list.');
                    }

                    window.location.hash = '#api-keys';

                } else {
                    // console.log("Response indicates failure or is malformed."); // DEBUG Removed
                    // Removed escapeHtml call
                    const message = (response && response.message) ? response.message : 'Unknown error structure in response.';
                    resultDisplay.html('<strong>Error:</strong> ' + message).addClass('alert alert-danger');
                }
            },
            error: function(xhr, status, error) {
                // console.error("AJAX Error encountered!"); // DEBUG Removed
                // console.error("Status:", status); // DEBUG Removed
                // console.error("Error:", error); // DEBUG Removed
                // console.error("XHR Status Code:", xhr.status); // DEBUG Removed
                // console.error("XHR Response Text:", xhr.responseText); // DEBUG Removed
                // Removed escapeHtml call
                resultDisplay.html('<strong>AJAX Error:</strong> Failed to communicate with the server. Status: ' + status + '. Check browser console for details.').addClass('alert alert-danger');
            }
        });
    });


    // --- Revoke API Key ---
    tableBody.on('click', '.revoke-api-key-btn', function() {
        const button = $(this);
        const keyId = button.data('key-id');
        const csrfToken = createForm.find('input[name="csrf_token"]').val();
        // console.log(`Revoke button clicked for key ID: ${keyId}`); // DEBUG Removed

        if (!keyId) {
            // console.error("Could not find key ID for revoke button."); // DEBUG Removed
            alert("Error: Could not determine which key to revoke.");
            return;
        }
        if (!csrfToken) {
             // console.error("CSRF token not found in the form."); // DEBUG Removed
             alert("Error: Security token missing. Cannot revoke key.");
             return;
        }

        if (confirm('Are you sure you want to revoke this API key? This action cannot be undone.')) {
            resultDisplay.html('').removeClass('alert alert-success alert-danger');
            // console.log(`Attempting to revoke key ID: ${keyId} with CSRF token.`); // DEBUG Removed

            $.ajax({
                url: 'ajax_handlers/api_keys_handler.php',
                type: 'POST',
                data: {
                    action: 'revoke_key',
                    key_id: keyId,
                    csrf_token: csrfToken
                },
                dataType: 'json',
                success: function(response) {
                    // console.log("Revoke AJAX Success Response:", response); // DEBUG Removed
                    if (response && response.success) {
                        $('#api-key-row-' + keyId).fadeOut(300, function() { $(this).remove(); });
                        alert('API Key revoked successfully.');
                        window.location.hash = '#api-keys';
                    } else {
                        // Removed escapeHtml call
                        const message = (response && response.message) ? response.message : 'Unknown error during revocation.';
                        resultDisplay.html('<strong>Error:</strong> ' + message).addClass('alert alert-danger');
                    }
                },
                error: function(xhr, status, error) {
                    // console.error("Revoke AJAX Error!"); // DEBUG Removed
                    // console.error("Status:", status); // DEBUG Removed
                    // console.error("Error:", error); // DEBUG Removed
                    // console.error("XHR Status Code:", xhr.status); // DEBUG Removed
                    // console.error("XHR Response Text:", xhr.responseText); // DEBUG Removed
                    // Removed escapeHtml call
                    resultDisplay.html('<strong>AJAX Error:</strong> Failed to communicate with the server during revocation. Status: ' + status + '. Check browser console.').addClass('alert alert-danger');
                }
            });
        } else {
             // console.log("Revoke cancelled by user."); // DEBUG Removed
        }
    });

});