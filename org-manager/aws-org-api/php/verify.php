<?php
/**
 * Read-only: verify a team's effective scope.
 *
 * STDIN: { "team": {"dept_id":69,"head_id":2560,"members":[...]},
 *          "entities": ["LEAD","DYNAMIC_1042_C13","DYNAMIC_1042_C14"],
 *          "deep": true }
 * Output: { ok, allPassed, checks:[{group, name, pass, level, detail, fix}] }
 *
 * Quick rules:
 *   - head sees the DEPARTMENT scope ('D') for each entity,
 *   - each agent sees only SELF ('A'),
 *   - every listed user's UF_DEPARTMENT is exactly the team department.
 *
 * Deep adds:
 *   - department exists / active / UF_HEAD matches / no duplicate departments,
 *   - head bound to role 54 by U<id>; agents in group G11; extra role bindings,
 *   - access codes carry D<dept> / G11,
 *   - role rows: ATTR present where SETTINGS names a scope; C14 stage rows,
 *   - item-level: agent reads own lead, cannot read a teammate's; head reads both.
 *
 * level: "fail" blocks the team, "warn" is advisory. fix: a mutate action the
 * console can offer, e.g. {"action":"fix_role_attrs","role_id":54}.
 */

declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

$req = a7_read_request();
$team = $req['team'] ?? [];
$deptId = (int) ($team['dept_id'] ?? 0);
$headId = isset($team['head_id']) && $team['head_id'] !== null ? (int) $team['head_id'] : null;
$members = array_values(array_unique(array_map('intval', $team['members'] ?? [])));
$entities = $req['entities'] ?? ['LEAD', 'DYNAMIC_1042_C13', 'DYNAMIC_1042_C14'];
$deep = (bool) ($req['deep'] ?? false);
$headRoleId = (int) ($team['head_role_id'] ?? 54);
$agentRoleId = (int) ($team['agent_role_id'] ?? 36);
$agentGroupId = (int) ($team['agent_group_id'] ?? A7_AGENT_GROUP_ID);

if ($deptId <= 0) {
    a7_fail('team.dept_id required');
}

$checks = [];
$add = function (string $group, string $name, bool $pass, string $detail = '', string $level = 'fail', ?array $fix = null) use (&$checks) {
    $c = ['group' => $group, 'name' => $name, 'pass' => $pass, 'level' => $level, 'detail' => $detail];
    if ($fix && !$pass) {
        $c['fix'] = $fix;
    }
    $checks[] = $c;
};

$readScope = function (int $uid, string $entity): string {
    $perms = new CCrmPerms($uid);
    return (string) $perms->GetPermType($entity, 'READ');
};
$agents = array_values(array_filter($members, fn($u) => $u !== $headId));

// ── Department ──────────────────────────────────────────────────────────
$dept = a7_department($deptId);
$add('team', "department $deptId exists", (bool) $dept, $dept ? $dept['name'] : 'not in company structure');
if (!$dept) {
    a7_ok(['allPassed' => false, 'checks' => $checks]);
}
if ($deep) {
    $add('team', 'department is active', $dept['active'], '', 'warn');
    if ($headId) {
        $add('team', "UF_HEAD of $deptId is $headId", $dept['headId'] === $headId, 'UF_HEAD=' . ($dept['headId'] ?? 'none'), 'fail', ['action' => 'set_head', 'dept_id' => $deptId, 'head_id' => $headId]);
    } else {
        $add('team', 'department has no leader', false, 'nobody sees the whole team', 'warn');
    }
}

