#!/usr/bin/env php
<?php
/**
 * Enforce exact visibility:
 *   Role 54 (Team Leader / heads): ATTR=D for CRM + Owner Data + Availability
 *   Role 36 (Agent):               ATTR=A for CRM + Owner Data + Availability
 * Then rebuild access for all Excel-final team users and deep-verify.
 *
 * Run: sudo -u bitrix php /tmp/enforce_head_agent_scope.php
 */
declare(strict_types=1);

require '/opt/a7-org-api/current/php/bootstrap.php';

$entities = [
    'LEAD',
    'CONTACT',
    'COMPANY',
    'DEAL',
    'DYNAMIC_1042',
    'DYNAMIC_1042_C13',
    'DYNAMIC_1042_C14',
];

$teams = [
    [
        'name' => 'Hassan',
        'dept_id' => 69,
        'head_id' => 2560,
        'members' => [2344, 2429, 2379, 2380, 2376, 2434, 2291, 2560],
    ],
    [
        'name' => 'Hussnain',
        'dept_id' => 70,
        'head_id' => 2551,
        'members' => [2369, 2381, 2371, 2535, 2368, 2367, 2350, 2551],
    ],
    [
        'name' => 'Jaweria',
        'dept_id' => 71,
        'head_id' => 2297,
        'members' => [2302, 2298, 2299, 2390, 2392, 2297],
    ],
];

function dumpRole(int $roleId, array $entities): void
{
    $m = a7_db();
    $in = implode(',', array_map(fn($e) => "'" . $m->real_escape_string($e) . "'", $entities));
    echo "--- role $roleId ---\n";
    $q = $m->query(
        "SELECT ENTITY, PERM_TYPE, ATTR, SETTINGS FROM b_crm_role_perms
         WHERE ROLE_ID=$roleId AND FIELD='-' AND ENTITY IN ($in)
           AND PERM_TYPE IN ('READ','WRITE','ADD')
         ORDER BY ENTITY, PERM_TYPE"
    );
    while ($r = $q->fetch_assoc()) {
        echo sprintf(
            "  %-18s %-5s ATTR=%-2s SETTINGS=%s\n",
            $r['ENTITY'],
            $r['PERM_TYPE'],
            (string) $r['ATTR'],
            (string) $r['SETTINGS']
        );
    }
}

echo "======== BEFORE ========\n";
dumpRole(54, $entities);
dumpRole(36, $entities);

echo "======== ENFORCE Head=D Agent=A ========\n";
foreach ($entities as $entity) {
    a7_set_entity_scope(54, $entity, 'D');
    a7_set_entity_scope(36, $entity, 'A');
    echo "set $entity → head D / agent A\n";
}
// Availability kanban stages for agents
a7_apply_c14_stage_reads(36, 'A');
a7_apply_c14_stage_reads(54, 'D');
echo "C14 stage READ rows synced\n";

$fixed54 = a7_fix_role_attrs(54, false);
$fixed36 = a7_fix_role_attrs(36, false);
echo "repaired empty ATTR rows: role54=" . count($fixed54) . " role36=" . count($fixed36) . "\n";

echo "======== AFTER ========\n";
dumpRole(54, $entities);
dumpRole(36, $entities);

$allUsers = [];
foreach ($teams as $t) {
    foreach ($t['members'] as $uid) {
        $allUsers[] = (int) $uid;
    }
}
$allUsers = array_values(array_unique($allUsers));

echo "======== FINALIZE " . count($allUsers) . " users ========\n";
$results = a7_finalize($allUsers, true);
echo json_encode($results, JSON_UNESCAPED_SLASHES) . "\n";

echo "======== EFFECTIVE SCOPE (CCrmPerms READ) ========\n";
foreach ($teams as $t) {
    $head = $t['head_id'];
    $agent = null;
    foreach ($t['members'] as $uid) {
        if ($uid !== $head) {
            $agent = $uid;
            break;
        }
    }
    $hp = new CCrmPerms($head);
    $ap = new CCrmPerms($agent);
    echo $t['name'] . " head $head / sample agent $agent\n";
    foreach (['LEAD', 'CONTACT', 'COMPANY', 'DEAL', 'DYNAMIC_1042', 'DYNAMIC_1042_C13', 'DYNAMIC_1042_C14'] as $e) {
        $hs = (string) $hp->GetPermType($e, 'READ');
        $as = (string) $ap->GetPermType($e, 'READ');
        $okH = ($hs === 'D' || $hs === 'X'); // D expected; X would be too open for head but rare
        $okA = ($as === 'A');
        // Heads must be D not X; agents must be A not D/X
        $passH = ($hs === 'D');
        $passA = ($as === 'A');
        echo sprintf(
            "  %-18s head=%s%s  agent=%s%s\n",
            $e,
            $hs !== '' ? $hs : '-',
            $passH ? ' OK' : ' FAIL',
            $as !== '' ? $as : '-',
            $passA ? ' OK' : ' FAIL'
        );
    }
}

echo "======== ITEM CHECKS (own vs teammate) ========\n";
\Bitrix\Main\Loader::includeModule('crm');

