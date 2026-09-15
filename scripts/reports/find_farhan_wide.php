#!/usr/bin/env php
<?php
declare(strict_types=1);
require '/opt/a7-org-api/current/php/bootstrap.php';
$m = a7_db();
$q = $m->query(
    "SELECT ID, ACTIVE, LOGIN, EMAIL, NAME, LAST_NAME
     FROM b_user
     WHERE LOWER(CONCAT_WS(' ', NAME, LAST_NAME, LOGIN, EMAIL)) LIKE '%farh%'
        OR LOWER(CONCAT_WS(' ', NAME, LAST_NAME, LOGIN, EMAIL)) LIKE '%faran%'
        OR LOWER(NAME) LIKE 'far%'
     ORDER BY ID"
);
while ($r = $q->fetch_assoc()) {
    $id = (int) $r['ID'];
    $name = trim(($r['NAME'] ?? '') . ' ' . ($r['LAST_NAME'] ?? ''));
    echo "U$id active={$r['ACTIVE']} login={$r['LOGIN']} email={$r['EMAIL']} name=$name\n";
}
$q2 = $m->query("SELECT ID, NAME FROM b_iblock_section WHERE ID IN (2,15,43,58)");
echo "===DEPTS===\n";
while ($r = $q2->fetch_assoc()) {
    echo $r['ID'] . ' ' . $r['NAME'] . "\n";
}
