#!/usr/bin/env php
<?php
declare(strict_types=1);
require '/opt/a7-org-api/current/php/bootstrap.php';
\Bitrix\Main\Loader::includeModule('crm');

$teams = [
    ['name' => 'Hassan', 'head' => 2560, 'a1' => 2344, 'a2' => 2429],
    ['name' => 'Hussnain', 'head' => 2551, 'a1' => 2369, 'a2' => 2381],
    ['name' => 'Jaweria', 'head' => 2297, 'a1' => 2302, 'a2' => 2298],
];

function pickLead(int $uid): ?int
{
    $m = a7_db();
    $q = $m->query("SELECT ID FROM b_crm_lead WHERE ASSIGNED_BY_ID=$uid ORDER BY ID DESC LIMIT 1");
    $r = $q ? $q->fetch_assoc() : null;
    return $r ? (int) $r['ID'] : null;
}
function pickOd(int $uid): ?int
{
    $m = a7_db();
    $q = $m->query("SELECT ID FROM b_crm_dynamic_items_1042 WHERE ASSIGNED_BY_ID=$uid AND CATEGORY_ID=13 ORDER BY ID DESC LIMIT 1");
    $r = $q ? $q->fetch_assoc() : null;
    return $r ? (int) $r['ID'] : null;
}

$fail = 0;
$leadFactory = \Bitrix\Crm\Service\Container::getInstance()->getFactory(\CCrmOwnerType::Lead);
$odFactory = \Bitrix\Crm\Service\Container::getInstance()->getFactory(1042);

foreach ($teams as $t) {
    $upH = \Bitrix\Crm\Service\Container::getInstance()->getUserPermissions($t['head']);
    $upA = \Bitrix\Crm\Service\Container::getInstance()->getUserPermissions($t['a1']);
    $l1 = pickLead($t['a1']);
    $l2 = pickLead($t['a2']);
    $o1 = pickOd($t['a1']);
    $o2 = pickOd($t['a2']);
    echo "{$t['name']} head={$t['head']} agent={$t['a1']} vs {$t['a2']}\n";

    foreach ([[$l1, 'LEAD own', true], [$l2, 'LEAD teammate', false]] as [$id, $label, $agentExpectOwn]) {
        if (!$id || !$leadFactory) {
            echo "  $label: no sample\n";
            continue;
        }
        $item = $leadFactory->getItem($id);
        if (!$item) {
            echo "  $label: missing item $id\n";
            continue;
        }
        $a = $upA->item()->canReadItem($item) ? 'Y' : 'N';
        $h = $upH->item()->canReadItem($item) ? 'Y' : 'N';
        $wantA = $agentExpectOwn ? 'Y' : 'N';
        $ok = ($a === $wantA && $h === 'Y');
        echo "  $label #$id agent=$a head=$h expect agent=$wantA head=Y " . ($ok ? 'OK' : 'FAIL') . "\n";
        if (!$ok) {
            $fail++;
        }
    }
    foreach ([[$o1, 'OD own', true], [$o2, 'OD teammate', false]] as [$id, $label, $agentExpectOwn]) {
        if (!$id || !$odFactory) {
            echo "  $label: no sample\n";
            continue;
        }
        $item = $odFactory->getItem($id);
        if (!$item) {
            echo "  $label: missing item $id\n";
            continue;
        }
        $a = $upA->item()->canReadItem($item) ? 'Y' : 'N';
        $h = $upH->item()->canReadItem($item) ? 'Y' : 'N';
        $wantA = $agentExpectOwn ? 'Y' : 'N';
        $ok = ($a === $wantA && $h === 'Y');
        echo "  $label #$id agent=$a head=$h expect agent=$wantA head=Y " . ($ok ? 'OK' : 'FAIL') . "\n";
        if (!$ok) {
            $fail++;
        }
    }
}
echo $fail === 0 ? "\nALL ITEM CHECKS PASSED\n" : "\nFAILURES=$fail\n";