function pickLead(int $assignedBy): ?int
{
    $m = a7_db();
    $q = $m->query(
        "SELECT ID FROM b_crm_lead WHERE ASSIGNED_BY_ID=$assignedBy AND STATUS_SEMANTIC_ID<>'S' ORDER BY ID DESC LIMIT 1"
    );
    $r = $q ? $q->fetch_assoc() : null;
    return $r ? (int) $r['ID'] : null;
}

function pickOwner(int $assignedBy, int $categoryId): ?int
{
    $m = a7_db();
    $q = $m->query(
        "SELECT ID FROM b_crm_dynamic_items_1042 WHERE ASSIGNED_BY_ID=$assignedBy AND CATEGORY_ID=$categoryId ORDER BY ID DESC LIMIT 1"
    );
    $r = $q ? $q->fetch_assoc() : null;
    return $r ? (int) $r['ID'] : null;
}

$fail = 0;
foreach ($teams as $t) {
    $head = $t['head_id'];
    $agents = array_values(array_filter($t['members'], fn($u) => $u !== $head));
    if (count($agents) < 2) {
        echo "{$t['name']}: need 2 agents for item test — skip\n";
        continue;
    }
    $a1 = $agents[0];
    $a2 = $agents[1];
    $lead1 = pickLead($a1);
    $lead2 = pickLead($a2);
    $od1 = pickOwner($a1, 13);
    $od2 = pickOwner($a2, 13);

    $upHead = \Bitrix\Crm\Service\Container::getInstance()->getUserPermissions($head);
    $upA1 = \Bitrix\Crm\Service\Container::getInstance()->getUserPermissions($a1);

    echo "{$t['name']}: agents $a1 / $a2 leads=" . ($lead1 ?: '-') . '/' . ($lead2 ?: '-') .
        " od=" . ($od1 ?: '-') . '/' . ($od2 ?: '-') . "\n";

    if ($lead1) {
        $own = $upA1->item()->canReadItem(\CCrmOwnerType::Lead, $lead1) ? 'Y' : 'N';
        $headCan = $upHead->item()->canReadItem(\CCrmOwnerType::Lead, $lead1) ? 'Y' : 'N';
        echo "  LEAD own($a1): agent=$own head=$headCan  expect Y/Y\n";
        if ($own !== 'Y' || $headCan !== 'Y') {
            $fail++;
        }
    }
    if ($lead2) {
        $teammate = $upA1->item()->canReadItem(\CCrmOwnerType::Lead, $lead2) ? 'Y' : 'N';
        $headCan = $upHead->item()->canReadItem(\CCrmOwnerType::Lead, $lead2) ? 'Y' : 'N';
        echo "  LEAD teammate($a2): agent=$teammate head=$headCan  expect N/Y\n";
        if ($teammate !== 'N' || $headCan !== 'Y') {
            $fail++;
        }
    }
    if ($od1) {
        $factory = \Bitrix\Crm\Service\Container::getInstance()->getFactory(1042);
        $item = $factory?->getItem($od1);
        if ($item) {
            $own = $upA1->item()->canReadItem($item) ? 'Y' : 'N';
            $headCan = $upHead->item()->canReadItem($item) ? 'Y' : 'N';
            echo "  OD C13 own($a1): agent=$own head=$headCan  expect Y/Y\n";
            if ($own !== 'Y' || $headCan !== 'Y') {
                $fail++;
            }
        }
    }
    if ($od2) {
        $factory = \Bitrix\Crm\Service\Container::getInstance()->getFactory(1042);
        $item = $factory?->getItem($od2);
        if ($item) {
            $teammate = $upA1->item()->canReadItem($item) ? 'Y' : 'N';
            $headCan = $upHead->item()->canReadItem($item) ? 'Y' : 'N';
            echo "  OD C13 teammate($a2): agent=$teammate head=$headCan  expect N/Y\n";
            if ($teammate !== 'N' || $headCan !== 'Y') {
                $fail++;
            }
        }
    }
}

echo "======== EXTRA ROLE WARNINGS (agents with role that may widen scope) ========\n";
$m = a7_db();
$agentIds = [];
foreach ($teams as $t) {
    foreach ($t['members'] as $uid) {
        if ($uid !== $t['head_id']) {
            $agentIds[] = (int) $uid;
        }
    }
}
$agentIds = array_values(array_unique($agentIds));
foreach ($agentIds as $uid) {
    $rels = [];
    $q = $m->query("SELECT ROLE_ID, RELATION FROM b_crm_role_relation WHERE RELATION='U$uid' OR RELATION='IU$uid'");
    while ($r = $q->fetch_assoc()) {
        $rels[] = 'R' . $r['ROLE_ID'] . ':' . $r['RELATION'];
    }
    // group roles
    $gq = $m->query("SELECT GROUP_ID FROM b_user_group WHERE USER_ID=$uid AND GROUP_ID IN (11,12)");
    $groups = [];
    while ($r = $gq->fetch_assoc()) {
        $groups[] = 'G' . $r['GROUP_ID'];
    }
    if ($rels || $groups) {
        echo "  U$uid rels=[" . implode(',', $rels) . '] groups=[' . implode(',', $groups) . "]\n";
    }
}

echo $fail === 0 ? "\nALL CRITICAL ITEM CHECKS PASSED (or skipped missing sample items)\n" : "\nITEM CHECK FAILURES: $fail\n";
echo "DONE\n";
