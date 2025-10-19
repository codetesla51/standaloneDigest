<?php

header('Access-Control-Allow-Origin: https://devuthman.vercel.app/');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Accept');
header('Access-Control-Allow-Credentials: true');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}
error_reporting(E_ALL);
ini_set("display_errors", 1);

require __DIR__ . "/../vendor/autoload.php";
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use Dotenv\Dotenv;

try {
  $dotenv_path = __DIR__ . '/../.env';
if (file_exists($dotenv_path)) {
    $dotenv = Dotenv::createImmutable(__DIR__ . '/..');
    $dotenv->load();
}
  // Load environment variables
  $db_url = $_ENV["DATABASE_URL"] ?? null;
  $gmail_email = $_ENV["GMAIL_EMAIL"] ?? null;
  $gmail_app_password = $_ENV["GMAIL_APP_PASSWORD"] ?? null;
  $reply_email = $_ENV["REPLY_EMAIL"] ?? $gmail_email;

  if (!$db_url || !$gmail_email || !$gmail_app_password) {
    throw new Exception("Missing environment variables");
  }

  // Parse PostgreSQL URL
  $db_parts = parse_url($db_url);
  $dsn =
    "pgsql:host=" .
    $db_parts["host"] .
    ";port=" .
    ($db_parts["port"] ?? 5432) .
    ";dbname=" .
    ltrim($db_parts["path"], "/") .
    ";user=" .
    $db_parts["user"] .
    ";password=" .
    $db_parts["pass"];

  // Database connection
  $pdo = new PDO($dsn);
} catch (Exception $e) {
  http_response_code(500);
  echo json_encode(["error" => "Startup error: " . $e->getMessage()]);
  die();
}

// Determine request type
$action = $_GET["action"] ?? ($_POST["action"] ?? "form");

if ($action === "form" && $_SERVER["REQUEST_METHOD"] === "POST") {
  handleFormSubmission($pdo, $gmail_email, $gmail_app_password, $reply_email);
} elseif ($action === "digest") {
  sendDailyDigest($pdo, $gmail_email, $gmail_app_password, $reply_email);
} else {
  http_response_code(400);
  echo json_encode(["error" => "Invalid action"]);
}

function handleFormSubmission($pdo, $email, $password, $reply_email)
{
  try {
    $name = sanitize($_POST["name"] ?? "");
    $sender_email = sanitize($_POST["email"] ?? "");
    $inquiry = sanitize($_POST["inquiry"] ?? "");
    $message = sanitize($_POST["message"] ?? "");

    if (!$name || !$sender_email || !$inquiry || !$message) {
      http_response_code(400);
      echo json_encode(["error" => "All fields required"]);
      return;
    }

    if (!filter_var($sender_email, FILTER_VALIDATE_EMAIL)) {
      http_response_code(400);
      echo json_encode(["error" => "Invalid email"]);
      return;
    }

    // Save to database
    $stmt = $pdo->prepare(
      "INSERT INTO contacts (name, email, inquiry, message, created_at, updated_at) VALUES (?, ?, ?, ?, NOW(), NOW())"
    );
    $stmt->execute([$name, $sender_email, $inquiry, $message]);

    // Send immediate notification email
    sendNotificationEmail(
      $email,
      $password,
      $name,
      $sender_email,
      $inquiry,
      $message,
      $reply_email
    );

    http_response_code(200);
    echo json_encode(["success" => "Message received and notification sent"]);
  } catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["error" => $e->getMessage()]);
  }
}

function sendDailyDigest($pdo, $email, $password, $reply_email)
{
  try {
    // Get all contacts from today
    $stmt = $pdo->prepare(
      "SELECT * FROM contacts WHERE DATE(created_at) = CURRENT_DATE ORDER BY created_at DESC"
    );
    $stmt->execute();
    $contacts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($contacts)) {
      http_response_code(200);
      echo json_encode(["message" => "No contacts today"]);
      return;
    }

    // Build email body
    $html_body = buildDigestHTML($contacts, $reply_email);

    // Send digest email
    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host = "smtp.gmail.com";
    $mail->SMTPAuth = true;
    $mail->Username = $email;
    $mail->Password = $password;
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port = 587;

    $mail->setFrom($email, "Contact Form");
    $mail->addAddress($email);
    $mail->isHTML(true);
    $mail->Subject = "Daily Contact Digest - " . date("Y-m-d");
    $mail->Body = $html_body;

    $mail->send();

    http_response_code(200);
    echo json_encode([
      "success" => "Daily digest sent",
      "count" => count($contacts),
    ]);
  } catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["error" => $e->getMessage()]);
  }
}

