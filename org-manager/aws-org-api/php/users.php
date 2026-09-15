<?php
/**
 * Read-only: search portal users (for adding members / choosing a head).
 *
 * STDIN: { "q": "hass", "limit": 30, "ids": [2560] }
 *   q     substring of name / last name / login / id (optional)
 *   ids   explicit user ids to return (optional; ignores q)
 * Output: { ok, users: [ {id, name, active, departments:[..], groups:[..], roles:[{roleId,name,via}] } ] }
 */

declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

$req = a7_read_request();
$q = trim((string) ($req['q'] ?? ''));
$limit = max(1, min(100, (int) ($req['limit'] ?? 30)));
$ids = array_values(array_filter(array_map('intval', $req['ids'] ?? [])));
$withRoles = (bool) ($req['with_roles'] ?? false);

$m = a7_db();

if ($ids) {
    $where = 'ID IN (' . implode(',', $ids) . ')';
} elseif ($q !== '') {
    $esc = $m->real_escape_string($q);
    $where = "ACTIVE='Y' AND (NAME LIKE '%$esc%' OR LAST_NAME LIKE '%$esc%' OR LOGIN LIKE '%$esc%' OR CONCAT(NAME,' ',LAST_NAME) LIKE '%$esc%'" . (ctype_digit($q) ? " OR ID=" . (int) $q : '') . ')';
} else {
    $where = "ACTIVE='Y'";
}

$rows = [];
$res = $m->query("SELECT ID, NAME, LAST_NAME, LOGIN, ACTIVE FROM b_user WHERE $where ORDER BY NAME, LAST_NAME LIMIT $limit");
while ($res && ($r = $res->fetch_assoc())) {
    $full = trim(($r['NAME'] ?? '') . ' ' . ($r['LAST_NAME'] ?? ''));
    $rows[] = [
        'id' => (int) $r['ID'],
        'name' => $full !== '' ? $full : $r['LOGIN'],
        'active' => $r['ACTIVE'] === 'Y',
    ];
}

// Departments in one pass (UF_DEPARTMENT lives in b_uts_user as a serialized array).
if ($rows) {
    $in = implode(',', array_map(fn($u) => $u['id'], $rows));
    $dept = [];
    $res = $m->query("SELECT VALUE_ID, UF_DEPARTMENT FROM b_uts_user WHERE VALUE_ID IN ($in)");
    while ($res && ($r = $res->fetch_assoc())) {
        $val = $r['UF_DEPARTMENT'];
        $arr = @unserialize((string) $val);
        if (!is_array($arr)) {
            $arr = $val !== null && $val !== '' ? [(int) $val] : [];
        }
        $dept[(int) $r['VALUE_ID']] = array_values(array_filter(array_map('intval', $arr)));
    }
    $groups = [];
    $res = $m->query("SELECT USER_ID, GROUP_ID FROM b_user_group WHERE USER_ID IN ($in)");
    while ($res && ($r = $res->fetch_assoc())) {
        $groups[(int) $r['USER_ID']][] = (int) $r['GROUP_ID'];
    }
    foreach ($rows as &$u) {
        $u['departments'] = $dept[$u['id']] ?? [];
        $u['groups'] = $groups[$u['id']] ?? [];
        if ($withRoles) {
            $u['roles'] = a7_user_roles($u['id']);
        }
    }
    unset($u);
}

a7_ok(['users' => $rows, 'total' => count($rows)]);