// ── Head ────────────────────────────────────────────────────────────────
if ($headId) {
    $hu = a7_user($headId);
    $add('leader', "leader $headId exists and is active", $hu && $hu['active'], $hu ? '' : 'user missing', 'fail');
    $hd = $hu ? $hu['departments'] : [];
    $add('leader', "leader $headId in department $deptId", in_array($deptId, $hd, true), 'UF_DEPARTMENT=' . json_encode($hd), 'fail', ['action' => 'set_head', 'dept_id' => $deptId, 'head_id' => $headId]);
    if ($deep && count($hd) > 1) {
        $add('leader', "leader $headId belongs to one department only", false, 'UF_DEPARTMENT=' . json_encode($hd), 'warn');
    }
    foreach ($entities as $e) {
        $scope = $readScope($headId, $e);
        $add('leader', "leader $headId $e READ is department-wide", in_array($scope, ['D', 'X'], true), "scope=" . ($scope === '' ? 'none' : $scope), 'fail', ['action' => 'fix_role_attrs', 'role_id' => $headRoleId]);
    }
    if ($deep) {
        $roles = a7_user_roles($headId);
        $viaU = array_filter($roles, fn($r) => $r['roleId'] === $headRoleId && $r['via'] === 'U' . $headId);
        $add('leader', "leader $headId bound to role $headRoleId via U$headId", (bool) $viaU, 'roles=' . implode(',', array_map(fn($r) => $r['roleId'] . '@' . $r['via'], $roles)), 'fail', ['action' => 'bind_role', 'role_id' => $headRoleId, 'relation' => 'U' . $headId]);
        $other = array_filter($roles, fn($r) => !in_array($r['roleId'], [$headRoleId, $agentRoleId, 6], true));
        $add('leader', "leader $headId has no unexpected CRM roles", !$other, $other ? 'extra: ' . implode(', ', array_map(fn($r) => "{$r['roleId']} {$r['name']} via {$r['via']}", $other)) : '', 'warn');
        $codes = a7_access_codes($headId);
        $add('leader', "leader $headId access codes include D$deptId", in_array("D$deptId", $codes, true), '', 'fail', ['action' => 'set_head', 'dept_id' => $deptId, 'head_id' => $headId]);
    }
}

// ── Agents ──────────────────────────────────────────────────────────────
foreach ($agents as $uid) {
    $au = a7_user($uid);
    $add('agents', "agent $uid exists and is active", $au && $au['active'], $au ? '' : 'user missing', $au ? 'warn' : 'fail');
    $ad = $au ? $au['departments'] : [];
    $add('agents', "agent $uid in department $deptId", in_array($deptId, $ad, true), 'UF_DEPARTMENT=' . json_encode($ad), 'fail', ['action' => 'add_member', 'dept_id' => $deptId, 'user_id' => $uid]);
    if ($deep && count($ad) > 1) {
        $add('agents', "agent $uid belongs to one department only", false, 'UF_DEPARTMENT=' . json_encode($ad), 'warn');
    }
    foreach ($entities as $e) {
        $scope = $readScope($uid, $e);
        $add('agents', "agent $uid $e READ is self-only", $scope === 'A', "scope=" . ($scope === '' ? 'none' : $scope), 'fail');
    }
    if ($deep && $au) {
        $add('agents', "agent $uid in group G$agentGroupId (Agent role)", in_array($agentGroupId, $au['groups'], true), 'groups=' . implode(',', $au['groups']), 'fail', ['action' => 'set_group', 'user_id' => $uid, 'group_id' => $agentGroupId, 'member' => true]);
        $roles = a7_user_roles($uid);
        $other = array_filter($roles, fn($r) => !in_array($r['roleId'], [$agentRoleId, 6], true));
        $add('agents', "agent $uid has no wider CRM role", !$other, $other ? 'extra: ' . implode(', ', array_map(fn($r) => "{$r['roleId']} {$r['name']} via {$r['via']}", $other)) : '', 'warn');
        $codes = a7_access_codes($uid);
        $add('agents', "agent $uid access codes include D$deptId", in_array("D$deptId", $codes, true), '', 'fail', ['action' => 'add_member', 'dept_id' => $deptId, 'user_id' => $uid]);
    }
}

