#!/usr/bin/env php
<?php
declare(strict_types=1);
require '/opt/a7-org-api/current/php/bootstrap.php';
\Bitrix\Main\Loader::includeModule('crm');

$m = a7_db();

// Find users matching Amna Khan / Raoof
$needles = ['amna', 'raoof', 'rauf', 'raouf'];
echo "=== User search ===\n";
$q = $m->query(
    "SELECT ID, ACTIVE, LOGIN, EMAIL, NAME, LAST_NAME, SECOND_NAME
     FROM b_user
     WHERE LOWER(CONCAT_WS(' ', NAME, LAST_NAME, SECOND_NAME, LOGIN, EMAIL)) LIKE '%amna%'
        OR LOWER(CONCAT_WS(' ', NAME, LAST_NAME, SECOND_NAME, LOGIN, EMAIL)) LIKE '%raoof%'
        OR LOWER(CONCAT_WS(' ', NAME, LAST_NAME, SECOND_NAME, LOGIN, EMAIL)) LIKE '%rauf%'
        OR LOWER(CONCAT_WS(' ', NAME, LAST_NAME, SECOND_NAME, LOGIN, EMAIL)) LIKE '%raouf%'
     ORDER BY ID"
);
$found = [];
while ($r = $q->fetch_assoc()) {
    $id = (int) $r['ID'];
    $name = trim(($r['NAME'] ?? '') . ' ' . ($r['LAST_NAME'] ?? '') . ' ' . ($r['SECOND_NAME'] ?? ''));
    echo "U$id active={$r['ACTIVE']} login={$r['LOGIN']} email={$r['EMAIL']} name=$name\n";
    $found[$id] = $r;
}

if (!$found) {
    echo "No users found\n";
    exit(1);
}

function deptNames(mysqli $m, array $deptIds): array
{
    if (!$deptIds) {
        return [];
    }
    $in = implode(',', array_map('intval', $deptIds));
    $out = [];
    $q = $m->query("SELECT ID, NAME FROM b_iblock_section WHERE ID IN ($in)");
    while ($r = $q->fetch_assoc()) {
        $out[(int) $r['ID']] = $r['NAME'];
    }
    return $out;
}

function rolesOf(mysqli $m, int $uid): array
{
    $groups = array_map('intval', \CUser::GetUserGroup($uid));
    $rels = ["U$uid"];
    foreach ($groups as $g) {
        $rels[] = "G$g";
    }
    $esc = array_map(fn($x) => "'" . $m->real_escape_string($x) . "'", $rels);
    $in = implode(',', $esc);
    $out = [];
    $q = $m->query(
        "SELECT rr.ROLE_ID, rr.RELATION, r.NAME
         FROM b_crm_role_relation rr
         LEFT JOIN b_crm_role r ON r.ID = rr.ROLE_ID
         WHERE rr.RELATION IN ($in)
         ORDER BY rr.ROLE_ID"
    );
    while ($r = $q->fetch_assoc()) {
        $out[] = "role {$r['ROLE_ID']} ({$r['NAME']}) via {$r['RELATION']}";
    }
    return [$groups, $out];
}

