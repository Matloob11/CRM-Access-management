#!/usr/bin/env php
<?php
declare(strict_types=1);
require '/opt/a7-org-api/current/php/bootstrap.php';
\Bitrix\Main\Loader::includeModule('crm');

$headId = 2551;
$deptId = 70;
$team = [
    2551 => 'Rana Muhammad Hussnain Ali (Head)',
    2369 => 'nimra akbar',
    2381 => 'mehnaz',
    2371 => 'tehreem Ijaaz',
    2535 => 'Mehreen',
    2368 => 'esha ghazi',
    2367 => 'Warda Hassan',
    2350 => 'Irum Yasin',
];

$m = a7_db();
$ids = implode(',', array_keys($team));

echo "=== Hussnain team (dept $deptId) — leads by ASSIGNED_BY_ID ===\n";
$counts = [];
$total = 0;
foreach ($team as $uid => $name) {
    $q = $m->query("SELECT COUNT(*) AS c FROM b_crm_lead WHERE ASSIGNED_BY_ID=$uid");
    $c = (int) ($q->fetch_assoc()['c'] ?? 0);
    $counts[$uid] = $c;
    $total += $c;
    echo sprintf("U%-5d %-40s %6d\n", $uid, $name, $c);
}
echo sprintf("%-47s %6d\n", 'TEAM TOTAL (all members incl. head)', $total);

// Status breakdown for team
echo "\n=== Status breakdown (team assigned) ===\n";
$q = $m->query(
    "SELECT STATUS_ID, COUNT(*) AS c FROM b_crm_lead
     WHERE ASSIGNED_BY_ID IN ($ids)
     GROUP BY STATUS_ID ORDER BY c DESC"
);
while ($r = $q->fetch_assoc()) {
    echo sprintf("  %-20s %6d\n", $r['STATUS_ID'], (int) $r['c']);
}

// What head can effectively READ via CCrmPerms / sample — department scope = team assignees
$perms = new \CCrmPerms($headId);
echo "\n=== Head $headId LEAD READ scope = " . $perms->GetPermType('LEAD', 'READ') . " ===\n";
echo "With ATTR=D, Hussnain sees leads assigned to himself + department $deptId members.\n";
echo "Expected visible total ≈ $total (same as team total above).\n";

// Double-check: any leads assigned to head but dept membership edge cases
$own = $counts[$headId];
$agentsOnly = $total - $own;
echo "\nSummary:\n";
echo "  Hussnain own leads:     $own\n";
echo "  Agents' leads (sum):    $agentsOnly\n";
echo "  Total visible to head:  $total\n";