function sendNotificationEmail(
  $email,
  $password,
  $name,
  $sender_email,
  $inquiry,
  $message,
  $reply_email
) {
  $mail = new PHPMailer(true);
  $mail->isSMTP();
  $mail->Host = "smtp.gmail.com";
  $mail->SMTPAuth = true;
  $mail->Username = $email;
  $mail->Password = $password;
  $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
  $mail->Port = 587;

  $mail->setFrom($email, "Contact Form");
  $mail->addAddress($email);
  $mail->isHTML(true);
  $mail->Subject = "New Contact: {$inquiry}";
  $mail->Body = buildNotificationHTML(
    $name,
    $sender_email,
    $inquiry,
    $message,
    $reply_email
  );

  $mail->send();
}

function buildNotificationHTML(
  $name,
  $sender_email,
  $inquiry,
  $message,
  $reply_email
) {
  $formatted_time = date('M d, Y \a\t H:i A');
  $reply_subject = urlencode("Re: {$inquiry}");
  $reply_link = "mailto:{$sender_email}?subject={$reply_subject}";

  return "
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset='UTF-8'>
        <meta name='viewport' content='width=device-width, initial-scale=1.0'>
        <style>
            @import url('https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700&display=swap');
            body { margin: 0; padding: 0; font-family: 'Poppins', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f5f5f5; }
            .container { max-width: 600px; margin: 40px auto; background: #ffffff; }
            .header { padding: 48px 40px 32px; border-bottom: 2px solid #000000; }
            .header h1 { color: #000000; margin: 0 0 8px 0; font-size: 28px; font-weight: 700; letter-spacing: -0.5px; }
            .header p { color: #666666; margin: 0; font-size: 15px; font-weight: 400; }
            .content { padding: 40px; }
            .info-row { margin-bottom: 32px; }
            .label { color: #000000; font-weight: 600; font-size: 12px; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 8px; display: block; }
            .value { color: #000000; font-size: 16px; line-height: 1.5; padding: 16px; background: #fafafa; border-left: 3px solid #000000; }
            .value a { color: #000000; text-decoration: underline; }
            .message-box { background: #fafafa; padding: 24px; border-left: 3px solid #000000; margin: 32px 0; }
            .message-text { color: #000000; font-size: 15px; line-height: 1.7; white-space: pre-wrap; word-wrap: break-word; margin: 0; }
            .meta { color: #999999; font-size: 13px; margin-top: 16px; padding-top: 16px; border-top: 1px solid #e0e0e0; }
            .button-container { text-align: center; margin: 40px 0; }
            .reply-btn { display: inline-block; background: #000000; color: #ffffff; padding: 16px 48px; text-decoration: none; font-weight: 600; font-size: 15px; border: 2px solid #000000; transition: all 0.2s; }
            .reply-btn:hover { background: #ffffff; color: #000000; }
            .footer { background: #fafafa; padding: 32px; text-align: center; border-top: 1px solid #e0e0e0; }
            .footer-text { color: #999999; font-size: 13px; margin: 0; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h1>New Contact Submission</h1>
                <p>You have received a new message</p>
            </div>
            <div class='content'>
                <div class='info-row'>
                    <span class='label'>From</span>
                    <div class='value'>{$name}</div>
                </div>

                <div class='info-row'>
                    <span class='label'>Email Address</span>
                    <div class='value'><a href='mailto:{$sender_email}'>{$sender_email}</a></div>
                </div>

                <div class='info-row'>
                    <span class='label'>Inquiry Type</span>
                    <div class='value'>{$inquiry}</div>
                </div>

                <div class='info-row'>
                    <span class='label'>Message</span>
                    <div class='message-box'>
                        <div class='message-text'>{$message}</div>
                        <div class='meta'>Received on {$formatted_time}</div>
                    </div>
                </div>

                <div class='button-container'>
                    <a href='{$reply_link}' class='reply-btn'>Reply to {$name}</a>
                </div>
            </div>
            <div class='footer'>
                <p class='footer-text'>Automated notification from your contact form</p>
            </div>
        </div>
    </body>
    </html>
    ";
}

function buildDigestHTML($contacts, $reply_email)
{
  $contact_cards = "";
  foreach ($contacts as $contact) {
    $formatted_time = date("M d, Y H:i A", strtotime($contact["created_at"]));
    $reply_subject = urlencode("Re: " . $contact["inquiry"]);
    $reply_link = "mailto:{$contact["email"]}?subject={$reply_subject}";

    $contact_cards .= "
            <div style='background: #ffffff; border: 2px solid #000000; padding: 32px; margin: 24px 0;'>
                <div style='margin-bottom: 24px; padding-bottom: 16px; border-bottom: 1px solid #e0e0e0;'>
                    <div style='color: #000000; font-weight: 700; font-size: 18px; margin-bottom: 4px;'>{$contact["name"]}</div>
                    <div style='color: #999999; font-size: 13px;'>{$formatted_time}</div>
                </div>

                <div style='margin: 16px 0;'>
                    <div style='color: #000000; font-weight: 600; font-size: 11px; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 6px;'>Email</div>
                    <div style='color: #000000; font-size: 14px;'><a href='mailto:{$contact["email"]}' style='color: #000000; text-decoration: underline;'>{$contact["email"]}</a></div>
                </div>

                <div style='margin: 16px 0;'>
                    <div style='color: #000000; font-weight: 600; font-size: 11px; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 6px;'>Inquiry Type</div>
                    <div style='color: #000000; font-size: 14px;'>{$contact["inquiry"]}</div>
                </div>

                <div style='margin: 16px 0;'>
                    <div style='color: #000000; font-weight: 600; font-size: 11px; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 6px;'>Message</div>
                    <div style='background: #fafafa; padding: 16px; border-left: 3px solid #000000;'>
                        <div style='color: #000000; font-size: 14px; line-height: 1.6; white-space: pre-wrap;'>{$contact["message"]}</div>
                    </div>
                </div>

                <div style='text-align: center; margin-top: 24px; padding-top: 24px; border-top: 1px solid #e0e0e0;'>
                    <a href='{$reply_link}' style='display: inline-block; background: #000000; color: #ffffff; padding: 12px 32px; text-decoration: none; font-weight: 600; font-size: 14px; border: 2px solid #000000;'>Reply</a>
                </div>
            </div>
        ";
  }

  $total = count($contacts);
  $today = date("l, F j, Y");

  return "
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset='UTF-8'>
        <meta name='viewport' content='width=device-width, initial-scale=1.0'>
        <style>
            @import url('https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700&display=swap');
            body { margin: 0; padding: 0; font-family: 'Poppins', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f5f5f5; }
            .container { max-width: 700px; margin: 40px auto; background: #ffffff; }
            .header { padding: 48px 40px; border-bottom: 2px solid #000000; text-align: center; }
            .header h1 { color: #000000; margin: 0 0 12px 0; font-size: 32px; font-weight: 700; letter-spacing: -0.5px; }
            .header p { color: #666666; margin: 0 0 24px 0; font-size: 16px; }
            .stats { display: inline-block; background: #000000; color: #ffffff; padding: 16px 32px; }
            .stat-number { font-size: 36px; font-weight: 700; margin-bottom: 4px; }
            .stat-label { font-size: 12px; text-transform: uppercase; letter-spacing: 1px; opacity: 0.8; }
            .content { padding: 40px; }
            .footer { background: #fafafa; padding: 32px; text-align: center; border-top: 1px solid #e0e0e0; }
            .footer-text { color: #999999; font-size: 13px; margin: 0; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h1>Daily Contact Digest</h1>
                <p>{$today}</p>
                <div class='stats'>
                    <div class='stat-number'>{$total}</div>
                    <div class='stat-label'>Total Submissions</div>
                </div>
            </div>
            <div class='content'>
                {$contact_cards}
            </div>
            <div class='footer'>
                <p class='footer-text'>Daily digest report from your contact form system</p>
            </div>
        </div>
    </body>
    </html>
    ";
}

function sanitize($input)
{
  return htmlspecialchars(trim($input), ENT_QUOTES, "UTF-8");
}