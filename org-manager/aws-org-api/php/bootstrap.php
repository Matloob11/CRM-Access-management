<?php
/**
 * Shared Bitrix bootstrap + helpers for the A7 Org & Security Manager engine.
 *
 * Every engine script (tree / roles / apply / verify) includes this file. It
 * boots the Bitrix kernel as the `bitrix` OS user, opens a raw mysqli handle for
 * the few places the ORM is awkward, and exposes small, well-tested primitives
 * lifted from the existing handoff scripts under scripts/fixes and
 * scripts/related-tl (upsertPerm, department head, access-code rebuild, cache
 * clear, entity attribute rebuild).
 *
 * The scripts read a single JSON document on STDIN and print a single JSON
 * document on STDOUT. All human/debug text goes to STDERR so STDOUT stays valid
 * JSON for the Python API layer.
 */

declare(strict_types=1);

const A7_DOCROOT = '/home/bitrix/www';
const A7_STRUCTURE_IBLOCK = 3;              // company structure iblock
const A7_STRUCTURE_UTS = 'b_uts_iblock_3_section'; // UF_HEAD lives here
const A7_AGENT_GROUP_ID = 11;               // intranet group G11 (role 36 Agent)

$_SERVER['DOCUMENT_ROOT'] = A7_DOCROOT;
define('NO_KEEP_STATISTIC', true);
define('NOT_CHECK_PERMISSIONS', true);
define('BX_NO_ACCELERATOR_RESET', true);

require A7_DOCROOT . '/bitrix/modules/main/include/prolog_before.php';
\Bitrix\Main\Loader::includeModule('crm');
\Bitrix\Main\Loader::includeModule('intranet');
\Bitrix\Main\Loader::includeModule('iblock');

/** Log a line to STDERR (never STDOUT — that carries the JSON result). */
function a7_log(string $msg): void
{
    fwrite(STDERR, $msg . "\n");
}

/**
 * Read and decode the JSON request.
 *
 * Normally the request arrives on STDIN (how the API service calls the scripts).
 * When the script itself is streamed on STDIN (e.g. `php /dev/stdin '<json>'` for
 * on-box testing), the JSON is taken from argv[1] instead. Returns [] when there
 * is neither.
 */
function a7_read_request(): array
{
    global $argv;
    $raw = '';
    if (isset($argv[1]) && trim((string) $argv[1]) !== '') {
        $raw = (string) $argv[1];
    } else {
        $stdin = stream_get_contents(STDIN);
        $raw = $stdin === false ? '' : $stdin;
    }
    if (trim($raw) === '') {
        return [];
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        a7_fail('invalid JSON request');
    }
    return $data;
}

/** Print the JSON result to STDOUT and exit 0. */
function a7_ok(array $result): void
{
    $result['ok'] = true;
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit(0);
}

