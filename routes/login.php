<?php

ob_start();

// CORS headers - Local and Netlify
$allowedOrigins = [
    "http://localhost:5173",
    "https://dazzling-horse-b7bcb0.netlify.app"
];

$origin = $_SERVER["HTTP_ORIGIN"] ?? "";

if (in_array($origin, $allowedOrigins, true)) {
    header("Access-Control-Allow-Origin: " . $origin);
    header("Vary: Origin");
}

header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Credentials: true");
header("Content-Type: application/json; charset=UTF-8");

// Handle browser preflight request
if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(204);
    ob_end_clean();
    exit;
}

// Include backend files
require_once __DIR__ . "/../app/Support/db.php";
require_once __DIR__ . "/../app/Support/legacy_auth.php";

// JSON response helper
function send_json($data, $status = 200)
{
    http_response_code($status);

    if (ob_get_level() > 0 && ob_get_length() !== false) {
        ob_clean();
    }

    echo json_encode($data);
    exit;
}

// Allow POST requests only
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    send_json([
        "success" => false,
        "message" => "Only POST request is allowed"
    ], 405);
}

// Read request data
$input = $_POST;

if (empty($input)) {
    $rawInput = file_get_contents("php://input");
    $jsonInput = json_decode($rawInput, true);

    if (is_array($jsonInput)) {
        $input = $jsonInput;
    }
}

// Get email and password
$email = trim($input["email"] ?? "");
$password = $input["password"] ?? "";

if ($email === "" || $password === "") {
    send_json([
        "success" => false,
        "message" => "Email and password are required"
    ], 400);
}

// Check database connection
if (!isset($conn) || !($conn instanceof mysqli)) {
    error_log("Login error: Database connection is unavailable.");

    send_json([
        "success" => false,
        "message" => "Database connection failed"
    ], 500);
}

// Find user by email
$sql = "SELECT id, name, email, password, role, is_active
        FROM users
        WHERE email = ?";

$stmt = $conn->prepare($sql);

if (!$stmt) {
    error_log("Login SQL prepare error: " . $conn->error);

    send_json([
        "success" => false,
        "message" => "Unable to process login"
    ], 500);
}

$stmt->bind_param("s", $email);

if (!$stmt->execute()) {
    error_log("Login SQL execute error: " . $stmt->error);

    $stmt->close();

    send_json([
        "success" => false,
        "message" => "Unable to process login"
    ], 500);
}

$result = $stmt->get_result();

// Check whether the user exists
if ($result->num_rows === 0) {
    if (function_exists("log_activity")) {
        log_activity(
            $conn,
            null,
            null,
            "login_failed"
        );
    }

    $stmt->close();

    send_json([
        "success" => false,
        "message" => "Invalid email or password"
    ], 401);
}

$user = $result->fetch_assoc();

// Verify password
if (!password_verify($password, $user["password"])) {
    if (function_exists("log_activity")) {
        log_activity(
            $conn,
            $user["id"],
            $user["role"],
            "login_failed"
        );
    }

    $stmt->close();

    send_json([
        "success" => false,
        "message" => "Invalid email or password"
    ], 401);
}

// Check whether the account is active
if ((int) $user["is_active"] === 0) {
    if (function_exists("log_activity")) {
        log_activity(
            $conn,
            $user["id"],
            $user["role"],
            "login_blocked"
        );
    }

    $stmt->close();

    send_json([
        "success" => false,
        "message" => "Your account has been deactivated. Contact support."
    ], 403);
}

// Prepare authenticated user data
$sessionUser = [
    "id" => $user["id"],
    "name" => $user["name"],
    "email" => $user["email"],
    "role" => $user["role"]
];

// Create login session
login_user($sessionUser);
session_regenerate_id(true);

// Update last active time
$update = $conn->prepare(
    "UPDATE users
     SET last_active = NOW(), force_logout = 0
     WHERE id = ?"
);

if ($update) {
    $userId = (int) $user["id"];

    $update->bind_param("i", $userId);
    $update->execute();
    $update->close();
} else {
    error_log("Login activity update error: " . $conn->error);
}

// Record successful login
if (function_exists("log_activity")) {
    log_activity(
        $conn,
        $user["id"],
        $user["role"],
        "login_success"
    );
}

// Close database resources
$stmt->close();
$conn->close();

// Send successful response
send_json([
    "success" => true,
    "message" => "Login successful",
    "user" => $sessionUser,
    "token" => session_id()
]);
