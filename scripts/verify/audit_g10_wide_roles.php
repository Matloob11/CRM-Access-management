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
$heads = [2560 => 69, 2551 => 70, 2297 => 71];

echo "======== Who is in G10 / G7 / G8 / G9 / G13 / G19 ========\n";
$watch = [7, 8, 9, 10, 13, 19];
foreach ($teamUsers as $uid) {
    $gs = array_map('intval', \CUser::GetUserGroup($uid));
    $hit = array_values(array_intersect($gs, $watch));
    if ($hit) {
        $u = \CUser::GetByID($uid)->Fetch();
        $name = trim(($u['NAME'] ?? '') . ' ' . ($u['LAST_NAME'] ?? ''));
        echo "U$uid $name groups_extra=" . json_encode($hit) . " all=" . json_encode($gs) . "\n";
    }
}

echo "\n======== Role 7/24/25/26 LEAD+OD READ ATTR ========\n";
foreach ([7, 24, 25, 26] as $rid) {
    $q = $m->query("SELECT ENTITY, ATTR FROM b_crm_role_perms WHERE ROLE_ID=$rid AND PERM_TYPE='READ' AND ENTITY IN ('LEAD','CONTACT','COMPANY','DEAL','DYNAMIC_1042','DYNAMIC_1042_C13','DYNAMIC_1042_C14')");
    echo "Role $rid:\n";
    while ($r = $q->fetch_assoc()) {
        $flag = in_array($r['ATTR'], ['X', 'O', 'D'], true) ? ' << WIDE' : '';
        echo "  {$r['ENTITY']} ATTR={$r['ATTR']}$flag\n";
    }
}

echo "\n======== Effective CCrmPerms for risky users ========\n";
$check = [2350, 2392, 2551, 2297, 2344]; // G10 users + sample
foreach ($check as $uid) {
    if (!in_array($uid, $teamUsers, true)) {
        continue;
    }
    $up = \Bitrix\Crm\Service\Container::getInstance()->getUserPermissions($uid);
    foreach (['LEAD', 'CONTACT', 'DEAL', 'DYNAMIC_1042', 'DYNAMIC_1042_C13', 'DYNAMIC_1042_C14'] as $e) {
        // entity()->canReadSomething or get permission attributes
        try {
            $attrs = $up->entity()->getEntityAttributes($e);
        } catch (\Throwable $ex) {
            $attrs = 'err:' . $ex->getMessage();
        }
        // fallback via CCrmPerms
        $p = new \CCrmPerms($uid);
        $pt = $p->GetPermType($e, 'READ');
        echo "U$uid $e READ permType=$pt\n";
    }
    echo "---\n";
}
