#!/usr/bin/env php
<?php
/**
 * Strip attachments that can interfere with Excel-final team visibility:
 * - Agents out of G10 (Management → Chief/Supervisor CRM roles)
 * - Heads out of G11 (Agent role) — keep only role 54 U-bind
 * - Drop personal role-8 binds for team agents (use G11→role36 only)
 * - Re-assert one department only
 * - Finalize access
 */
declare(strict_types=1);

require '/opt/a7-org-api/current/php/bootstrap.php';
\Bitrix\Main\Loader::includeModule('crm');

$salesDept = 60;
$teams = [
    69 => ['head' => 2560, 'agents' => [2344, 2429, 2379, 2380, 2376, 2434, 2291]],
    70 => ['head' => 2551, 'agents' => [2369, 2381, 2371, 2535, 2368, 2367, 2350]],
    71 => ['head' => 2297, 'agents' => [2302, 2298, 2299, 2390, 2392]],
];

$agentGroup = 11;
$mgmtBoard = 10; // DIRECTION — Supervisor / Chief executive CRM roles
$role8Users = [2368, 2367, 2299];

function groupsOf(int $uid): array
{
    return array_values(array_unique(array_map('intval', \CUser::GetUserGroup($uid))));
}

function setGroups(int $uid, array $groups): bool
{
    $groups = array_values(array_unique(array_map('intval', $groups)));
    $user = new \CUser();
    return (bool) $user->Update($uid, ['GROUP_ID' => $groups]);
}

function deptsOf(int $uid): array
{
    $u = \CUser::GetByID($uid)->Fetch();
    $d = $u['UF_DEPARTMENT'] ?? [];
    return is_array($d) ? array_values(array_map('intval', array_filter($d))) : [];
}

$m = a7_db();
$changed = [];

echo "======== BEFORE ========\n";
foreach ($teams as $deptId => $t) {
    $all = array_merge([$t['head']], $t['agents']);
    foreach ($all as $uid) {
        echo "U$uid dept=" . json_encode(deptsOf($uid)) . " groups=" . json_encode(groupsOf($uid)) . "\n";
    }
}

echo "======== 1) One department only ========\n";
foreach ($teams as $deptId => $t) {
    foreach (array_merge([$t['head']], $t['agents']) as $uid) {
        $have = deptsOf($uid);
        if ($have === [$deptId]) {
            continue;
        }
        $ok = (bool) (new \CUser())->Update($uid, ['UF_DEPARTMENT' => [$deptId]]);
        echo "U$uid dept " . json_encode($have) . " -> [$deptId] " . ($ok ? 'OK' : 'FAIL') . "\n";
        $changed[] = $uid;
    }
}

echo "======== 2) Heads: remove G11 (Agent) ========\n";
foreach ($teams as $deptId => $t) {
    $uid = $t['head'];
    $g = groupsOf($uid);
    if (!in_array($agentGroup, $g, true)) {
        echo "U$uid head already without G11\n";
        continue;
    }
    $g = array_values(array_diff($g, [$agentGroup]));
    $ok = setGroups($uid, $g);
    echo "U$uid removed G11 -> " . json_encode(groupsOf($uid)) . ($ok ? " OK" : " FAIL") . "\n";
    $changed[] = $uid;
}

echo "======== 3) Agents: remove G10 (Management Board) ========\n";
foreach ($teams as $deptId => $t) {
    foreach ($t['agents'] as $uid) {
        $g = groupsOf($uid);
        if (!in_array($mgmtBoard, $g, true)) {
            continue;
        }
        $g = array_values(array_diff($g, [$mgmtBoard]));
        $ok = setGroups($uid, $g);
        echo "U$uid removed G10 -> " . json_encode(groupsOf($uid)) . ($ok ? " OK" : " FAIL") . "\n";
        $changed[] = $uid;
    }
}

echo "======== 4) Agents: ensure G11 present ========\n";
foreach ($teams as $deptId => $t) {
    foreach ($t['agents'] as $uid) {
        $g = groupsOf($uid);
        if (in_array($agentGroup, $g, true)) {
            continue;
        }
        $g[] = $agentGroup;
        $ok = setGroups($uid, $g);
        echo "U$uid added G11 -> " . json_encode(groupsOf($uid)) . ($ok ? " OK" : " FAIL") . "\n";
        $changed[] = $uid;
    }
}

