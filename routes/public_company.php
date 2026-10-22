<?php
require_once __DIR__ . "/../app/Support/db.php";
require_once __DIR__ . "/../app/Support/helpers.php";

json_cors();
json_cors();

$id = (int)($_GET["id"] ?? 0);
if (!$id) {
    echo json_encode(["success" => false, "message" => "Company id required"]);
    exit;
}

$stmt = $conn->prepare("SELECT * FROM companies WHERE id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();

if (!$row) {
    echo json_encode(["success" => false, "message" => "Company not found"]);
    exit;
}

$extra = json_decode($row["profile_extra"] ?? "{}", true);
if (!is_array($extra)) $extra = [];
$facts = $extra["facts"] ?? [];
unset($extra["facts"]);

$company = [
    "id"          => (int)$row["id"],
    "name"        => $row["name"] ?? "",
    "tagline"     => $facts["tagline"]  ?? ($row["tagline"] ?? ""),
    "industry"    => $facts["industry"] ?? ($row["industry"] ?? ""),
    "size"        => $facts["size"]     ?? ($row["size"] ?? $row["company_size"] ?? ""),
    "type"        => $facts["type"]     ?? ($row["type"] ?? $row["company_type"] ?? ""),
    "founded"     => $facts["founded"]  ?? ($row["founded"] ?? ""),
    "website"     => $row["website"] ?? "",
    "location"    => $row["location"] ?? trim(($row["city"] ?? "") . ", " . ($row["state"] ?? ""), ", "),
    "description" => $row["about"] ?? $row["description"] ?? "",
    "logo_url"    => $row["logo_url"] ?? "",
];

$stmt = $conn->prepare(
    "SELECT id, title, location, job_type FROM jobs
     WHERE company_id = ? AND status = 'Active' ORDER BY id DESC"
);
$stmt->bind_param("i", $id);
$stmt->execute();
$jobs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

echo json_encode(["success" => true, "company" => $company, "extra" => (object)$extra, "jobs" => $jobs]);