<?php

namespace GlpiPlugin\Mcpserverlite\Tools;

use Exception;
use Project;
use ProjectCost;
use ProjectState;
use ProjectTask;
use ProjectTeam;
use ProjectType;
use Search;

/**
 * Projects module: list/get/search/create/update projects, plus tasks,
 * team members, and costs. Verified against the `11.0/bugfixes` branch
 * specifically (per this project's standing rule — see
 * PROJECT-REFERENCE.md §7 / CHANGELOG 0.8.0): `glpi_projects` genuinely
 * has an `is_deleted` column (confirmed against
 * install/mysql/glpi-empty.sql), unlike `glpi_knowbaseitems` — the two
 * shouldn't be assumed to match just because they're both GLPI tables.
 */
class ProjectTools
{
    private static function findProjectIds(array $criteria, int $start, int $limit): array
    {
        $data = Search::getDatas('Project', [
            'deleted'    => false,
            'criteria'   => $criteria,
            'sort'       => 19,
            'order'      => 'DESC',
            'start'      => $start,
            'list_limit' => $limit,
        ]);

        $ids = [];
        foreach (($data['data']['rows'] ?? []) as $row) {
            if (isset($row['id'])) {
                $ids[] = (int) $row['id'];
            }
        }
        return $ids;
    }

    private static function hydrateSummary(int $id): ?array
    {
        $project = new Project();
        if (!$project->getFromDB($id)) {
            return null;
        }
        return [
            'id'               => (int) $project->fields['id'],
            'name'             => $project->fields['name'],
            'projectstates_id' => (int) $project->fields['projectstates_id'],
            'priority'         => (int) $project->fields['priority'],
            'percent_done'     => (int) $project->fields['percent_done'],
            'date'             => $project->fields['date'],
        ];
    }

    private static function assertProjectReadable(int $id): Project
    {
        $project = new Project();
        if (!$project->getFromDB($id)) {
            throw new Exception("project $id not found", 404);
        }
        if (!$project->can($id, READ)) {
            throw new Exception("not allowed to read project $id", 403);
        }
        return $project;
    }

    public static function listProjects(array $args): array
    {
        $criteria = [];
        if (isset($args['state_id'])) {
            $criteria[] = ['link' => 'AND', 'field' => 12, 'searchtype' => 'equals', 'value' => (int) $args['state_id']];
        }

        $ids = self::findProjectIds($criteria, (int) ($args['start'] ?? 0), min((int) ($args['limit'] ?? 20), 100));
        return array_values(array_filter(array_map([self::class, 'hydrateSummary'], $ids)));
    }

    /**
     * Free-text search via the 'view' special search-option id — the
     * same mechanism GLPI's own top search box uses, and the fix already
     * verified for glpi_search_tickets (a literal field name like 'name'
     * is not a valid search-option id and is silently ignored).
     */
    public static function searchProjects(array $args): array
    {
        $criteria = [];
        if (!empty($args['text'])) {
            $criteria[] = ['link' => 'AND', 'field' => 'view', 'searchtype' => 'contains', 'value' => $args['text']];
        }

        $ids = self::findProjectIds($criteria, 0, min((int) ($args['limit'] ?? 20), 100));
        return array_values(array_filter(array_map([self::class, 'hydrateSummary'], $ids)));
    }

    /**
     * Deliberate over-fetch, same reasoning as TicketTools::getTicket():
     * fields + team + tasks in one call rather than requiring the model
     * to chain 3 separate tool calls. Team comes from Project::getTeam()
     * (populated via post_getFromDB() → ProjectTeam::getTeamFor(),
     * verified against src/Project.php/src/ProjectTeam.php) — already
     * hydrated to id/name/realname/firstname/itemtype, not just raw ids.
     */
    public static function getProject(array $args): array
    {
        $id      = (int) $args['id'];
        $project = self::assertProjectReadable($id);

        return [
            'project' => $project->fields,
            'team'    => $project->getTeam(),
            'tasks'   => self::taskRows($id),
        ];
    }

    private static function taskRows(int $projectId): array
    {
        $rows = (new ProjectTask())->find(
            ['projects_id' => $projectId, 'is_deleted' => 0],
            ['plan_start_date ASC']
        );
        return array_values($rows);
    }

    public static function getProjectTasks(array $args): array
    {
        $id = (int) $args['project_id'];
        self::assertProjectReadable($id);
        return self::taskRows($id);
    }

    public static function getProjectTeam(array $args): array
    {
        $id      = (int) $args['project_id'];
        $project = self::assertProjectReadable($id);
        return $project->getTeam();
    }

    public static function getProjectCosts(array $args): array
    {
        $id = (int) $args['project_id'];
        self::assertProjectReadable($id);
        $rows = (new ProjectCost())->find(['projects_id' => $id], ['begin_date DESC']);
        return array_values($rows);
    }

    /**
     * Same purpose as KnowbaseTools::searchKnowbaseCategories() — a
     * person naming a state/type ("put this in Planning") gives a name,
     * not an id; resolve it before calling create/update. Both
     * ProjectState and ProjectType are plain CommonDropdown classes
     * (verified against src/ProjectState.php/src/ProjectType.php), so a
     * simple CommonDBTM::find() is enough — same trade-off as
     * UserTools::listUsers() (no entity restriction; each is a small,
     * low-risk dropdown table).
     */
    public static function searchProjectDropdowns(array $args): array
    {
        $dropdown = $args['dropdown'] ?? 'state';
        $item     = $dropdown === 'type' ? new ProjectType() : new ProjectState();

        $condition = [];
        if (!empty($args['text'])) {
            $condition['name'] = ['LIKE', '%' . $args['text'] . '%'];
        }

        $limit = min((int) ($args['limit'] ?? 20), 100);
        $rows  = $item->find($condition, ['name ASC'], $limit);

        $out = [];
        foreach ($rows as $row) {
            $out[] = ['id' => (int) $row['id'], 'name' => $row['name']];
        }
        return $out;
    }
}
