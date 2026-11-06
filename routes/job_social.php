<?php
/**
 * Job likes & comments API (candidate side)
 * ---------------------------------------------------------
 * GET  ?action=summary            -> like counts, comment counts, jobs I liked
 * GET  ?action=comments&job_id=1  -> comments of one job
 * POST action=toggle_like         job_id
 * POST action=comment             job_id, body
 * ---------------------------------------------------------
 * Tables job_likes & job_comments apne aap ban jaati hain.
 *
 * Isko rakho: startup-hire-api/routes/job_social.php
 */

require_once __DIR__ . "/../app/Support/db.php";
require_once __DIR__ . "/../app/Support/legacy_auth.php";
require_once __DIR__ . "/../app/Support/helpers.php";

json_cors();

$me = require_role(["candidate", "employer", "superadmin"]);
$myRole = $me["role"];
$uid = (int)$me["id"];
$method = $_SERVER["REQUEST_METHOD"];

$conn->query(
    "CREATE TABLE IF NOT EXISTS job_likes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        job_id INT NOT NULL,
        user_id INT NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_job_user (job_id, user_id)
    )"
);

$conn->query(
    "CREATE TABLE IF NOT EXISTS job_comments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        job_id INT NOT NULL,
        user_id INT NOT NULL,
        body TEXT NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_job (job_id)
    )"
);
$col = $conn->query("SHOW COLUMNS FROM job_comments LIKE 'parent_id'");
if ($col && $col->num_rows === 0) {
    $conn->query("ALTER TABLE job_comments ADD COLUMN parent_id INT NULL DEFAULT NULL AFTER job_id");
} 
/* ---------------- helpers ---------------- */

function js_out($data)
{
    echo json_encode($data);
    exit;
}

function js_ago($s)
{
    $s = (int)$s;
    if ($s < 60) return "just now";
    if ($s < 3600) return floor($s / 60) . " min ago";
    if ($s < 86400) {
        $h = floor($s / 3600);
        return $h . ($h == 1 ? " hour" : " hours") . " ago";
    }
    $d = floor($s / 86400);
    if ($d < 30) return $d . ($d == 1 ? " day" : " days") . " ago";
    $m = floor($d / 30);
    return $m . ($m == 1 ? " month" : " months") . " ago";
}

function js_job_exists($conn, $jobId)
{
    $st = $conn->prepare("SELECT id FROM jobs WHERE id = ?");
    $st->bind_param("i", $jobId);
    $st->execute();
    return $st->get_result()->num_rows > 0;
}

function js_like_count($conn, $jobId)
{
    $st = $conn->prepare("SELECT COUNT(*) AS c FROM job_likes WHERE job_id = ?");
    $st->bind_param("i", $jobId);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    return (int)$row["c"];
}

$action = $_GET["action"] ?? "";

/* ---------------- GET: summary ---------------- */

if ($method === "GET" && $action === "summary") {
    $likes = [];
    $res = $conn->query("SELECT job_id, COUNT(*) AS c FROM job_likes GROUP BY job_id");
    while ($r = $res->fetch_assoc()) {
        $likes[$r["job_id"]] = (int)$r["c"];
    }

    $comments = [];
    $res = $conn->query("SELECT job_id, COUNT(*) AS c FROM job_comments GROUP BY job_id");
    while ($r = $res->fetch_assoc()) {
        $comments[$r["job_id"]] = (int)$r["c"];
    }

    $liked = [];
    $st = $conn->prepare("SELECT job_id FROM job_likes WHERE user_id = ?");
    $st->bind_param("i", $uid);
    $st->execute();
    foreach ($st->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
        $liked[] = (int)$r["job_id"];
    }

    js_out([
        "success"  => true,
        "likes"    => (object)$likes,
        "comments" => (object)$comments,
        "liked"    => $liked,
    ]);
}


/* ---------------- GET: who liked ---------------- */

if ($method === "GET" && $action === "likes") {
    $jobId = (int)($_GET["job_id"] ?? 0);

    $st = $conn->prepare(
        "SELECT u.name AS user_name, u.role AS user_role,
                TIMESTAMPDIFF(SECOND, l.created_at, NOW()) AS secs
         FROM job_likes l
         JOIN users u ON u.id = l.user_id
         WHERE l.job_id = ?
         ORDER BY l.id DESC
         LIMIT 200"
    );
    $st->bind_param("i", $jobId);
    $st->execute();

    $list = [];
    foreach ($st->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
        $list[] = [
            "user_name" => $r["user_name"],
            "user_role" => $r["user_role"],
            "time_ago"  => js_ago($r["secs"]),
        ];
    }
    js_out(["success" => true, "likes" => $list]);
}

