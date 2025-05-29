document.addEventListener('DOMContentLoaded', function () {
    const qrReaderElement = document.getElementById('qr-reader');
    const qrResultElement = document.getElementById('qr-reader-results');
    const csrfTokenMeta = document.querySelector('meta[name="csrf-token"]');
    let html5QrCode; // Declare scanner instance variable

    if (!qrReaderElement) {
        console.error("QR Reader element (#qr-reader) not found.");
        return;
    }
    if (!qrResultElement) {
        console.error("QR Reader results element (#qr-reader-results) not found.");
        return;
    }
    if (!csrfTokenMeta || !csrfTokenMeta.content) {
        console.error("CSRF token meta tag not found or empty.");
        // Optionally display an error to the user
        qrResultElement.innerHTML = '<div class="alert alert-danger">Configuration error: Cannot proceed securely.</div>';
        return;
    }

    const csrfToken = csrfTokenMeta.content;

    function onScanSuccess(decodedText, decodedResult) {
        // Handle the scanned code.
        console.log(`Code matched = ${decodedText}`, decodedResult);
        qrResultElement.innerHTML = `<div class="alert alert-info">Processing scan...</div>`;

        // Validate the URL format
        const expectedPrefix = '/kiosk/qr_checkin?cid='; // Assuming this is the path on the *same domain*
        let clientIdentifier = null;

        try {
            // Check if it's a full URL or just the path
            let urlToParse;
            if (decodedText.startsWith('http://') || decodedText.startsWith('https://')) {
                 urlToParse = new URL(decodedText);
                 // Optional: Check if hostname matches current site if needed
                 // if (urlToParse.hostname !== window.location.hostname) { ... }
                 // Allow both /kiosk/qr_checkin and /kiosk/qr_checkin.php
                 if ((urlToParse.pathname === '/kiosk/qr_checkin' || urlToParse.pathname === '/kiosk/qr_checkin.php') && urlToParse.searchParams.has('cid')) {
                     clientIdentifier = urlToParse.searchParams.get('cid');
                 }
            } else if (decodedText.startsWith(expectedPrefix)) {
                 // Handle relative path case
                 // Need to construct a dummy base URL to use URLSearchParams correctly
                 const dummyBase = 'http://dummy.com';
                 const fullDummyUrl = new URL(decodedText, dummyBase);
                 if (fullDummyUrl.pathname === '/kiosk/qr_checkin' && fullDummyUrl.searchParams.has('cid')) {
                    clientIdentifier = fullDummyUrl.searchParams.get('cid');
                 }
            }


            if (clientIdentifier) {
                console.log("Extracted Client Identifier:", clientIdentifier);

                // Stop the scanner briefly to show processing/result
                if (html5QrCode && html5QrCode.isScanning) {
                    html5QrCode.stop().then(() => {
                        console.log("QR Code scanning stopped for processing.");
                        sendCheckinRequest(clientIdentifier);
                    }).catch(err => {
                        console.error("Failed to stop QR scanner:", err);
                        // Still attempt to send request even if stop fails
                        sendCheckinRequest(clientIdentifier);
                    });
                } else {
                     sendCheckinRequest(clientIdentifier); // Send if already stopped or instance unavailable
                }

            } else {
                console.warn("Scanned QR code does not match expected format or missing 'cid'. Text:", decodedText);
                qrResultElement.innerHTML = `<div class="alert alert-warning">Invalid QR Code scanned. Please use the official Arizona@Work QR code.</div>`;
                 // Optionally restart scanner after a delay if stopped
                 setTimeout(startScanner, 3000); // Restart after 3 seconds on invalid code
            }
        } catch (e) {
            console.error("Error parsing scanned URL:", e);
            qrResultElement.innerHTML = `<div class="alert alert-danger">Error processing scanned code. Please try again.</div>`;
            // Optionally restart scanner after a delay if stopped
             setTimeout(startScanner, 3000); // Restart after 3 seconds on error
        }
    }

    function onScanFailure(error) {
        // Handle scan failure, usually better to ignore and keep scanning.
        // console.warn(`Code scan error = ${error}`);
        // qrResultElement.innerHTML = `<div class="alert alert-light">Scanning... Align QR code.</div>`; // Can be noisy
    }

    function sendCheckinRequest(identifier) {
        // Determine the base path for the API URL.
        // This allows flexibility for different deployment environments (e.g., localhost/subfolder vs. root on live).
        // Define window.APP_BASE_URL_PATH in your main HTML (e.g., checkin.php) if a subfolder is used.
        // Example for localhost: <script>window.APP_BASE_URL_PATH = '/public_html';</script>
        // Example for live (root): <script>window.APP_BASE_URL_PATH = '';</script> or simply don't define it.
        // Determine the base path for the API URL.
        // This allows flexibility for different deployment environments.
        let basePath = typeof window.APP_BASE_URL_PATH === 'string' ? window.APP_BASE_URL_PATH.trim() : '';

        // Ensure basePath is correctly formatted as a path segment (e.g., "" or "/subfolder")
        // An empty basePath means the app is at the root.
        if (basePath) { // If basePath is not an empty string (i.e., app is in a subfolder)
            if (!basePath.startsWith('/')) {
                basePath = '/' + basePath; // Ensure leading slash
            }
            if (basePath.endsWith('/')) {
                basePath = basePath.slice(0, -1); // Remove trailing slash
            }
        }
        // Now, basePath is either "" (for root) or like "/subfolder" (no trailing slash).

        const endpointPath = '/kiosk/qr_checkin.php'; // Path to the actual PHP handler from the app's base
        
        // fullPath will be like "/kiosk/qr_checkin.php" or "/subfolder/kiosk/qr_checkin.php"
        const fullPath = basePath + endpointPath; 

        // Construct the absolute URL using the current host's origin.
        // This ensures the AJAX request goes to the same server that served the page,
        // regardless of <base href> tags or other ambiguities.
        const apiUrl = window.location.origin + fullPath;

        // Enhanced logging for clarity
        console.log('Kiosk.js - window.APP_BASE_URL_PATH (raw input):', typeof window.APP_BASE_URL_PATH !== 'undefined' ? window.APP_BASE_URL_PATH : '[not set]');
        console.log('Kiosk.js - Processed basePath for URL construction:', basePath);
        console.log('Kiosk.js - Final constructed apiUrl:', apiUrl);

        fetch(apiUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': csrfToken // Send CSRF token in header
            },
            body: JSON.stringify({ qr_identifier: identifier })
        })
        .then(response => {
            if (!response.ok) {
                // Try to get error message from JSON body if possible
                return response.json().then(errData => {
                    throw new Error(errData.message || `HTTP error! Status: ${response.status}`);
                }).catch(() => {
                    // Fallback if response is not JSON or doesn't have message
                     throw new Error(`HTTP error! Status: ${response.status}`);
                });
            }
            return response.json();
        })
        .then(data => {
            console.log("Check-in Response:", data);
            // Corrected check from data.success to data.status === 'success' to match PHP response
            if (data.status === 'success' && data.message) {
                qrResultElement.innerHTML = `<div class="alert alert-success">${escapeHtml(data.message)}</div>`;
                // Optionally redirect or clear form after success? Keep kiosk ready for next scan.
                // Consider restarting scanner after a longer delay
                 setTimeout(startScanner, 5000); // Restart after 5 seconds on success
            } else {
                 qrResultElement.innerHTML = `<div class="alert alert-danger">${escapeHtml(data.message || 'Check-in failed. Unknown error.')}</div>`;
                 // Restart scanner sooner on failure
                 setTimeout(startScanner, 3000);
            }
        })
        .catch(error => {
            console.error('Error sending check-in request:', error);
            qrResultElement.innerHTML = `<div class="alert alert-danger">Error communicating with server: ${escapeHtml(error.message)}. Please try again.</div>`;
             // Restart scanner sooner on failure
             setTimeout(startScanner, 3000);
        });
    }

    // Simple HTML escaping function
    function escapeHtml(unsafe) {
        if (!unsafe) return '';
        return unsafe
             .replace(/&/g, "&amp;")
             .replace(/</g, "&lt;")
             .replace(/>/g, "&gt;")
             .replace(/"/g, "&quot;")
             .replace(/'/g, "&#039;");
     }


    function startScanner() {
         // Ensure scanner instance exists and is not already scanning
         if (html5QrCode && typeof html5QrCode.getState === 'function' && html5QrCode.getState() === Html5QrcodeScannerState.SCANNING) {
            // console.log("Scanner already running."); // Log removed
            return;
         }

         // Log dimensions for debugging - REMOVED
         // if (qrReaderElement) {
         //    console.log("QR Reader Element:", qrReaderElement);
         //    console.log("QR Reader offsetWidth:", qrReaderElement.offsetWidth, "offsetHeight:", qrReaderElement.offsetHeight);
         //    if (qrReaderElement.parentElement) {
         //        console.log("QR Reader Parent offsetWidth:", qrReaderElement.parentElement.offsetWidth, "offsetHeight:", qrReaderElement.parentElement.offsetHeight);
         //        // Assuming the parent is the 'camera-column'
         //        const cameraColumn = qrReaderElement.closest('.camera-column');
         //        if (cameraColumn) {
         //            console.log("Camera Column offsetWidth:", cameraColumn.offsetWidth, "offsetHeight:", cameraColumn.offsetHeight);
         //        } else {
         //            console.log("Camera column not found as parent.");
         //        }
         //    }
         // }

         // Check if camera permission was previously denied or is unavailable
         Html5Qrcode.getCameras().then(cameras => {
            if (!cameras || cameras.length === 0) {
                qrResultElement.innerHTML = `<div class="alert alert-warning">No cameras found or access denied. Please ensure camera access is allowed in browser settings.</div>`;
                console.warn("No cameras found or access denied.");
                return; // Don't try to start if no cameras
            }

            // Attempt to start the scanner
            qrResultElement.innerHTML = 'Please align the QR code within the frame.'; // Reset message
            const qrBoxConfig = { width: 250, height: 250 };
            // console.log("Starting scanner with qrbox config:", qrBoxConfig); // Log removed
            html5QrCode.start(
                { facingMode: "environment" }, // Use rear camera if available
                {
                    fps: 10,                // Optional frame rate
                    qrbox: qrBoxConfig // Optional scan box size
                },
                onScanSuccess,
                onScanFailure
            ).then(() => {
                 console.log("QR Code scanning started/restarted.");
            }).catch(err => {
                console.error("Unable to start scanning.", err);
                let errorMsg = err.message || err;
                // Provide more specific feedback if possible
                if (errorMsg.includes('Permission denied') || errorMsg.includes('NotAllowedError')) {
                     qrResultElement.innerHTML = `<div class="alert alert-danger">Camera permission denied. Please allow camera access in your browser settings and refresh the page.</div>`;
                } else if (errorMsg.includes('Requested device not found') || errorMsg.includes('NotFoundError')) {
                     qrResultElement.innerHTML = `<div class="alert alert-warning">Camera not found. Is it connected and enabled?</div>`;
                } else {
                    qrResultElement.innerHTML = `<div class="alert alert-danger">Error starting camera: ${escapeHtml(errorMsg)}. Please ensure camera access is allowed.</div>`;
                }
            });

         }).catch(err => {
             console.error("Error getting camera list:", err);
             qrResultElement.innerHTML = `<div class="alert alert-danger">Could not access camera devices. Please check permissions and connections.</div>`;
         });
    }

    // Initialize the scanner instance
    // Check if Html5Qrcode is available (CDN loaded)
    if (typeof Html5Qrcode !== 'undefined') {
        html5QrCode = new Html5Qrcode("qr-reader");
        startScanner(); // Initial start
    } else {
        console.error("Html5Qrcode library not loaded. Cannot initialize scanner.");
        qrResultElement.innerHTML = '<div class="alert alert-danger">QR Scanner library failed to load. Please check your internet connection and refresh.</div>';
    }

});