/** Print a JSON error to STDOUT and exit non-zero. */
function a7_fail(string $message, int $code = 1): void
{
    echo json_encode(['ok' => false, 'error' => $message], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit($code);
}

/** Open a raw mysqli connection using Bitrix's own credentials. */
function a7_db(): mysqli
{
    static $conn = null;
    if ($conn instanceof mysqli) {
        return $conn;
    }
    $settings = include A7_DOCROOT . '/bitrix/.settings.php';
    $db = $settings['connections']['value']['default'];
    $conn = new mysqli($db['host'], $db['login'], $db['password'], $db['database']);
    if ($conn->connect_error) {
        a7_fail('database connection failed');
    }
    $conn->set_charset('utf8');
    return $conn;
}

/**
 * Insert or update one CRM role permission row.
 * Lifted verbatim (behaviour) from scripts/fixes/fix_agent_owner_data_perms.php.
 */
function a7_upsert_perm(
    int $roleId,
    string $entity,
    string $permType,
    ?string $attr,
    ?string $settings = '[]',
    string $field = '-',
    ?string $fieldValue = null
): void {
    $m = a7_db();
    $e = $m->real_escape_string($entity);
    $p = $m->real_escape_string($permType);
    $f = $m->real_escape_string($field);
    $fvSql = $fieldValue === null ? 'IS NULL' : ("='" . $m->real_escape_string($fieldValue) . "'");

    $q = $m->query(
        "SELECT ID FROM b_crm_role_perms
         WHERE ROLE_ID=$roleId AND ENTITY='$e' AND PERM_TYPE='$p' AND FIELD='$f' AND FIELD_VALUE $fvSql
         LIMIT 1"
    );
    $row = $q ? $q->fetch_assoc() : null;

    $attrSql = $attr === null ? 'NULL' : ("'" . $m->real_escape_string($attr) . "'");
    $settingsSql = $settings === null ? 'NULL' : ("'" . $m->real_escape_string($settings) . "'");
    $fvIns = $fieldValue === null ? 'NULL' : ("'" . $m->real_escape_string($fieldValue) . "'");

    if ($row) {
        $id = (int) $row['ID'];
        $m->query("UPDATE b_crm_role_perms SET ATTR=$attrSql, SETTINGS=$settingsSql WHERE ID=$id");
    } else {
        $m->query(
            "INSERT INTO b_crm_role_perms (ROLE_ID, ENTITY, FIELD, FIELD_VALUE, PERM_TYPE, ATTR, SETTINGS)
             VALUES ($roleId, '$e', '$f', $fvIns, '$p', $attrSql, $settingsSql)"
        );
    }
}

/**
 * SETTINGS JSON that matches a legacy ATTR letter. Bitrix's legacy permission
 * layer (CCrmPerms) reads ATTR; the newer UI reads SETTINGS. Rows that carry
 * only SETTINGS resolve to an empty scope in CCrmPerms, which is exactly the
 * "Team Leader cannot open Contacts" bug this portal had. Always write both.
 */
function a7_settings_for_scope(string $scope): string
{
    switch ($scope) {
        case 'D':
            return '["SELF","DEPARTMENT","SUBDEPARTMENTS"]';
        case 'X':
            return '["ALL"]';
        default:
            return '["SELF"]';
    }
}

/** Legacy ATTR letter derived from a SETTINGS JSON list (null when unknown). */
function a7_scope_from_settings(?string $settings): ?string
{
    $list = json_decode((string) $settings, true);
    if (!is_array($list)) {
        return null;
    }
    $list = array_map('strtoupper', array_map('strval', $list));
    if (in_array('ALL', $list, true)) {
        return 'X';
    }
    if (in_array('DEPARTMENT', $list, true) || in_array('SUBDEPARTMENTS', $list, true)) {
        return 'D';
    }
    if (in_array('SELF', $list, true)) {
        return 'A';
    }
    return null;
}

/** Write one permission row at a scope with consistent ATTR + SETTINGS. */
function a7_set_perm(int $roleId, string $entity, string $permType, string $scope): void
{
    a7_upsert_perm($roleId, $entity, $permType, $scope, a7_settings_for_scope($scope));
}

/** Delete one permission row (all FIELD/FIELD_VALUE variants for that perm type). */
function a7_delete_perm(int $roleId, string $entity, string $permType): int
{
    $m = a7_db();
    $e = $m->real_escape_string($entity);
    $p = $m->real_escape_string($permType);
    $m->query("DELETE FROM b_crm_role_perms WHERE ROLE_ID=$roleId AND ENTITY='$e' AND PERM_TYPE='$p'");
    return (int) $m->affected_rows;
}

/**
 * Apply the standard entity permission set for one role + one entity at a scope.
 * scope is a Bitrix ATTR: 'A' (self/assigned), 'D' (department + sub), 'X' (all).
 */
function a7_set_entity_scope(int $roleId, string $entity, string $scope): void
{
    foreach (['READ', 'WRITE', 'ADD'] as $perm) {
        a7_set_perm($roleId, $entity, $perm, $scope);
    }
    // View helpers that every role in this portal already carries.
    a7_upsert_perm($roleId, $entity, 'HIDE_SUM', 'X', '[]');
    a7_upsert_perm($roleId, $entity, 'MYCARDVIEW', 'X', '[]');
    a7_upsert_perm($roleId, $entity, 'TRANSITION', null, '["ANY"]');
}

/**
 * Repair rows whose ATTR is empty while SETTINGS names a scope. Returns the
 * number of rows changed. Only touches READ/WRITE/ADD/DELETE/EXPORT/IMPORT.
 */
function a7_fix_role_attrs(int $roleId, bool $dry = false): array
{
    $m = a7_db();
    $fixed = [];
    $q = $m->query(
        "SELECT ID, ENTITY, PERM_TYPE, SETTINGS FROM b_crm_role_perms
         WHERE ROLE_ID=$roleId AND FIELD='-' AND (ATTR IS NULL OR ATTR='')
           AND PERM_TYPE IN ('READ','WRITE','ADD','DELETE','EXPORT','IMPORT')"
    );
    while ($r = $q->fetch_assoc()) {
        $scope = a7_scope_from_settings($r['SETTINGS']);
        if ($scope === null) {
            continue;
        }
        $fixed[] = ['entity' => $r['ENTITY'], 'perm' => $r['PERM_TYPE'], 'attr' => $scope];
        if (!$dry) {
            $m->query("UPDATE b_crm_role_perms SET ATTR='$scope' WHERE ID=" . (int) $r['ID']);
        }
    }
    return $fixed;
}

/** Rows for a role whose ATTR and SETTINGS disagree or where ATTR is missing. */
function a7_role_attr_mismatches(int $roleId): array
{
    $m = a7_db();
    $out = [];
    $q = $m->query(
        "SELECT ENTITY, PERM_TYPE, ATTR, SETTINGS FROM b_crm_role_perms
         WHERE ROLE_ID=$roleId AND FIELD='-' AND PERM_TYPE IN ('READ','WRITE','ADD','DELETE','EXPORT','IMPORT')"
    );
    while ($r = $q->fetch_assoc()) {
        $fromSettings = a7_scope_from_settings($r['SETTINGS']);
        $attr = (string) $r['ATTR'];
        if ($attr === '' && $fromSettings !== null) {
            $out[] = ['entity' => $r['ENTITY'], 'perm' => $r['PERM_TYPE'], 'attr' => '', 'settings' => $fromSettings];
        }
    }
    return $out;
}

/**
 * Copy the per-stage READ rows for the Availability category from the Sales
 * Agent template (role 8) onto $roleId, so a role can see the kanban stages.
 * Falls back to the status table when the template has none.
 */
function a7_apply_c14_stage_reads(int $roleId, string $scope): int
{
    $m = a7_db();
    $stages = [];
    $q = $m->query(
        "SELECT DISTINCT FIELD_VALUE FROM b_crm_role_perms
         WHERE ROLE_ID=8 AND ENTITY='DYNAMIC_1042_C14' AND FIELD='STAGE_ID' AND PERM_TYPE='READ'
           AND FIELD_VALUE IS NOT NULL"
    );
    while ($r = $q->fetch_assoc()) {
        $stages[] = $r['FIELD_VALUE'];
    }
    if (!$stages) {
        $q = $m->query("SELECT STATUS_ID FROM b_crm_status WHERE ENTITY_ID='DYNAMIC_1042_STAGE_14' ORDER BY SORT");
        while ($r = $q->fetch_assoc()) {
            $stages[] = $r['STATUS_ID'];
        }
    }
    foreach ($stages as $st) {
        a7_upsert_perm($roleId, 'DYNAMIC_1042_C14', 'READ', $scope, 'null', 'STAGE_ID', $st);
    }
    return count($stages);
}

/** Number of per-stage READ rows a role has for the Availability category. */
function a7_c14_stage_row_count(int $roleId): int
{
    $m = a7_db();
    $q = $m->query("SELECT COUNT(*) c FROM b_crm_role_perms WHERE ROLE_ID=$roleId AND ENTITY='DYNAMIC_1042_C14' AND FIELD='STAGE_ID' AND PERM_TYPE='READ'");
    $r = $q ? $q->fetch_assoc() : null;
    return (int) ($r['c'] ?? 0);
}

/** UF_DEPARTMENT ids for a user. */
function a7_user_departments(int $userId): array
{
    $u = CUser::GetByID($userId)->Fetch();
    if (!$u) {
        return [];
    }
    $d = $u['UF_DEPARTMENT'] ?? [];
    $d = is_array($d) ? $d : [$d];
    return array_values(array_filter(array_map('intval', $d)));
}

/** Basic user record (null when missing). */
function a7_user(int $userId): ?array
{
    $u = CUser::GetByID($userId)->Fetch();
    if (!$u) {
        return null;
    }
    $full = trim(($u['NAME'] ?? '') . ' ' . ($u['LAST_NAME'] ?? ''));
    return [
        'id' => (int) $u['ID'],
        'name' => $full !== '' ? $full : $u['LOGIN'],
        'active' => ($u['ACTIVE'] ?? 'N') === 'Y',
        'departments' => a7_user_departments($userId),
        'groups' => array_map('intval', CUser::GetUserGroup($userId)),
    ];
}

/** Remove a user from an intranet group. */
function a7_remove_user_from_group(int $userId, int $groupId): void
{
    $existing = array_map('intval', CUser::GetUserGroup($userId));
    if (in_array($groupId, $existing, true)) {
        $existing = array_values(array_filter($existing, fn($g) => $g !== $groupId));
        $user = new CUser();
        $user->Update($userId, ['GROUP_ID' => $existing]);
    }
}

/** Department head (UF_HEAD) for a department, or null. */
function a7_department_head(int $deptId): ?int
{
    $m = a7_db();
    $q = $m->query('SELECT UF_HEAD FROM ' . A7_STRUCTURE_UTS . ' WHERE VALUE_ID=' . $deptId);
    $r = $q ? $q->fetch_assoc() : null;
    return $r && $r['UF_HEAD'] ? (int) $r['UF_HEAD'] : null;
}

/** Department row from the structure iblock, or null. */
function a7_department(int $deptId): ?array
{
    $m = a7_db();
    $q = $m->query('SELECT ID, NAME, IBLOCK_SECTION_ID, ACTIVE FROM b_iblock_section WHERE IBLOCK_ID=' . A7_STRUCTURE_IBLOCK . ' AND ID=' . $deptId);
    $r = $q ? $q->fetch_assoc() : null;
    if (!$r) {
        return null;
    }
    return [
        'id' => (int) $r['ID'],
        'name' => $r['NAME'],
        'parentId' => $r['IBLOCK_SECTION_ID'] !== null ? (int) $r['IBLOCK_SECTION_ID'] : null,
        'active' => $r['ACTIVE'] === 'Y',
        'headId' => a7_department_head($deptId),
    ];
}

/** Member user ids of a department (direct members only). */
function a7_department_members(int $deptId): array
{
    $ids = [];
    $rs = CIntranetUtils::GetDepartmentEmployees([$deptId], false, false, 'N', ['ID']);
    while ($emp = $rs->Fetch()) {
        $ids[] = (int) $emp['ID'];
    }
    return $ids;
}

/** Child department ids (direct). */
function a7_department_children(int $deptId): array
{
    $m = a7_db();
    $out = [];
    $q = $m->query('SELECT ID FROM b_iblock_section WHERE IBLOCK_ID=' . A7_STRUCTURE_IBLOCK . ' AND IBLOCK_SECTION_ID=' . $deptId);
    while ($q && ($r = $q->fetch_assoc())) {
        $out[] = (int) $r['ID'];
    }
    return $out;
}

/** Role ids a user reaches through any of their access codes. */
function a7_user_roles(int $userId): array
{
    $m = a7_db();
    $codes = [];
    $q = $m->query("SELECT ACCESS_CODE FROM b_user_access WHERE USER_ID=$userId");
    while ($q && ($r = $q->fetch_assoc())) {
        $codes[] = "'" . $m->real_escape_string($r['ACCESS_CODE']) . "'";
    }
    if (!$codes) {
        return [];
    }
    $out = [];
    $q = $m->query('SELECT DISTINCT rr.ROLE_ID, r.NAME, rr.RELATION FROM b_crm_role_relation rr JOIN b_crm_role r ON r.ID=rr.ROLE_ID WHERE rr.RELATION IN (' . implode(',', $codes) . ') ORDER BY rr.ROLE_ID');
    while ($q && ($r = $q->fetch_assoc())) {
        $out[] = ['roleId' => (int) $r['ROLE_ID'], 'name' => $r['NAME'], 'via' => $r['RELATION']];
    }
    return $out;
}

/**
 * Everything that must happen after a structure/permission change so the
 * result is visible now: access codes, HR sync, caches, entity attributes.
 */
function a7_finalize(array $userIds, bool $rebuildAttrs = true): array
{
    $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds))));
    $out = [];
    if ($userIds) {
        a7_rebuild_access_codes($userIds);
        $out['accessCodes'] = count($userIds);
    }
    $out['hrSynced'] = a7_sync_hr();
    a7_clear_caches();
    if ($rebuildAttrs && $userIds) {
        $out['leadAttrs'] = a7_rebuild_entity_attrs($userIds, \CCrmOwnerType::Lead, 'b_crm_lead');
        $out['ownerAttrs'] = a7_rebuild_entity_attrs($userIds, 1042, 'b_crm_dynamic_items_1042', 8000);
    }
    return $out;
}

