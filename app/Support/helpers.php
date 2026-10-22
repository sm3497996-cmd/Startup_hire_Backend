<?php
/**
 * Company + team-permission helpers.
 * Include after config/db.php and config/auth.php.
 */

const ALL_PERMISSIONS = [
    "manage_jobs",
    "view_applications",
    "manage_applications",
    "view_candidates",
    "manage_company_profile",
];

/**
 * Returns ["company_id" => int, "permissions" => [..], "is_owner" => bool]
 * for the given user_id, or null if they belong to no company.
 */
function get_membership($conn, $userId) {
    $stmt = $conn->prepare(
        "SELECT company_id, is_owner, permissions, status
         FROM company_users
         WHERE user_id = ? AND status = 'active'
         LIMIT 1"
    );
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        $stmt->close();
        return null;
    }

    $row = $result->fetch_assoc();
    $stmt->close();

    $perms = $row["permissions"] === "" ? [] : explode(",", $row["permissions"]);

    return [
        "company_id" => (int)$row["company_id"],
        "is_owner" => (bool)$row["is_owner"],
        "permissions" => $perms,
    ];
}

/**
 * True if the membership has the given permission (owners always do).
 */
function member_can($membership, $permission) {
    if (!$membership) return false;
    if ($membership["is_owner"]) return true;
    return in_array($permission, $membership["permissions"], true);
}

/**
 * Finds the employer's company, creating one (and an owner
 * company_users row) the first time they need it — e.g. on
 * their first job post.
 */
function get_or_create_company($conn, $user) {
    $membership = get_membership($conn, $user["id"]);
    if ($membership) {
        return $membership["company_id"];
    }

    $stmt = $conn->prepare(
        "INSERT INTO companies (owner_user_id, name) VALUES (?, ?)"
    );
    $stmt->bind_param("is", $user["id"], $user["name"]);
    $stmt->execute();
    $companyId = $stmt->insert_id;
    $stmt->close();

    $allPerms = implode(",", ALL_PERMISSIONS);
    $stmt = $conn->prepare(
        "INSERT INTO company_users (company_id, user_id, is_owner, permissions, status)
         VALUES (?, ?, 1, ?, 'active')"
    );
    $stmt->bind_param("iis", $companyId, $user["id"], $allPerms);
    $stmt->execute();
    $stmt->close();

    return $companyId;
}

/**
 * Reads a JSON body (for routes that accept JSON instead of
 * multipart form data) and returns it as an associative array.
 */
function read_json_body() {
    $raw = file_get_contents("php://input");
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}