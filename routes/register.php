<?php

// CORS: Allow both Vite ports
$allowedOrigins = [
    "http://localhost:5173",
    "http://localhost:5174",
    "http://localhost:5175",
    "http://localhost:5176",
];

$origin = $_SERVER["HTTP_ORIGIN"] ?? "";

if (in_array($origin, $allowedOrigins, true)) {
    header("Access-Control-Allow-Origin: " . $origin);
}

header("Vary: Origin");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

// Handle browser preflight request
if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(204);
    exit;
}

// Allow only POST requests
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);

    echo json_encode([
        "success" => false,
        "message" => "Only POST request is allowed"
    ]);

    exit;
}

// Database connection
require_once __DIR__ . "/../app/Support/db.php";

// Read form data
$name = trim($_POST["name"] ?? "");
$email = trim($_POST["email"] ?? "");
$phone = trim($_POST["phone"] ?? "");
$password = $_POST["password"] ?? "";
$role = trim($_POST["role"] ?? "candidate");

// Validate required fields
if ($name === "" || $email === "" || $password === "") {
    http_response_code(400);

    echo json_encode([
        "success" => false,
        "message" => "Name, email and password are required"
    ]);

    exit;
}

// Validate email
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);

    echo json_encode([
        "success" => false,
        "message" => "Please enter a valid email address"
    ]);

    exit;
}

// Validate account type
if (!in_array($role, ["candidate", "employer"], true)) {
    http_response_code(400);

    echo json_encode([
        "success" => false,
        "message" => "Invalid account type"
    ]);

    exit;
}

try {

    // Check whether email already exists
    $check = $conn->prepare(
        "SELECT id FROM users WHERE email = ?"
    );

    $check->bind_param("s", $email);
    $check->execute();

    $result = $check->get_result();

    if ($result->num_rows > 0) {
        http_response_code(409);

        echo json_encode([
            "success" => false,
            "message" => "Email already registered"
        ]);

        $check->close();
        $conn->close();
        exit;
    }

    $check->close();

    // Hash password before saving
    $hashedPassword = password_hash(
        $password,
        PASSWORD_DEFAULT
    );

    // Insert new user
    $sql = "INSERT INTO users (name, email, phone, password, role)
            VALUES (?, ?, ?, ?, ?)";

    $stmt = $conn->prepare($sql);

    $stmt->bind_param(
        "sssss",
        $name,
        $email,
        $phone,
        $hashedPassword,
        $role
    );

    if ($stmt->execute()) {

        http_response_code(201);

        echo json_encode([
            "success" => true,
            "message" => "Registration successful"
        ]);

    } else {

        http_response_code(500);

        echo json_encode([
            "success" => false,
            "message" => "Registration failed"
        ]);
    }

    $stmt->close();
    $conn->close();

} catch (mysqli_sql_exception $e) {

    http_response_code(500);

    // Log the technical error on the server
    error_log("Registration database error: " . $e->getMessage());

    echo json_encode([
        "success" => false,
        "message" => "A database error occurred. Please try again."

    ]);

}

?>