/** Set (or clear) a department's head in the UF_HEAD user field. */
function a7_set_department_head(int $deptId, ?int $headId): void
{
    $m = a7_db();
    $val = $headId === null ? 'NULL' : (int) $headId;
    $m->query('UPDATE ' . A7_STRUCTURE_UTS . ' SET UF_HEAD=' . $val . ' WHERE VALUE_ID=' . (int) $deptId);
}

/** Ensure a b_crm_role_relation row exists binding an access code to a role. */
function a7_bind_role(int $roleId, string $relation): void
{
    $m = a7_db();
    $rel = $m->real_escape_string($relation);
    $q = $m->query("SELECT ID FROM b_crm_role_relation WHERE ROLE_ID=$roleId AND RELATION='$rel' LIMIT 1");
    if (!($q && $q->fetch_assoc())) {
        $m->query("INSERT INTO b_crm_role_relation (ROLE_ID, RELATION) VALUES ($roleId, '$rel')");
    }
}

/** Remove a role binding (used when a user changes team role). */
function a7_unbind_role(int $roleId, string $relation): void
{
    $m = a7_db();
    $rel = $m->real_escape_string($relation);
    $m->query("DELETE FROM b_crm_role_relation WHERE ROLE_ID=$roleId AND RELATION='$rel'");
}

