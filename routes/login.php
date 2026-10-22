<?php

require_once __DIR__ . "/../app/Support/db.php";
require_once __DIR__ . "/../app/Support/legacy_auth.php";

json_cors();

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    echo json_encode([
        "success" => false,
        "message" => "Only POST request is allowed"
    ]);
    exit;
}

$email = $_POST["email"] ?? "";
$password = $_POST["password"] ?? "";

if (empty($email) || empty($password)) {
    echo json_encode([
        "success" => false,
        "message" => "Email and password are required"
    ]);
    exit;
}

$sql = "SELECT id, name, email, password, role, is_active
        FROM users
        WHERE email = ?";

$stmt = $conn->prepare($sql);

$stmt->bind_param("s", $email);

$stmt->execute();

$result = $stmt->get_result();

if ($result->num_rows === 0) {
    // Unknown email â€” log with no user_id so failed attempts are still visible to admin
    log_activity($conn, null, null, "login_failed");

    echo json_encode([
        "success" => false,
        "message" => "Invalid email or password"
    ]);
    exit;
}

$user = $result->fetch_assoc();

if (!password_verify($password, $user["password"])) {
    log_activity($conn, $user["id"], $user["role"], "login_failed");

    echo json_encode([
        "success" => false,
        "message" => "Invalid email or password"
    ]);
    exit;
}

if ((int)$user["is_active"] === 0) {
    log_activity($conn, $user["id"], $user["role"], "login_blocked");

    echo json_encode([
        "success" => false,
        "message" => "Your account has been deactivated. Contact support."
    ]);
    exit;
}

// DATABASE role is the source of truth â€” session stores exactly
// what's in the users table, nothing the frontend sent.
$sessionUser = [
    "id" => $user["id"],
    "name" => $user["name"],
    "email" => $user["email"],
    "role" => $user["role"],
];

login_user($sessionUser);
$conn->query("UPDATE users SET last_active = NOW(), force_logout = 0 WHERE id = " . (int)$user["id"]);
log_activity($conn, $user["id"], $user["role"], "login_success");

echo json_encode([
    "success" => true,
    "message" => "Login successful",
    "user" => $sessionUser
]);

$stmt->close();
$conn->close();


