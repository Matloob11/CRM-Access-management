#!/usr/bin/env php
<?php
declare(strict_types=1);
require '/opt/a7-org-api/current/php/bootstrap.php';

$needles = ['hina', 'raoof', 'rauf', 'raouf', 'farhan', 'sreya', 'gopi', 'usman', 'furqan'];
$m = a7_db();
$like = [];
foreach ($needles as $n) {
    $e = $m->real_escape_string($n);
    $like[] = "LOWER(CONCAT_WS(' ', NAME, LAST_NAME, SECOND_NAME, LOGIN, EMAIL)) LIKE '%$e%'";
}
$sql = "SELECT ID, ACTIVE, LOGIN, EMAIL, NAME, LAST_NAME, SECOND_NAME
        FROM b_user
        WHERE " . implode(' OR ', $like) . "
        ORDER BY ID";
$q = $m->query($sql);
while ($r = $q->fetch_assoc()) {
    $id = (int) $r['ID'];
    $name = trim(($r['NAME'] ?? '') . ' ' . ($r['LAST_NAME'] ?? '') . ' ' . ($r['SECOND_NAME'] ?? ''));
    $u = \CUser::GetByID($id)->Fetch();
    $depts = is_array($u['UF_DEPARTMENT'] ?? null) ? array_map('intval', $u['UF_DEPARTMENT']) : [];
    echo "U$id active={$r['ACTIVE']} login={$r['LOGIN']} email={$r['EMAIL']} name=$name depts=" . json_encode($depts) . "\n";
}
