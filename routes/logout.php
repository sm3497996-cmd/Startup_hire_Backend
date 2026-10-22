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

$user = current_user();

if ($user) {
    log_activity($conn, $user["id"], $user["role"], "logout");
}

logout_user();

echo json_encode([
    "success" => true,
    "message" => "Logged out"
]);

$conn->close();