foreach ($found as $uid => $u) {
    $name = trim(($u['NAME'] ?? '') . ' ' . ($u['LAST_NAME'] ?? ''));
    echo "\n======== U$uid $name ========\n";
    $cu = \CUser::GetByID($uid)->Fetch();
    $depts = is_array($cu['UF_DEPARTMENT'] ?? null) ? array_map('intval', $cu['UF_DEPARTMENT']) : [];
    $dnames = deptNames($m, $depts);
    $deptLine = [];
    foreach ($depts as $d) {
        $deptLine[] = "$d:" . ($dnames[$d] ?? '?');
    }
    echo "active={$u['ACTIVE']} email={$u['EMAIL']}\n";
    echo "departments=" . ($deptLine ? implode(', ', $deptLine) : '(none)') . "\n";

    [$groups, $roles] = rolesOf($m, $uid);
    echo "groups=" . json_encode($groups) . "\n";
    echo "crm_roles:\n";
    if ($roles) {
        foreach ($roles as $line) {
            echo "  $line\n";
        }
    } else {
        echo "  (none direct/group)\n";
    }

    $p = new \CCrmPerms($uid);
    foreach (['LEAD', 'CONTACT', 'DEAL', 'DYNAMIC_1042', 'DYNAMIC_1042_C13', 'DYNAMIC_1042_C14'] as $e) {
        $r = (string) $p->GetPermType($e, 'READ');
        $w = (string) $p->GetPermType($e, 'WRITE');
        echo sprintf("  %-18s READ=%-2s WRITE=%s\n", $e, $r === '' ? '-' : $r, $w === '' ? '-' : $w);
    }

    $own = (int) ($m->query("SELECT COUNT(*) c FROM b_crm_lead WHERE ASSIGNED_BY_ID=$uid")->fetch_assoc()['c'] ?? 0);
    echo "own_assigned_leads=$own\n";

    // Estimate visible leads by scope
    $scope = (string) $p->GetPermType('LEAD', 'READ');
    if ($scope === 'X' || $scope === 'O') {
        $vis = (int) ($m->query("SELECT COUNT(*) c FROM b_crm_lead")->fetch_assoc()['c'] ?? 0);
        echo "visible_leads_estimate=ALL $vis (scope=$scope)\n";
    } elseif ($scope === 'D') {
        // department members
        $memberIds = [$uid];
        if ($depts) {
            $all = $m->query("SELECT VALUE_ID, UF_DEPARTMENT FROM b_uts_user WHERE UF_DEPARTMENT IS NOT NULL AND UF_DEPARTMENT!=''");
            while ($row = $all->fetch_assoc()) {
                $d = @unserialize($row['UF_DEPARTMENT']);
                if (!is_array($d)) {
                    continue;
                }
                $d = array_map('intval', $d);
                if (array_intersect($depts, $d)) {
                    $memberIds[] = (int) $row['VALUE_ID'];
                }
            }
        }
        $memberIds = array_values(array_unique($memberIds));
        $in = implode(',', $memberIds);
        $vis = (int) ($m->query("SELECT COUNT(*) c FROM b_crm_lead WHERE ASSIGNED_BY_ID IN ($in)")->fetch_assoc()['c'] ?? 0);
        echo "dept_members=" . count($memberIds) . " ids_sample=" . implode(',', array_slice($memberIds, 0, 15)) . (count($memberIds) > 15 ? '…' : '') . "\n";
        echo "visible_leads_estimate=$vis (scope=D, assigned to dept members)\n";

        // per-member breakdown if small team
        if (count($memberIds) <= 40) {
            echo "leads_by_dept_member:\n";
            $qq = $m->query(
                "SELECT ASSIGNED_BY_ID, COUNT(*) c FROM b_crm_lead
                 WHERE ASSIGNED_BY_ID IN ($in) GROUP BY ASSIGNED_BY_ID ORDER BY c DESC"
            );
            $by = [];
            while ($row = $qq->fetch_assoc()) {
                $by[(int) $row['ASSIGNED_BY_ID']] = (int) $row['c'];
            }
            foreach ($memberIds as $mid) {
                $cu2 = \CUser::GetByID($mid)->Fetch();
                $nm = $cu2 ? trim(($cu2['NAME'] ?? '') . ' ' . ($cu2['LAST_NAME'] ?? '')) : '?';
                $c = $by[$mid] ?? 0;
                if ($c > 0 || $mid === $uid) {
                    echo sprintf("  U%-5d %-35s %6d\n", $mid, $nm, $c);
                }
            }
        }
    } elseif ($scope === 'A') {
        echo "visible_leads_estimate=$own (scope=A self only)\n";
    } else {
        echo "visible_leads_estimate=0 or unknown (scope empty)\n";
    }
}