/** Set a single user's department membership (single department per user here). */
function a7_set_user_department(int $userId, int $deptId): bool
{
    $user = new CUser();
    return (bool) $user->Update($userId, ['UF_DEPARTMENT' => [$deptId]]);
}

/** Add a user to an intranet user group (e.g. G11 for agents). */
function a7_add_user_to_group(int $userId, int $groupId): void
{
    $existing = CUser::GetUserGroup($userId);
    $existing = array_map('intval', $existing);
    if (!in_array($groupId, $existing, true)) {
        $existing[] = $groupId;
        $user = new CUser();
        $user->Update($userId, ['GROUP_ID' => $existing]);
    }
}

/** Rebuild the access codes (b_user_access) for a set of users. */
function a7_rebuild_access_codes(array $userIds): void
{
    $access = new CAccess();
    foreach (array_unique(array_map('intval', $userIds)) as $uid) {
        $access->UpdateCodes(['USER_ID' => $uid]);
    }
}

/** Best-effort HumanResources structure sync (new Bitrix org tree). */
function a7_sync_hr(): bool
{
    if (!\Bitrix\Main\Loader::includeModule('humanresources')) {
        return false;
    }
    try {
        $cls = '\\Bitrix\\HumanResources\\Compat\\Structure';
        if (class_exists($cls) && method_exists($cls, 'syncAll')) {
            $cls::syncAll();
            return true;
        }
    } catch (\Throwable $e) {
        a7_log('HR sync error: ' . $e->getMessage());
    }
    return false;
}

