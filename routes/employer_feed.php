<?php
/**
 * Employer feed API: real notifications, interactions & messages
 * ---------------------------------------------------------
 * GET  ?action=notifications   -> bell + Notifications page
 * GET  ?action=interactions    -> Dashboard "Interactions" panel
 * GET  ?action=conversations   -> Messages page (list + messages)
 * POST action=mark_all_read
 * POST action=send_message     application_id, body
 * POST action=mark_read        application_id
 * ---------------------------------------------------------
 * Koi nayi notifications table nahi: data applications, saved_jobs
 * aur messages tables se nikalta hai. Sirf ek chhoti table
 * employer_feed_state (last_seen_at) apne aap ban jaati hai.
 *
 * Isko rakho: startup-hire-api/routes/employer_feed.php
 */

require_once __DIR__ . "/../app/Support/db.php";
require_once __DIR__ . "/../app/Support/legacy_auth.php";
require_once __DIR__ . "/../app/Support/helpers.php";

json_cors();

$me = require_role("employer");
$uid = (int)$me["id"];
$method = $_SERVER["REQUEST_METHOD"];

$conn->query(
    "CREATE TABLE IF NOT EXISTS employer_feed_state (
        user_id INT NOT NULL PRIMARY KEY
        last_seen_at DATETIME NOT NULL
    )"
);

/* ---------------- helpers ---------------- */

function ef_out($data)
{
    echo json_encode($data);
    exit;
}

function ef_ago($s)
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

function ef_initials($name)
{
    $parts = preg_split('/\s+/', trim((string)$name));
    $i = "";
    foreach (array_slice($parts, 0, 2) as $p) {
        $i .= mb_strtoupper(mb_substr($p, 0, 1));
    }
    return $i !== "" ? $i : "?";
}

// last_seen kitne seconds pehle tha (row na ho to sab unread)
function ef_last_seen_secs($conn, $uid)
{
    $st = $conn->prepare(
        "SELECT TIMESTAMPDIFF(SECOND, last_seen_at, NOW()) AS s
         FROM employer_feed_state WHERE user_id = ?"
    );
    $st->bind_param("i", $uid);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    return $row ? (int)$row["s"] : PHP_INT_MAX;
}

// kya ye application is employer ki job par hai?
function ef_owns_application($conn, $appId, $uid)
{
    $st = $conn->prepare(
        "SELECT a.id FROM applications a
         JOIN jobs j ON j.id = a.job_id
         WHERE a.id = ? AND j.posted_by = ?"
    );
    $st->bind_param("ii", $appId, $uid);
    $st->execute();
    return $st->get_result()->num_rows > 0;
}

$action = $_GET["action"] ?? "";

/* ---------------- GET: notifications ---------------- */

if ($method === "GET" && $action === "notifications") {
    $seen = ef_last_seen_secs($conn, $uid);

    $sql = "
        SELECT CONCAT('a', a.id) AS id, 'applicant' AS `type`, 'New applicant' AS title,
               CONCAT(u.name, ' applied for ', j.title) AS descr,
               TIMESTAMPDIFF(SECOND, a.applied_at, NOW()) AS secs
        FROM applications a
        JOIN jobs j ON j.id = a.job_id
        JOIN users u ON u.id = a.candidate_id
        WHERE j.posted_by = ?

        UNION ALL

        SELECT CONCAT('s', s.id), 'job', 'Job saved',
               CONCAT(u.name, ' saved ', j.title),
               TIMESTAMPDIFF(SECOND, s.saved_at, NOW())
        FROM saved_jobs s
        JOIN jobs j ON j.id = s.job_id
        JOIN users u ON u.id = s.candidate_id
        WHERE j.posted_by = ?

        UNION ALL

        SELECT CONCAT('m', m.id), 'message', 'New message',
               CONCAT(u.name, ' sent you a message'),
               TIMESTAMPDIFF(SECOND, m.created_at, NOW())
        FROM messages m
        JOIN applications a ON a.id = m.application_id
        JOIN jobs j ON j.id = a.job_id
        JOIN users u ON u.id = m.sender_id
        WHERE j.posted_by = ? AND m.sender_role = 'candidate'

        ORDER BY secs ASC
        LIMIT 30
    ";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("iii", $uid, $uid, $uid);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    $list = [];
    foreach ($rows as $r) {
        $secs = (int)$r["secs"];
        $list[] = [
            "id"     => $r["id"],
            "type"   => $r["type"],
            "title"  => $r["title"],
            "desc"   => $r["descr"],
            "time"   => ef_ago($secs),
            "unread" => $secs < $seen,
        ];
    }

    ef_out(["success" => true, "notifications" => $list]);
}

/* ---------------- GET: interactions ---------------- */

if ($method === "GET" && $action === "interactions") {
    $seen = ef_last_seen_secs($conn, $uid);

    $sql = "
        SELECT CONCAT('a', a.id) AS id, 'apply' AS `type`, u.name AS `name`,
               'applied for' AS `text`, j.title AS `role`,
               DATE_FORMAT(a.applied_at, '%d %b %Y') AS `date`,
               TIMESTAMPDIFF(SECOND, a.applied_at, NOW()) AS secs
        FROM applications a
        JOIN jobs j ON j.id = a.job_id
        JOIN users u ON u.id = a.candidate_id
        WHERE j.posted_by = ?

        UNION ALL

        SELECT CONCAT('s', s.id), 'save', u.name, 'saved your', j.title,
               DATE_FORMAT(s.saved_at, '%d %b %Y'),
               TIMESTAMPDIFF(SECOND, s.saved_at, NOW())
        FROM saved_jobs s
        JOIN jobs j ON j.id = s.job_id
        JOIN users u ON u.id = s.candidate_id
        WHERE j.posted_by = ?

        ORDER BY secs ASC
        LIMIT 30
    ";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $uid, $uid);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    $list = [];
    foreach ($rows as $r) {
        $list[] = [
            "id"     => $r["id"],
            "type"   => $r["type"],
            "name"   => $r["name"],
            "text"   => $r["text"],
            "role"   => $r["role"],
            "date"   => $r["date"],
            "unread" => ((int)$r["secs"]) < $seen,
        ];
    }

    ef_out(["success" => true, "interactions" => $list]);
}

