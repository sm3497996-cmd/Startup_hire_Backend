<?php
/**
 * Applications API
 * ---------------------------------------------------------
 * POST action=apply            (candidate)  job_id
 * GET  ?mine=1                  (candidate)  list own applications
 * GET  ?job_id=5                (employer)   applications for one of their jobs
 * GET  (employer, no job_id)    (employer)   applications across all their jobs
 * POST action=update_status     (employer)   application_id, status
 * ---------------------------------------------------------
 */

require_once __DIR__ . "/../app/Support/db.php";
require_once __DIR__ . "/../app/Support/legacy_auth.php";   // FIX: pehle "../legacy_auth.php" tha (galat path)
require_once __DIR__ . "/../app/Support/helpers.php";

json_cors();

$method = $_SERVER["REQUEST_METHOD"];

function application_row($row) {
    return [
        "id" => (int)$row["id"],
        "job_id" => (int)$row["job_id"],

        "job_title" => $row["title"] ?? null,
        "company_name" => $row["company_name"] ?? null,

        "location" => $row["location"] ?? null,
        "job_type" => $row["job_type"] ?? null,
        "salary" => $row["salary"] ?? null,

        "company_email" => $row["company_email"] ?? null,
        "company_phone" => $row["company_phone"] ?? null,
        "company_website" => $row["company_website"] ?? null,

        "candidate_id" => (int)$row["candidate_id"],
        "candidate_name" => $row["candidate_name"] ?? null,
        "candidate_email" => $row["candidate_email"] ?? null,
        "resume_version_id" => isset($row["resume_version_id"]) ? (int)$row["resume_version_id"] : null,

        "status" => $row["status"] ?? "Applied",

        "applied_at" => $row["applied_at"] ?? null,
        "updated_at" => $row["updated_at"] ?? null,

        "reviewed_by_name" => $row["reviewed_by_name"] ?? null,
        "reviewed_at" => $row["reviewed_at"] ?? null,
                "reviewed_at" => $row["reviewed_at"] ?? null,

        "screening_answers" => !empty($row["screening_answers"])
            ? (json_decode($row["screening_answers"], true) ?: [])
            : [],
    ];
}

/**
 * Company ke contact columns (email / phone / website) ko tumhari companies
 * table ke asli column names se pick karta hai. Column na ho to NULL deta hai.
 */
function company_contact_select($conn) {
    $have = [];
    $res = $conn->query("SHOW COLUMNS FROM companies");
    while ($r = $res->fetch_assoc()) {
        $have[] = $r["Field"];
    }

    $pick = function ($options) use ($have) {
        foreach ($options as $o) {
            if (in_array($o, $have, true)) {
                return "c.`" . $o . "`";
            }
        }
        return "NULL";
    };

    return ", " . $pick(["contact_email", "hr_email", "email"]) . " AS company_email"
         . ", " . $pick(["contact_phone", "hr_phone", "phone", "mobile", "contact_number"]) . " AS company_phone"
         . ", " . $pick(["website", "website_url", "site"]) . " AS company_website";
}

