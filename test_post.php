<?php
// Simple test script to verify POST requests
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Log all requests to this script
error_log("test_post.php accessed via " . $_SERVER['REQUEST_METHOD']);

// If this is a POST request, log the POST data
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    error_log("POST data received in test_post.php: " . print_r($_POST, true));
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>POST Test</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        .container { max-width: 800px; margin: 0 auto; }
        .form-group { margin-bottom: 15px; }
        label { display: block; margin-bottom: 5px; }
        input, button { padding: 8px; }
        .data-display { background: #f5f5f5; padding: 15px; border-radius: 5px; margin-top: 20px; }
        pre { white-space: pre-wrap; }
    </style>
</head>
<body>
    <div class="container">
        <h1>POST Request Test</h1>
        
        <?php if ($_SERVER['REQUEST_METHOD'] === 'POST'): ?>
            <div class="data-display">
                <h2>Received POST Data:</h2>
                <pre><?php echo htmlspecialchars(print_r($_POST, true)); ?></pre>
            </div>
        <?php endif; ?>
        
        <h2>Test Form</h2>
        <form method="POST" action="test_post.php">
            <div class="form-group">
                <label for="test_field">Test Field:</label>
                <input type="text" id="test_field" name="test_field" value="test value">
            </div>
            <div class="form-group">
                <label for="action">Action (simulating ads panel):</label>
                <input type="text" id="action" name="action" value="test_action">
            </div>
            <div class="form-group">
                <button type="submit">Submit Test Form</button>
            </div>
        </form>
        
        <h2>Instructions</h2>
        <ol>
            <li>Click the "Submit Test Form" button above</li>
            <li>The page should refresh and show the submitted data at the top</li>
            <li>Check the error log for entries starting with "test_post.php accessed" and "POST data received"</li>
        </ol>
        
        <p><a href="configurations.php?tab=ads-management">Return to Ads Management</a></p>
    </div>
</body>
</html>