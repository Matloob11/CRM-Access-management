<?php
/**
 * Read-only: dump the company structure (departments) with head + members.
 *
 * Optional STDIN: {"root": <deptId>} to limit the dump to one subtree
 * (default: the whole iblock-3 structure). Output:
 *   { ok, departments: [ {id, name, parentId, headId, headName, members:[{id,name,active}] } ] }
 */

declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

$req = a7_read_request();
$rootId = isset($req['root']) ? (int) $req['root'] : 0;

$m = a7_db();

// Heads: VALUE_ID (section id) -> UF_HEAD.
$heads = [];
$q = $m->query('SELECT VALUE_ID, UF_HEAD FROM ' . A7_STRUCTURE_UTS . ' WHERE UF_HEAD IS NOT NULL');
while ($r = $q->fetch_assoc()) {
    $heads[(int) $r['VALUE_ID']] = (int) $r['UF_HEAD'];
}

// Sections of the company-structure iblock.
$sections = [];
$where = 'IBLOCK_ID=' . A7_STRUCTURE_IBLOCK;
$q = $m->query("SELECT ID, NAME, IBLOCK_SECTION_ID FROM b_iblock_section WHERE $where ORDER BY LEFT_MARGIN");
while ($r = $q->fetch_assoc()) {
    $sections[(int) $r['ID']] = [
        'id' => (int) $r['ID'],
        'name' => $r['NAME'],
        'parentId' => $r['IBLOCK_SECTION_ID'] !== null ? (int) $r['IBLOCK_SECTION_ID'] : null,
        'headId' => $heads[(int) $r['ID']] ?? null,
    ];
}

// Optionally restrict to a subtree rooted at $rootId.
if ($rootId > 0) {
    $keep = [];
    $stack = [$rootId];
    while ($stack) {
        $cur = array_pop($stack);
        if (!isset($sections[$cur]) || isset($keep[$cur])) {
            continue;
        }
        $keep[$cur] = true;
        foreach ($sections as $sid => $s) {
            if ($s['parentId'] === $cur) {
                $stack[] = $sid;
            }
        }
    }
    $sections = array_intersect_key($sections, $keep);
}

// Collect member ids per department, plus a name lookup.
$needNames = [];
foreach ($sections as $sid => &$s) {
    $memberIds = [];
    $rs = CIntranetUtils::GetDepartmentEmployees([$sid], false, false, 'N', ['ID']);
    while ($emp = $rs->Fetch()) {
        $memberIds[] = (int) $emp['ID'];
    }
    $s['memberIds'] = $memberIds;
    foreach ($memberIds as $id) {
        $needNames[$id] = true;
    }
    if ($s['headId']) {
        $needNames[$s['headId']] = true;
    }
}
unset($s);

// Resolve names + active flag in one pass.
$names = [];
if ($needNames) {
    $in = implode(',', array_map('intval', array_keys($needNames)));
    $q = $m->query("SELECT ID, NAME, LAST_NAME, LOGIN, ACTIVE FROM b_user WHERE ID IN ($in)");
    while ($r = $q->fetch_assoc()) {
        $full = trim(($r['NAME'] ?? '') . ' ' . ($r['LAST_NAME'] ?? ''));
        $names[(int) $r['ID']] = [
            'name' => $full !== '' ? $full : $r['LOGIN'],
            'active' => $r['ACTIVE'] === 'Y',
        ];
    }
}

$departments = [];
foreach ($sections as $s) {
    $members = [];
    foreach ($s['memberIds'] as $id) {
        $members[] = [
            'id' => $id,
            'name' => $names[$id]['name'] ?? ('User ' . $id),
            'active' => $names[$id]['active'] ?? false,
        ];
    }
    $departments[] = [
        'id' => $s['id'],
        'name' => $s['name'],
        'parentId' => $s['parentId'],
        'headId' => $s['headId'],
        'headName' => $s['headId'] ? ($names[$s['headId']]['name'] ?? ('User ' . $s['headId'])) : null,
        'members' => $members,
    ];
}

a7_ok(['departments' => $departments]);