echo "======== 5) Drop role-8 personal binds for team agents ========\n";
foreach ($role8Users as $uid) {
    $m->query("DELETE FROM b_crm_role_relation WHERE ROLE_ID=8 AND RELATION='U$uid'");
    echo "deleted role8 U$uid rows=" . $m->affected_rows . "\n";
    $changed[] = $uid;
}

echo "======== 6) Heads: ensure role 54 U-bind ========\n";
foreach ($teams as $deptId => $t) {
    $uid = $t['head'];
    $q = $m->query("SELECT 1 FROM b_crm_role_relation WHERE ROLE_ID=54 AND RELATION='U$uid' LIMIT 1");
    if ($q && $q->num_rows) {
        echo "U$uid role54 OK\n";
        continue;
    }
    $m->query("INSERT INTO b_crm_role_relation (ROLE_ID, RELATION) VALUES (54, 'U$uid')");
    echo "U$uid role54 inserted\n";
    $changed[] = $uid;
}

echo "======== FINALIZE ========\n";
$changed = array_values(array_unique(array_map('intval', $changed)));
// Always finalize all team users so caches match
$all = [];
foreach ($teams as $t) {
    $all[] = $t['head'];
    foreach ($t['agents'] as $a) {
        $all[] = $a;
    }
}
$all = array_values(array_unique($all));
if (function_exists('a7_finalize')) {
    echo json_encode(a7_finalize($all)) . "\n";
}

echo "======== VERIFY effective scopes ========\n";
$fail = 0;
foreach ($teams as $deptId => $t) {
    $head = $t['head'];
    $agent = $t['agents'][0];
    $upH = \Bitrix\Crm\Service\Container::getInstance()->getUserPermissions($head);
    $upA = \Bitrix\Crm\Service\Container::getInstance()->getUserPermissions($agent);
    foreach (['LEAD', 'CONTACT', 'COMPANY', 'DEAL', 'DYNAMIC_1042', 'DYNAMIC_1042_C13', 'DYNAMIC_1042_C14'] as $e) {
        $h = (new \CCrmPerms($head))->GetPermType($e, 'READ');
        $a = (new \CCrmPerms($agent))->GetPermType($e, 'READ');
        $ok = ($h === 'D' && $a === 'A');
        echo "dept $deptId head$head=$h agent$agent=$a $e " . ($ok ? 'OK' : 'FAIL') . "\n";
        if (!$ok) {
            $fail++;
        }
    }
    // G11 / G10 checks
    $hg = groupsOf($head);
    $ag = groupsOf($agent);
    echo "head$head hasG11=" . (in_array(11, $hg, true) ? 'Y' : 'N') . " (want N) ";
    echo "agent$agent hasG11=" . (in_array(11, $ag, true) ? 'Y' : 'N') . " hasG10=" . (in_array(10, $ag, true) ? 'Y' : 'N') . "\n";
    if (in_array(11, $hg, true) || !in_array(11, $ag, true) || in_array(10, $ag, true)) {
        $fail++;
    }
    // Check all agents no G10
    foreach ($t['agents'] as $uid) {
        if (in_array(10, groupsOf($uid), true)) {
            echo "FAIL agent $uid still G10\n";
            $fail++;
        }
        if (deptsOf($uid) !== [$deptId]) {
            echo "FAIL agent $uid dept " . json_encode(deptsOf($uid)) . "\n";
            $fail++;
        }
    }
    if (deptsOf($head) !== [$deptId]) {
        echo "FAIL head $head dept\n";
        $fail++;
    }
}

$q = $m->query("SELECT RELATION FROM b_crm_role_relation WHERE ROLE_ID=8 AND RELATION IN ('U2368','U2367','U2299')");
while ($r = $q->fetch_assoc()) {
    echo "FAIL still role8 {$r['RELATION']}\n";
    $fail++;
}

echo $fail === 0 ? "\nALL CLEAN — only Excel team depts + Head=D / Agent=A\n" : "\nFAILURES=$fail\n";
