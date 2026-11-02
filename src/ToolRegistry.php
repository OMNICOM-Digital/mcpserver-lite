<?php

namespace GlpiPlugin\Mcpserverlite;

use GlpiPlugin\Mcpserverlite\Tools\EntityTools;
use GlpiPlugin\Mcpserverlite\Tools\FormTools;
use GlpiPlugin\Mcpserverlite\Tools\KnowbaseTools;
use GlpiPlugin\Mcpserverlite\Tools\ProjectTools;
use GlpiPlugin\Mcpserverlite\Tools\TicketTools;
use GlpiPlugin\Mcpserverlite\Tools\UserTools;
use Session;

/**
 * Central registry of MCP tools exposed by this plugin. To add a tool:
 * implement a static method on one of the Tools\* classes and register it
 * below — front/mcp.php and the transport layer don't change.
 *
 * Each tool definition carries an 'interfaces' key naming which GLPI
 * profile interface(s) can see/use it — verified against src/Profile.php
 * / src/Session.php: a GLPI profile's `interface` field is either
 * 'central' (Standard interface — technicians, admins) or 'helpdesk'
 * (Self-Service, the simplified interface end-users get). A tool with no
 * 'interfaces' key defaults to `[INTERFACE_CENTRAL]` — i.e. hidden from
 * Self-Service by default, since most admin and project tools here don't map to
 * anything a Self-Service profile can normally do in the GLPI UI either.
 * This is filtered in two places, not just one: toMcpList() (so the model
 * never even sees a tool it shouldn't reach for) AND
 * isAllowedForInterface() (checked again in front/mcp.php's
 * tools/call, since nothing stops an MCP client from calling a tool name
 * directly without ever fetching tools/list first).
 *
 * This is a UX/steering filter, not the actual security boundary — GLPI's
 * own can()/canCreate()/canUpdate() etc. (already used throughout
 * Tools\*) remain the real permission enforcement. A Self-Service user
 * who somehow reached a central-only tool would still be stopped by
 * the object-level rights check regardless of this filter. What this filter buys is: the
 * model doesn't waste a turn attempting (and the person doesn't see) a
 * tool that was never going to be relevant for their interface in the
 * first place.
 */
class ToolRegistry
{
    public const INTERFACE_CENTRAL  = 'central';
    public const INTERFACE_HELPDESK = 'helpdesk';

    private static ?array $tools = null;

