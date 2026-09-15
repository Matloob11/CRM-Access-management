<?php
/**
 * Single targeted change to the org tree or CRM security, with dry-run.
 *
 * STDIN (JSON): { "action": "<name>", "dry_run": false, ...params }
 *
 * Actions
 *   rename_dept     dept_id, name
 *   move_dept       dept_id, parent_id
 *   create_dept     name, parent_id
 *   delete_dept     dept_id, move_to (members go here; default parent)
 *   set_head        dept_id, head_id, head_role_id=54, agent_group_id=11, drop_agent_group=false
 *   clear_head      dept_id, head_role_id=54
 *   add_member      dept_id, user_id, agent_group_id=11
 *   remove_member   dept_id, user_id, move_to (null = no department), drop_agent_group=false
 *   bind_role       role_id, relation ("U123" / "G11" / "D69")
 *   unbind_role     role_id, relation
 *   set_perm        role_id, entity, perm_type, scope ("A"|"D"|"X"|"" to delete)
 *   set_entity_scope role_id, entity, scope (READ/WRITE/ADD + helpers + C14 stages)
 *   fix_role_attrs  role_id
 *   fix_c14_stages  role_id, scope
 *   set_group       user_id, group_id, member (true/false)
 *
 * Output: { ok, dryRun, action, plan:[...], results:{...} }
 */

declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

$req = a7_read_request();
$action = (string) ($req['action'] ?? '');
$dry = (bool) ($req['dry_run'] ?? false);

$plan = [];
$results = [];
$touched = [];
$note = function (string $s) use (&$plan) { $plan[] = $s; a7_log($s); };
$int = fn(string $k, int $default = 0) => isset($req[$k]) && $req[$k] !== null && $req[$k] !== '' ? (int) $req[$k] : $default;
$scopeArg = function (string $k) use ($req): string {
    $s = strtoupper(trim((string) ($req[$k] ?? '')));
    if (!in_array($s, ['A', 'D', 'X', ''], true)) {
        a7_fail("invalid scope '$s'");
    }
    return $s;
};

