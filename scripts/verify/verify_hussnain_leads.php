#!/usr/bin/env php
<?php
declare(strict_types=1);
require '/opt/a7-org-api/current/php/bootstrap.php';
\Bitrix\Main\Loader::includeModule('crm');

$agents = [2369, 2381, 2371, 2535, 2368, 2367, 2350];
$m = a7_db();
$with = [];
foreach ($agents as $u) {
    $q = $m->query("SELECT ID FROM b_crm_lead WHERE ASSIGNED_BY_ID=$u ORDER BY ID DESC LIMIT 1");
    $r = $q ? $q->fetch_assoc() : null;
    echo "agent $u lead=" . ($r ? $r['ID'] : 'none') . "\n";
    if ($r) {
        $with[] = [$u, (int) $r['ID']];
    }
}
if (count($with) < 2) {
    echo "need 2 agents with leads, have " . count($with) . "\n";
    exit(1);
}
$a1 = $with[0];
$a2 = $with[1];
$upH = \Bitrix\Crm\Service\Container::getInstance()->getUserPermissions(2551);
$upA = \Bitrix\Crm\Service\Container::getInstance()->getUserPermissions($a1[0]);
$lf = \Bitrix\Crm\Service\Container::getInstance()->getFactory(\CCrmOwnerType::Lead);
$fail = 0;
foreach ([[$a1[1], 'own', true], [$a2[1], 'teammate', false]] as [$id, $label, $own]) {
    $item = $lf->getItem($id);
    $a = $upA->item()->canReadItem($item) ? 'Y' : 'N';
    $h = $upH->item()->canReadItem($item) ? 'Y' : 'N';
    $want = $own ? 'Y' : 'N';
    $ok = ($a === $want && $h === 'Y');
    echo "Hussnain LEAD $label #$id agent{$a1[0]}=$a head=$h expect agent=$want " . ($ok ? 'OK' : 'FAIL') . "\n";
    if (!$ok) {
        $fail++;
    }
}
echo $fail === 0 ? "PASSED\n" : "FAILURES=$fail\n";
