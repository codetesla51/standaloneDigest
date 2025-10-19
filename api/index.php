<?php
$allowed_origin = 'https://devuthman.vercel.app/';
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';

if ($origin === $allowed_origin) {
    header('Access-Control-Allow-Origin: ' . $allowed_origin);
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Accept');
}

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
            body { margin: 0; padding: 0; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #0f0f0f; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
            .header { background: linear-gradient(135deg, #1a1a1a 0%, #2d2d2d 100%); padding: 30px; border-radius: 12px 12px 0 0; text-align: center; border-bottom: 3px solid #00d4ff; }
            .header h1 { color: #00d4ff; margin: 0; font-size: 24px; font-weight: 600; }
            .header p { color: #aaa; margin: 8px 0 0 0; font-size: 13px; }
            .content { background: #1a1a1a; padding: 30px; border: 1px solid #333; border-top: none; }
            .info-block { background: #252525; border-left: 4px solid #00d4ff; padding: 15px; margin: 20px 0; border-radius: 6px; }
            .info-label { color: #00d4ff; font-weight: 600; font-size: 12px; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 4px; }
            .info-value { color: #e0e0e0; font-size: 14px; word-break: break-word; }
            .message-block { background: #2a2a2a; padding: 20px; border-radius: 8px; border: 1px solid #404040; margin: 20px 0; }
            .message-label { color: #00d4ff; font-weight: 600; font-size: 12px; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 12px; display: block; }
            .message-text { color: #d0d0d0; font-size: 14px; line-height: 1.6; white-space: pre-wrap; word-wrap: break-word; }
            .meta { color: #888; font-size: 12px; margin-top: 15px; border-top: 1px solid #404040; padding-top: 10px; }
            .footer { background: #252525; padding: 20px; text-align: center; border-radius: 0 0 12px 12px; border: 1px solid #333; border-top: none; }
            .reply-btn { display: inline-block; background: linear-gradient(135deg, #00d4ff 0%, #00a8cc 100%); color: #000; padding: 12px 30px; border-radius: 6px; text-decoration: none; font-weight: 600; font-size: 14px; margin: 15px 0; transition: all 0.3s ease; box-shadow: 0 4px 15px rgba(0, 212, 255, 0.3); }
            .reply-btn:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(0, 212, 255, 0.5); }
            .footer-text { color: #888; font-size: 12px; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h1>New Contact Submission</h1>
                <p>You received a new message</p>
            </div>
            <div class='content'>
                <div class='info-block'>
                    <div class='info-label'>From</div>
                    <div class='info-value'>{$name}</div>
                </div>

                <div class='info-block'>
                    <div class='info-label'>Email Address</div>
                    <div class='info-value'><a href='mailto:{$sender_email}' style='color: #00d4ff; text-decoration: none;'>{$sender_email}</a></div>
                </div>

                <div class='info-block'>
                    <div class='info-label'>Inquiry Type</div>
                    <div class='info-value'>{$inquiry}</div>
                </div>

                <div class='message-block'>
                    <span class='message-label'>Message</span>
                    <div class='message-text'>{$message}</div>
                    <div class='meta'>Submitted on {$formatted_time}</div>
                </div>

                <div style='text-align: center; margin-top: 30px;'>
                    <a href='{$reply_link}' class='reply-btn'>Reply to {$name}</a>
                </div>
            </div>
            <div class='footer'>
                <p class='footer-text'>This is an automated notification from your contact form</p>
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
            <div style='background: #252525; border: 1px solid #333; border-left: 4px solid #00d4ff; padding: 20px; margin: 15px 0; border-radius: 8px;'>
                <div style='display: flex; justify-content: space-between; align-items: start; margin-bottom: 15px;'>
                    <div>
                        <div style='color: #00d4ff; font-weight: 600; font-size: 14px;'>{$contact["name"]}</div>
                        <div style='color: #888; font-size: 12px; margin-top: 4px;'>{$formatted_time}</div>
                    </div>
                </div>

                <div style='background: #1a1a1a; padding: 12px; border-radius: 6px; margin: 12px 0; border-left: 3px solid #404040;'>
                    <div style='color: #aaa; font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 6px;'>Email</div>
                    <div style='color: #e0e0e0; font-size: 13px;'><a href='mailto:{$contact["email"]}' style='color: #00d4ff; text-decoration: none;'>{$contact["email"]}</a></div>
                </div>

                <div style='background: #1a1a1a; padding: 12px; border-radius: 6px; margin: 12px 0; border-left: 3px solid #404040;'>
                    <div style='color: #aaa; font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 6px;'>Inquiry Type</div>
                    <div style='color: #e0e0e0; font-size: 13px;'>{$contact["inquiry"]}</div>
                </div>

                <div style='background: #1a1a1a; padding: 12px; border-radius: 6px; margin: 12px 0; border-left: 3px solid #404040;'>
                    <div style='color: #aaa; font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 6px;'>Message</div>
                    <div style='color: #d0d0d0; font-size: 13px; line-height: 1.5; white-space: pre-wrap;'>{$contact["message"]}</div>
                </div>

                <div style='text-align: center; margin-top: 12px;'>
                    <a href='{$reply_link}' style='display: inline-block; background: linear-gradient(135deg, #00d4ff 0%, #00a8cc 100%); color: #000; padding: 8px 20px; border-radius: 5px; text-decoration: none; font-weight: 600; font-size: 12px; transition: all 0.3s ease;'>Reply</a>
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
            body { margin: 0; padding: 0; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #0f0f0f; }
            .container { max-width: 650px; margin: 0 auto; padding: 20px; }
            .header { background: linear-gradient(135deg, #1a1a1a 0%, #2d2d2d 100%); padding: 35px; border-radius: 12px 12px 0 0; text-align: center; border-bottom: 3px solid #00d4ff; }
            .header h1 { color: #00d4ff; margin: 0; font-size: 28px; font-weight: 600; }
            .header p { color: #aaa; margin: 12px 0 0 0; font-size: 14px; }
            .stats { display: flex; justify-content: center; gap: 30px; margin-top: 15px; padding-top: 15px; border-top: 1px solid #404040; }
            .stat { text-align: center; }
            .stat-number { color: #00d4ff; font-size: 28px; font-weight: 700; }
            .stat-label { color: #888; font-size: 11px; text-transform: uppercase; letter-spacing: 1px; margin-top: 4px; }
            .content { background: #1a1a1a; padding: 30px; border: 1px solid #333; border-top: none; }
            .footer { background: #252525; padding: 20px; text-align: center; border-radius: 0 0 12px 12px; border: 1px solid #333; border-top: none; }
            .footer-text { color: #888; font-size: 12px; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h1>Daily Contact Digest</h1>
                <p>{$today}</p>
                <div class='stats'>
                    <div class='stat'>
                        <div class='stat-number'>{$total}</div>
                        <div class='stat-label'>Submissions</div>
                    </div>
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
