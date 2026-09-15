#!/usr/bin/env php
<?php
declare(strict_types=1);
require '/opt/a7-org-api/current/php/bootstrap.php';

$m = a7_db();
$groups = [2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16, 19];

echo "======== Group names ========\n";
$in = implode(',', $groups);
$q = $m->query("SELECT ID, NAME, STRING_ID FROM b_group WHERE ID IN ($in) ORDER BY ID");
while ($r = $q->fetch_assoc()) {
    echo "G{$r['ID']} {$r['NAME']} ({$r['STRING_ID']})\n";
}

echo "\n======== CRM role relations for these groups ========\n";
$roleNames = [];
$rn = $m->query("SELECT ID, NAME FROM b_crm_role");
while ($r = $rn->fetch_assoc()) {
    $roleNames[(int) $r['ID']] = $r['NAME'];
}
foreach ($groups as $g) {
    $q = $m->query("SELECT ROLE_ID, RELATION FROM b_crm_role_relation WHERE RELATION='G$g'");
    $rows = [];
    while ($r = $q->fetch_assoc()) {
        $rid = (int) $r['ROLE_ID'];
        $rows[] = "role $rid (" . ($roleNames[$rid] ?? '?') . ")";
    }
    echo "G$g => " . ($rows ? implode(', ', $rows) : '(no CRM role)') . "\n";
}
