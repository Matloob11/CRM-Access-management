#!/usr/bin/env php
<?php
declare(strict_types=1);
require '/opt/a7-org-api/current/php/bootstrap.php';
$m = a7_db();
$q = $m->query(
    "SELECT ID, ACTIVE, LOGIN, EMAIL, NAME, LAST_NAME
     FROM b_user
     WHERE LOWER(CONCAT_WS(' ', NAME, LAST_NAME, LOGIN, EMAIL)) LIKE '%farhan%'
        OR LOWER(CONCAT_WS(' ', NAME, LAST_NAME, LOGIN, EMAIL)) LIKE '%farhaan%'
     ORDER BY ID"
);
$n = 0;
while ($r = $q->fetch_assoc()) {
    $n++;
    $id = (int) $r['ID'];
    $name = trim(($r['NAME'] ?? '') . ' ' . ($r['LAST_NAME'] ?? ''));
    $u = \CUser::GetByID($id)->Fetch();
    $depts = is_array($u['UF_DEPARTMENT'] ?? null) ? array_map('intval', $u['UF_DEPARTMENT']) : [];
    echo "U$id active={$r['ACTIVE']} login={$r['LOGIN']} email={$r['EMAIL']} name=$name depts=" . json_encode($depts) . "\n";
}
if ($n === 0) {
    echo "NO_FARHAN\n";
}
