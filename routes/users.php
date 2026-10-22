<?php
/**
 * Super Admin â€” platform-wide Users API
 * ---------------------------------------------------------
 * GET  ?role=candidate|employer|All  &q=search&status=active|inactive
 *      -> list of ALL users on the platform (never passwords)
 * POST action=toggle_active   user_id
 * GET  action=detail&user_id=5  -> one user's details
 * ---------------------------------------------------------
 */

require_once __DIR__ . "/../app/Support/db.php";
require_once __DIR__ . "/../app/Support/legacy_auth.php";
require_once __DIR__ . "/../app/Support/helpers.php";

json_cors();

$me = require_role("superadmin");
$method = $_SERVER["REQUEST_METHOD"];

if ($method === "GET" && ($_GET["action"] ?? "") === "detail") {
    $userId = (int)($_GET["user_id"] ?? 0);

    $stmt = $conn->prepare("SELECT id, name, email, phone, role, is_active, created_at FROM users WHERE id = ?");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $res = $stmt->get_result();

    if ($res->num_rows === 0) {
        echo json_encode(["success" => false, "message" => "User not found"]);
        exit;
    }

    $user = $res->fetch_assoc();

    $stmt2 = $conn->prepare(
        "SELECT action, ip_address, created_at FROM activity_log WHERE user_id = ? ORDER BY created_at DESC LIMIT 20"
    );
    $stmt2->bind_param("i", $userId);
    $stmt2->execute();
    $activity = $stmt2->get_result()->fetch_all(MYSQLI_ASSOC);

    echo json_encode(["success" => true, "user" => $user, "activity" => $activity]);
    exit;
}

if ($method === "GET") {
    $where = [];
    $params = [];
    $types = "";

    if (!empty($_GET["role"]) && $_GET["role"] !== "All") {
        $where[] = "role = ?";
        $params[] = $_GET["role"];
        $types .= "s";
    }

    if (!empty($_GET["status"])) {
        $where[] = "is_active = ?";
        $params[] = $_GET["status"] === "active" ? 1 : 0;
        $types .= "i";
    }

    if (!empty($_GET["q"])) {
        $where[] = "(name LIKE ? OR email LIKE ?)";
        $like = "%" . $_GET["q"] . "%";
        $params[] = $like; $params[] = $like;
        $types .= "ss";
    }

    $sql = "SELECT id, name, email, phone, role, is_active, created_at FROM users";
    if (!empty($where)) {
        $sql .= " WHERE " . implode(" AND ", $where);
    }
    $sql .= " ORDER BY created_at DESC";

    $stmt = $conn->prepare($sql);
    if ($types !== "") {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $res = $stmt->get_result();

    $users = $res->fetch_all(MYSQLI_ASSOC);

    echo json_encode(["success" => true, "users" => $users]);
    exit;
}

if ($method === "POST") {
    $body = $_POST;
    if (empty($body)) {
        $body = read_json_body();
    }

    $action = $body["action"] ?? "";
    $userId = (int)($body["user_id"] ?? 0);

    if ($action === "toggle_active") {
        if ($userId === $me["id"]) {
            echo json_encode(["success" => false, "message" => "You cannot deactivate your own account."]);
            exit;
        }

        $stmt = $conn->prepare(
            "UPDATE users SET is_active = IF(is_active = 1, 0, 1) WHERE id = ?"
        );
        $stmt->bind_param("i", $userId);
        $stmt->execute();

        echo json_encode(["success" => true, "message" => "User status updated"]);
        exit;
    }

    echo json_encode(["success" => false, "message" => "Unknown action"]);
    exit;
}

echo json_encode(["success" => false, "message" => "Method not allowed"]);
