<?php

namespace GlpiPlugin\Mcpserverlite\Tools;

use DbUtils;
use Entity;
use Exception;
use Glpi\DBAL\QuerySubQuery;
use Group;
use Profile_User;
use User;

/**
 * Users and groups (read-only). listUsers() does NOT go through
 * Search::getDatas() — User's search-option ids weren't part of the
 * verified field-id set this plugin already relies on for tickets (see the
 * `field => 'name'` bug fixed in TicketTools::searchTickets()).
 * CommonDBTM::find() is used instead, and — since 1.3.0 (review
 * finding 9) — it is restricted explicitly to users
 * holding a profile in one of the caller's active entities, via
 * DbUtils::getEntitiesRestrictCriteria() on glpi_profiles_users (the same
 * restriction core's own user dropdown uses).
 */
class UserTools
{
    public static function listUsers(array $args): array
    {
        // Predicate 1: the caller may view users at all (user READ — held by
        // every stock central profile, Observer and Read-Only included).
        if (!User::canView()) {
            throw new Exception('not allowed to list users (user read right required)', 403);
        }

        // Predicate 2: inactive/deleted accounts are account-administration
        // data, so include_inactive requires user UPDATE. One input, one
        // remediation: an explicit include_inactive=true without the right is
        // refused with a 403 rather than silently downgraded to active-only,
        // so the caller never mistakes a filtered list for a complete one.
        $includeInactive = !empty($args['include_inactive']);
        if ($includeInactive && !User::canUpdate()) {
            throw new Exception('include_inactive requires the right to update users', 403);
        }

        $condition = [];

        if (!$includeInactive) {
            $condition['is_active']  = 1;
            $condition['is_deleted'] = 0;
        }

        // Predicate 3: only users with a profile in one of the caller's
        // active entities (recursive profile assignments on an ancestor
        // entity count, matching core's user dropdown). With no active
        // entities in the session, getEntitiesRestrictCriteria() yields
        // `false` and the list is empty.
        $condition['id'] = new QuerySubQuery([
            'SELECT' => 'users_id',
            'FROM'   => Profile_User::getTable(),
            'WHERE'  => (new DbUtils())->getEntitiesRestrictCriteria(Profile_User::getTable(), '', '', true),
        ]);

        if (!empty($args['text'])) {
            $like = '%' . $args['text'] . '%';
            $condition[] = [
                'OR' => [
                    'name'      => ['LIKE', $like],
                    'realname'  => ['LIKE', $like],
                    'firstname' => ['LIKE', $like],
                ],
            ];
        }

        $limit = min((int) ($args['limit'] ?? 20), 100);
        $rows  = (new User())->find($condition, ['name ASC'], $limit);

        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'id'        => (int) $row['id'],
                'name'      => $row['name'],
                'realname'  => $row['realname'] ?? null,
                'firstname' => $row['firstname'] ?? null,
                'is_active' => (bool) $row['is_active'],
            ];
        }
        return $out;
    }

    /** role argument => Group flag (src/Group.php, 11.0/bugfixes). */
    private const GROUP_ROLE_FLAGS = [
        'assign'    => 'is_assign',
        'requester' => 'is_requester',
        'watcher'   => 'is_watcher',
    ];

    /**
     * 1.5.0: resolve a group name to its id, so the person does not
     * have to look ids up in the UI.
     *
     * Rights — decided with the person 2026-09-26: `group` READ required
     * (Group::canView()), like glpi_list_users requires `user` READ, even
     * though core's ticket actor dropdown lists groups without it.
     *
     * Scope: groups of the active entities plus recursive groups of their
     * ancestors — DbUtils::getEntitiesRestrictCriteria() with recursion,
     * the same rule as glpi_search_itil_categories and core's dropdowns.
     * `role` filters on the flag core's actor dropdown uses for that role
     * (is_assign for "Assigned to", CommonITILObject.php). Members are
     * deliberately not returned.
     */
    public static function searchGroups(array $args): array
    {
        if (!Group::canView()) {
            throw new Exception('not allowed to search groups (group read right required)', 403);
        }

        $condition = [
            (new DbUtils())->getEntitiesRestrictCriteria(Group::getTable(), '', '', true),
        ];

        if (!empty($args['role'])) {
            if (!array_key_exists($args['role'], self::GROUP_ROLE_FLAGS)) {
                throw new Exception('role must be assign, requester or watcher', 400);
            }
            $condition[self::GROUP_ROLE_FLAGS[$args['role']]] = 1;
        }
        if (!empty($args['text'])) {
            $like = '%' . $args['text'] . '%';
            $condition[] = [
                'OR' => [
                    'name'         => ['LIKE', $like],
                    'completename' => ['LIKE', $like],
                ],
            ];
        }

        $limit = max(1, min((int) ($args['limit'] ?? 20), 100));
        $rows  = (new Group())->find($condition, ['completename ASC'], $limit + 1);

        // Entity path per group. A recursive group may live in an ancestor
        // entity outside the active ones; its path is a prefix of the
        // caller's own entity paths, so nothing new is disclosed (same
        // reasoning as the parent in EntityTools::getEntity()).
        $entityPaths = [];
        $out         = [];
        foreach ($rows as $row) {
            if (count($out) === $limit) {
                break;
            }
            $entityId = (int) $row['entities_id'];
            if (!array_key_exists($entityId, $entityPaths)) {
                $entity = new Entity();
                $entityPaths[$entityId] = $entity->getFromDB($entityId) ? $entity->fields['completename'] : null;
            }
            $out[] = [
                'id'           => (int) $row['id'],
                'name'         => $row['name'],
                'completename' => $row['completename'],
                'entity'       => ['id' => $entityId, 'completename' => $entityPaths[$entityId]],
                'is_recursive' => (bool) $row['is_recursive'],
                'is_assign'    => (bool) $row['is_assign'],
                'is_requester' => (bool) $row['is_requester'],
                'is_watcher'   => (bool) $row['is_watcher'],
            ];
        }

        return ['groups' => $out, 'truncated' => count($rows) > $limit];
    }
}
