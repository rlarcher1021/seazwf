<?php
// --- Client Session Handling ---
// Use a distinct session name to avoid conflicts with staff sessions
if (session_status() == PHP_SESSION_NONE) {
    session_name("CLIENT_SESSION"); // Unique name for client sessions
    session_start();
}

// --- CSRF Protection ---
// Generate CSRF token if one doesn't exist
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// --- Database Connection ---
require_once 'includes/db_connect.php'; // Assumes $pdo is available here

$error_message = '';
$username_value = ''; // To repopulate username field on error

// --- Form Submission Logic ---
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Verify CSRF token
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $error_message = "Invalid request. Please try again.";
        // Optionally log this attempt
    } else {
        // --- Input Retrieval and Sanitization ---
        $username = isset($_POST['username']) ? trim($_POST['username']) : '';
        $password = isset($_POST['password']) ? trim($_POST['password']) : '';
        $username_value = htmlspecialchars($username); // For repopulating form

        // --- Server-Side Validation ---
        if (empty($username) || empty($password)) {
            $error_message = "Username and password are required.";
        } else {
            try {
                // --- Database Query ---
                // Assuming a 'clients' table exists as per Living Plan/Task Context
                // Columns needed: client_id, username, first_name, password_hash
                $sql = "SELECT id, username, first_name, password_hash, client_qr_identifier
                        FROM clients
                        WHERE username = :username AND deleted_at IS NULL";
                $stmt = $pdo->prepare($sql);
                $stmt->bindParam(':username', $username, PDO::PARAM_STR);
                $stmt->execute();
                $client = $stmt->fetch(PDO::FETCH_ASSOC);

                // --- Password Verification ---
                if ($client && password_verify($password, $client['password_hash'])) {
                    // --- Login Success ---
                    // Regenerate session ID for security
                    session_regenerate_id(true); 

                    // Store client data in the specific client session using the session name as the key
                    $_SESSION['CLIENT_SESSION'] = [
                        'client_id' => $client['id'],
                        'username' => $client['username'],
                        'first_name' => $client['first_name'],
                        'qr_identifier' => $client['client_qr_identifier'] // Store the QR identifier
                    ];

                    // Clear CSRF token after successful login
                    unset($_SESSION['csrf_token']);

                    // Redirect to client portal dashboard
                    header("Location: client_portal/profile.php"); // Redirect to client profile page
                    exit;

                } else {
                    // --- Login Failure ---
                    $error_message = "Invalid username or password.";
                }

            } catch (PDOException $e) {
                // Log the error properly in a real application
                error_log("Database error during client login: " . $e->getMessage()); // Log the specific PDOException
                $error_message = "An error occurred. Please try again later.";
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Client Login - Arizona@Work</title>
    <!-- Bootstrap CSS -->
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css" integrity="sha384-JcKb8q3iqJ61gNV9KGb8thSsNjpSL0n8PARn9HuZOnIxN0hoP+VmmDGMN5t9UJ0Z" crossorigin="anonymous">
    <link rel="stylesheet" href="assets/css/client.css"> <!-- Link to custom client CSS -->
    <!-- Font Awesome CSS -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css" integrity="sha512-1ycn6IcaQQ40/MKBW2W4Rhis/DbILU74C1vSrLJxCq57o941Ym01SwNsOMqvEBFlcgUa6xLiPY/NS5R+E6ztJQ==" crossorigin="anonymous" referrerpolicy="no-referrer" />
    <style>
        body {
            background-color: #f8f9fa;
        }
        .login-container {
            max-width: 400px;
            margin: 50px auto;
            padding: 30px;
            background-color: #ffffff;
            border-radius: 8px;
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }
        .login-header {
            text-align: center;
            margin-bottom: 25px;
        }
        .form-group label {
            font-weight: bold;
        }
        .btn-login {
            background-color: #007bff; /* Or Arizona@Work brand color */
            border-color: #007bff;
        }
        .register-link {
            display: block;
            text-align: center;
            margin-top: 15px;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-logo text-center mb-4">
            <img src="assets/img/logo.jpg" alt="Arizona@Work Logo" class="img-fluid login-img-max-width">
        </div>
        <div class="client-form-area"> <!-- Added wrapper similar to index.php's login-form-container -->
            <div class="login-header">
                <h2>Client Login</h2>
                <p>Arizona@Work Check-In System</p>
            </div>

            <?php if (!empty($error_message)): ?>
                <div class="alert alert-danger" role="alert">
                    <?php echo htmlspecialchars($error_message); ?>
                </div>
            <?php endif; ?>

            <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post">
                <!-- CSRF Token -->
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">

                <div class="form-group">
                    <label for="username">Username</label>
                    <input type="text" class="form-control" id="username" name="username" value="<?php echo $username_value; ?>" required>
                </div>
                <div class="form-group">
                    <label for="password">Password</label>
                    <input type="password" class="form-control" id="password" name="password" required>
                </div>
                <button type="submit" class="btn btn-primary btn-block btn-login">Login</button>
            </form>
        </div> <!-- End client-form-area -->

        <div class="client-footer-area"> <!-- Added wrapper similar to index.php's login-footer -->
            <a href="client_register.php" class="register-link">Don't have an account? Register here.</a>
        </div> <!-- End client-footer-area -->
    </div> <!-- End login-container -->

    <!-- Bootstrap JS and dependencies -->
    <script src="https://code.jquery.com/jquery-3.5.1.slim.min.js" integrity="sha384-DfXdz2htPH0lsSSs5nCTpuj/zy4C+OGpamoFVy38MVBnE+IbbVYUew+OrCXaRkfj" crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.6/dist/umd/popper.min.js" integrity="sha384-oBqDVmMz9ATKxIep9tiCxS/Z9fNfEXiDAYTujMAeBAsjFuCZSmKbSSUnQlmh/jp3" crossorigin="anonymous"></script>
    <script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js" integrity="sha384-B4gt1jrGC7Jh4AgTPSdUtOBvfO8shuf57BaghqFfPlYxofvL8/KUEfYiJOMMV+rV" crossorigin="anonymous"></script>
    <script src="assets/js/main.js"></script> <!-- For password toggle -->
</body>
</html>