<?php

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

/**
 * Sends a transactional email using PHPMailer and settings from config.ini.
 *
 * @param string $toEmail Recipient's email address.
 * @param string $toName Recipient's name.
 * @param string $subject Email subject.
 * @param string $htmlBody HTML content of the email.
 * @param string $plainTextBody Optional plain text content. If empty, generated from HTML.
 * @return bool True on success, false on failure.
 */
function sendTransactionalEmail(string $toEmail, string $toName, string $subject, string $htmlBody, string $plainTextBody = '') : bool
{
    // 1. Include Composer Autoloader
    $autoloaderPath = __DIR__ . '/../vendor/autoload.php';
    if (!file_exists($autoloaderPath)) {
        error_log("Error: Composer autoloader not found at {$autoloaderPath}");
        return false;
    }
    require_once $autoloaderPath;

    // 2. Define and check config file path
    // Path is relative from /includes/email_utils.php up one level to /public_html, then down into /config/
    $configPath = __DIR__ . '/../../config/config.ini';
    if (!file_exists($configPath)) {
        error_log("Error: Configuration file not found at {$configPath}");
        return false;
    }

    // 3. Parse config file
    $config = parse_ini_file($configPath, true);
    if ($config === false) {
        error_log("Error: Could not parse configuration file at {$configPath}");
        return false;
    }
    if (!isset($config['smtp'])) {
        error_log("Error: [smtp] section not found in configuration file at {$configPath}");
        return false;
    }
    $smtpConfig = $config['smtp'];

    // 4. Instantiate PHPMailer
    $mail = new PHPMailer(true);

    try {
        // 5. Configure PHPMailer
        // Server settings
        // $mail->SMTPDebug = SMTP::DEBUG_SERVER; // Enable verbose debug output for testing
        $mail->SMTPDebug = 0; // Production: 0=off, 2=client/server messages
        $mail->isSMTP();
        $mail->Host       = $smtpConfig['host'] ?? '';
        $mail->SMTPAuth   = true; // Assuming SMTP Auth is always needed if section exists
        $mail->Username   = $smtpConfig['username'] ?? '';
        $mail->Password   = $smtpConfig['password'] ?? '';

        // Encryption: Infer SMTPSecure based on port or explicit setting (defaulting to SMTPS for port 465)
        if (isset($smtpConfig['encryption']) && !empty($smtpConfig['encryption'])) {
             $mail->SMTPSecure = strtolower($smtpConfig['encryption']) === 'tls' ? PHPMailer::ENCRYPTION_STARTTLS : PHPMailer::ENCRYPTION_SMTPS;
        } elseif (isset($smtpConfig['port']) && (int)$smtpConfig['port'] === 587) {
             $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS; // Common port for TLS
        } else {
             $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS; // Default assumption for port 465 or unspecified
        }
        $mail->Port       = isset($smtpConfig['port']) ? (int)$smtpConfig['port'] : ($mail->SMTPSecure === PHPMailer::ENCRYPTION_STARTTLS ? 587 : 465); // Default port based on inferred encryption

        // Recipients
        $mail->setFrom($smtpConfig['from_email'] ?? '', $smtpConfig['from_name'] ?? 'Arizona@Work');
        $mail->addAddress($toEmail, $toName);

        // Content
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $htmlBody;
        // Generate AltBody if not provided or empty
        $mail->AltBody = !empty($plainTextBody) ? $plainTextBody : strip_tags($htmlBody);

        // 6. Send Email
        $mail->send();
        // Optional: Log success if needed
        // error_log("Message sent successfully to {$toEmail}");
        return true;

    } catch (Exception $e) {
        // 7. Catch exceptions and log errors
        error_log("Mailer Error sending to [{$toEmail}] with subject [{$subject}]: " . $mail->ErrorInfo);
        return false;
    }
}

?>