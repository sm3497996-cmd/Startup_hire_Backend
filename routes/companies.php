<?php
/**
 * Companies + Team API (employer only)
 * ---------------------------------------------------------
 * GET  action=profile            -> own company profile (auto-created)
 * POST action=update_profile     -> update name/description/website/location/logo_url
 *
 * GET  action=team               -> list this company's team members
 * POST action=remove_member      -> member_user_id
 * POST action=update_permissions -> member_user_id, permissions[]
 * POST action=toggle_status      -> member_user_id
 *
 * INVITES (owner side)
 * POST action=send_invite        -> email, permissions[]   (add_member is kept as an alias)
 * GET  action=company_invites    -> pending invites of this company
 * POST action=cancel_invite      -> invite_id
 *
 * INVITES (invited person side)
 * GET  action=my_invites         -> pending invites addressed to my email
 * POST action=accept_invite      -> invite_id, confirm_switch (optional bool)
 * POST action=decline_invite     -> invite_id
 * ---------------------------------------------------------
 */

require_once __DIR__ . "/../app/Support/db.php";
require_once __DIR__ . "/../app/Support/legacy_auth.php";
require_once __DIR__ . "/../app/Support/helpers.php";

json_cors();

$user = require_role("employer");
$method = $_SERVER["REQUEST_METHOD"];

const INVITE_VALID_DAYS = 7;
const MAX_PENDING_INVITES = 50;

$action = $method === "GET" ? ($_GET["action"] ?? "profile") : null;

if ($method === "POST") {
    $body = $_POST;
    if (empty($body)) {
        $body = read_json_body();
    }
    $action = $body["action"] ?? "";
}

function json_out($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data);
    exit;
}

function clean_permissions($raw) {
    if (!is_array($raw)) $raw = explode(",", (string)$raw);
    return array_values(array_intersect($raw, ALL_PERMISSIONS));
}

/** Logged-in user's email, straight from the DB (does not depend on what require_role returns). */
function my_email($conn, $userId) {
    $stmt = $conn->prepare("SELECT email FROM users WHERE id = ?");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return strtolower(trim($row["email"] ?? ""));
}

/* ------------------------------------------------------------------ */
/* PROFILE                                                            */
/* ------------------------------------------------------------------ */

if ($action === "profile" && $method === "GET") {
    $companyId = get_or_create_company($conn, $user);

    $stmt = $conn->prepare("SELECT * FROM companies WHERE id = ?");
    $stmt->bind_param("i", $companyId);
    $stmt->execute();
    $company = $stmt->get_result()->fetch_assoc();

    echo json_encode(["success" => true, "company" => $company]);
    exit;
}

if ($action === "update_profile" && $method === "POST") {
    $membership = get_membership($conn, $user["id"]);

    if (!$membership || !member_can($membership, "manage_company_profile")) {
        json_out(["success" => false, "message" => "You don't have permission to edit the company profile."], 403);
    }

    $fields = [];
    $params = [];
    $types = "";

    foreach (["name", "description", "website", "location", "logo_url"] as $f) {
        if (isset($body[$f])) {
            $fields[] = "$f = ?";
            $params[] = $body[$f];
            $types .= "s";
        }
    }

    if (empty($fields)) {
        json_out(["success" => false, "message" => "Nothing to update"]);
    }

    $params[] = $membership["company_id"];
    $types .= "i";

    $stmt = $conn->prepare("UPDATE companies SET " . implode(", ", $fields) . " WHERE id = ?");
    $stmt->bind_param($types, ...$params);
    $stmt->execute();

    json_out(["success" => true, "message" => "Company profile updated"]);
}

/* ------------------------------------------------------------------ */
/* TEAM                                                               */
/* ------------------------------------------------------------------ */

if ($action === "team" && $method === "GET") {
    $membership = get_membership($conn, $user["id"]);

    if (!$membership) {
        json_out(["success" => true, "team" => []]);
    }

    $stmt = $conn->prepare(
        "SELECT cu.id, cu.user_id, cu.is_owner, cu.permissions, cu.status, cu.created_at,
                u.name, u.email
         FROM company_users cu
         JOIN users u ON u.id = cu.user_id
         WHERE cu.company_id = ?
         ORDER BY cu.is_owner DESC, cu.created_at ASC"
    );
    $stmt->bind_param("i", $membership["company_id"]);
    $stmt->execute();
    $res = $stmt->get_result();

    $team = [];
    while ($row = $res->fetch_assoc()) {
        $team[] = [
            "membership_id" => (int)$row["id"],
            "user_id" => (int)$row["user_id"],
            "name" => $row["name"],
            "email" => $row["email"],
            "is_owner" => (bool)$row["is_owner"],
            "permissions" => $row["permissions"] === "" ? [] : explode(",", $row["permissions"]),
            "status" => $row["status"],
            "added_on" => $row["created_at"],
        ];
    }

    json_out(["success" => true, "team" => $team]);
}

