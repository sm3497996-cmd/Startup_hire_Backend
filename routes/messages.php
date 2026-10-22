<?php
/**
 * Messages API (candidate side)
 * GET  ?action=threads                       -> candidate ki saari applications ki chats
 * GET  ?action=messages&application_id=5     -> ek chat ke messages (employer ke msgs read mark hote hain)
 * POST action=send  application_id, body     -> message bhejo
 * Chat sirf us application ke candidate ke liye khulti hai.
 */
require_once __DIR__ . "/../app/Support/db.php";
require_once __DIR__ . "/../app/Support/legacy_auth.php";
require_once __DIR__ . "/../app/Support/helpers.php";

json_cors();

$user = require_role("candidate");
$uid = (int)$user["id"];
$method = $_SERVER["REQUEST_METHOD"];

function owns_application($conn, $appId, $uid) {
    $s = $conn->prepare("SELECT id FROM applications WHERE id = ? AND candidate_id = ?");
    $s->bind_param("ii", $appId, $uid);
    $s->execute();
    return $s->get_result()->num_rows > 0;
}

try {
    if ($method === "GET") {
        $action = $_GET["action"] ?? "threads";

        if ($action === "threads") {
            $s = $conn->prepare(
                "SELECT a.id AS application_id, a.status, a.applied_at, j.title AS job_title, c.name AS company_name,
                        (SELECT body FROM messages m WHERE m.application_id = a.id ORDER BY m.id DESC LIMIT 1) AS last_message,
                        (SELECT created_at FROM messages m WHERE m.application_id = a.id ORDER BY m.id DESC LIMIT 1) AS last_at,
                        (SELECT COUNT(*) FROM messages m WHERE m.application_id = a.id AND m.sender_role = 'employer' AND m.read_at IS NULL) AS unread
                 FROM applications a
                 JOIN jobs j ON j.id = a.job_id
                 JOIN companies c ON c.id = j.company_id
                 WHERE a.candidate_id = ?"
            );
            $s->bind_param("i", $uid);
            $s->execute();
            $res = $s->get_result();

            $threads = [];
            while ($r = $res->fetch_assoc()) {
                $r["application_id"] = (int)$r["application_id"];
                $r["unread"] = (int)$r["unread"];
                $threads[] = $r;
            }
            usort($threads, function ($a, $b) {
                return strcmp($b["last_at"] ?? $b["applied_at"], $a["last_at"] ?? $a["applied_at"]);
            });

            echo json_encode(["success" => true, "threads" => $threads]);
            exit;
        }

        if ($action === "messages") {
            $appId = (int)($_GET["application_id"] ?? 0);
            if (!$appId || !owns_application($conn, $appId, $uid)) {
                http_response_code(403);
                echo json_encode(["success" => false, "message" => "Not allowed"]);
                exit;
            }

            $s = $conn->prepare("SELECT id, sender_role, body, created_at FROM messages WHERE application_id = ? ORDER BY id ASC");
            $s->bind_param("i", $appId);
            $s->execute();
            $res = $s->get_result();
            $msgs = [];
            while ($r = $res->fetch_assoc()) { $msgs[] = $r; }

            $u = $conn->prepare("UPDATE messages SET read_at = NOW() WHERE application_id = ? AND sender_role = 'employer' AND read_at IS NULL");
            $u->bind_param("i", $appId);
            $u->execute();

            echo json_encode(["success" => true, "messages" => $msgs]);
            exit;
        }
    }

    if ($method === "POST") {
        $body = $_POST;
        if (empty($body)) { $body = read_json_body(); }

        if (($body["action"] ?? "") === "send") {
            $appId = (int)($body["application_id"] ?? 0);
            $text = trim($body["body"] ?? "");

            if (!$appId || $text === "" || mb_strlen($text) > 2000) {
                echo json_encode(["success" => false, "message" => "Message empty ya bahut lamba hai"]);
                exit;
            }
            if (!owns_application($conn, $appId, $uid)) {
                http_response_code(403);
                echo json_encode(["success" => false, "message" => "Not allowed"]);
                exit;
            }

            $s = $conn->prepare("INSERT INTO messages (application_id, sender_id, sender_role, body) VALUES (?, ?, 'candidate', ?)");
            $s->bind_param("iis", $appId, $uid, $text);
            $s->execute();

            echo json_encode(["success" => true]);
            exit;
        }
    }

    echo json_encode(["success" => false, "message" => "Unknown action"]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "Messages error: " . $e->getMessage()]);
}