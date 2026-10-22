<?php

require_once __DIR__ . "/../app/Support/db.php";
require_once __DIR__ . "/../app/Support/legacy_auth.php";

json_cors();

$user = current_user();

if (!$user) {
    echo json_encode([
        "success" => false,
        "message" => "Not logged in"
    ]);
    exit;
}

$uid = (int)$user["id"];
$chk = $conn->query("SELECT force_logout FROM users WHERE id = " . $uid);
$row = $chk ? $chk->fetch_assoc() : null;

if ($row && (int)$row["force_logout"] === 1) {
    $conn->query("UPDATE users SET force_logout = 0, last_active = NULL WHERE id = " . $uid);
    logout_user();
    echo json_encode([
        "success" => false,
        "message" => "You were logged out by admin."
    ]);
    exit;
}

$conn->query("UPDATE users SET last_active = NOW() WHERE id = " . $uid);

echo json_encode([
    "success" => true,
    "user" => $user
]);

$conn->close();