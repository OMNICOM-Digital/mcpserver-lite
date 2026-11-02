<?php

namespace GlpiPlugin\Mcpserverlite\Tools;

use DbUtils;
use Exception;
use KnowbaseItem;
use KnowbaseItemCategory;

/**
 * Knowledge base: read tools (list/get/search on both GLPI interfaces,
 * category lookup on Standard/central only).
 *
 * Listing/searching deliberately does NOT go through Search::getDatas()
 * the way TicketTools does — KnowbaseItem has its own, more elaborate
 * visibility model (an article's visibility is a set of rows in
 * Entity_KnowbaseItem / Group_KnowbaseItem / KnowbaseItem_Profile /
 * KnowbaseItem_User, not a single entities_id column), and GLPI ships a
 * ready-made helper for exactly this: KnowbaseItem::getVisibilityCriteria()
 * (verified against src/KnowbaseItem.php) already encodes "FAQ-only for
 * Self-Service interface, full KB for Standard, public FAQ for anonymous"
 * — reusing it means we inherit that logic instead of re-deriving it.
 * It returns a LEFT JOIN + WHERE fragment meant to be merged into a
 * `$DB->request()` call, which is exactly how core's own
 * KnowbaseItem::showRecentPopular() consumes it — including its use of
 * `'DISTINCT' => true` rather than GROUP BY to collapse the duplicate
 * rows the LEFT JOINs produce (one KB item can match several
 * visibility rows at once), a pattern copied directly from there.
 */
class KnowbaseTools
{
    private static function baseVisibleQuery(): array
    {
        $criteria = KnowbaseItem::getVisibilityCriteria();
        $criteria['SELECT']   = ['glpi_knowbaseitems.id', 'glpi_knowbaseitems.name', 'glpi_knowbaseitems.is_faq', 'glpi_knowbaseitems.date_mod'];
        $criteria['DISTINCT'] = true;
        $criteria['FROM']     = 'glpi_knowbaseitems';
        return $criteria;
    }

    public static function listKnowbase(array $args): array
    {
        global $DB;

        $criteria = self::baseVisibleQuery();
        $criteria['ORDER'] = ['glpi_knowbaseitems.date_mod DESC'];
        $criteria['LIMIT'] = min((int) ($args['limit'] ?? 20), 100);

        return self::hydrateRows($DB->request($criteria));
    }

    public static function searchKnowbase(array $args): array
    {
        global $DB;

        $criteria = self::baseVisibleQuery();
        $criteria['WHERE'][] = ['glpi_knowbaseitems.name' => ['LIKE', '%' . $args['query'] . '%']];
        $criteria['ORDER']   = ['glpi_knowbaseitems.date_mod DESC'];
        $criteria['LIMIT']   = min((int) ($args['limit'] ?? 20), 100);

        return self::hydrateRows($DB->request($criteria));
    }

    private static function hydrateRows(iterable $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'id'       => (int) $row['id'],
                'name'     => $row['name'],
                'is_faq'   => (bool) $row['is_faq'],
                'date_mod' => $row['date_mod'],
            ];
        }
        return $out;
    }

    public static function getKnowbaseItem(array $args): array
    {
        $id   = (int) $args['id'];
        $item = new KnowbaseItem();

        if (!$item->getFromDB($id)) {
            throw new Exception("knowledge base item $id not found", 404);
        }
        if (!$item->canViewItem()) {
            throw new Exception("not allowed to read knowledge base item $id", 403);
        }

        return [
            'id'       => (int) $item->fields['id'],
            'name'     => $item->fields['name'],
            'answer'   => $item->fields['answer'],
            'is_faq'   => (bool) $item->fields['is_faq'],
            'date_mod' => $item->fields['date_mod'],
        ];
    }

    /**
     * Finds category ids by name — meant to run *before*
     * createKnowbaseItem()/updateKnowbaseItem(): a person naming a
     * category ("put this under Networking") gives a name, not an id, so
     * the model needs a way to resolve one to the other. Matches on both
     * `name` (this category alone) and `completename` (full tree path,
     * e.g. "Hardware > Printers") so a category nested under a
     * same-named parent elsewhere in the tree is still disambiguated.
     *
     * Entity-restricted since 1.3.0 ( review
     * finding 15): only categories in the caller's active entities, or
     * recursive categories in an ancestor of them, are returned — the
     * standard dropdown visibility rule, via DbUtils::
     * getEntitiesRestrictCriteria() (instantiated; it has no static form).
     * completename paths from other tenants' trees no longer leak.
     */
    public static function searchKnowbaseCategories(array $args): array
    {
        $condition = [
            (new DbUtils())->getEntitiesRestrictCriteria(KnowbaseItemCategory::getTable(), '', '', true),
        ];
        if (!empty($args['text'])) {
            $like = '%' . $args['text'] . '%';
            $condition[] = [
                'OR' => [
                    'name'         => ['LIKE', $like],
                    'completename' => ['LIKE', $like],
                ],
            ];
        }

        $limit = min((int) ($args['limit'] ?? 20), 100);
        $rows  = (new KnowbaseItemCategory())->find($condition, ['completename ASC'], $limit);

        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'id'           => (int) $row['id'],
                'name'         => $row['name'],
                'completename' => $row['completename'],
                'entities_id'  => (int) $row['entities_id'],
            ];
        }
        return $out;
    }
}