/* ---------------- GET: comments of one job ---------------- */

if ($method === "GET" && $action === "comments") {
    $jobId = (int)($_GET["job_id"] ?? 0);

    $st = $conn->prepare(
        "SELECT c.id, c.parent_id, c.user_id, c.body, u.name AS user_name, u.role AS user_role,
                TIMESTAMPDIFF(SECOND, c.created_at, NOW()) AS secs
         FROM job_comments c
         JOIN users u ON u.id = c.user_id
         WHERE c.job_id = ?
         ORDER BY c.id ASC
         LIMIT 300"
    );
    $st->bind_param("i", $jobId);
    $st->execute();

    $list = [];
    foreach ($st->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
        $list[] = [
            "id"        => (int)$r["id"],
            "parent_id" => $r["parent_id"] ? (int)$r["parent_id"] : null,
            "user_name" => $r["user_name"],
            "user_role" => $r["user_role"],
            "mine"      => (int)$r["user_id"] === $uid,
            "body"      => $r["body"],
            "time_ago"  => js_ago($r["secs"]),
        ];
    }
    js_out(["success" => true, "comments" => $list]);
}
/* ---------------- POST ---------------- */

if ($method === "POST") {
    $body = $_POST;
    if (empty($body)) {
        $body = read_json_body();
    }

    $act = $body["action"] ?? "";
    $jobId = (int)($body["job_id"] ?? 0);

    if ($jobId <= 0 || !js_job_exists($conn, $jobId)) {
        js_out(["success" => false, "message" => "Job not found"]);
    }
        if ($act === "toggle_like" && $myRole !== "candidate") {
        js_out(["success" => false, "message" => "Only candidates can like jobs"]);
    }

    if ($act === "toggle_like") {
        $st = $conn->prepare("SELECT id FROM job_likes WHERE job_id = ? AND user_id = ?");
        $st->bind_param("ii", $jobId, $uid);
        $st->execute();
        $exists = $st->get_result()->num_rows > 0;

        if ($exists) {
            $del = $conn->prepare("DELETE FROM job_likes WHERE job_id = ? AND user_id = ?");
            $del->bind_param("ii", $jobId, $uid);
            $del->execute();
        } else {
            $ins = $conn->prepare("INSERT INTO job_likes (job_id, user_id) VALUES (?, ?)");
            $ins->bind_param("ii", $jobId, $uid);
            $ins->execute();
        }

        js_out([
            "success" => true,
            "liked"   => !$exists,
            "likes"   => js_like_count($conn, $jobId),
        ]);
    }

        if ($act === "comment") {
        if (!in_array($myRole, ["candidate", "employer"], true)) {
            js_out(["success" => false, "message" => "Not allowed"]);
        }

        $text = trim((string)($body["body"] ?? ""));
        if ($text === "") {
            js_out(["success" => false, "message" => "Comment is empty"]);
        }
        if (mb_strlen($text) > 1000) {
            js_out(["success" => false, "message" => "Comment is too long"]);
        }

        // Reply hai to parent dhundo. Reply ka reply bhi top comment ke neeche hi judta hai.
        $parent = null;
        $parentId = (int)($body["parent_id"] ?? 0);
        if ($parentId > 0) {
            $st = $conn->prepare("SELECT id, parent_id FROM job_comments WHERE id = ? AND job_id = ?");
            $st->bind_param("ii", $parentId, $jobId);
            $st->execute();
            $p = $st->get_result()->fetch_assoc();
            if (!$p) {
                js_out(["success" => false, "message" => "Comment not found"]);
            }
            $parent = !empty($p["parent_id"]) ? (int)$p["parent_id"] : (int)$p["id"];
        }

        $ins = $conn->prepare("INSERT INTO job_comments (job_id, user_id, parent_id, body) VALUES (?, ?, ?, ?)");
        $ins->bind_param("iiis", $jobId, $uid, $parent, $text);
        $ins->execute();

        js_out([
            "success" => true,
            "comment" => [
                "id"        => (int)$conn->insert_id,
                "parent_id" => $parent,
                "user_name" => $me["name"] ?? "You",
                "user_role" => $myRole,
                "mine"      => true,
                "body"      => $text,
                "time_ago"  => "just now",
            ],
        ]);
    }

    js_out(["success" => false, "message" => "Unknown action"]);
}

js_out(["success" => false, "message" => "Method not allowed"]);