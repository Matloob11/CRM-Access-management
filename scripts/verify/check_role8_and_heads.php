#!/usr/bin/env php
<?php
declare(strict_types=1);
require '/opt/a7-org-api/current/php/bootstrap.php';
\Bitrix\Main\Loader::includeModule('crm');

$m = a7_db();

echo "======== Role 8 (Sales Agent) entity ATTRS ========\n";
$q = $m->query("SELECT ENTITY, PERM_TYPE, ATTR FROM b_crm_role_perms WHERE ROLE_ID=8 AND PERM_TYPE IN ('READ','WRITE','ADD') AND ENTITY IN ('LEAD','CONTACT','COMPANY','DEAL','DYNAMIC_1042','DYNAMIC_1042_C13','DYNAMIC_1042_C14') ORDER BY ENTITY, PERM_TYPE");
while ($r = $q->fetch_assoc()) {
    echo sprintf("  %-18s %-5s ATTR=%s\n", $r['ENTITY'], $r['PERM_TYPE'], $r['ATTR'] === '' ? '(empty)' : $r['ATTR']);
}

echo "\n======== Role 8 relations (sample) ========\n";
$q = $m->query("SELECT RELATION FROM b_crm_role_relation WHERE ROLE_ID=8");
while ($r = $q->fetch_assoc()) {
    echo "  " . $r['RELATION'] . "\n";
}

echo "\n======== Find UF_HEAD for sections 69/70/71 ========\n";
for ($ib = 1; $ib <= 20; $ib++) {
    $uts = "b_uts_iblock_{$ib}_section";
    $ex = $m->query("SHOW TABLES LIKE '$uts'");
    if (!$ex || !$ex->num_rows) {
        continue;
    }
    $cols = $m->query("SHOW COLUMNS FROM $uts LIKE 'UF_HEAD'");
    if (!$cols || !$cols->num_rows) {
        continue;
    }
    $q = $m->query("SELECT VALUE_ID, UF_HEAD FROM $uts WHERE VALUE_ID IN (69,70,71)");
    $any = false;
    while ($r = $q->fetch_assoc()) {
        $any = true;
        echo "  $uts section={$r['VALUE_ID']} UF_HEAD={$r['UF_HEAD']}\n";
    }
    if ($any) {
        // also: any other sections headed by our 3 heads
        $q2 = $m->query("SELECT VALUE_ID, UF_HEAD FROM $uts WHERE UF_HEAD IN (2560,2551,2297)");
        while ($r = $q2->fetch_assoc()) {
            echo "  $uts headU{$r['UF_HEAD']} also leads section {$r['VALUE_ID']}\n";
        }
    }
}

echo "\n======== Effective LEAD READ for agents with role8 ========\n";
foreach ([2368, 2367, 2299, 2344] as $uid) {
    $p = new \CCrmPerms($uid);
    $attr = $p->GetAttr('LEAD', 'READ');
    // Actually GetUserPermsAttr or similar
    $s = $p->GetPermType('LEAD', 'READ');
    echo "U$uid LEAD READ permType=$s\n";
}
