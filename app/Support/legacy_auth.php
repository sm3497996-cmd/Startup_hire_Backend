
<?php
/**
 * Shared auth + CORS helper for StartupHire backend.
 */

function get_bearer_token() {
    $h = $_SERVER["HTTP_AUTHORIZATION"] ?? $_SERVER["REDIRECT_HTTP_AUTHORIZATION"] ?? "";
    if ($h === "" && function_exists("getallheaders")) {
        foreach (getallheaders() as $k => $v) {
            if (strtolower($k) === "authorization") { $h = $v; }
        }
    }
    if (preg_match('/^Bearer\s+([A-Za-z0-9,-]{20,128})$/', $h, $m)) {
        return $m[1];
    }
    return null;
}

if (session_status() === PHP_SESSION_NONE) {
    ini_set("session.use_strict_mode", "1"); // fake session id accept nahi hoga

    $sid = get_bearer_token();
    if ($sid) {
        session_id($sid);
    }
    ini_set("session.gc_maxlifetime", "86400"); // 24 ghante

    session_set_cookie_params([
        "lifetime" => 0,
        "path" => "/",
        "samesite" => "Lax",
    ]);

    session_start();
}

/**
 * Sets JSON + CORS headers used by every route.
 */
function json_cors() {

    header("Content-Type: application/json; charset=UTF-8");

    $origin = $_SERVER["HTTP_ORIGIN"] ?? "";

    // Local development + Netlify production
    $allowedOrigins = [
        "http://localhost:5173",
        "http://localhost:5174",
        "http://localhost:5175",
        "http://localhost:5176",
        "https://dazzling-horse-b7bcb0.netlify.app"
    ];

    if (in_array($origin, $allowedOrigins, true)) {
        header("Access-Control-Allow-Origin: " . $origin);
        header("Vary: Origin");
    }

    header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
    header("Access-Control-Allow-Headers: Content-Type, Authorization");
    header("Access-Control-Allow-Credentials: true");

    if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
        http_response_code(204);
        exit;
    }
}

/**
 * Returns logged-in user session data.
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
 * Stores logged-in user in PHP session.
 */
function login_user($user) {

    $_SESSION["user_id"] = $user["id"];
    $_SESSION["user_name"] = $user["name"];
    $_SESSION["user_email"] = $user["email"];
    $_SESSION["user_role"] = $user["role"];
}

/**
 * Clears the session.
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
 * Requires any logged-in user.
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
 * Requires a specific role.
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
 * Records activity without logging passwords.
 */
function log_activity($conn, $userId, $role, $action) {

    $ip = $_SERVER["REMOTE_ADDR"] ?? null;

    $stmt = $conn->prepare(
        "INSERT INTO activity_log (user_id, role, action, ip_address)
         VALUES (?, ?, ?, ?)"
    );

    $stmt->bind_param("isss", $userId, $role, $action, $ip);

    $stmt->execute();

    $stmt->close();
}
