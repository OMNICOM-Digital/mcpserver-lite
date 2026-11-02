<?php

namespace GlpiPlugin\Mcpserverlite\Tools;

use Entity;
use Exception;
use Session;
use User;

/**
 * Read-only entity tools: resolving an entity the person names ("the Košice
 * branch") to its id, and telling the model what session context it is
 * running in.
 *
 * Scope rule, shared by listEntities() and getEntity()'s children list:
 * exactly the session's active entities, `id IN glpiactiveentities`. That
 * is the same set Session::haveAccessToEntity() tests (verified against
 * src/Session.php on 11.0/bugfixes: a plain in_array() on
 * $_SESSION['glpiactiveentities']). Ancestors are deliberately NOT included
 * (DbUtils::getEntitiesRestrictCriteria('glpi_entities', ..., true) would
 * add them — verified in src/DbUtils.php — but they are outside the
 * session's active scope).
 *
 * listEntities() needs no `entity` right: it returns what the GLPI
 * entity selector already shows the same user. getEntity() is the
 * equivalent of Administration > Entities > (item), so it requires
 * Entity::can($id, READ) = `entity` READ right (Entity::$rightname) plus
 * Entity::canViewItem() = haveAccessToEntity($id) (src/Entity.php).
 * Its output is a field whitelist: glpi_entities also carries
 * notification/mail/LDAP configuration that is not needed here.
 */
class EntityTools
{
    private const DEFAULT_LIMIT = 50;
    private const MAX_LIMIT     = 200;
    private const MAX_IDS       = 200;

    /** Fields of glpi_entities returned by getEntity() (see class docblock). */
    private const DETAIL_FIELDS = [
        'registration_number', 'address', 'postcode', 'town', 'state',
        'country', 'website', 'phonenumber', 'fax', 'email',
    ];

    /**
     * Rule 7: no active entities in the session means nothing is listed,
     * never a fallback to "all entities".
     */
    private static function activeEntityIds(): array
    {
        return array_values(array_map('intval', Session::getActiveEntities()));
    }

    public static function listEntities(array $args): array
    {
        $active = self::activeEntityIds();
        if ($active === []) {
            return ['entities' => [], 'truncated' => false];
        }

        $condition = ['id' => $active];
        if (!empty($args['text'])) {
            $like = '%' . $args['text'] . '%';
            $condition[] = [
                'OR' => [
                    'name'         => ['LIKE', $like],
                    'completename' => ['LIKE', $like],
                ],
            ];
        }

        $limit = max(1, min((int) ($args['limit'] ?? self::DEFAULT_LIMIT), self::MAX_LIMIT));
        $rows  = (new Entity())->find($condition, ['completename ASC'], $limit + 1);

        $activeEntity = (int) Session::getActiveEntity();
        $out = [];
        foreach ($rows as $row) {
            if (count($out) === $limit) {
                break;
            }
            $id    = (int) $row['id'];
            $out[] = [
                'id'               => $id,
                'name'             => $row['name'],
                'completename'     => $row['completename'],
                'parent_id'        => $id === 0 ? null : (int) $row['entities_id'],
                'level'            => (int) $row['level'],
                'is_active_entity' => $id === $activeEntity,
                // Session::haveRecursiveAccessToEntity() — whether objects
                // created here may be made recursive (src/Session.php).
                'recursive_access' => (bool) Session::haveRecursiveAccessToEntity($id),
            ];
        }

        return ['entities' => $out, 'truncated' => count($rows) > $limit];
    }

    public static function getEntity(array $args): array
    {
        $id     = (int) $args['id'];
        $entity = new Entity();
        if (!$entity->getFromDB($id)) {
            throw new Exception("entity $id not found", 404);
        }
        if (!$entity->can($id, READ)) {
            throw new Exception("not allowed to read entity $id (requires the entity read right and access to that entity)", 403);
        }

        $fields = $entity->fields;
        $detail = [
            'id'           => $id,
            'name'         => $fields['name'],
            'completename' => $fields['completename'],
            'comment'      => $fields['comment'],
            'level'        => (int) $fields['level'],
        ];
        foreach (self::DETAIL_FIELDS as $field) {
            $detail[$field] = $fields[$field] ?? null;
        }

        // Parent: its name is already part of this entity's own
        // completename, so returning it discloses nothing new even when the
        // parent itself is outside the caller's active entities.
        $detail['parent'] = null;
        if ($id !== 0) {
            $parent = new Entity();
            if ($parent->getFromDB((int) $fields['entities_id'])) {
                $detail['parent'] = [
                    'id'           => (int) $parent->fields['id'],
                    'completename' => $parent->fields['completename'],
                ];
            }
        }

        // Children: direct sub-entities, restricted to active entities
        // (same scope rule as listEntities()).
        $children = [];
        $active   = self::activeEntityIds();
        if ($active !== []) {
            $rows = (new Entity())->find(
                ['entities_id' => $id, 'id' => $active, ['NOT' => ['id' => $id]]],
                ['name ASC']
            );
            foreach ($rows as $row) {
                $children[] = [
                    'id'           => (int) $row['id'],
                    'name'         => $row['name'],
                    'completename' => $row['completename'],
                ];
            }
        }
        $detail['children'] = $children;

        return $detail;
    }

    /**
     * Who the MCP session is running as and in which entity scope. Reads
     * only the caller's own session. active_entity.is_recursive is
     * Session::getIsActiveEntityRecursive(), the selection flag — a
     * non-recursive profile assignment still limits the real scope, which
     * is why the note points to active_entity_ids (1.4.1, live test
     * 2026-09-26: Technician on Košice showed is_recursive=true with
     * sub-entity 3 absent from active_entity_ids). Rule 7: without a logged-in user
     * there is no context to report, so this fails instead of returning
     * defaults.
     */
    public static function getSessionContext(array $args): array
    {
        $userId = (int) Session::getLoginUserID();
        if ($userId <= 0) {
            throw new Exception('no active GLPI session', 403);
        }

        $user = new User();
        $user->getFromDB($userId);

        $activeEntityId = (int) Session::getActiveEntity();
        $activeEntity   = new Entity();
        $activeEntityName = $activeEntity->getFromDB($activeEntityId)
            ? $activeEntity->fields['completename']
            : null;

        $profile = $_SESSION['glpiactiveprofile'] ?? [];
        $ids     = self::activeEntityIds();

        return [
            'user' => [
                'id'        => $userId,
                'login'     => $user->fields['name'] ?? null,
                'realname'  => $user->fields['realname'] ?? null,
                'firstname' => $user->fields['firstname'] ?? null,
            ],
            'profile' => [
                'id'        => isset($profile['id']) ? (int) $profile['id'] : null,
                'name'      => $profile['name'] ?? null,
                'interface' => Session::getCurrentInterface() ?: null,
            ],
            'active_entity' => [
                'id'           => $activeEntityId,
                'completename' => $activeEntityName,
                'is_recursive' => Session::getIsActiveEntityRecursive(),
            ],
            'active_entities_count'     => count($ids),
            'active_entity_ids'         => array_slice($ids, 0, self::MAX_IDS),
            'active_entity_ids_truncated' => count($ids) > self::MAX_IDS,
            'note' => 'active_entity_ids is the authoritative list of entities this session can see; '
                . 'active_entity is the one selected in the GLPI UI. active_entity.is_recursive is the session\'s '
                . 'selection flag only: whether sub-entities are really included depends on the profile '
                . 'assignment, so rely on active_entity_ids. Use glpi_list_entities to resolve names.',
        ];
    }
}