if ($action === "remove_member" && $method === "POST") {
    $membership = get_membership($conn, $user["id"]);
    $targetUserId = (int)($body["member_user_id"] ?? 0);

    if (!$membership || !$membership["is_owner"]) {
        json_out(["success" => false, "message" => "Only the company owner can remove team members."], 403);
    }

    if ($targetUserId === $user["id"]) {
        json_out(["success" => false, "message" => "You cannot remove yourself as owner."]);
    }

    $stmt = $conn->prepare("DELETE FROM company_users WHERE company_id = ? AND user_id = ? AND is_owner = 0");
    $stmt->bind_param("ii", $membership["company_id"], $targetUserId);
    $stmt->execute();

    json_out(["success" => true, "message" => "Team member removed"]);
}

if ($action === "update_permissions" && $method === "POST") {
    $membership = get_membership($conn, $user["id"]);
    $targetUserId = (int)($body["member_user_id"] ?? 0);
    $permissions = clean_permissions($body["permissions"] ?? []);

    if (!$membership || !$membership["is_owner"]) {
        json_out(["success" => false, "message" => "Only the company owner can change permissions."], 403);
    }

    $permsCsv = implode(",", $permissions);
    $stmt = $conn->prepare(
        "UPDATE company_users SET permissions = ? WHERE company_id = ? AND user_id = ? AND is_owner = 0"
    );
    $stmt->bind_param("sii", $permsCsv, $membership["company_id"], $targetUserId);
    $stmt->execute();

    json_out(["success" => true, "message" => "Permissions updated"]);
}

if ($action === "toggle_status" && $method === "POST") {
    $membership = get_membership($conn, $user["id"]);
    $targetUserId = (int)($body["member_user_id"] ?? 0);

    if (!$membership || !$membership["is_owner"]) {
        json_out(["success" => false, "message" => "Only the company owner can change member status."], 403);
    }

    $stmt = $conn->prepare(
        "SELECT status FROM company_users WHERE company_id = ? AND user_id = ? AND is_owner = 0"
    );
    $stmt->bind_param("ii", $membership["company_id"], $targetUserId);
    $stmt->execute();
    $target = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$target) {
        json_out(["success" => false, "message" => "Member not found."]);
    }

    // Reactivating: the person must not be active in another company.
    if ($target["status"] === "inactive") {
        $stmt = $conn->prepare(
            "SELECT COUNT(*) AS c FROM company_users
             WHERE user_id = ? AND status = 'active' AND company_id <> ?"
        );
        $stmt->bind_param("ii", $targetUserId, $membership["company_id"]);
        $stmt->execute();
        $other = (int)$stmt->get_result()->fetch_assoc()["c"];
        $stmt->close();

        if ($other > 0) {
            json_out([
                "success" => false,
                "message" => "This person has joined or created another company, so they can't be reactivated. Remove them and send a new invitation instead.",
            ]);
        }
    }

    $stmt = $conn->prepare(
        "UPDATE company_users
         SET status = IF(status = 'active', 'inactive', 'active')
         WHERE company_id = ? AND user_id = ? AND is_owner = 0"
    );
    $stmt->bind_param("ii", $membership["company_id"], $targetUserId);
    $stmt->execute();

    json_out(["success" => true, "message" => "Member status updated"]);
}
/* ------------------------------------------------------------------ */
/* INVITES - owner side                                               */
/* ------------------------------------------------------------------ */