    public static function all(): array
    {
        if (self::$tools !== null) {
            return self::$tools;
        }

        self::$tools = [
            // ---- Tickets: read -------------------------------------------------
            'glpi_list_tickets' => [
                'description' => 'List tickets, optionally filtered by status. Returns id, name, status, priority, date.',
                'interfaces'  => [self::INTERFACE_CENTRAL, self::INTERFACE_HELPDESK],
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'status' => ['type' => 'integer', 'description' => 'GLPI ticket status code. Omit for all.'],
                        'start'  => ['type' => 'integer', 'default' => 0],
                        'limit'  => ['type' => 'integer', 'default' => 20, 'maximum' => 100],
                    ],
                ],
                'handler' => [TicketTools::class, 'listTickets'],
            ],
            'glpi_get_ticket' => [
                'description' => 'Get full detail of a single ticket by id. Returns the ticket fields plus, in the same call: the entity the ticket belongs to (name and full path), requesters, observers, assigned technicians and assigned groups (each with name and email, not just an internal id), and the full timeline (followups/tasks/solutions/validations). No need to separately call glpi_get_ticket_timeline or look up users for this ticket. If the ticket is still in "New" status and you are on the Standard (central) interface, the response also includes a short type_check_hint flag pointing to glpi_analyze_itil_ticket_type, since this is the point in the process where that classification should be reviewed. For a New ticket whose ITIL category is missing or not allowed for its type, a category_check_hint flag is also included, pointing to glpi_analyze_itil_ticket_category. Both hints are advisory: show the ticket details the user asked for, then mention the hint in one sentence; the analyze tools provide the details.',
                'interfaces'  => [self::INTERFACE_CENTRAL, self::INTERFACE_HELPDESK],
                'input_schema' => [
                    'type' => 'object',
                    'properties' => ['id' => ['type' => 'integer']],
                    'required' => ['id'],
                ],
                'handler' => [TicketTools::class, 'getTicket'],
            ],
            'glpi_search_tickets' => [
                'description' => 'High-level ticket search by status/requester/technician/date range/free-text.',
                'interfaces'  => [self::INTERFACE_CENTRAL, self::INTERFACE_HELPDESK],
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'text'          => ['type' => 'string'],
                        'status'        => ['type' => 'integer'],
                        'requester_id'  => ['type' => 'integer'],
                        'technician_id' => ['type' => 'integer'],
                        'date_from'     => ['type' => 'string', 'description' => 'YYYY-MM-DD'],
                        'date_to'       => ['type' => 'string', 'description' => 'YYYY-MM-DD'],
                        'limit'         => ['type' => 'integer', 'default' => 20, 'maximum' => 100],
                    ],
                ],
                'handler' => [TicketTools::class, 'searchTickets'],
            ],
            'glpi_list_overdue_tickets' => [
                'description' => 'Tickets whose SLA "time to resolve" has been exceeded and are not yet solved/closed.',
                // Central-only: an org-wide SLA/queue-management view, not
                // something a Self-Service end-user acts on.
                'input_schema' => [
                    'type' => 'object',
                    'properties' => ['limit' => ['type' => 'integer', 'default' => 20, 'maximum' => 100]],
                ],
                'handler' => [TicketTools::class, 'listOverdueTickets'],
            ],
            'glpi_get_ticket_timeline' => [
                'description' => 'Full chronological timeline of a ticket: followups + tasks + solutions + validations.',
                'interfaces'  => [self::INTERFACE_CENTRAL, self::INTERFACE_HELPDESK],
                'input_schema' => [
                    'type' => 'object',
                    'properties' => ['id' => ['type' => 'integer']],
                    'required' => ['id'],
                ],
                'handler' => [TicketTools::class, 'getTimeline'],
            ],
            'glpi_analyze_itil_ticket_type' => [
                'description' => 'Gather everything needed to judge whether a ticket\'s ITIL type (Incident=1 vs Service Request=2) is set correctly: current type, subject, description, and the full followup+solution communication history in chronological order. Also returns the canonical ITIL definitions of both types, so the judgment is always anchored to the same reference. Read-only: it does not decide or change anything — state your assessment in text; if the type looks wrong, tell the user it has to be changed in GLPI itself. glpi_get_ticket only flags a New ticket for review (type_check_hint); call this tool when the user wants the type reviewed, for a ticket in any status. The response also includes current_category with its type flags; category_conflict=true means the current category is not allowed for the other type, so the category has to change together with the type (see glpi_analyze_itil_ticket_category).',
                'interfaces'  => [self::INTERFACE_CENTRAL],
                'input_schema' => [
                    'type' => 'object',
                    'properties' => ['id' => ['type' => 'integer']],
                    'required' => ['id'],
                ],
                'handler' => [TicketTools::class, 'analyzeItilTicketType'],
            ],
            'glpi_analyze_itil_ticket_category' => [
                'description' => 'Gather everything needed to propose an ITIL category for a ticket: subject, description, the followup+solution communication history, current type and category, and the candidate categories valid for the ticket\'s type and entity (completename, code, comment, is_leaf). Also returns ITIL best-practice guidance for categorization. Read-only: it does not decide or change anything — propose 1 to 3 candidates plus the keep option in text; the category has to be changed in GLPI itself. Pass for_type to evaluate candidates for a type the ticket is about to be changed to.',
                'interfaces'  => [self::INTERFACE_CENTRAL],
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'id'              => ['type' => 'integer'],
                        'for_type'        => ['type' => 'integer', 'description' => '1 = Incident, 2 = Service request. Omit to use the ticket\'s current type.'],
                        'candidate_limit' => ['type' => 'integer', 'default' => 50, 'maximum' => 200],
                    ],
                    'required' => ['id'],
                ],
                'handler' => [TicketTools::class, 'analyzeItilTicketCategory'],
            ],
            'glpi_search_itil_categories' => [
                'description' => 'Find ITIL (ticket) category ids by name, full tree path (e.g. "Hardware > Printers") or code. Returns only categories visible in your active entities (including recursive categories of parent entities), with their is_incident / is_request flags. Filter by ticket_type to get only categories allowed for that type. Use this to resolve a category the person names to its id.',
                'interfaces'  => [self::INTERFACE_CENTRAL, self::INTERFACE_HELPDESK],
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'text'        => ['type' => 'string'],
                        'ticket_type' => ['type' => 'integer', 'description' => '1 = Incident, 2 = Service request. Omit for both.'],
                        'limit'       => ['type' => 'integer', 'default' => 20, 'maximum' => 100],
                    ],
                ],
                'handler' => [TicketTools::class, 'searchItilCategories'],
            ],
            'glpi_get_itil_category' => [
                'description' => 'Get full detail of one ITIL category by id: comment, parent and sub-categories, the technician and group responsible for it, linked knowledge base category, ticket/problem/change templates, and its type and visibility flags. Requires the ITIL category read right (Setup > Dropdowns); without it the call is refused.',
                'interfaces'  => [self::INTERFACE_CENTRAL],
                'input_schema' => [
                    'type' => 'object',
                    'properties' => ['id' => ['type' => 'integer']],
                    'required' => ['id'],
                ],
                'handler' => [TicketTools::class, 'getItilCategory'],
            ],
            'glpi_get_ticket_followups' => [
                'description' => 'List followups of a ticket.',
                'interfaces'  => [self::INTERFACE_CENTRAL, self::INTERFACE_HELPDESK],
                'input_schema' => [
                    'type' => 'object',
                    'properties' => ['ticket_id' => ['type' => 'integer']],
                    'required' => ['ticket_id'],
                ],
                'handler' => [TicketTools::class, 'getFollowups'],
            ],
            'glpi_get_ticket_tasks' => [
                'description' => 'List tasks (with time tracking) of a ticket.',
                // Central-only: tasks/time tracking are a technician concept.
                'input_schema' => [
                    'type' => 'object',
                    'properties' => ['ticket_id' => ['type' => 'integer']],
                    'required' => ['ticket_id'],
                ],
                'handler' => [TicketTools::class, 'getTasks'],
            ],
            'glpi_get_ticket_solutions' => [
                'description' => 'List solutions proposed on a ticket.',
                // Central-only by default. Some GLPI setups do let the
                // requester see/approve a proposed solution from
                // Self-Service — if that's the case here, add
                // INTERFACE_HELPDESK to this tool too.
                'input_schema' => [
                    'type' => 'object',
                    'properties' => ['ticket_id' => ['type' => 'integer']],
                    'required' => ['ticket_id'],
                ],
                'handler' => [TicketTools::class, 'getSolutions'],
            ],
            'glpi_get_ticket_validations' => [
                'description' => 'List validation (approval) requests on a ticket.',
                // Self-Service included: a validator (e.g. a manager
                // approving a purchase-type ticket) may well be a
                // Self-Service profile rather than a technician.
                'interfaces'  => [self::INTERFACE_CENTRAL, self::INTERFACE_HELPDESK],
                'input_schema' => [
                    'type' => 'object',
                    'properties' => ['ticket_id' => ['type' => 'integer']],
                    'required' => ['ticket_id'],
                ],
                'handler' => [TicketTools::class, 'getValidations'],
            ],
            'glpi_get_ticket_documents' => [
                'description' => 'List documents attached to a ticket.',
                'interfaces'  => [self::INTERFACE_CENTRAL, self::INTERFACE_HELPDESK],
                'input_schema' => [
                    'type' => 'object',
                    'properties' => ['ticket_id' => ['type' => 'integer']],
                    'required' => ['ticket_id'],
                ],
                'handler' => [TicketTools::class, 'getDocuments'],
            ],
            'glpi_get_ticket_satisfaction' => [
                'description' => 'Get the satisfaction survey result for a ticket, if answered.',
                'interfaces'  => [self::INTERFACE_CENTRAL, self::INTERFACE_HELPDESK],
                'input_schema' => [
                    'type' => 'object',
                    'properties' => ['ticket_id' => ['type' => 'integer']],
                    'required' => ['ticket_id'],
                ],
                'handler' => [TicketTools::class, 'getSatisfaction'],
            ],

            'glpi_list_users' => [
                'description' => 'List GLPI users, optionally filtered by free-text on name/realname/firstname. Active users only unless include_inactive=true.',
                // Central-only: user directory browsing is an admin/
                // technician action (and see UserTools' own docblock — no
                // entity restriction is applied here either, another
                // reason to keep this away from lower-trust callers).
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'text'             => ['type' => 'string'],
                        'include_inactive' => ['type' => 'boolean', 'default' => false],
                        'limit'            => ['type' => 'integer', 'default' => 20, 'maximum' => 100],
                    ],
                ],
                'handler' => [UserTools::class, 'listUsers'],
            ],
            'glpi_search_groups' => [
                'description' => 'Find GLPI group ids by name or full path (e.g. "IT > Helpdesk"). Returns groups visible in your active entities (including recursive groups of parent entities) with their is_assign / is_requester / is_watcher flags; members are not returned. Filter by role to get only groups usable in that ticket role — role "assign" gives the groups that can be assigned to tickets. Requires the group read right (Administration > Groups).',
                // Central-only (default), read-only: no enable/disable
                // switch, like every read tool.
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'text'  => ['type' => 'string'],
                        'role'  => [
                            'type'        => 'string',
                            'enum'        => ['assign', 'requester', 'watcher'],
                            'description' => 'Only groups allowed in this ticket role (is_assign / is_requester / is_watcher). Omit for all.',
                        ],
                        'limit' => ['type' => 'integer', 'default' => 20, 'maximum' => 100],
                    ],
                ],
                'handler' => [UserTools::class, 'searchGroups'],
            ],
            'glpi_list_entities' => [
                'description' => 'List the GLPI entities in your active entity scope — exactly the entities you can see in this session. Returns id, name, full path (completename), parent_id, level, whether it is the active entity, and whether you have recursive access there. Filter with text (matches name or full path, e.g. "Košice"). Use this to resolve an entity the person names into an entity_id.',
                'interfaces'  => [self::INTERFACE_CENTRAL, self::INTERFACE_HELPDESK],
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'text'  => ['type' => 'string'],
                        'limit' => ['type' => 'integer', 'default' => 50, 'maximum' => 200],
                    ],
                ],
                'handler' => [EntityTools::class, 'listEntities'],
            ],
            'glpi_get_entity' => [
                'description' => 'Get detail of one entity by id: full path, comment, address and contact fields, parent, and direct sub-entities in your scope. Requires the entity read right (Administration > Entities); without it the call is refused — use glpi_list_entities for names and ids.',
                'interfaces'  => [self::INTERFACE_CENTRAL],
                'input_schema' => [
                    'type' => 'object',
                    'properties' => ['id' => ['type' => 'integer']],
                    'required' => ['id'],
                ],
                'handler' => [EntityTools::class, 'getEntity'],
            ],
            'glpi_get_session_context' => [
                'description' => 'Who this MCP session runs as: GLPI user, active profile and interface (Standard/central or Self-Service/helpdesk), active entity, and the ids of all active entities. active_entity_ids is the authoritative scope (the is_recursive flag reflects the session selection, not the profile, so it can say true while sub-entities are still excluded). Use it to explain a permission refusal.',
                'interfaces'  => [self::INTERFACE_CENTRAL, self::INTERFACE_HELPDESK],
                'input_schema' => [
                    'type' => 'object',
                    'properties' => new \stdClass(),
                ],
                'handler' => [EntityTools::class, 'getSessionContext'],
            ],

            'glpi_list_knowbase' => [
                'description' => 'List knowledge base articles visible to the caller (FAQ-only for Self-Service, full KB for Standard).',
                'interfaces'  => [self::INTERFACE_CENTRAL, self::INTERFACE_HELPDESK],
                'input_schema' => [
                    'type' => 'object',
                    'properties' => ['limit' => ['type' => 'integer', 'default' => 20, 'maximum' => 100]],
                ],
                'handler' => [KnowbaseTools::class, 'listKnowbase'],
            ],
            'glpi_get_knowbase_item' => [
                'description' => 'Get a knowledge base article by id, including its full answer content.',
                'interfaces'  => [self::INTERFACE_CENTRAL, self::INTERFACE_HELPDESK],
                'input_schema' => [
                    'type' => 'object',
                    'properties' => ['id' => ['type' => 'integer']],
                    'required' => ['id'],
                ],
                'handler' => [KnowbaseTools::class, 'getKnowbaseItem'],
            ],
            'glpi_search_knowbase' => [
                'description' => 'Free-text search of knowledge base articles by title.',
                'interfaces'  => [self::INTERFACE_CENTRAL, self::INTERFACE_HELPDESK],
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'query' => ['type' => 'string'],
                        'limit' => ['type' => 'integer', 'default' => 20, 'maximum' => 100],
                    ],
                    'required' => ['query'],
                ],
                'handler' => [KnowbaseTools::class, 'searchKnowbase'],
            ],
            'glpi_search_knowbase_categories' => [
                'description' => 'Find knowledge base category ids by name (matches both the category\'s own name and its full tree path, e.g. "Hardware > Printers"). Use this to resolve a category the person names to its id.',
                // Central-only: only useful ahead of the (central-only)
                // create/update tools.
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'text'  => ['type' => 'string', 'description' => 'Omit to list all categories'],
                        'limit' => ['type' => 'integer', 'default' => 20, 'maximum' => 100],
                    ],
                ],
                'handler' => [KnowbaseTools::class, 'searchKnowbaseCategories'],
            ],
            'glpi_list_forms' => [
                'description' => 'List GLPI Forms (service catalog) the caller is allowed to fill in, based on that form\'s own access-control policy (e.g. published to their profile/group). Available on both interfaces — whether a given form shows up depends entirely on the form\'s own publication settings, not on Standard vs Self-Service.',
                'interfaces'  => [self::INTERFACE_CENTRAL, self::INTERFACE_HELPDESK],
                'input_schema' => [
                    'type' => 'object',
                    'properties' => ['limit' => ['type' => 'integer', 'default' => 20, 'maximum' => 100]],
                ],
                'handler' => [FormTools::class, 'listForms'],
            ],
            'glpi_get_form_schema' => [
                'description' => 'Get a form\'s questions (id, name, type, mandatory flag, extra_data) grouped by section. Read-only description of the form as the person would see it; question types and their answer shapes vary (dropdown, date, actor, file, free text, ...). Forms cannot be submitted through this server.',
                'interfaces'  => [self::INTERFACE_CENTRAL, self::INTERFACE_HELPDESK],
                'input_schema' => [
                    'type' => 'object',
                    'properties' => ['form_id' => ['type' => 'integer']],
                    'required' => ['form_id'],
                ],
                'handler' => [FormTools::class, 'getFormSchema'],
            ],
            'glpi_list_projects' => [
                'description' => 'List projects, optionally filtered by state. Returns id, name, state id, priority, percent done, date.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'state_id' => ['type' => 'integer', 'description' => 'ProjectState id — use glpi_search_project_dropdowns to find it'],
                        'start'    => ['type' => 'integer', 'default' => 0],
                        'limit'    => ['type' => 'integer', 'default' => 20, 'maximum' => 100],
                    ],
                ],
                'handler' => [ProjectTools::class, 'listProjects'],
            ],
            'glpi_search_projects' => [
                'description' => 'Free-text search of projects by name/content.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'text'  => ['type' => 'string'],
                        'limit' => ['type' => 'integer', 'default' => 20, 'maximum' => 100],
                    ],
                ],
                'handler' => [ProjectTools::class, 'searchProjects'],
            ],
            'glpi_get_project' => [
                'description' => 'Get full detail of a single project by id. Returns the project fields plus, in the same call: team members (users/groups/suppliers/contacts) and tasks. No need to separately call glpi_get_project_team or glpi_get_project_tasks.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => ['id' => ['type' => 'integer']],
                    'required' => ['id'],
                ],
                'handler' => [ProjectTools::class, 'getProject'],
            ],
            'glpi_get_project_tasks' => [
                'description' => 'List tasks of a project.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => ['project_id' => ['type' => 'integer']],
                    'required' => ['project_id'],
                ],
                'handler' => [ProjectTools::class, 'getProjectTasks'],
            ],
            'glpi_get_project_team' => [
                'description' => 'List team members of a project (users, groups, suppliers, contacts).',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => ['project_id' => ['type' => 'integer']],
                    'required' => ['project_id'],
                ],
                'handler' => [ProjectTools::class, 'getProjectTeam'],
            ],
            'glpi_get_project_costs' => [
                'description' => 'List cost entries of a project.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => ['project_id' => ['type' => 'integer']],
                    'required' => ['project_id'],
                ],
                'handler' => [ProjectTools::class, 'getProjectCosts'],
            ],
            'glpi_search_project_dropdowns' => [
                'description' => 'Find project state or type ids by name. Use this to resolve a state or type the person names ("Planning") to its id, e.g. to filter glpi_list_projects by state_id.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'dropdown' => ['type' => 'string', 'enum' => ['state', 'type'], 'default' => 'state'],
                        'text'     => ['type' => 'string', 'description' => 'Omit to list all'],
                        'limit'    => ['type' => 'integer', 'default' => 20, 'maximum' => 100],
                    ],
                ],
                'handler' => [ProjectTools::class, 'searchProjectDropdowns'],
            ],
        ];

        return self::$tools;
    }

    public static function get(string $name): ?array
    {
        return self::all()[$name] ?? null;
    }

    /**
     * Which GLPI interface(s) ('central' / 'helpdesk') a tool is exposed
     * to. Missing key defaults to central-only — see class docblock.
     */
    private static function interfacesFor(string $name): array
    {
        $tool = self::get($name);
        return $tool['interfaces'] ?? [self::INTERFACE_CENTRAL];
    }

    /**
     * The session's GLPI interface, or null when none is loaded.
     *
     * Fails closed: a profile-less session (reachable when Session::init()
     * refuses a deleted/inactive/date-expired user without throwing) gets
     * no tools instead of resolving to the more privileged interface. Only
     * 'central' and 'helpdesk' are recognised; anything else is treated as
     * no interface too.
     */
    private static function currentInterface(): ?string
    {
        $interface = Session::getCurrentInterface();
        return in_array($interface, [self::INTERFACE_CENTRAL, self::INTERFACE_HELPDESK], true) ? $interface : null;
    }

    /**
     * Real, enforced check — front/mcp.php's tools/call handler calls this,
     * not just tools/list, since nothing stops an MCP client from calling a
     * tool name it never fetched from tools/list.
     */
    public static function isAllowedForInterface(string $name): bool
    {
        if (self::get($name) === null) {
            return false;
        }
        $currentInterface = self::currentInterface();
        if ($currentInterface === null) {
            return false; // fail closed → front/mcp.php answers 403 tool_not_available_for_interface
        }
        return in_array($currentInterface, self::interfacesFor($name), true);
    }

    /**
     * tools/list is filtered by the caller's own GLPI interface (Standard
     * vs Self-Service — Session::getCurrentInterface()), so a Self-Service
     * session's AI client never even sees central-only tools.
     */
    public static function toMcpList(): array
    {
        $currentInterface = self::currentInterface();
        if ($currentInterface === null) {
            // Fail closed. An empty list rather than an exception:
            // front/mcp.php's catch would turn an exception into a
            // tool-call-shaped error, which is not a valid tools/list result.
            return [];
        }

        $out = [];
        foreach (self::all() as $name => $def) {
            if (!in_array($currentInterface, $def['interfaces'] ?? [self::INTERFACE_CENTRAL], true)) {
                continue;
            }
            $out[] = [
                'name'        => $name,
                'description' => (string) $def['description'],
                'inputSchema' => $def['input_schema'],
            ];
        }
        return $out;
    }
}
