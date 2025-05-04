<?php

/**
 * API Handler for Client Endpoints
 *
 * Handles requests related to client data, such as fetching individual clients
 * or searching for clients based on criteria.
 */

require_once __DIR__ . '/../../../includes/data_access/client_data.php'; // Provides getClientById, searchClientsApi
require_once __DIR__ . '/../includes/auth_functions.php'; // Provides checkApiKeyPermission
require_once __DIR__ . '/../includes/error_handler.php'; // Provides sendJsonError

/**
 * Handles GET requests for /api/v1/clients/{client_id}.
 * Fetches a single client by their ID.
 *
 * @param PDO $pdo The PDO database connection object.
 * @param array $requestPathParams Associative array of path parameters (e.g., ['client_id' => '123']).
 * @param array $apiKeyData Authenticated API key data (including permissions).
 * @return void Outputs JSON response directly.
 */
function handleGetClientById(PDO $pdo, array $requestPathParams, array $apiKeyData): void
{
    header('Content-Type: application/json; charset=utf-8'); // Set header early

    // 1. Extract and Validate Client ID (Permission check moved to index.php router)
    if (!isset($requestPathParams['client_id'])) {
         sendJsonError(400, "Bad Request: Missing client_id path parameter.", 'INVALID_INPUT');
         return;
    }
    $clientId = filter_var($requestPathParams['client_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($clientId === false) {
        sendJsonError(400, "Bad Request: Invalid client_id. Must be a positive integer.", 'INVALID_INPUT');
        return; // Exit function
    }

    // 3. Fetch Client Data
    try {
        $client = getClientById($pdo, $clientId);

        if ($client) {
            // 4. Send Success Response
            http_response_code(200);
            // Ensure sensitive data like password_hash is not included
            unset($client['password_hash']);
            try {
                ob_clean(); // Clear any previous output buffer
                echo json_encode($client, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                exit; // Terminate script after successful JSON output
            } catch (JsonException $e) {
                error_log("JSON Encode Error in handleGetClientById for client ID {$clientId}: " . $e->getMessage());
                // Don't call sendJsonError here if headers might already be sent, just log.
                // A partially sent response might already exist. Best effort is to log.
                // If header wasn't sent yet, sendJsonError could be used, but safer to just log.
            }
        } else {
            // 5. Send Not Found Response
            // Header already set at the top
            sendJsonError(404, "Not Found: Client with ID {$clientId} not found.", 'RESOURCE_NOT_FOUND');
        }
    } catch (PDOException $e) {
        // 6. Handle Database Errors
        error_log("Database Error in handleGetClientById for client ID {$clientId}: " . $e->getMessage());
        sendJsonError(500, "Internal Server Error: Could not retrieve client data.", 'DB_ERROR');
    } catch (Exception $e) {
        // Catch any other unexpected errors
        error_log("Unexpected Error in handleGetClientById for client ID {$clientId}: " . $e->getMessage());
        sendJsonError(500, "Internal Server Error: An unexpected error occurred.", 'UNEXPECTED_ERROR');
    }
}

/**
 * Handles GET requests for /api/v1/clients.
 * Searches for clients based on query parameters.
 *
 * @param PDO $pdo The PDO database connection object.
 * @param array $requestQueryParams Associative array of query parameters ($_GET).
 * @param array $apiKeyData Authenticated API key data (including permissions).
 * @return void Outputs JSON response directly.
 */
function handleSearchClients(PDO $pdo, array $requestQueryParams, array $apiKeyData): void
{
    header('Content-Type: application/json; charset=utf-8'); // Set header early

    // 1. Parse and Validate Query Parameters (Permission check moved to index.php router)
    // Refined check for required parameters
    $hasName = !empty($requestQueryParams['name']); // Keep for potential fallback/other uses, though not primary focus
    $hasFirstName = !empty($requestQueryParams['firstName']);
    $hasLastName = !empty($requestQueryParams['lastName']);
    $hasValidEmail = !empty($requestQueryParams['email']) && filter_var(trim($requestQueryParams['email']), FILTER_VALIDATE_EMAIL);
    $hasQr = !empty($requestQueryParams['qr_identifier']);

    // Check if an invalid email format was provided *if* the email parameter exists but is invalid
    if (!empty($requestQueryParams['email']) && !$hasValidEmail) {
        sendJsonError(400, "Bad Request: Invalid 'email' format provided.", 'INVALID_INPUT');
        return;
    }

    // Require at least one valid search filter (firstName AND lastName counts as one logical filter)
    $hasAnyFilter = $hasName || $hasValidEmail || $hasQr || ($hasFirstName && $hasLastName); // Focus on the AND case for first/last name
    // Note: Cases with only firstName or only lastName are not explicitly handled as required filters per instructions.

    if (!$hasAnyFilter) {
        // Updated error message to reflect new options
        sendJsonError(400, "Bad Request: At least one valid search filter (name, email, qr_identifier, or both firstName and lastName) is required.", 'MISSING_FILTER');
        return;
    }

    // Populate params based on valid filters found
    $params = [];
    if ($hasFirstName) { // Prioritize firstName/lastName if provided
        $params['firstName'] = trim($requestQueryParams['firstName']);
    }
    if ($hasLastName) { // Prioritize firstName/lastName if provided
        $params['lastName'] = trim($requestQueryParams['lastName']);
    }
    // Include other filters if they are also present (though the DAL will prioritize first/last name AND logic)
    if ($hasName) {
        $params['name'] = trim($requestQueryParams['name']);
    }
    if ($hasValidEmail) {
        // Use the already trimmed and validated email
        $params['email'] = trim($requestQueryParams['email']);
    }
    if ($hasQr) {
        // Add validation if qr_identifier has a specific format (e.g., UUID) later if needed
        $params['qr_identifier'] = trim($requestQueryParams['qr_identifier']);
    }

    // Pagination parameters
    $page = filter_var($requestQueryParams['page'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'default' => 1]]);
    $limit = filter_var($requestQueryParams['limit'] ?? 10, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100, 'default' => 10]]); // Max limit 100

    if ($page === false || $limit === false) {
        sendJsonError(400, "Bad Request: Invalid 'page' or 'limit' parameter. Must be positive integers.", 'INVALID_PAGINATION');
        return;
    }

    // 3. Search Clients
    try {
        // Assuming searchClientsApi returns ['clients' => [...], 'total_count' => X]
        $result = searchClientsApi($pdo, $params, $page, $limit);

        if ($result === null || !isset($result['clients']) || !isset($result['total_count'])) {
             // Handle case where DAL returns unexpected format or error indication
             error_log("Error in handleSearchClients: searchClientsApi returned unexpected format.");
             sendJsonError(500, "Internal Server Error: Failed to retrieve client search results.", 'DAL_ERROR');
             return;
        }

        $clients = $result['clients'];
        $totalItems = $result['total_count'];

        // Remove sensitive data like password_hash from results
        foreach ($clients as &$client) {
            unset($client['password_hash']);
        }
        unset($client); // Unset reference

        // 4. Calculate Pagination
        $totalPages = ($limit > 0) ? (int)ceil($totalItems / $limit) : 0;

        // 5. Format and Send Response
        $response = [
            'pagination' => [
                'total_items' => $totalItems,
                'total_pages' => $totalPages,
                'current_page' => $page,
                'limit' => $limit, // Use 'limit' instead of 'per_page' for consistency with request param
            ],
            'clients' => $clients,
        ];

        http_response_code(200);
        try {
            ob_clean(); // Clear any previous output buffer
            echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            exit; // Terminate script after successful JSON output
        } catch (JsonException $e) {
             error_log("JSON Encode Error in handleSearchClients: " . $e->getMessage());
             // Don't call sendJsonError here if headers might already be sent, just log.
        }

    } catch (PDOException $e) {
        // 6. Handle Database Errors
        // Header already set at the top
        error_log("Database Error in handleSearchClients: " . $e->getMessage());
        sendJsonError(500, "Internal Server Error: Could not perform client search.", 'DB_ERROR');
    } catch (Exception $e) {
        // Catch any other unexpected errors
        error_log("Unexpected Error in handleSearchClients: " . $e->getMessage());
        sendJsonError(500, "Internal Server Error: An unexpected error occurred during search.", 'UNEXPECTED_ERROR');
    }
}

?>