// "add_member" still works (old frontend) but now only creates an invite.
if (($action === "send_invite" || $action === "add_member") && $method === "POST") {
    $membership = get_membership($conn, $user["id"]);

    if (!$membership || !$membership["is_owner"]) {
        json_out(["success" => false, "message" => "Only the company owner can invite team members."], 403);
    }

    $email = strtolower(trim($body["email"] ?? ""));
    $permissions = clean_permissions($body["permissions"] ?? []);
    $permsCsv = implode(",", $permissions);
    $companyId = $membership["company_id"];

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        json_out(["success" => false, "message" => "Enter a valid email address."]);
    }

    if ($email === my_email($conn, $user["id"])) {
        json_out(["success" => false, "message" => "You are already the owner of this company."]);
    }

    // Already an active member of THIS company?
    $stmt = $conn->prepare(
        "SELECT cu.id FROM company_users cu
         JOIN users u ON u.id = cu.user_id
         WHERE cu.company_id = ? AND LOWER(u.email) = ? AND cu.status = 'active'"
    );
    $stmt->bind_param("is", $companyId, $email);
    $stmt->execute();
    if ($stmt->get_result()->num_rows > 0) {
        json_out(["success" => false, "message" => "This person is already on your team."]);
    }
    $stmt->close();

    // Pending invite limit (stops spamming)
    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS c FROM company_invites
         WHERE company_id = ? AND status = 'pending' AND expires_at > NOW()"
    );
    $stmt->bind_param("i", $companyId);
    $stmt->execute();
    $count = (int)$stmt->get_result()->fetch_assoc()["c"];
    $stmt->close();
    if ($count >= MAX_PENDING_INVITES) {
        json_out(["success" => false, "message" => "Too many pending invitations. Cancel some first."]);
    }

    // Same email already invited? refresh it instead of creating a duplicate.
    $stmt = $conn->prepare(
        "SELECT id FROM company_invites WHERE company_id = ? AND email = ? AND status = 'pending' LIMIT 1"
    );
    $stmt->bind_param("is", $companyId, $email);
    $stmt->execute();
    $existing = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $days = INVITE_VALID_DAYS;
    if ($existing) {
        $stmt = $conn->prepare(
            "UPDATE company_invites
             SET permissions = ?, invited_by = ?, expires_at = DATE_ADD(NOW(), INTERVAL $days DAY)
             WHERE id = ?"
        );
        $stmt->bind_param("sii", $permsCsv, $user["id"], $existing["id"]);
    } else {
        $stmt = $conn->prepare(
            "INSERT INTO company_invites (company_id, invited_by, email, permissions, status, expires_at)
             VALUES (?, ?, ?, ?, 'pending', DATE_ADD(NOW(), INTERVAL $days DAY))"
        );
        $stmt->bind_param("iiss", $companyId, $user["id"], $email, $permsCsv);
    }
    $stmt->execute();
    $stmt->close();

    // Same reply whether or not the email has an account (no email-list leak).
    json_out([
        "success" => true,
        "message" => "Invitation sent to $email. They will see it in their dashboard and can accept or decline (valid for $days days).",
    ]);
}

if ($action === "company_invites" && $method === "GET") {
    $membership = get_membership($conn, $user["id"]);

    if (!$membership || !$membership["is_owner"]) {
        json_out(["success" => true, "invites" => []]);
    }

    $stmt = $conn->prepare(
        "SELECT id, email, permissions, created_at, expires_at
         FROM company_invites
         WHERE company_id = ? AND status = 'pending' AND expires_at > NOW()
         ORDER BY created_at DESC"
    );
    $stmt->bind_param("i", $membership["company_id"]);
    $stmt->execute();
    $res = $stmt->get_result();

    $invites = [];
    while ($row = $res->fetch_assoc()) {
        $invites[] = [
            "id" => (int)$row["id"],
            "email" => $row["email"],
            "permissions" => $row["permissions"] === "" ? [] : explode(",", $row["permissions"]),
            "sent_on" => $row["created_at"],
            "expires_on" => $row["expires_at"],
        ];
    }

    json_out(["success" => true, "invites" => $invites]);
}

if ($action === "cancel_invite" && $method === "POST") {
    $membership = get_membership($conn, $user["id"]);
    $inviteId = (int)($body["invite_id"] ?? 0);

    if (!$membership || !$membership["is_owner"]) {
        json_out(["success" => false, "message" => "Only the company owner can cancel invitations."], 403);
    }

    $stmt = $conn->prepare(
        "UPDATE company_invites SET status = 'cancelled', responded_at = NOW()
         WHERE id = ? AND company_id = ? AND status = 'pending'"
    );
    $stmt->bind_param("ii", $inviteId, $membership["company_id"]);
    $stmt->execute();

    json_out(["success" => true, "message" => "Invitation cancelled"]);
}

/* ------------------------------------------------------------------ */
/* INVITES - invited person side                                      */
/* ------------------------------------------------------------------ */

if ($action === "my_invites" && $method === "GET") {
    $email = my_email($conn, $user["id"]);

    $stmt = $conn->prepare(
        "SELECT i.id, i.permissions, i.created_at, i.expires_at,
                c.name AS company_name, u.name AS invited_by_name
         FROM company_invites i
         JOIN companies c ON c.id = i.company_id
         LEFT JOIN users u ON u.id = i.invited_by
         WHERE LOWER(i.email) = ? AND i.status = 'pending' AND i.expires_at > NOW()
         ORDER BY i.created_at DESC"
    );
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $res = $stmt->get_result();

    $invites = [];
    while ($row = $res->fetch_assoc()) {
        $invites[] = [
            "id" => (int)$row["id"],
            "company_name" => $row["company_name"],
            "invited_by" => $row["invited_by_name"],
            "permissions" => $row["permissions"] === "" ? [] : explode(",", $row["permissions"]),
            "sent_on" => $row["created_at"],
            "expires_on" => $row["expires_at"],
        ];
    }

    json_out(["success" => true, "invites" => $invites]);
}

