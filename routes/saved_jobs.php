<?php
/**
 * Saved Jobs API (candidate only)
 * ---------------------------------------------------------
 * GET                      -> list candidate's saved jobs
 * POST action=save          job_id
 * POST action=unsave        job_id
 * ---------------------------------------------------------
 */

require_once __DIR__ . "/../app/Support/db.php";
require_once __DIR__ . "/../app/Support/legacy_auth.php";
require_once __DIR__ . "/../app/Support/helpers.php";

json_cors();

$user = require_role("candidate");
$method = $_SERVER["REQUEST_METHOD"];

if ($method === "GET") {
    $stmt = $conn->prepare(
        "SELECT j.*, c.name AS company_name, s.saved_at
         FROM saved_jobs s
         JOIN jobs j ON j.id = s.job_id
         JOIN companies c ON c.id = j.company_id
         WHERE s.candidate_id = ?
         ORDER BY s.saved_at DESC"
    );
    $stmt->bind_param("i", $user["id"]);
    $stmt->execute();
    $res = $stmt->get_result();

    $jobs = [];
    while ($row = $res->fetch_assoc()) {
        $jobs[] = [
            "id" => (int)$row["id"],
            "company_name" => $row["company_name"],
            "title" => $row["title"],
            "location" => $row["location"],
            "category" => $row["category"],
            "job_type" => $row["job_type"],
            "salary" => $row["salary"],
            "status" => $row["status"],
            "saved_at" => $row["saved_at"],
        ];
    }

    echo json_encode(["success" => true, "jobs" => $jobs]);
    exit;
}

if ($method === "POST") {
    $body = $_POST;
    if (empty($body)) {
        $body = read_json_body();
    }

    $action = $body["action"] ?? "";
    $jobId = (int)($body["job_id"] ?? 0);

    if (!$jobId) {
        echo json_encode(["success" => false, "message" => "job_id is required"]);
        exit;
    }

    if ($action === "save") {
        $stmt = $conn->prepare(
            "INSERT IGNORE INTO saved_jobs (candidate_id, job_id) VALUES (?, ?)"
        );
        $stmt->bind_param("ii", $user["id"], $jobId);
        $stmt->execute();

        echo json_encode(["success" => true, "message" => "Job saved"]);
        exit;
    }

    if ($action === "unsave") {
        $stmt = $conn->prepare(
            "DELETE FROM saved_jobs WHERE candidate_id = ? AND job_id = ?"
        );
        $stmt->bind_param("ii", $user["id"], $jobId);
        $stmt->execute();

        echo json_encode(["success" => true, "message" => "Job removed from saved list"]);
        exit;
    }

    echo json_encode(["success" => false, "message" => "Unknown action"]);
    exit;
}

echo json_encode(["success" => false, "message" => "Method not allowed"]);