/* ---------------- GET: conversations ---------------- */

if ($method === "GET" && $action === "conversations") {
    // Jin applicants se chat ho chuki hai ya jo Shortlisted/Selected hain
    $sql = "
        SELECT a.id AS application_id, j.title AS job_title, u.name AS cand_name
        FROM applications a
        JOIN jobs j ON j.id = a.job_id
        JOIN users u ON u.id = a.candidate_id
        WHERE j.posted_by = ?
          AND (
                a.status IN ('Shortlisted', 'Selected')
                OR EXISTS (SELECT 1 FROM messages m WHERE m.application_id = a.id)
          )
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $uid);
    $stmt->execute();
    $apps = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    if (empty($apps)) {
        ef_out(["success" => true, "conversations" => []]);
    }

    $ids = array_map("intval", array_column($apps, "application_id"));
    $ph = implode(",", array_fill(0, count($ids), "?"));

    $stmt2 = $conn->prepare(
        "SELECT id, application_id, sender_role, body, read_at
         FROM messages WHERE application_id IN ($ph) ORDER BY id ASC"
    );
    $stmt2->bind_param(str_repeat("i", count($ids)), ...$ids);
    $stmt2->execute();
    $msgs = $stmt2->get_result()->fetch_all(MYSQLI_ASSOC);

    $byApp = [];
    foreach ($msgs as $m) {
        $byApp[(int)$m["application_id"]][] = $m;
    }

    $colors = ["#5b3df5", "#12b86f", "#e83f7d", "#159fd8", "#f7a51c", "#7d2ae8"];
    $convos = [];

    foreach ($apps as $a) {
        $aid = (int)$a["application_id"];
        $thread = [];
        $unread = 0;
        $lastId = 0;

        foreach ($byApp[$aid] ?? [] as $m) {
            $thread[] = [
                "from" => $m["sender_role"] === "employer" ? "me" : "them",
                "text" => $m["body"],
            ];
            if ($m["sender_role"] === "candidate" && $m["read_at"] === null) {
                $unread++;
            }
            $lastId = (int)$m["id"];
        }

        $convos[] = [
            "id"        => $aid,   // application_id hi conversation id hai
            "name"      => $a["cand_name"],
            "initials"  => ef_initials($a["cand_name"]),
            "color"     => $colors[$aid % count($colors)],
            "job_title" => $a["job_title"],
            "unread"    => $unread,
            "messages"  => $thread,
            "_last"     => $lastId,
        ];
    }

    usort($convos, function ($x, $y) {
        return $y["_last"] <=> $x["_last"];
    });
    foreach ($convos as &$c) {
        unset($c["_last"]);
    }
    unset($c);

    ef_out(["success" => true, "conversations" => $convos]);
}

/* ---------------- POST ---------------- */

if ($method === "POST") {
    $body = $_POST;
    if (empty($body)) {
        $body = read_json_body();
    }

    $act = $body["action"] ?? "";

    if ($act === "mark_all_read") {
        $stmt = $conn->prepare(
            "INSERT INTO employer_feed_state (user_id, last_seen_at) VALUES (?, NOW())
             ON DUPLICATE KEY UPDATE last_seen_at = NOW()"
        );
        $stmt->bind_param("i", $uid);
        $stmt->execute();
        ef_out(["success" => true]);
    }

    if ($act === "mark_read") {
        $appId = (int)($body["application_id"] ?? 0);
        if (!ef_owns_application($conn, $appId, $uid)) {
            ef_out(["success" => false, "message" => "Conversation not found"]);
        }
        $stmt = $conn->prepare(
            "UPDATE messages SET read_at = NOW()
             WHERE application_id = ? AND sender_role = 'candidate' AND read_at IS NULL"
        );
        $stmt->bind_param("i", $appId);
        $stmt->execute();
        ef_out(["success" => true]);
    }

    if ($act === "send_message") {
        $appId = (int)($body["application_id"] ?? 0);
        $text = trim((string)($body["body"] ?? ""));

        if ($text === "") {
            ef_out(["success" => false, "message" => "Message is empty"]);
        }
        if (mb_strlen($text) > 2000) {
            ef_out(["success" => false, "message" => "Message is too long"]);
        }
        if (!ef_owns_application($conn, $appId, $uid)) {
            ef_out(["success" => false, "message" => "Conversation not found"]);
        }

        $stmt = $conn->prepare(
            "INSERT INTO messages (application_id, sender_id, sender_role, body)
             VALUES (?, ?, 'employer', ?)"
        );
        $stmt->bind_param("iis", $appId, $uid, $text);
        $stmt->execute();

        ef_out(["success" => true]);
    }

    ef_out(["success" => false, "message" => "Unknown action"]);
}

ef_out(["success" => false, "message" => "Method not allowed"]);