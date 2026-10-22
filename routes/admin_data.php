<?php

require_once __DIR__ . "/../app/Support/db.php";
require_once __DIR__ . "/../app/Support/legacy_auth.php";
require_once __DIR__ . "/../app/Support/helpers.php";

json_cors();

$me = require_role("superadmin");
$method = $_SERVER["REQUEST_METHOD"];

$hasStatus = false;
$col = $conn->query("SHOW COLUMNS FROM jobs LIKE 'status'");
if ($col && $col->num_rows > 0) {
    $hasStatus = true;
}

if ($method === "POST") {
    $body = $_POST;
    if (empty($body)) {
        $body = read_json_body();
    }
    $action = $body["action"] ?? "";

    if ($action === "set_job_status") {
        $jobId = (int)($body["job_id"] ?? 0);
        $status = $body["status"] ?? "";

        if (!in_array($status, ["Active", "Pending", "Flagged", "Removed"], true)) {
            echo json_encode(["success" => false, "message" => "Invalid status"]);
            exit;
        }
        if (!$hasStatus) {
            echo json_encode(["success" => false, "message" => "jobs table mein status column nahi hai."]);
            exit;
        }
        $stmt = $conn->prepare("UPDATE jobs SET status = ? WHERE id = ?");
        $stmt->bind_param("si", $status, $jobId);
        $stmt->execute();
        echo json_encode(["success" => true]);
        exit;
    }

    if ($action === "verify_user") {
        $userId = (int)($body["user_id"] ?? 0);
        $stmt = $conn->prepare("UPDATE users SET is_verified = 1 WHERE id = ?");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        echo json_encode(["success" => true]);
        exit;
    }

    if ($action === "force_logout") {
        $userId = (int)($body["user_id"] ?? 0);
        if ($userId === (int)$me["id"]) {
            echo json_encode(["success" => false, "message" => "You cannot log out yourself here."]);
            exit;
        }
        $stmt = $conn->prepare("UPDATE users SET force_logout = 1, last_active = NULL WHERE id = ?");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        echo json_encode(["success" => true]);
        exit;
    }

    echo json_encode(["success" => false, "message" => "Unknown action"]);
    exit;
}

if ($method !== "GET") {
    echo json_encode(["success" => false, "message" => "Method not allowed"]);
    exit;
}

// Users (online = pichle 2 minute mein active)
$users = $conn->query(
    "SELECT u.id, u.name, u.email, u.phone, u.role, u.is_active, u.is_verified, u.created_at, u.last_active,
            (u.last_active IS NOT NULL AND u.last_active >= (NOW() - INTERVAL 2 MINUTE)) AS online,
            (SELECT COUNT(*) FROM activity_log a WHERE a.user_id = u.id AND a.action = 'login_success') AS logins,
            (SELECT COUNT(*) FROM applications ap WHERE ap.candidate_id = u.id) AS applied,
            (SELECT COUNT(*) FROM jobs j WHERE j.posted_by = u.id) AS jobs_posted,
            (SELECT a2.ip_address FROM activity_log a2 WHERE a2.user_id = u.id ORDER BY a2.id DESC LIMIT 1) AS last_ip
     FROM users u
     ORDER BY u.created_at DESC"
)->fetch_all(MYSQLI_ASSOC);

// Activity feed
$events = $conn->query(
    "SELECT id, user_id, role, action, ip_address, created_at
     FROM activity_log
     WHERE user_id IS NOT NULL
     ORDER BY id DESC LIMIT 60"
)->fetch_all(MYSQLI_ASSOC);

// Jobs
$jobs = $conn->query(
    "SELECT j.*, u.name AS poster_name,
            (SELECT COUNT(*) FROM applications ap WHERE ap.job_id = j.id) AS applicants
     FROM jobs j
     LEFT JOIN users u ON u.id = j.posted_by
     ORDER BY j.id DESC"
)->fetch_all(MYSQLI_ASSOC);

// Logins chart (real, activity_log se)
$rows = $conn->query(
    "SELECT TIMESTAMPDIFF(HOUR, created_at, NOW()) AS h_ago,
            DATEDIFF(CURDATE(), DATE(created_at)) AS d_ago
     FROM activity_log
     WHERE action = 'login_success' AND created_at >= (NOW() - INTERVAL 7 DAY)"
)->fetch_all(MYSQLI_ASSOC);

$v24 = array_fill(0, 12, 0);
$l24 = [];
for ($i = 11; $i >= 0; $i--) {
    $l24[] = date("ga", time() - $i * 7200);
}
$v7 = array_fill(0, 7, 0);
$l7 = [];
for ($i = 6; $i >= 0; $i--) {
    $l7[] = date("D", strtotime("-$i day"));
}
foreach ($rows as $r) {
    $h = (int)$r["h_ago"];
    $d = (int)$r["d_ago"];
    if ($h >= 0 && $h < 24) {
        $v24[11 - intdiv($h, 2)]++;
    }
    if ($d >= 0 && $d < 7) {
        $v7[6 - $d]++;
    }
}

echo json_encode([
    "success" => true,
    "users" => $users,
    "events" => $events,
    "jobs" => $jobs,
    "chart" => [
        "24h" => ["labels" => $l24, "values" => $v24],
        "7d" => ["labels" => $l7, "values" => $v7],
    ],
    "jobs_has_status" => $hasStatus,
]);