// ── Roles (deep) ────────────────────────────────────────────────────────
if ($deep) {
    foreach ([$headRoleId => 'leader', $agentRoleId => 'agent'] as $rid => $label) {
        $mm = a7_role_attr_mismatches($rid);
        $add('roles', "role $rid ($label) rows carry ATTR where SETTINGS names a scope", !$mm, $mm ? count($mm) . ' rows without ATTR: ' . implode(', ', array_map(fn($x) => "{$x['entity']} {$x['perm']}", array_slice($mm, 0, 6))) . (count($mm) > 6 ? '…' : '') : '', 'fail', ['action' => 'fix_role_attrs', 'role_id' => $rid]);
    }
    if (in_array('DYNAMIC_1042_C14', $entities, true)) {
        foreach ([$headRoleId => 'D', $agentRoleId => 'A'] as $rid => $sc) {
            $n = a7_c14_stage_row_count($rid);
            $add('roles', "role $rid has Availability (C14) per-stage READ rows", $n > 0, "rows=$n", 'warn', ['action' => 'fix_c14_stages', 'role_id' => $rid, 'scope' => $sc]);
        }
    }
    $rel = a7_db()->query("SELECT COUNT(*) c FROM b_crm_role_relation WHERE ROLE_ID=$agentRoleId AND RELATION='G$agentGroupId'")->fetch_assoc();
    $add('roles', "role $agentRoleId bound to group G$agentGroupId", (int) $rel['c'] > 0, '', 'fail', ['action' => 'bind_role', 'role_id' => $agentRoleId, 'relation' => 'G' . $agentGroupId]);
}

// ── Item-level (deep) ───────────────────────────────────────────────────
if ($deep && $agents) {
    $m = a7_db();
    $sample = [];
    foreach (array_slice($agents, 0, 3) as $uid) {
        $q = $m->query("SELECT ID FROM b_crm_lead WHERE ASSIGNED_BY_ID=$uid ORDER BY ID DESC LIMIT 1");
        $r = $q ? $q->fetch_assoc() : null;
        if ($r) {
            $sample[$uid] = (int) $r['ID'];
        }
    }
    if (!$sample) {
        $add('records', 'sample leads found for agents', false, 'no leads assigned to the first agents; item checks skipped', 'warn');
    }
    foreach ($sample as $uid => $leadId) {
        $own = CCrmLead::CheckReadPermission($leadId, new CCrmPerms($uid));
        $add('records', "agent $uid can open own lead #$leadId", (bool) $own, '', 'fail');
        foreach ($sample as $other => $otherLead) {
            if ($other === $uid) {
                continue;
            }
            $can = CCrmLead::CheckReadPermission($otherLead, new CCrmPerms($uid));
            $add('records', "agent $uid cannot open teammate $other's lead #$otherLead", !$can, '', 'fail');
            break;
        }
        if ($headId) {
            $can = CCrmLead::CheckReadPermission($leadId, new CCrmPerms($headId));
            $add('records', "leader $headId can open agent $uid's lead #$leadId", (bool) $can, '', 'fail');
        }
    }
}

$allPassed = true;
$failed = 0;
$warned = 0;
foreach ($checks as $c) {
    if (!$c['pass']) {
        if ($c['level'] === 'fail') {
            $allPassed = false;
            $failed++;
        } else {
            $warned++;
        }
    }
}

a7_ok(['allPassed' => $allPassed, 'failed' => $failed, 'warned' => $warned, 'deep' => $deep, 'checks' => $checks]);

/** Access codes for a user (b_user_access). */
function a7_access_codes(int $userId): array
{
    $m = a7_db();
    $out = [];
    $q = $m->query("SELECT ACCESS_CODE FROM b_user_access WHERE USER_ID=$userId");
    while ($q && ($r = $q->fetch_assoc())) {
        $out[] = $r['ACCESS_CODE'];
    }
    return $out;
}
