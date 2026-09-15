#!/usr/bin/env php
<?php
/**
 * Deep check: only Excel teams apply — no extra members, no multi-dept heads,
 * no stray role bindings that would widen Agent/Head visibility.
 */
declare(strict_types=1);

require '/opt/a7-org-api/current/php/bootstrap.php';
\Bitrix\Main\Loader::includeModule('crm');

$expected = [
    69 => [2560 => 'head', 2344 => 'agent', 2429 => 'agent', 2379 => 'agent', 2380 => 'agent', 2376 => 'agent', 2434 => 'agent', 2291 => 'agent'],
    70 => [2551 => 'head', 2369 => 'agent', 2381 => 'agent', 2371 => 'agent', 2535 => 'agent', 2368 => 'agent', 2367 => 'agent', 2350 => 'agent'],
    71 => [2297 => 'head', 2302 => 'agent', 2298 => 'agent', 2299 => 'agent', 2390 => 'agent', 2392 => 'agent'],
];
$heads = [2560, 2551, 2297];
$allExpected = [];
foreach ($expected as $dept => $map) {
    foreach ($map as $uid => $role) {
        $allExpected[$uid] = $dept;
    }
}

$m = a7_db();
$issues = 0;

function nameOf(int $uid): string
{
    $u = \CUser::GetByID($uid)->Fetch();
    if (!$u) {
        return '?';
    }
    return trim(($u['NAME'] ?? '') . ' ' . ($u['LAST_NAME'] ?? ''));
}

echo "======== 1) Extra / missing members in dept 69/70/71 ========\n";
foreach ($expected as $deptId => $wantMap) {
    $want = array_map('intval', array_keys($wantMap));
    sort($want);
    // Find users whose UF_DEPARTMENT contains this dept
    $have = [];
    $res = $m->query("SELECT VALUE_ID, UF_DEPARTMENT FROM b_uts_user WHERE UF_DEPARTMENT LIKE '%i:$deptId;%' OR UF_DEPARTMENT LIKE '%:\"$deptId\";%' OR UF_DEPARTMENT LIKE '%;$deptId;%'");
    // Better: scan all with unserialize
    $res = $m->query("SELECT VALUE_ID, UF_DEPARTMENT FROM b_uts_user WHERE UF_DEPARTMENT IS NOT NULL AND UF_DEPARTMENT != ''");
    while ($r = $res->fetch_assoc()) {
        $raw = $r['UF_DEPARTMENT'];
        $d = @unserialize($raw);
        if (!is_array($d)) {
            continue;
        }
        $d = array_map('intval', $d);
        if (in_array($deptId, $d, true)) {
            $have[] = (int) $r['VALUE_ID'];
        }
    }
    $have = array_values(array_unique($have));
    sort($have);
    $extra = array_values(array_diff($have, $want));
    $missing = array_values(array_diff($want, $have));
    echo "Dept $deptId members=" . count($have) . " expected=" . count($want) . "\n";
    if ($extra) {
        $issues++;
        foreach ($extra as $uid) {
            echo "  EXTRA U$uid " . nameOf($uid) . " — will REMOVE from $deptId\n";
        }
    } else {
        echo "  no extras\n";
    }
    if ($missing) {
        $issues++;
        foreach ($missing as $uid) {
            echo "  MISSING U$uid " . nameOf($uid) . " — should be in $deptId\n";
        }
    } else {
        echo "  none missing\n";
    }
}

echo "\n======== 2) UF_HEAD of departments (heads must lead ONLY their team) ========\n";
// b_iblock_section for structure — UF_HEAD often in b_uts_iblock_N_section
$ib = 0;
$q = $m->query("SELECT ID FROM b_iblock WHERE IBLOCK_TYPE_ID='structure' OR CODE='departments' LIMIT 5");
while ($r = $q->fetch_assoc()) {
    echo "  structure iblock candidate ID=" . $r['ID'] . "\n";
    $ib = (int) $r['ID'];
}
if ($ib > 0) {
    $uts = "b_uts_iblock_{$ib}_section";
    $exists = $m->query("SHOW TABLES LIKE '$uts'");
    if ($exists && $exists->num_rows) {
        $res = $m->query("SELECT VALUE_ID, UF_HEAD FROM $uts WHERE UF_HEAD IS NOT NULL AND UF_HEAD != '' AND UF_HEAD != '0'");
        $headMap = []; // userId => [sectionIds]
        while ($r = $res->fetch_assoc()) {
            $hid = (int) $r['UF_HEAD'];
            $sid = (int) $r['VALUE_ID'];
            if (!isset($headMap[$hid])) {
                $headMap[$hid] = [];
            }
            $headMap[$hid][] = $sid;
        }
        foreach ($heads as $hid) {
            $led = $headMap[$hid] ?? [];
            $wantDept = $allExpected[$hid];
            echo "Head U$hid " . nameOf($hid) . " leads sections=" . json_encode($led) . " want=[$wantDept]\n";
            $bad = array_values(array_diff($led, [$wantDept]));
            if ($bad) {
                $issues++;
                echo "  PROBLEM: also heads of " . json_encode($bad) . "\n";
            } elseif (!in_array($wantDept, $led, true)) {
                $issues++;
                echo "  PROBLEM: not UF_HEAD of own team $wantDept\n";
            } else {
                echo "  OK sole head of $wantDept\n";
            }
        }
        // Anyone else UF_HEAD of 69/70/71?
        foreach ([69, 70, 71] as $d) {
            $who = [];
            foreach ($headMap as $uid => $secs) {
                if (in_array($d, $secs, true)) {
                    $who[] = $uid;
                }
            }
            $wantHead = array_keys(array_filter($expected[$d], fn($r) => $r === 'head'))[0];
            echo "Dept $d UF_HEAD users=" . json_encode($who) . " expect=[$wantHead]\n";
            if ($who !== [$wantHead]) {
                $issues++;
                echo "  PROBLEM wrong head(s)\n";
            }
        }
    } else {
        echo "  no table $uts\n";
    }
}