if ($method === "GET") {

    // ---------- Candidate: apni applications (?mine=1) ----------
    // FIX: ye block missing tha, isliye candidate ko 403 milta tha
    if (!empty($_GET["mine"])) {
        $user = require_role("candidate");

        $stmt = $conn->prepare(
            "SELECT a.*, j.title, j.location, j.job_type, j.salary,
                    c.name AS company_name, r.name AS reviewed_by_name" . company_contact_select($conn) . "
             FROM applications a
             JOIN jobs j ON j.id = a.job_id
             JOIN companies c ON c.id = j.company_id
             LEFT JOIN users r ON r.id = a.reviewed_by
             WHERE a.candidate_id = ?
             ORDER BY a.applied_at DESC"
        );
        $stmt->bind_param("i", $user["id"]);
        $stmt->execute();
        $res = $stmt->get_result();

        $apps = [];
        while ($row = $res->fetch_assoc()) {
            $apps[] = application_row($row);
        }

        echo json_encode(["success" => true, "applications" => $apps]);
        exit;
    }

    // ---------- Employer views ----------
        $user = require_role(["employer", "superadmin"]);

    // ---------- Employer: resume version an applicant applied with ----------
    if (!empty($_GET["resume_version_for"])) {
        $appId = (int)$_GET["resume_version_for"];

        $stmt = $conn->prepare(
            "SELECT a.resume_version_id, a.candidate_id, j.company_id
             FROM applications a JOIN jobs j ON j.id = a.job_id WHERE a.id = ?"
        );
        $stmt->bind_param("i", $appId);
        $stmt->execute();
        $app = $stmt->get_result()->fetch_assoc();

        if (!$app) {
            echo json_encode(["success" => false, "message" => "Application not found"]);
            exit;
        }

        if ($user["role"] !== "superadmin") {
            $membership = get_membership($conn, $user["id"]);
            if (!$membership
                || (int)$membership["company_id"] !== (int)$app["company_id"]
                || !member_can($membership, "view_applications")) {
                http_response_code(403);
                echo json_encode(["success" => false, "message" => "Not allowed"]);
                exit;
            }
        }

        if (empty($app["resume_version_id"])) {
            echo json_encode(["success" => true, "version" => null]);
            exit;
        }

        $vid = (int)$app["resume_version_id"];
        $cid = (int)$app["candidate_id"];
        $stmt = $conn->prepare(
            "SELECT title, target_field, data FROM resume_versions WHERE id = ? AND candidate_id = ?"
        );
        $stmt->bind_param("ii", $vid, $cid);
        $stmt->execute();
        $v = $stmt->get_result()->fetch_assoc();

        if (!$v) {
            echo json_encode(["success" => true, "version" => null]);
            exit;
        }

        echo json_encode([
            "success" => true,
            "version" => [
                "title" => $v["title"],
                "target_field" => $v["target_field"],
                "data" => json_decode($v["data"], true) ?: new stdClass(),
            ],
        ]);
        exit;
    }

    if ($user["role"] === "superadmin") {
        $res = $conn->query(
            "SELECT a.*, j.title, c.name AS company_name, u.name AS candidate_name, u.email AS candidate_email
             FROM applications a
             JOIN jobs j ON j.id = a.job_id
             JOIN companies c ON c.id = j.company_id
             JOIN users u ON u.id = a.candidate_id
             ORDER BY a.applied_at DESC"
        );
    } else {
        $membership = get_membership($conn, $user["id"]);

        if (!$membership || !member_can($membership, "view_applications")) {
            echo json_encode(["success" => true, "applications" => []]);
            exit;
        }

        $companyId = (int)$membership["company_id"];

        if (!empty($_GET["job_id"])) {
            $jobId = (int)$_GET["job_id"];
            $stmt = $conn->prepare(
                "SELECT a.*, j.title, c.name AS company_name, u.name AS candidate_name, u.email AS candidate_email
                 FROM applications a
                 JOIN jobs j ON j.id = a.job_id
                 JOIN companies c ON c.id = j.company_id
                 JOIN users u ON u.id = a.candidate_id
                 WHERE j.company_id = ? AND a.job_id = ?
                 ORDER BY a.applied_at DESC"
            );
            $stmt->bind_param("ii", $companyId, $jobId);
        } else {
            $stmt = $conn->prepare(
                "SELECT a.*, j.title, c.name AS company_name, u.name AS candidate_name, u.email AS candidate_email
                 FROM applications a
                 JOIN jobs j ON j.id = a.job_id
                 JOIN companies c ON c.id = j.company_id
                 JOIN users u ON u.id = a.candidate_id
                 WHERE j.company_id = ?
                 ORDER BY a.applied_at DESC"
            );
            $stmt->bind_param("i", $companyId);
        }

        $stmt->execute();
        $res = $stmt->get_result();
    }

    $apps = [];
    while ($row = $res->fetch_assoc()) {
        $apps[] = application_row($row);
    }

    echo json_encode(["success" => true, "applications" => $apps]);
    exit;
}

