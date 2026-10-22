<?php
ini_set("display_errors", 1);
error_reporting(E_ALL);

require_once __DIR__ . "/../app/Support/db.php";
require_once __DIR__ . "/../app/Support/legacy_auth.php";
require_once __DIR__ . "/../app/Support/helpers.php";

json_cors();

$user = require_role(["employer"]);

$companyId = 0;

// 1) membership se
$m = get_membership($conn, $user["id"]);
if ($m && !empty($m["company_id"])) {
    $companyId = (int)$m["company_id"];
}

// 2) companies table se (owner column ke alag naam try karta hai)
if (!$companyId) {
    foreach (["owner_id", "user_id", "created_by", "employer_id"] as $col) {
        $chk = $conn->query("SHOW COLUMNS FROM companies LIKE '$col'");
        if ($chk && $chk->num_rows) {
            $stmt = $conn->prepare("SELECT id FROM companies WHERE $col = ? LIMIT 1");
            $stmt->bind_param("i", $user["id"]);
            $stmt->execute();
            $r = $stmt->get_result()->fetch_assoc();
            if ($r) { $companyId = (int)$r["id"]; break; }
        }
    }
}

if (!$companyId) {
    echo json_encode([
        "success" => false,
        "message" => "No company found",
        "debug_user" => $user,
        "debug_membership" => $m,
    ]);
    exit;
}

function load_extra($conn, $companyId) {
    $stmt = $conn->prepare("SELECT profile_extra FROM companies WHERE id = ?");
    $stmt->bind_param("i", $companyId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $arr = json_decode($row["profile_extra"] ?? "{}", true);
    return is_array($arr) ? $arr : [];
}

if ($_SERVER["REQUEST_METHOD"] === "GET") {
    echo json_encode([
        "success" => true,
        "company_id" => $companyId,
        "extra" => (object)load_extra($conn, $companyId),
    ]);
    exit;
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $extra = json_decode($_POST["extra"] ?? "{}", true);
    if (!is_array($extra)) $extra = [];

    if (!empty($_FILES["cover"]["tmp_name"])) {
        $ext = strtolower(pathinfo($_FILES["cover"]["name"], PATHINFO_EXTENSION));
        if (!in_array($ext, ["jpg", "jpeg", "png", "webp"], true) || $_FILES["cover"]["size"] > 5 * 1024 * 1024) {
            echo json_encode(["success" => false, "message" => "Use JPG/PNG/WEBP up to 5 MB"]);
            exit;
        }
        $dir = __DIR__ . "/../uploads/covers/";
        if (!is_dir($dir)) mkdir($dir, 0777, true);
        $name = "company_{$companyId}_" . time() . "." . $ext;
        move_uploaded_file($_FILES["cover"]["tmp_name"], $dir . $name);
        $extra["cover_url"] = "uploads/covers/" . $name;
    }

    $json = json_encode($extra);
    $stmt = $conn->prepare("UPDATE companies SET profile_extra = ? WHERE id = ?");
    $stmt->bind_param("si", $json, $companyId);
    $stmt->execute();

    echo json_encode(["success" => true, "company_id" => $companyId, "extra" => (object)$extra]);
    exit;
}

echo json_encode(["success" => false, "message" => "Method not allowed"]);