echo "\n======== 3) CRM role relations for team users ========\n";
// Agents should be via G11 (role 36). Heads via U{id} (role 54). Flag X/All roles.
$agentIds = [];
foreach ($expected as $map) {
    foreach ($map as $uid => $role) {
        if ($role === 'agent') {
            $agentIds[] = $uid;
        }
    }
}
$inUsers = implode(',', array_merge($heads, $agentIds));
// Relations that mention these users
$rels = [];
$res = $m->query("SELECT ROLE_ID, RELATION FROM b_crm_role_relation");
while ($r = $res->fetch_assoc()) {
    $rels[] = $r;
}
$roleNames = [];
$rn = $m->query("SELECT ID, NAME FROM b_crm_role");
while ($r = $rn->fetch_assoc()) {
    $roleNames[(int) $r['ID']] = $r['NAME'];
}

foreach (array_merge($heads, $agentIds) as $uid) {
    $matched = [];
    foreach ($rels as $r) {
        $rel = $r['RELATION'];
        $rid = (int) $r['ROLE_ID'];
        if ($rel === "U$uid" || preg_match('/^U' . $uid . '$/', $rel)) {
            $matched[] = "role $rid (" . ($roleNames[$rid] ?? '?') . ") via $rel";
        }
    }
    // Also G11 membership for agents
    $groups = \CUser::GetUserGroup($uid);
    $groups = array_map('intval', $groups);
    $isHead = in_array($uid, $heads, true);
    echo "U$uid " . nameOf($uid) . ($isHead ? ' HEAD' : ' AGENT') . " groups=" . json_encode($groups) . "\n";
    if ($matched) {
        foreach ($matched as $line) {
            echo "  bind: $line\n";
        }
    } else {
        echo "  bind: (no U{id} direct role — OK if agent via G11)\n";
    }
    if ($isHead) {
        $has54 = false;
        foreach ($matched as $line) {
            if (str_contains($line, 'role 54')) {
                $has54 = true;
            }
        }
        if (!$has54) {
            $issues++;
            echo "  PROBLEM: head missing role 54 U-bind\n";
        }
        // Head should NOT be in G11 if that would also give agent scope (usually fine) — warn only
        if (in_array(11, $groups, true)) {
            echo "  note: head also in G11 (agent group) — role 54 D still applies for wider scope\n";
        }
    } else {
        if (!in_array(11, $groups, true)) {
            $issues++;
            echo "  PROBLEM: agent not in G11\n";
        }
        // Direct binds other than expected
        foreach ($matched as $line) {
            if (!str_contains($line, 'role 54') && !str_contains($line, 'role 36')) {
                // other personal roles
                echo "  WARN extra personal role: $line\n";
            }
            if (str_contains($line, 'role 54')) {
                $issues++;
                echo "  PROBLEM: agent has Team Leader role 54 bind\n";
            }
        }
    }
}

// Check role 36 / 54 still A / D for LEAD
echo "\n======== 4) Role 36/54 LEAD READ attrs ========\n";
foreach ([54 => 'D', 36 => 'A'] as $rid => $want) {
    $q = $m->query("SELECT ATTR FROM b_crm_role_perms WHERE ROLE_ID=$rid AND ENTITY='LEAD' AND PERM_TYPE='READ' LIMIT 1");
    $r = $q->fetch_assoc();
    $attr = $r['ATTR'] ?? '';
    echo "role $rid LEAD READ ATTR=$attr want=$want " . ($attr === $want ? 'OK' : 'FAIL') . "\n";
    if ($attr !== $want) {
        $issues++;
    }
}

echo "\nISSUES_FOUND=$issues\n";
