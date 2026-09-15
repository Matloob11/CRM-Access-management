#!/usr/bin/env php
<?php
declare(strict_types=1);
require '/opt/a7-org-api/current/php/bootstrap.php';
\Bitrix\Main\Loader::includeModule('crm');

$m = a7_db();

$teamUsers = [
    2560, 2344, 2429, 2379, 2380, 2376, 2434, 2291,
    2551, 2369, 2381, 2371, 2535, 2368, 2367, 2350,
    2297, 2302, 2298, 2299, 2390, 2392,
];
$heads = [2560, 2551, 2297];

// Collect all groups used by team users
$allGroups = [];
foreach ($teamUsers as $uid) {
    foreach (\CUser::GetUserGroup($uid) as $g) {
        $allGroups[(int) $g] = true;
    }
}
$groups = array_keys($allGroups);
sort($groups);

$roleNames = [];
$rn = $m->query("SELECT ID, NAME FROM b_crm_role");
while ($r = $rn->fetch_assoc()) {
    $roleNames[(int) $r['ID']] = $r['NAME'];
}

echo "======== CRM roles reachable via team user groups / U-binds ========\n";
$rels = [];
$q = $m->query("SELECT ROLE_ID, RELATION FROM b_crm_role_relation");
while ($r = $q->fetch_assoc()) {
    $rels[] = $r;
}

$relevantRoles = [];
foreach ($rels as $r) {
    $rid = (int) $r['ROLE_ID'];
    $rel = $r['RELATION'];
    $hit = false;
    if (preg_match('/^G(\d+)$/', $rel, $m1) && isset($allGroups[(int) $m1[1]])) {
        $hit = true;
    }
    if (preg_match('/^U(\d+)$/', $rel, $m1) && in_array((int) $m1[1], $teamUsers, true)) {
        $hit = true;
    }
    // DR / D department relations
    if (preg_match('/^D(R)?(69|70|71)$/', $rel) || preg_match('/^IU(69|70|71)$/', $rel)) {
        $hit = true;
    }
    if ($hit) {
        if (!isset($relevantRoles[$rid])) {
            $relevantRoles[$rid] = [];
        }
        $relevantRoles[$rid][] = $rel;
    }
}

foreach ($relevantRoles as $rid => $relsHit) {
    $name = $roleNames[$rid] ?? '?';
    echo "Role $rid ($name) via " . implode(', ', array_unique($relsHit)) . "\n";
    $q = $m->query("SELECT ENTITY, PERM_TYPE, ATTR FROM b_crm_role_perms WHERE ROLE_ID=$rid AND PERM_TYPE='READ' AND ENTITY IN ('LEAD','CONTACT','COMPANY','DEAL','DYNAMIC_1042','DYNAMIC_1042_C13','DYNAMIC_1042_C14') ORDER BY ENTITY");
    while ($r = $q->fetch_assoc()) {
        $attr = $r['ATTR'] === '' ? '(none)' : $r['ATTR'];
        $flag = in_array($attr, ['X', 'O'], true) ? ' << ALL/OPEN' : '';
        echo sprintf("  %-18s READ ATTR=%s%s\n", $r['ENTITY'], $attr, $flag);
    }
}

echo "\n======== Clean plan ========\n";
echo "1) Remove heads 2551,2297 from G11 (agent group) — Hassan 2560 already not in G11\n";
echo "2) Remove role-8 personal binds for team agents U2368,U2367,U2299 (redundant; keep G11→role36)\n";
echo "3) Departments already exclusive — nothing to strip\n";