if ($method === "POST") {
    $body = $_POST;
    if (empty($body)) {
        $body = read_json_body();
    }

    $action = $body["action"] ?? "";

    if ($action === "apply") {
        $user = require_role("candidate");
        $jobId = (int)($body["job_id"] ?? 0);

        if (!$jobId) {
            echo json_encode(["success" => false, "message" => "job_id is required"]);
            exit;
        }

        $stmt = $conn->prepare("SELECT status FROM jobs WHERE id = ?");
        $stmt->bind_param("i", $jobId);
        $stmt->execute();
        $res = $stmt->get_result();

        if ($res->num_rows === 0) {
            echo json_encode(["success" => false, "message" => "Job not found"]);
            exit;
        }

        $job = $res->fetch_assoc();
        if ($job["status"] !== "Active") {
            echo json_encode(["success" => false, "message" => "This job is no longer accepting applications"]);
            exit;
        }

                // Screening answers (JSON string)
        $answersJson = null;
        $rawAnswers = $body["screening_answers"] ?? null;
        if ($rawAnswers) {
            $decoded = json_decode($rawAnswers, true);
            if (is_array($decoded)) {
                $clean = [];
                foreach (array_slice($decoded, 0, 10) as $a) {
                    $clean[] = [
                        "question" => mb_substr((string)($a["question"] ?? ""), 0, 500),
                        "type" => (string)($a["type"] ?? "text"),
                        "answer" => mb_substr((string)($a["answer"] ?? ""), 0, 1000),
                    ];
                }
                $answersJson = json_encode($clean, JSON_UNESCAPED_UNICODE);
            }
        }
                // Optional: resume version the candidate chose (must be their own)
        $versionId = null;
        $rawVersion = (int)($body["resume_version_id"] ?? 0);
        if ($rawVersion > 0) {
            $chk = $conn->prepare("SELECT id FROM resume_versions WHERE id = ? AND candidate_id = ?");
            $chk->bind_param("ii", $rawVersion, $user["id"]);
            $chk->execute();
            if ($chk->get_result()->num_rows > 0) {
                $versionId = $rawVersion;
            }
        }

        // FIX: PHP 8.1+ me duplicate insert exception throw karta hai, isliye try/catch
        try {
                       $stmt = $conn->prepare(
                "INSERT INTO applications (job_id, candidate_id, screening_answers, resume_version_id) VALUES (?, ?, ?, ?)"
            );
            $stmt->bind_param("iisi", $jobId, $user["id"], $answersJson, $versionId);
            $stmt->execute();

            echo json_encode(["success" => true, "message" => "Application submitted"]);
        } catch (mysqli_sql_exception $e) {
            if ((int)$e->getCode() === 1062) {
                echo json_encode(["success" => false, "message" => "You already applied to this job"]);
            } else {
                echo json_encode(["success" => false, "message" => "Could not submit application"]);
            }
        }
        exit;
    }

    if ($action === "update_status") {
        $user = require_role(["employer", "superadmin"]);
        $appId = (int)($body["application_id"] ?? 0);
        $status = $body["status"] ?? "";

        $validStatuses = ["Applied", "Shortlisted", "Rejected", "Selected"];
        if (!$appId || !in_array($status, $validStatuses, true)) {
            echo json_encode(["success" => false, "message" => "application_id and a valid status are required"]);
            exit;
        }

        $stmt = $conn->prepare(
            "SELECT j.company_id FROM applications a JOIN jobs j ON j.id = a.job_id WHERE a.id = ?"
        );
        $stmt->bind_param("i", $appId);
        $stmt->execute();
        $res = $stmt->get_result();

        if ($res->num_rows === 0) {
            echo json_encode(["success" => false, "message" => "Application not found"]);
            exit;
        }

        $row = $res->fetch_assoc();

        if ($user["role"] !== "superadmin") {
            $membership = get_membership($conn, $user["id"]);

            if (!$membership || (int)$membership["company_id"] !== (int)$row["company_id"] || !member_can($membership, "manage_applications")) {
                http_response_code(403);
                echo json_encode(["success" => false, "message" => "You can only manage applications for your own company's jobs."]);
                exit;
            }
        }

        $stmt = $conn->prepare(
            "UPDATE applications
             SET status = ?, reviewed_by = ?, reviewed_at = COALESCE(reviewed_at, NOW())
             WHERE id = ?"
        );
        $stmt->bind_param("sii", $status, $user["id"], $appId);
        $stmt->execute();

        echo json_encode(["success" => true, "message" => "Application status updated"]);
        exit;
    }

    echo json_encode(["success" => false, "message" => "Unknown action"]);
    exit;
}

echo json_encode(["success" => false, "message" => "Method not allowed"]);
