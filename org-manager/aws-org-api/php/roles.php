<?php
/**
 * Read-only: list CRM roles, their access-code bindings, and (optionally) the
 * per-entity scope summary for a set of roles.
 *
 * Optional STDIN: {"perms_for": [54, 36], "entities": ["LEAD", ...]}
 * Output: { ok, roles:[{id,name}], relations:[{roleId,relation}],
 *           perms: { "<roleId>": { "<ENTITY>": {"READ":ATTR,"WRITE":ATTR,"ADD":ATTR,
 *                                               "READ_S":scope-from-SETTINGS, ...} } },
 *           mismatches: { "<roleId>": n },   // rows with SETTINGS but no ATTR
 *           c14Stages:  { "<roleId>": n } }  // Availability per-stage READ rows
 */

declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

$req = a7_read_request();
$permsFor = array_map('intval', $req['perms_for'] ?? []);
$entities = $req['entities'] ?? [];

$m = a7_db();

$roles = [];
$q = $m->query('SELECT ID, NAME FROM b_crm_role ORDER BY ID');
while ($r = $q->fetch_assoc()) {
    $roles[] = ['id' => (int) $r['ID'], 'name' => $r['NAME']];
}

$relations = [];
$q = $m->query('SELECT ROLE_ID, RELATION FROM b_crm_role_relation ORDER BY ROLE_ID, RELATION');
while ($r = $q->fetch_assoc()) {
    $relations[] = ['roleId' => (int) $r['ROLE_ID'], 'relation' => $r['RELATION']];
}

$perms = [];
$mismatches = [];
$c14 = [];
if ($permsFor) {
    $inRoles = implode(',', $permsFor);
    $entityFilter = '';
    if ($entities) {
        $esc = array_map(fn($e) => "'" . $m->real_escape_string((string) $e) . "'", $entities);
        $entityFilter = ' AND ENTITY IN (' . implode(',', $esc) . ')';
    }
    $q = $m->query(
        "SELECT ROLE_ID, ENTITY, PERM_TYPE, ATTR, SETTINGS FROM b_crm_role_perms
         WHERE ROLE_ID IN ($inRoles) AND PERM_TYPE IN ('READ','WRITE','ADD','DELETE','EXPORT','IMPORT') AND FIELD='-'$entityFilter
         ORDER BY ROLE_ID, ENTITY"
    );
    while ($r = $q->fetch_assoc()) {
        $rid = (string) (int) $r['ROLE_ID'];
        $ent = $r['ENTITY'];
        $perms[$rid][$ent][$r['PERM_TYPE']] = $r['ATTR'];
        $perms[$rid][$ent][$r['PERM_TYPE'] . '_S'] = a7_scope_from_settings($r['SETTINGS']);
    }
    foreach ($permsFor as $rid) {
        $mismatches[(string) $rid] = count(a7_role_attr_mismatches($rid));
        $c14[(string) $rid] = a7_c14_stage_row_count($rid);
    }
}

a7_ok(['roles' => $roles, 'relations' => $relations, 'perms' => $perms, 'mismatches' => $mismatches, 'c14Stages' => $c14]);