switch ($action) {
    case 'rename_dept': {
        $deptId = $int('dept_id');
        $name = trim((string) ($req['name'] ?? ''));
        $d = a7_department($deptId) ?: a7_fail("department $deptId not found");
        if ($name === '') {
            a7_fail('name required');
        }
        $note("rename department $deptId \"{$d['name']}\" -> \"$name\"");
        if (!$dry) {
            $s = new CIBlockSection();
            if (!$s->Update($deptId, ['NAME' => $name])) {
                a7_fail('rename failed: ' . $s->LAST_ERROR);
            }
        }
        break;
    }

    case 'move_dept': {
        $deptId = $int('dept_id');
        $parentId = $int('parent_id');
        $d = a7_department($deptId) ?: a7_fail("department $deptId not found");
        if ($parentId === $deptId) {
            a7_fail('a department cannot be its own parent');
        }
        if ($parentId > 0 && !a7_department($parentId)) {
            a7_fail("parent $parentId not found");
        }
        $note("move department $deptId under " . ($parentId ?: 'root'));
        $members = a7_department_members($deptId);
        if (!$dry) {
            $s = new CIBlockSection();
            if (!$s->Update($deptId, ['IBLOCK_SECTION_ID' => $parentId ?: false])) {
                a7_fail('move failed: ' . $s->LAST_ERROR);
            }
            $touched = $members;
        }
        break;
    }

    case 'create_dept': {
        $name = trim((string) ($req['name'] ?? ''));
        $parentId = $int('parent_id', 60);
        if ($name === '') {
            a7_fail('name required');
        }
        $note("create department \"$name\" under $parentId");
        if (!$dry) {
            $s = new CIBlockSection();
            $id = $s->Add(['IBLOCK_ID' => A7_STRUCTURE_IBLOCK, 'IBLOCK_SECTION_ID' => $parentId, 'NAME' => $name, 'ACTIVE' => 'Y', 'SORT' => 500]);
            if (!$id) {
                a7_fail('create failed: ' . $s->LAST_ERROR);
            }
            $results['deptId'] = (int) $id;
        }
        break;
    }

    case 'delete_dept': {
        $deptId = $int('dept_id');
        $d = a7_department($deptId) ?: a7_fail("department $deptId not found");
        $children = a7_department_children($deptId);
        if ($children) {
            a7_fail('department has sub-departments (' . implode(', ', $children) . '); move or delete them first');
        }
        $moveTo = $int('move_to', (int) ($d['parentId'] ?? 0));
        if ($moveTo === $deptId) {
            a7_fail('move_to cannot be the department being deleted');
        }
        $members = a7_department_members($deptId);
        $head = $d['headId'];
        foreach ($members as $uid) {
            $note("user $uid -> " . ($moveTo ? "department $moveTo" : 'no department'));
        }
        if ($head) {
            $note("unbind role 54 from U$head (department is going away)");
        }
        $note("delete department $deptId \"{$d['name']}\"");
        if (!$dry) {
            foreach ($members as $uid) {
                $u = new CUser();
                $u->Update($uid, ['UF_DEPARTMENT' => $moveTo ? [$moveTo] : []]);
            }
            if ($head) {
                a7_unbind_role(54, 'U' . $head);
            }
            if (!CIBlockSection::Delete($deptId)) {
                a7_fail('delete failed (Bitrix refused)');
            }
            $touched = $members;
        }
        break;
    }

    case 'set_head': {
        $deptId = $int('dept_id');
        $headId = $int('head_id');
        $roleId = $int('head_role_id', 54);
        $groupId = $int('agent_group_id', A7_AGENT_GROUP_ID);
        $dropGroup = (bool) ($req['drop_agent_group'] ?? false);
        a7_department($deptId) ?: a7_fail("department $deptId not found");
        a7_user($headId) ?: a7_fail("user $headId not found");
        $old = a7_department_head($deptId);
        if ($old && $old !== $headId) {
            $note("previous head $old: unbind role $roleId from U$old");
        }
        $note("user $headId -> department $deptId (head)");
        $note("set UF_HEAD of $deptId = $headId; bind role $roleId to U$headId");
        if ($dropGroup) {
            $note("remove user $headId from group G$groupId");
        }
        if (!$dry) {
            if ($old && $old !== $headId) {
                a7_unbind_role($roleId, 'U' . $old);
                $touched[] = $old;
            }
            a7_set_user_department($headId, $deptId);
            a7_set_department_head($deptId, $headId);
            a7_bind_role($roleId, 'U' . $headId);
            if ($dropGroup) {
                a7_remove_user_from_group($headId, $groupId);
            }
            $touched[] = $headId;
        }
        break;
    }

    case 'clear_head': {
        $deptId = $int('dept_id');
        $roleId = $int('head_role_id', 54);
        a7_department($deptId) ?: a7_fail("department $deptId not found");
        $old = a7_department_head($deptId);
        if (!$old) {
            $note("department $deptId has no head; nothing to clear");
            break;
        }
        $note("clear UF_HEAD of $deptId (was $old); unbind role $roleId from U$old");
        if (!$dry) {
            a7_set_department_head($deptId, null);
            a7_unbind_role($roleId, 'U' . $old);
            $touched[] = $old;
        }
        break;
    }

    case 'add_member': {
        $deptId = $int('dept_id');
        $uid = $int('user_id');
        $groupId = $int('agent_group_id', A7_AGENT_GROUP_ID);
        a7_department($deptId) ?: a7_fail("department $deptId not found");
        $u = a7_user($uid) ?: a7_fail("user $uid not found");
        $from = $u['departments'];
        $note("user $uid -> department $deptId" . ($from ? ' (from ' . implode(',', $from) . ')' : ''));
        if ($groupId > 0) {
            $note("add user $uid to group G$groupId (Agent role)");
        }
        if (!$dry) {
            if (!a7_set_user_department($uid, $deptId)) {
                a7_fail("could not set department for user $uid");
            }
            if ($groupId > 0) {
                a7_add_user_to_group($uid, $groupId);
            }
            $touched[] = $uid;
        }
        break;
    }

    case 'remove_member': {
        $deptId = $int('dept_id');
        $uid = $int('user_id');
        $moveTo = isset($req['move_to']) && $req['move_to'] !== null && $req['move_to'] !== '' ? (int) $req['move_to'] : null;
        $dropGroup = (bool) ($req['drop_agent_group'] ?? false);
        $groupId = $int('agent_group_id', A7_AGENT_GROUP_ID);
        a7_department($deptId) ?: a7_fail("department $deptId not found");
        a7_user($uid) ?: a7_fail("user $uid not found");
        if (a7_department_head($deptId) === $uid) {
            a7_fail("user $uid is the head of department $deptId; clear the head first");
        }
        if ($moveTo !== null && !a7_department($moveTo)) {
            a7_fail("target department $moveTo not found");
        }
        $note("user $uid leaves department $deptId -> " . ($moveTo ? "department $moveTo" : 'no department'));
        if ($dropGroup) {
            $note("remove user $uid from group G$groupId");
        }
        if (!$dry) {
            $u = new CUser();
            if (!$u->Update($uid, ['UF_DEPARTMENT' => $moveTo ? [$moveTo] : []])) {
                a7_fail("could not update user $uid: " . $u->LAST_ERROR);
            }
            if ($dropGroup) {
                a7_remove_user_from_group($uid, $groupId);
            }
            $touched[] = $uid;
        }
        break;
    }

    case 'bind_role':
    case 'unbind_role': {
        $roleId = $int('role_id');
        $rel = strtoupper(trim((string) ($req['relation'] ?? '')));
        if ($roleId <= 0 || !preg_match('/^(U|G|D|DR|SND|SNDR)\d+$/', $rel)) {
            a7_fail('role_id and a relation like U123 / G11 / D69 are required');
        }
        $note(($action === 'bind_role' ? 'bind' : 'unbind') . " role $roleId <-> $rel");
        if (!$dry) {
            $action === 'bind_role' ? a7_bind_role($roleId, $rel) : a7_unbind_role($roleId, $rel);
            if (preg_match('/^U(\d+)$/', $rel, $mm)) {
                $touched[] = (int) $mm[1];
            }
        }
        break;
    }

    case 'set_perm': {
        $roleId = $int('role_id');
        $entity = strtoupper(trim((string) ($req['entity'] ?? '')));
        $perm = strtoupper(trim((string) ($req['perm_type'] ?? '')));
        $scope = $scopeArg('scope');
        if ($roleId <= 0 || $entity === '' || !in_array($perm, ['READ', 'WRITE', 'ADD', 'DELETE', 'EXPORT', 'IMPORT'], true)) {
            a7_fail('role_id, entity and perm_type (READ/WRITE/ADD/DELETE/EXPORT/IMPORT) required');
        }
        $note("role $roleId: $entity $perm = " . ($scope === '' ? 'removed' : $scope));
        if (!$dry) {
            $scope === '' ? a7_delete_perm($roleId, $entity, $perm) : a7_set_perm($roleId, $entity, $perm, $scope);
        }
        break;
    }

    case 'set_entity_scope': {
        $roleId = $int('role_id');
        $entity = strtoupper(trim((string) ($req['entity'] ?? '')));
        $scope = $scopeArg('scope');
        if ($roleId <= 0 || $entity === '' || $scope === '') {
            a7_fail('role_id, entity and scope required');
        }
        $note("role $roleId: $entity READ/WRITE/ADD = $scope");
        if (!$dry) {
            a7_set_entity_scope($roleId, $entity, $scope);
            if ($entity === 'DYNAMIC_1042_C14') {
                $results['stageRows'] = a7_apply_c14_stage_reads($roleId, $scope);
            }
        }
        break;
    }

    case 'fix_role_attrs': {
        $roleId = $int('role_id');
        if ($roleId <= 0) {
            a7_fail('role_id required');
        }
        $fixed = a7_fix_role_attrs($roleId, $dry);
        foreach ($fixed as $f) {
            $note("role $roleId: {$f['entity']} {$f['perm']} ATTR '' -> {$f['attr']} (from SETTINGS)");
        }
        if (!$fixed) {
            $note("role $roleId: nothing to repair");
        }
        $results['fixed'] = count($fixed);
        break;
    }

    case 'fix_c14_stages': {
        $roleId = $int('role_id');
        $scope = $scopeArg('scope') ?: 'D';
        if ($roleId <= 0) {
            a7_fail('role_id required');
        }
        $note("role $roleId: write Availability (C14) per-stage READ rows at scope $scope");
        if (!$dry) {
            $results['stageRows'] = a7_apply_c14_stage_reads($roleId, $scope);
        }
        break;
    }

    case 'set_group': {
        $uid = $int('user_id');
        $gid = $int('group_id');
        $member = (bool) ($req['member'] ?? true);
        a7_user($uid) ?: a7_fail("user $uid not found");
        if ($gid <= 0) {
            a7_fail('group_id required');
        }
        $note(($member ? 'add' : 'remove') . " user $uid " . ($member ? 'to' : 'from') . " group G$gid");
        if (!$dry) {
            $member ? a7_add_user_to_group($uid, $gid) : a7_remove_user_from_group($uid, $gid);
            $touched[] = $uid;
        }
        break;
    }

    default:
        a7_fail("unknown action '$action'");
}

$roleChange = in_array($action, ['bind_role', 'unbind_role', 'set_perm', 'set_entity_scope', 'fix_role_attrs', 'fix_c14_stages'], true);
$note('rebuild access codes, clear CRM caches' . ($touched ? ', refresh entity attributes' : ''));
if (!$dry) {
    $results += a7_finalize($touched, !$roleChange || (bool) $touched);
}

a7_ok(['dryRun' => $dry, 'action' => $action, 'plan' => $plan, 'results' => $results]);
