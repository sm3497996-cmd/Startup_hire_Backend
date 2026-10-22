<?php
/**
 * Jobs API
 * ---------------------------------------------------------
 * GET  (no params)        -> public list of Active jobs (browse/search)
 * GET  ?id=5               -> single job detail (public)
 * GET  ?mine=1              -> employer's own company jobs (any status)
 * POST action=create        -> employer creates a job
 * POST action=update        -> employer updates own job
 * POST action=close         -> employer closes own job
 * POST action=reopen        -> employer reopens own job
 * POST action=remove        -> employer removes own job, OR superadmin removes any job
 * ---------------------------------------------------------
 */

require_once __DIR__ . "/../app/Support/db.php";
require_once __DIR__ . "/../app/Support/legacy_auth.php";
require_once __DIR__ . "/../app/Support/helpers.php";

json_cors();

$method = $_SERVER["REQUEST_METHOD"];
function job_row_to_array($row) {
    return [
        "id" => (int)$row["id"],
        "company_id" => (int)$row["company_id"],
        "company_name" => $row["company_name"] ?? null,

        "title" => $row["title"],
        "headline" => $row["headline"] ?? $row["title"],

        // Location
        "location" => $row["location"],
        "city" => $row["city"] ?? null,
        "state" => $row["state"] ?? null,
        "country" => $row["country"] ?? null,
        "pincode" => $row["pincode"] ?? null,
        "address" => $row["address"] ?? null,

        // Job Details
        "category" => $row["category"],
        "job_section" => $row["job_section"] ?? null,
        "industry" => $row["industry"] ?? null,

        "job_type" => $row["job_type"],
        "work_mode" => $row["work_mode"] ?? null,

        // Payment
        "payment_type" => $row["payment_type"] ?? null,
        "payment_duration" => $row["payment_duration"] ?? null,

        "salary" => $row["salary"],

        // Requirements
        "experience_required" => $row["experience_required"] ?? null,
        "experience_months" => isset($row["experience_months"])
            ? (int)$row["experience_months"]
            : 0,

        "openings" => isset($row["openings"])
            ? (int)$row["openings"]
            : 0,

        "required_skills" =>
            $row["required_skills"] ?? $row["requirements"] ?? null,

        "qualification" => $row["qualification"] ?? null,

        // Job Information
        "summary" => $row["summary"] ?? null,
        "description" => $row["description"],
        "rules" => $row["rules"] ?? null,
        "requirements" => $row["requirements"],

        "status" => $row["status"],
        "created_at" => $row["created_at"],
    ];
}
if ($method === "POST") {

    $body = $_POST;

    if (empty($body)) {
        $body = read_json_body();
    }

    $action = $body["action"] ?? "";

    // Authentication
    $user = require_role(["employer", "superadmin"]);

    // =========================================================
    // CREATE JOB
    // =========================================================
    if ($action === "create" || $action === "") {

        if ($user["role"] === "superadmin") {
            $companyId = (int)($body["company_id"] ?? 0);

            if (!$companyId) {
                echo json_encode([
                    "success" => false,
                    "message" => "company_id is required"
                ]);
                exit;
            }
        } else {
            $membership = get_membership($conn, $user["id"]);

            if (!$membership) {
                echo json_encode([
                    "success" => false,
                    "message" => "Company membership not found"
                ]);
                exit;
            }

            $companyId = (int)$membership["company_id"];
        }

        // Basic Job Details
        $title = $body["title"] ?? null;
        $headline = $body["headline"] ?? $title;
        $category = $body["category"] ?? "General";
        $jobType = $body["job_type"] ?? $body["type"] ?? null;
        $workMode = $body["work_mode"] ?? null;
        $industry = $body["industry"] ?? null;
        $jobSection = $body["job_section"] ?? null;

        // Location
        $location = $body["location"] ?? null;
        $city = $body["city"] ?? null;
        $state = $body["state"] ?? null;
        $country = $body["country"] ?? null;
        $pincode = $body["pincode"] ?? null;
        $address = $body["address"] ?? null;

        // Payment & Salary
        $paymentType = $body["payment_type"] ?? null;
        $paymentDuration = $body["payment_duration"] ?? null;
        $salaryStart = $body["salary_start"] ?? null;
        $salaryEnd = $body["salary_end"] ?? null;
        $salary = $body["salary"] ?? (($salaryStart || $salaryEnd) ? trim("₹$salaryStart - ₹$salaryEnd") : null);

        // Requirements
        $requiredSkills = $body["required_skills"] ?? null;
        $requirements = $body["requirements"] ?? $requiredSkills;
        $qualification = $body["qualification"] ?? null;

        $experienceRequired = $body["experience_required"] ?? null;
        $experienceMonths = (int)($body["experience_months"] ?? 0);
        $openings = (int)($body["openings"] ?? 1);

        // Job Information
        $summary = $body["summary"] ?? null;
        $description = $body["description"] ?? null;
        $rules = $body["rules"] ?? null;
        $otherDetails = $body["other_details"] ?? null;

        // Status
        $status = $user["role"] === "superadmin"
    ? ($body["status"] ?? "Active")
    : "Active";

        $stmt = $conn->prepare(
            "INSERT INTO jobs
            (
                company_id,
                posted_by,
                title,
                headline,
                location,
                city,
                state,
                country,
                pincode,
                address,
                category,
                job_section,
                industry,
                work_mode,
                job_type,
                payment_type,
                payment_duration,
                salary,
                experience_required,
                experience_months,
                openings,
                required_skills,
                qualification,
                summary,
                description,
                rules,
                other_details,
                requirements,
                status
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,?)"
        );
        $types = "ii" . str_repeat("s", 17) . "ii" . str_repeat("s", 8);

        $stmt->bind_param(
     $types,
    $companyId,
    $user["id"],
    $title,
    $headline,
    $location,
    $city,
    $state,
    $country,
    $pincode,
    $address,
    $category,
    $jobSection,
    $industry,
    $workMode,
    $jobType,
    $paymentType,
    $paymentDuration,
    $salary,
    $experienceRequired,
    $experienceMonths,
    $openings,
    $requiredSkills,
    $qualification,
    $summary,
    $description,
    $rules,
    $otherDetails,
    $requirements,
    $status
);

        if (!$stmt->execute()) {
            echo json_encode([
                "success" => false,
                "message" => "Failed to post job",
                "error" => $stmt->error
            ]);
            exit;
        }

        echo json_encode([
            "success" => true,
            "message" => "Job posted",
            "job_id" => $stmt->insert_id
        ]);

        $stmt->close();
        exit;
    }


    // =========================================================
    // UPDATE / CLOSE / REOPEN / REMOVE
    // =========================================================

    if (in_array($action, ["update", "close", "reopen", "remove"], true)) {

        $jobId = (int)($body["job_id"] ?? $body["id"] ?? 0);

        if (!$jobId) {
            echo json_encode([
                "success" => false,
                "message" => "job_id is required"
            ]);
            exit;
        }

        // Get job's company
        $stmt = $conn->prepare(
            "SELECT company_id, status FROM jobs WHERE id = ?"
        );

        $stmt->bind_param("i", $jobId);
        $stmt->execute();

        $res = $stmt->get_result();

        if ($res->num_rows === 0) {
            echo json_encode([
                "success" => false,
                "message" => "Job not found"
            ]);
            exit;
        }

        $job = $res->fetch_assoc();
        $stmt->close();


        // Check company permission
        if ($user["role"] !== "superadmin") {

            $membership = get_membership($conn, $user["id"]);

            if (
                !$membership ||
                $membership["company_id"] !== (int)$job["company_id"] ||
                !member_can($membership, "manage_jobs")
            ) {
                http_response_code(403);

                echo json_encode([
                    "success" => false,
                    "message" => "You can only manage your own company's jobs."
                ]);

                exit;
            }
        }
        if (
    $user["role"] !== "superadmin" &&
    $action === "reopen" &&
    in_array($job["status"], ["Removed", "Flagged", "Pending"], true)
) {
    http_response_code(403);
    echo json_encode([
        "success" => false,
        "message" => "This job was blocked by admin. Contact support."
    ]);
    exit;
}


        // =====================================================
        // UPDATE
        // =====================================================

        if ($action === "update") {
            if ($user["role"] !== "superadmin") {
    unset($body["status"]);
}

            $fields = [];
            $params = [];
            $types = "";

            foreach ([
                "title" => "s",
                "headline" => "s",
                "location" => "s",
                "city" => "s",
                "state" => "s",
                "country" => "s",
                "pincode" => "s",
                "address" => "s",
                "category" => "s",
                "job_section" => "s",
                "industry" => "s",
                "work_mode" => "s",
                "job_type" => "s",
                "payment_type" => "s",
                "payment_duration" => "s",
                "salary" => "s",
                "experience_required" => "s",
                "experience_months" => "i",
                "openings" => "i",
                "required_skills" => "s",
                "qualification" => "s",
                "summary" => "s",
                "description" => "s",
                "rules" => "s",
                "requirements" => "s",
                "status" => "s"
            ] as $field => $type) {

                if (isset($body[$field])) {

                    $fields[] = "$field = ?";
                    $params[] = $body[$field];
                    $types .= $type;
                }
            }

            if (empty($fields)) {
                echo json_encode([
                    "success" => false,
                    "message" => "Nothing to update"
                ]);
                exit;
            }

            $params[] = $jobId;
            $types .= "i";

            $sql = "UPDATE jobs SET "
                . implode(", ", $fields)
                . " WHERE id = ?";

            $stmt = $conn->prepare($sql);

            $stmt->bind_param(
                $types,
                ...$params
            );

            if (!$stmt->execute()) {
                echo json_encode([
                    "success" => false,
                    "message" => "Failed to update job",
                    "error" => $stmt->error
                ]);
                exit;
            }

            echo json_encode([
                "success" => true,
                "message" => "Job updated"
            ]);

            $stmt->close();
            exit;
        }


        // =====================================================
        // CLOSE = INACTIVE
        // =====================================================

        if ($action === "close") {

            $stmt = $conn->prepare(
                "UPDATE jobs SET status = 'Inactive' WHERE id = ?"
            );

            $stmt->bind_param("i", $jobId);
            $stmt->execute();

            echo json_encode([
                "success" => true,
                "message" => "Job marked as inactive"
            ]);

            $stmt->close();
            exit;
        }


        // =====================================================
        // REOPEN = ACTIVE
        // =====================================================

        if ($action === "reopen") {

            $stmt = $conn->prepare(
                "UPDATE jobs SET status = 'Active' WHERE id = ?"
            );

            $stmt->bind_param("i", $jobId);
            $stmt->execute();

            echo json_encode([
                "success" => true,
                "message" => "Job activated"
            ]);

            $stmt->close();
            exit;
        }


        // =====================================================
        // REMOVE = INACTIVE (DO NOT DELETE)
        // =====================================================

        if ($action === "remove") {

            $stmt = $conn->prepare(
                "UPDATE jobs SET status = 'Inactive' WHERE id = ?"
            );

            $stmt->bind_param("i", $jobId);
            $stmt->execute();

            echo json_encode([
                "success" => true,
                "message" => "Job marked as inactive"
            ]);

            $stmt->close();
            exit;
        }
    }


    echo json_encode([
        "success" => false,
        "message" => "Unknown action"
    ]);

    exit;
}
if ($method === "GET") {
    $base = "SELECT j.*, c.name AS company_name, c.description AS company_description
             FROM jobs j JOIN companies c ON c.id = j.company_id";

    if (!empty($_GET["mine"])) {
        $user = require_role("employer");
        $membership = get_membership($conn, $user["id"]);
        if (!$membership) {
            echo json_encode(["success" => true, "jobs" => []]);
            exit;
        }
        $stmt = $conn->prepare("$base WHERE j.company_id = ? ORDER BY j.created_at DESC");
        $stmt->bind_param("i", $membership["company_id"]);
    } else {
        $stmt = $conn->prepare("$base WHERE j.status = 'Active' ORDER BY j.created_at DESC");
    }

    $stmt->execute();
    $res = $stmt->get_result();
    $jobs = [];
    while ($row = $res->fetch_assoc()) {
        $job = job_row_to_array($row);
        $job["company_description"] = $row["company_description"];
        $jobs[] = $job;
    }
    echo json_encode(["success" => true, "jobs" => $jobs]);
    exit;
}

echo json_encode([
    "success" => false,
    "message" => "Method not allowed"
]);