/** Clear all CRM permission / role caches so new scopes take effect. */
function a7_clear_caches(): void
{
    if (function_exists('BXClearCache')) {
        BXClearCache(true, '/crm/');
    }
    try {
        $managed = \Bitrix\Main\Application::getInstance()->getManagedCache();
        $managed->cleanDir('/crm/');
        $managed->cleanDir('/crm/user_permission_roles/');
        $managed->cleanDir('/crm/user_access_codes/');
    } catch (\Throwable $e) {
        a7_log('managed cache clear error: ' . $e->getMessage());
    }
    try {
        \Bitrix\Crm\Security\Role\Model\RolePermissionTable::getEntity()->cleanCache();
    } catch (\Throwable $e) {
        // Older/newer kernels may not expose this; the managed clean above is enough.
    }
}

/**
 * Re-register CRM entity access attributes for items assigned to a set of users.
 * Keeps list filters (b_crm_*_access_attr) consistent after a scope change.
 * Bounded by $limit per entity type to keep a single apply call fast.
 */
function a7_rebuild_entity_attrs(array $userIds, int $entityTypeId, string $table, int $limit = 5000): array
{
    $m = a7_db();
    $ids = array_unique(array_map('intval', $userIds));
    if (!$ids) {
        return ['rebuilt' => 0, 'failed' => 0];
    }
    $in = implode(',', $ids);
    $rebuilt = 0;
    $failed = 0;
    try {
        $factory = \Bitrix\Crm\Service\Container::getInstance()->getFactory($entityTypeId);
        if (!$factory) {
            return ['rebuilt' => 0, 'failed' => 0, 'note' => 'no factory'];
        }
        $q = $m->query("SELECT ID FROM `$table` WHERE ASSIGNED_BY_ID IN ($in) ORDER BY ID DESC LIMIT $limit");
        while ($r = $q->fetch_assoc()) {
            $id = (int) $r['ID'];
            try {
                $item = $factory->getItem($id);
                if (!$item) {
                    continue;
                }
                \Bitrix\Crm\Security\Controller\Manager::getController($entityTypeId)->registerEntity($id, [
                    'ASSIGNED_BY_ID' => $item->getAssignedById(),
                    'OPENED' => $item->getOpened(),
                    'CATEGORY_ID' => method_exists($item, 'getCategoryId') ? $item->getCategoryId() : 0,
                ]);
                $rebuilt++;
            } catch (\Throwable $e) {
                $failed++;
            }
        }
    } catch (\Throwable $e) {
        a7_log('attr rebuild error: ' . $e->getMessage());
    }
    return ['rebuilt' => $rebuilt, 'failed' => $failed];
}
