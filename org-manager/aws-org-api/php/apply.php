<?php
/**
 * Apply one team + security change atomically-ish, then rebuild access codes and
 * clear caches so the change is visible without waiting for Bitrix to self-heal.
 *
 * STDIN (JSON):
 * {
 *   "dry_run": true,
 *   "team": {
 *     "dept_id": 69,            // existing department, or null with create=true
 *     "create": false,
 *     "name": "Hassan Team (TL)",
 *     "parent_id": 60,          // Sales Department
 *     "head_id": 2560,          // null clears the current head (and its role binding)
 *     "members": [2380, 2344],  // agent user ids (head is added automatically)
 *     "remove": [2303],         // users to take out of this department
 *     "remove_to": 60,          // where removed users go (null = no department)
 *     "head_role_id": 54,       // Team Leader
 *     "agent_role_id": 36,      // Agent
 *     "agent_group_id": 11      // G11 (how role 36 is granted)
 *   },
 *   "security": {               // optional; role id -> entity -> ATTR scope
 *     "54": {"LEAD":"D", ...},
 *     "36": {"LEAD":"A", ...}
 *   }
 * }
 *
 * Output: { ok, dryRun, deptId, plan:[...], results:{...} }
 */

declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

$req = a7_read_request();
$dry = (bool) ($req['dry_run'] ?? true);
$team = $req['team'] ?? [];
$security = $req['security'] ?? [];

$plan = [];
$note = function (string $s) use (&$plan) { $plan[] = $s; a7_log($s); };

$deptId = isset($team['dept_id']) ? (int) $team['dept_id'] : 0;
$create = (bool) ($team['create'] ?? false);
$name = trim((string) ($team['name'] ?? ''));
$parentId = (int) ($team['parent_id'] ?? 60);
$headId = isset($team['head_id']) && $team['head_id'] !== null ? (int) $team['head_id'] : null;
$members = array_values(array_unique(array_map('intval', $team['members'] ?? [])));
$remove = array_values(array_unique(array_map('intval', $team['remove'] ?? [])));
$removeTo = isset($team['remove_to']) && $team['remove_to'] !== null ? (int) $team['remove_to'] : null;
$headRoleId = (int) ($team['head_role_id'] ?? 54);
$agentGroupId = (int) ($team['agent_group_id'] ?? A7_AGENT_GROUP_ID);

$results = [];
$touched = [];

// --- 1. Department -------------------------------------------------------
if ($create) {
    if ($name === '' || $parentId <= 0) {
        a7_fail('create requires name and parent_id');
    }
    $note("create department \"$name\" under parent $parentId");
    if (!$dry) {
        $section = new CIBlockSection();
        $newId = $section->Add([
            'IBLOCK_ID' => A7_STRUCTURE_IBLOCK,
            'IBLOCK_SECTION_ID' => $parentId,
            'NAME' => $name,
            'ACTIVE' => 'Y',
            'SORT' => 500,
        ]);
        if (!$newId) {
            a7_fail('department create failed: ' . $section->LAST_ERROR);
        }
        $deptId = (int) $newId;
    }
} elseif ($deptId <= 0) {
    a7_fail('dept_id required unless create=true');
} else {
    $existing = a7_department($deptId);
    if (!$existing) {
        a7_fail("department $deptId not found");
    }
    if ($name !== '' && $name !== $existing['name']) {
        $note("rename department $deptId \"{$existing['name']}\" -> \"$name\"");
        if (!$dry) {
            $section = new CIBlockSection();
            if (!$section->Update($deptId, ['NAME' => $name])) {
                a7_fail('rename failed: ' . $section->LAST_ERROR);
            }
        }
    }
}

// The head is always a member of the department it leads.
$allUsers = $members;
if ($headId) {
    $allUsers[] = $headId;
}
$allUsers = array_values(array_unique($allUsers));

// --- 2. Head -------------------------------------------------------------
$oldHead = $create ? null : a7_department_head($deptId);
if ($headId) {
    if ($oldHead && $oldHead !== $headId) {
        $note("previous head $oldHead: unbind role $headRoleId from U$oldHead");
        if (!$dry) {
            a7_unbind_role($headRoleId, 'U' . $oldHead);
            $touched[] = $oldHead;
        }
    }
    $note("set department $deptId head = user $headId");
    $note("bind role $headRoleId to U$headId");
    if (!$dry) {
        a7_set_department_head($deptId, $headId);
        a7_bind_role($headRoleId, 'U' . $headId);
    }
} elseif ($oldHead) {
    $note("clear head of department $deptId (was $oldHead); unbind role $headRoleId from U$oldHead");
    if (!$dry) {
        a7_set_department_head($deptId, null);
        a7_unbind_role($headRoleId, 'U' . $oldHead);
        $touched[] = $oldHead;
    }
}

// --- 3. Members ----------------------------------------------------------
foreach ($allUsers as $uid) {
    $note("user $uid -> department $deptId" . ($uid === $headId ? ' (head)' : ''));
    if (!$dry) {
        $ok = a7_set_user_department($uid, $deptId);
        $results['members'][$uid] = $ok ? 'ok' : 'fail';
    }
    // Agents (everyone except the head) get the Agent role via the group.
    if ($uid !== $headId && $agentGroupId > 0) {
        $note("add user $uid to group G$agentGroupId (Agent role)");
        if (!$dry) {
            a7_add_user_to_group($uid, $agentGroupId);
        }
    }
}

// --- 3b. Removed members --------------------------------------------------
foreach ($remove as $uid) {
    if (in_array($uid, $allUsers, true)) {
        continue;
    }
    $note("user $uid leaves department $deptId" . ($removeTo ? " -> department $removeTo" : ' (no department)'));
    if (!$dry) {
        $user = new CUser();
        $ok = $user->Update($uid, ['UF_DEPARTMENT' => $removeTo ? [$removeTo] : []]);
        $results['removed'][$uid] = $ok ? 'ok' : 'fail';
        $touched[] = $uid;
    }
}

// --- 4. Security matrix --------------------------------------------------
foreach ($security as $roleIdStr => $entityMap) {
    $roleId = (int) $roleIdStr;
    if ($roleId <= 0 || !is_array($entityMap)) {
        continue;
    }
    foreach ($entityMap as $entity => $scope) {
        $scope = strtoupper(trim((string) $scope));
        if (!in_array($scope, ['A', 'D', 'X'], true)) {
            $note("skip $entity for role $roleId: invalid scope '$scope'");
            continue;
        }
        $note("role $roleId: $entity READ/WRITE/ADD = $scope");
        if (!$dry) {
            a7_set_entity_scope($roleId, (string) $entity, $scope);
            // Availability kanban (C14) needs per-stage READ or cards hide even
            // when the entity scope is right — mirror the stage rows.
            if ($entity === 'DYNAMIC_1042_C14') {
                a7_apply_c14_stage_reads($roleId, $scope);
            }
        }
    }
}

// --- 5. Access codes + caches + entity attributes -------------------------
$note('rebuild access codes, HR sync, clear CRM caches, refresh entity attributes');
if (!$dry) {
    $results += a7_finalize(array_merge($allUsers, $touched));
}

a7_ok([
    'dryRun' => $dry,
    'deptId' => $deptId,
    'plan' => $plan,
    'results' => $results,
    'hint' => 'Ask affected users to log out and back in; Bitrix keeps a per-session permission bag.',
]);
