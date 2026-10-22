<?php
/**
 * Super Admin â€” Activity API
 * ---------------------------------------------------------
 * GET ?action=login_success|login_failed|logout|All  &q=search
 *     -> recent activity_log rows, newest first, joined with user info
 * ---------------------------------------------------------
 */

require_once __DIR__ . "/../app/Support/db.php";
require_once __DIR__ . "/../app/Support/legacy_auth.php";
require_once __DIR__ . "/../app/Support/helpers.php";

json_cors();

require_role("superadmin");

$where = [];
$params = [];
$types = "";

if (!empty($_GET["action"]) && $_GET["action"] !== "All") {
    $where[] = "a.action = ?";
    $params[] = $_GET["action"];
    $types .= "s";
}

if (!empty($_GET["q"])) {
    $where[] = "(u.name LIKE ? OR u.email LIKE ?)";
    $like = "%" . $_GET["q"] . "%";
    $params[] = $like; $params[] = $like;
    $types .= "ss";
}

$sql = "SELECT a.id, a.user_id, a.role, a.action, a.ip_address, a.created_at,
               u.name AS user_name, u.email AS user_email
        FROM activity_log a
        LEFT JOIN users u ON u.id = a.user_id";

if (!empty($where)) {
    $sql .= " WHERE " . implode(" AND ", $where);
}

$sql .= " ORDER BY a.created_at DESC LIMIT 200";

$stmt = $conn->prepare($sql);
if ($types !== "") {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$res = $stmt->get_result();

$logs = $res->fetch_all(MYSQLI_ASSOC);

echo json_encode(["success" => true, "activity" => $logs]);