if ($action === "decline_invite" && $method === "POST") {
    $inviteId = (int)($body["invite_id"] ?? 0);
    $email = my_email($conn, $user["id"]);

    $stmt = $conn->prepare(
        "UPDATE company_invites SET status = 'declined', responded_at = NOW()
         WHERE id = ? AND LOWER(email) = ? AND status = 'pending'"
    );
    $stmt->bind_param("is", $inviteId, $email);
    $stmt->execute();

    json_out(["success" => true, "message" => "Invitation declined"]);
}

if ($action === "accept_invite" && $method === "POST") {
    $inviteId = (int)($body["invite_id"] ?? 0);
    $confirmSwitch = !empty($body["confirm_switch"]);
    $email = my_email($conn, $user["id"]);

    // Only the person the invite was sent to can accept it.
    $stmt = $conn->prepare(
        "SELECT id, company_id, permissions FROM company_invites
         WHERE id = ? AND LOWER(email) = ? AND status = 'pending' AND expires_at > NOW()"
    );
    $stmt->bind_param("is", $inviteId, $email);
    $stmt->execute();
    $invite = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$invite) {
        json_out(["success" => false, "message" => "This invitation is no longer valid."]);
    }

    $current = get_membership($conn, $user["id"]);

    if ($current && $current["company_id"] === (int)$invite["company_id"]) {
        json_out(["success" => false, "message" => "You are already a member of this company."]);
    }

    if ($current) {
        // Member (not owner) of another company -> must leave it first.
        if (!$current["is_owner"]) {
            json_out(["success" => false, "message" => "You already belong to another company. Ask its owner to remove you first."]);
        }

        // Owner of a company that has other active people -> cannot just walk away.
        $stmt = $conn->prepare(
            "SELECT COUNT(*) AS c FROM company_users WHERE company_id = ? AND user_id <> ? AND status = 'active'"
        );
        $stmt->bind_param("ii", $current["company_id"], $user["id"]);
        $stmt->execute();
        $others = (int)$stmt->get_result()->fetch_assoc()["c"];
        $stmt->close();

        if ($others > 0) {
            json_out(["success" => false, "message" => "You own a company with other team members. Remove them or transfer it before joining another company."]);
        }

        // Solo owner (e.g. auto-created empty company): allowed, but only with explicit confirmation.
        if (!$confirmSwitch) {
            json_out([
                "success" => false,
                "needs_confirm" => true,
                "message" => "You already have your own company. Joining this one will make your own company inactive for your account. Continue?",
            ]);
        }
    }

    $conn->begin_transaction();
    try {
        if ($current) {
            $stmt = $conn->prepare("UPDATE company_users SET status = 'inactive' WHERE company_id = ? AND user_id = ?");
            $stmt->bind_param("ii", $current["company_id"], $user["id"]);
            $stmt->execute();
            $stmt->close();
        }

        $stmt = $conn->prepare(
            "INSERT INTO company_users (company_id, user_id, is_owner, permissions, status)
             VALUES (?, ?, 0, ?, 'active')
             ON DUPLICATE KEY UPDATE permissions = VALUES(permissions), status = 'active', is_owner = 0"
        );
        $stmt->bind_param("iis", $invite["company_id"], $user["id"], $invite["permissions"]);
        $stmt->execute();
        $stmt->close();

        $stmt = $conn->prepare("UPDATE company_invites SET status = 'accepted', responded_at = NOW() WHERE id = ?");
        $stmt->bind_param("i", $inviteId);
        $stmt->execute();
        $stmt->close();

        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        json_out(["success" => false, "message" => "Could not accept the invitation. Please try again."], 500);
    }

    json_out(["success" => true, "message" => "You joined the company."]);
}
if ($action === "my_company" && $method === "GET") {
    $membership = get_membership($conn, $user["id"]);

    if (!$membership) {
        json_out(["success" => true, "company" => null]);
    }

    $stmt = $conn->prepare("SELECT * FROM companies WHERE id = ?");
    $stmt->bind_param("i", $membership["company_id"]);
    $stmt->execute();
    $company = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    json_out(["success" => true, "company" => $company]);
}

json_out(["success" => false, "message" => "Unknown action"]);