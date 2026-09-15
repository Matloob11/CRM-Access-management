#!/usr/bin/env php
<?php
/**
 * Strip every Excel-final team user down to exactly ONE department: their team dept.
 * Also ensure removed-from-team users are NOT still in 69/70/71 (move to Sales 60 if still there).
 */
declare(strict_types=1);

require '/opt/a7-org-api/current/php/bootstrap.php';
\Bitrix\Main\Loader::includeModule('intranet');

$salesDept = 60;
$teams = [
    69 => [2560, 2344, 2429, 2379, 2380, 2376, 2434, 2291], // Hassan + agents (head included)
    70 => [2551, 2369, 2381, 2371, 2535, 2368, 2367, 2350], // Hussnain
    71 => [2297, 2302, 2298, 2299, 2390, 2392],             // Jaweria
];
$removed = [2378, 2303, 2351]; // Harm, Ruqia, Momina — must leave teams

function deptsOf(int $uid): array
{
    $u = \CUser::GetByID($uid)->Fetch();
    if (!$u) {
        return [];
    }
    $d = $u['UF_DEPARTMENT'] ?? [];
    if (!is_array($d)) {
        return [];
    }
    return array_values(array_map('intval', array_filter($d)));
}

function setOnlyDept(int $uid, int $deptId): bool
{
    $user = new \CUser();
    return (bool) $user->Update($uid, ['UF_DEPARTMENT' => [$deptId]]);
}

echo "======== BEFORE ========\n";
$allTeam = [];
foreach ($teams as $deptId => $uids) {
    foreach ($uids as $uid) {
        $allTeam[$uid] = $deptId;
        echo "U$uid expect=$deptId have=" . json_encode(deptsOf($uid)) . "\n";
    }
}
foreach ($removed as $uid) {
    echo "U$uid (removed) have=" . json_encode(deptsOf($uid)) . "\n";
}

echo "======== ENFORCE one-dept-only ========\n";
$changed = 0;
$fail = 0;

foreach ($allTeam as $uid => $want) {
    $have = deptsOf($uid);
    sort($have);
    if ($have === [$want]) {
        echo "U$uid OK already [$want]\n";
        continue;
    }
    $ok = setOnlyDept($uid, $want);
    $after = deptsOf($uid);
    echo "U$uid " . json_encode($have) . " -> " . json_encode($after) . ($ok && $after === [$want] ? " OK" : " FAIL") . "\n";
    if ($ok && $after === [$want]) {
        $changed++;
    } else {
        $fail++;
    }
}

foreach ($removed as $uid) {
    $have = deptsOf($uid);
    $inTeam = array_intersect($have, [69, 70, 71]);
    if (!$inTeam) {
        echo "U$uid removed OK (not in team depts) have=" . json_encode($have) . "\n";
        continue;
    }
    // Keep non-team depts if any; else put in Sales 60
    $keep = array_values(array_diff($have, [69, 70, 71]));
    if (!$keep) {
        $keep = [$salesDept];
    }
    $user = new \CUser();
    $ok = (bool) $user->Update($uid, ['UF_DEPARTMENT' => $keep]);
    $after = deptsOf($uid);
    $still = array_intersect($after, [69, 70, 71]);
    echo "U$uid removed " . json_encode($have) . " -> " . json_encode($after) . (!$still && $ok ? " OK" : " FAIL") . "\n";
    if (!$still && $ok) {
        $changed++;
    } else {
        $fail++;
    }
}

echo "======== FINALIZE affected users ========\n";
$toFinalize = array_keys($allTeam);
foreach ($removed as $uid) {
    $toFinalize[] = $uid;
}
$toFinalize = array_values(array_unique($toFinalize));
if (function_exists('a7_finalize')) {
    $res = a7_finalize($toFinalize);
    echo json_encode($res) . "\n";
} else {
    echo "a7_finalize missing — skip\n";
}

echo "======== AFTER ========\n";
$bad = 0;
foreach ($allTeam as $uid => $want) {
    $have = deptsOf($uid);
    $ok = ($have === [$want]);
    echo "U$uid expect=[$want] have=" . json_encode($have) . ($ok ? " OK" : " FAIL") . "\n";
    if (!$ok) {
        $bad++;
    }
}
foreach ($removed as $uid) {
    $have = deptsOf($uid);
    $still = array_intersect($have, [69, 70, 71]);
    echo "U$uid removed have=" . json_encode($have) . (!$still ? " OK" : " FAIL still in team") . "\n";
    if ($still) {
        $bad++;
    }
}

echo "\nchanged=$changed fail=$fail remaining_bad=$bad\n";
echo $bad === 0 ? "ALL CLEAN — one department each\n" : "STILL HAS ISSUES\n";
