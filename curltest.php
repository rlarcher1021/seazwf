<?php
// --- Configuration ---
// Adjust this path if the script is not in the same directory as ajax_chat_handler.php
$iniFilePath = __DIR__ . '/../config/ai_chat_setting.ini'; 
// Adjust this if your n8n IF node expects a different header name for the API key
$apiKeyHeaderName = 'X-API-Key'; 
// --- End Configuration ---

// Variables to store results/errors
$message = '';
$curlError = '';
$httpCode = '';
$responseBody = '';
$configError = '';
$n8nWebhookUrl = '';
$n8nApiKey = '';

// --- Logic only runs when the button is pressed (POST request) ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['send_webhook'])) {

    // --- Step 1: Read Config ---
    if (!file_exists($iniFilePath) || !is_readable($iniFilePath)) {
        $configError = "ERROR: Cannot find or read config file at: " . htmlspecialchars($iniFilePath);
    } else {
        $config = parse_ini_file($iniFilePath);

        if ($config === false) {
            $configError = "ERROR: Failed to parse INI file: " . htmlspecialchars($iniFilePath);
        } else {
            // Check for specific keys
            if (isset($config['N8N_WEBHOOK_URL'])) {
                $n8nWebhookUrl = $config['N8N_WEBHOOK_URL'];
            } else {
                $configError .= "ERROR: 'N8N_WEBHOOK_URL' not found in INI file. ";
            }
            if (isset($config['N8N_WEBHOOK_SECRET'])) {
                $n8nApiKey = $config['N8N_WEBHOOK_SECRET'];
            } else {
                $configError .= "ERROR: 'N8N_WEBHOOK_SECRET' not found in INI file.";
            }
        }
    }

    // --- Step 2: Proceed only if config was read successfully ---
    if (empty($configError) && !empty($n8nWebhookUrl) && $n8nApiKey !== null) {
        
        // --- Step 3: Prepare Payload & Headers ---
        $payloadData = [
            'message' => 'Test webhook trigger from simple PHP button',
            'timestamp' => date('c'), // ISO 8601 timestamp
            'source' => 'php_button_test'
        ];
        $payloadJson = json_encode($payloadData);

        $headers = [
            'Content-Type: application/json',
            'Content-Length: ' . strlen($payloadJson),
            $apiKeyHeaderName . ': ' . $n8nApiKey // Add the API Key header
        ];

        // --- Step 4: Execute cURL Request ---
        $ch = curl_init($n8nWebhookUrl);

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payloadJson);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20); // Set a timeout (seconds)

        // --- !! SSL Debugging for XAMPP (Uncomment ONE of these lines if you suspect SSL issues) !! ---
        // Option A: Disable SSL Peer Verification (Quick test, LESS SECURE)
        // curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); 
        // Option B: Specify CA Bundle (More Secure - requires cacert.pem file)
        // curl_setopt($ch, CURLOPT_CAINFO, 'C:\xampp\php\extras\ssl\cacert.pem'); // <-- Adjust path to your cacert.pem

        $responseBody = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (!empty($curlError)) {
            $message = "cURL request failed.";
        } elseif ($httpCode >= 200 && $httpCode < 300) {
            $message = "Webhook seemingly sent successfully!";
        } else {
             $message = "Webhook request sent, but received a non-success HTTP status code.";
        }

    } else {
        // Config reading failed earlier
        $message = "Failed due to configuration errors.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>n8n Webhook Test</title>
    <style>
        body { font-family: sans-serif; line-height: 1.6; padding: 20px; }
        .results { margin-top: 20px; padding: 15px; border: 1px solid #ccc; background-color: #f9f9f9; }
        pre { background-color: #eee; padding: 10px; border-radius: 4px; white-space: pre-wrap; word-wrap: break-word; }
        .error { color: red; font-weight: bold; }
    </style>
</head>
<body>

    <h1>Simple n8n Webhook Test</h1>
    <p>This page will attempt to send a POST request to the n8n webhook URL configured in <code><?php echo htmlspecialchars($iniFilePath); ?></code> when you click the button.</p>
    <p>Make sure n8n is listening for a **Test Event** on the URL specified in the `.ini` file before clicking.</p>

    <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post">
        <button type="submit" name="send_webhook">Send Test Webhook</button>
    </form>

    <?php if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['send_webhook'])): ?>
    <div class="results">
        <h2>Results:</h2>
        <p><strong>Status:</strong> <?php echo htmlspecialchars($message); ?></p>
        
        <?php if (!empty($configError)): ?>
            <p class="error"><strong>Configuration Error:</strong> <?php echo htmlspecialchars($configError); ?></p>
        <?php endif; ?>

        <p><strong>Target URL Used:</strong> <?php echo htmlspecialchars($n8nWebhookUrl); ?></p>

        <?php if (empty($configError)): // Only show cURL details if config was ok ?>
            <p><strong>HTTP Status Code Received:</strong> <?php echo htmlspecialchars($httpCode); ?></p>
            <p><strong>cURL Error (if any):</strong> <span class="<?php echo !empty($curlError) ? 'error' : ''; ?>"><?php echo !empty($curlError) ? htmlspecialchars($curlError) : 'None'; ?></span></p>
            <p><strong>Response Body Received:</strong></p>
            <pre><?php echo !empty($responseBody) ? htmlspecialchars($responseBody) : '(No response body received or N/A)'; ?></pre>
        <?php endif; ?>
    </div>
    <?php endif; ?>

</body>
</html>