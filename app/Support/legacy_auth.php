<?php
/**
 * Shared auth + CORS helper for StartupHire backend.
 * Include this in every route that needs to know who is logged in.
 *
 * Usage in a route file:
 *   require_once "../config/db.php";
 *   require_once "../config/auth.php";
 *   json_cors();                 // sets headers, handles OPTIONS
 *   $me = require_login();       // any logged-in user
 *   $me = require_role("employer");             // single role
 *   $me = require_role(["employer", "superadmin"]); // multiple roles
 */

if (session_status() === PHP_SESSION_NONE) {
    // Cross-port (5173 <-> 8080) but same host "localhost" counts as
    // same-site in the browser, so a Lax cookie is still sent on
    // fetch() calls as long as the frontend uses `credentials: "include"`.
    session_set_cookie_params([
        "lifetime" => 0,
        "path" => "/",
        "samesite" => "Lax",
    ]);
    session_start();
}

/**
 * Sets JSON + CORS headers used by every route.
 * Call this before printing any output.
 */
function json_cors() {
    header("Content-Type: application/json");
    $origin = $_SERVER["HTTP_ORIGIN"] ?? "";

$allowedOrigins = [
    "http://localhost:5173",
    "http://localhost:5174",
    "http://localhost:5175",
    "http://localhost:5176"
];

if (in_array($origin, $allowedOrigins, true)) {
    header("Access-Control-Allow-Origin: $origin");
    header("Vary: Origin");
}
    header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
    header("Access-Control-Allow-Headers: Content-Type");
    header("Access-Control-Allow-Credentials: true");

    if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
        http_response_code(200);
        exit;
    }
}

/**
 * Returns the logged-in user's session data, or null.
 * NEVER trust role/user_id/company_id sent from the frontend —
 * always read it from here (server-side session), not from
 * $_POST / $_GET.
 */
function current_user() {
    if (!isset($_SESSION["user_id"])) {
        return null;
    }

    return [
        "id" => $_SESSION["user_id"],
        "name" => $_SESSION["user_name"],
        "email" => $_SESSION["user_email"],
        "role" => $_SESSION["user_role"],
    ];
}

/**
 * Stores the logged-in user in the PHP session.
 * Call this right after a successful login.
 */
function login_user($user) {
    $_SESSION["user_id"] = $user["id"];
    $_SESSION["user_name"] = $user["name"];
    $_SESSION["user_email"] = $user["email"];
    $_SESSION["user_role"] = $user["role"];
}

/**
 * Clears the session (logout).
 */
function logout_user() {
    $_SESSION = [];

    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            "",
            time() - 42000,
            $params["path"],
            $params["domain"],
            $params["secure"],
            $params["httponly"]
        );
    }

    session_destroy();
}

/**
 * Requires ANY logged-in user. Stops the request with 401 if not.
 * Returns the user array on success.
 */
function require_login() {
    $user = current_user();

    if (!$user) {
        http_response_code(401);
        echo json_encode([
            "success" => false,
            "message" => "You must be logged in.",
        ]);
        exit;
    }

    return $user;
}

/**
 * Requires the logged-in user to have one of the given role(s).
 * $roles can be a single string ("employer") or an array
 * (["employer", "superadmin"]).
 * Stops the request with 403 if the role doesn't match.
 */
function require_role($roles) {
    $user = require_login();
    $roles = is_array($roles) ? $roles : [$roles];

    if (!in_array($user["role"], $roles, true)) {
        http_response_code(403);
        echo json_encode([
            "success" => false,
            "message" => "You do not have access to this resource.",
        ]);
        exit;
    }

    return $user;
}

/**
 * Records a row in activity_log. Never logs the password.
 */
function log_activity($conn, $userId, $role, $action) {
    $ip = $_SERVER["REMOTE_ADDR"] ?? null;

    $stmt = $conn->prepare(
        "INSERT INTO activity_log (user_id, role, action, ip_address) VALUES (?, ?, ?, ?)"
    );
    $stmt->bind_param("isss", $userId, $role, $action, $ip);
    $stmt->execute();
    $stmt->close();
}