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
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(200);
            // Ensure sensitive data like password_hash is not included
            unset($client['password_hash']);
            echo json_encode($client, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        } else {
            // 5. Send Not Found Response
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
    // 1. Parse and Validate Query Parameters (Permission check moved to index.php router)
    $params = [];
    $filtersProvided = false;

    if (!empty($requestQueryParams['name'])) {
        $params['name'] = trim($requestQueryParams['name']);
        $filtersProvided = true;
    }
    if (!empty($requestQueryParams['email'])) {
        // Basic email format check, can be enhanced
        if (filter_var($requestQueryParams['email'], FILTER_VALIDATE_EMAIL)) {
             $params['email'] = trim($requestQueryParams['email']);
             $filtersProvided = true;
        } else {
             sendJsonError(400, "Bad Request: Invalid 'email' format provided.", 'INVALID_INPUT');
             return;
        }
    }
    if (!empty($requestQueryParams['qr_identifier'])) {
        // Add validation if qr_identifier has a specific format (e.g., UUID)
        $params['qr_identifier'] = trim($requestQueryParams['qr_identifier']);
        $filtersProvided = true;
    }

    // Require at least one search filter
    if (!$filtersProvided) {
        sendJsonError(400, "Bad Request: At least one search filter (name, email, or qr_identifier) is required.", 'MISSING_FILTER');
        return;
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

        header('Content-Type: application/json; charset=utf-8');
        http_response_code(200);
        echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

    } catch (PDOException $e) {
        // 6. Handle Database Errors
        error_log("Database Error in handleSearchClients: " . $e->getMessage());
        sendJsonError(500, "Internal Server Error: Could not perform client search.", 'DB_ERROR');
    } catch (Exception $e) {
        // Catch any other unexpected errors
        error_log("Unexpected Error in handleSearchClients: " . $e->getMessage());
        sendJsonError(500, "Internal Server Error: An unexpected error occurred during search.", 'UNEXPECTED_ERROR');
    }
}

?>