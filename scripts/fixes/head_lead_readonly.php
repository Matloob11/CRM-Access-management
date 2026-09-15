#!/usr/bin/env php
<?php
/**
 * Heads (role 54) = LEAD read-only:
 *   READ = D (own + team)
 *   WRITE / ADD / DELETE / EXPORT / IMPORT = denied
 * Agents (role 36) unchanged — still A for read/write.
 *
 * Run: sudo -u bitrix php /tmp/head_lead_readonly.php
 */
declare(strict_types=1);

require '/opt/a7-org-api/current/php/bootstrap.php';
\Bitrix\Main\Loader::includeModule('crm');

$roleHead = 54;
$heads = [2560, 2551, 2297];
$sampleAgentLeads = [
    2560 => 72164, // Aila's lead (Hassan team) — if missing, pick later
    2551 => 72595, // esha
    2297 => 73248, // Fozia
];

$m = a7_db();

echo "======== BEFORE role 54 LEAD ========\n";
$q = $m->query(
    "SELECT FIELD, FIELD_VALUE, PERM_TYPE, ATTR, SETTINGS FROM b_crm_role_perms
     WHERE ROLE_ID=$roleHead AND ENTITY='LEAD'
     ORDER BY PERM_TYPE, FIELD, FIELD_VALUE"
);
while ($r = $q->fetch_assoc()) {
    echo sprintf(
        "  %-8s field=%-12s fv=%-20s ATTR=%s SETTINGS=%s\n",
        $r['PERM_TYPE'],
        $r['FIELD'],
        (string) $r['FIELD_VALUE'],
        (string) $r['ATTR'],
        (string) $r['SETTINGS']
    );
}

echo "======== ENFORCE LEAD READ=D, deny write/add/delete/export/import ========\n";
// Keep department-wide read
a7_set_perm($roleHead, 'LEAD', 'READ', 'D');

// Deny mutating / bulk actions on leads for heads
foreach (['WRITE', 'ADD', 'DELETE', 'EXPORT', 'IMPORT'] as $perm) {
    // Remove all variants (entity-level + field-level)
    $n = a7_delete_perm($roleHead, 'LEAD', $perm);
    echo "deleted LEAD $perm rows=$n\n";
}

// Keep view helpers; block stage transitions (stage move = edit)
a7_upsert_perm($roleHead, 'LEAD', 'HIDE_SUM', 'X', '[]');
a7_upsert_perm($roleHead, 'LEAD', 'MYCARDVIEW', 'X', '[]');
// Empty TRANSITION = no stage changes
a7_upsert_perm($roleHead, 'LEAD', 'TRANSITION', '', '[]');

// Ensure a7_fix_role_attrs does NOT re-fill empty WRITE from SETTINGS — we deleted them.

echo "======== AFTER role 54 LEAD ========\n";
$q = $m->query(
    "SELECT FIELD, FIELD_VALUE, PERM_TYPE, ATTR, SETTINGS FROM b_crm_role_perms
     WHERE ROLE_ID=$roleHead AND ENTITY='LEAD'
     ORDER BY PERM_TYPE, FIELD, FIELD_VALUE"
);
while ($r = $q->fetch_assoc()) {
    echo sprintf(
        "  %-8s field=%-12s fv=%-20s ATTR=%s SETTINGS=%s\n",
        $r['PERM_TYPE'],
        $r['FIELD'],
        (string) $r['FIELD_VALUE'],
        (string) $r['ATTR'],
        (string) $r['SETTINGS']
    );
}

echo "======== FINALIZE heads ========\n";
echo json_encode(a7_finalize($heads, true), JSON_UNESCAPED_SLASHES) . "\n";

echo "======== EFFECTIVE PERMS ========\n";
$fail = 0;
foreach ($heads as $uid) {
    $p = new \CCrmPerms($uid);
    $r = (string) $p->GetPermType('LEAD', 'READ');
    $w = (string) $p->GetPermType('LEAD', 'WRITE');
    $a = (string) $p->GetPermType('LEAD', 'ADD');
    $ok = ($r === 'D' || $r === 'X') && $w === '' && $a === '';
    echo "head $uid LEAD READ=$r WRITE=$w ADD=$a " . ($ok ? 'OK' : 'FAIL') . "\n";
    if (!$ok) {
        $fail++;
    }

    $leadId = $sampleAgentLeads[$uid] ?? 0;
    if ($leadId > 0) {
        $up = \Bitrix\Crm\Service\Container::getInstance()->getUserPermissions($uid);
        $factory = \Bitrix\Crm\Service\Container::getInstance()->getFactory(\CCrmOwnerType::Lead);
        $item = $factory ? $factory->getItem($leadId) : null;
        if ($item) {
            $canR = $up->item()->canReadItem($item) ? 'Y' : 'N';
            $canU = $up->item()->canUpdateItem($item) ? 'Y' : 'N';
            $okItem = ($canR === 'Y' && $canU === 'N');
            echo "  lead #$leadId canRead=$canR canUpdate=$canU " . ($okItem ? 'OK' : 'FAIL') . "\n";
            if (!$okItem) {
                $fail++;
            }
        } else {
            echo "  lead #$leadId missing — skip item check\n";
        }
    }
}

// Agent still writable
$agent = 2344;
$ap = new \CCrmPerms($agent);
$ar = (string) $ap->GetPermType('LEAD', 'READ');
$aw = (string) $ap->GetPermType('LEAD', 'WRITE');
$okA = ($ar === 'A' && $aw === 'A');
echo "agent $agent LEAD READ=$ar WRITE=$aw " . ($okA ? 'OK' : 'FAIL') . "\n";
if (!$okA) {
    $fail++;
}

echo $fail === 0 ? "\nALL OK — heads LEAD read-only\n" : "\nFAILURES=$fail\n";
exit($fail === 0 ? 0 : 1);
