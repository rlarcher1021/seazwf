<?php

/**
 * Sends a standardized JSON error response and terminates the script.
 *
 * @param int $statusCode The HTTP status code (e.g., 400, 401, 403, 404, 500).
 * @param string $message A human-readable error message.
 * @param ?string $errorCode An optional internal error code string (e.g., "AUTH_FAILED").
 * @return void
 */
function sendJsonError(int $statusCode, string $message, ?string $errorCode = null): void
{
    // Ensure no prior output interferes
    if (headers_sent()) {
        // Log this issue if possible, as it indicates a problem elsewhere
        error_log("Error handler called after headers already sent.");
        // Still try to output something, though it might be broken
    }

    // Set headers
    header_remove('Set-Cookie'); // Don't leak session cookies on API errors
    header('Content-Type: application/json; charset=utf-8');
    http_response_code($statusCode);

    // Prepare error body
    $errorBody = ['message' => $message];
    if ($errorCode !== null) {
        $errorBody['code'] = $errorCode;
    }

    // Output JSON
    echo json_encode(['error' => $errorBody], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

    // Terminate script
    exit;
}

?>