<?php

namespace GlpiPlugin\Mcpserverlite\Tools;

use ChangeTemplate;
use CommonITILActor;
use DbUtils;
use Document;
use Document_Item;
use Entity;
use Exception;
use Group;
use Group_Ticket;
use ITILCategory;
use ITILFollowup;
use ITILSolution;
use KnowbaseItemCategory;
use ProblemTemplate;
use Search;
use Session;
use Ticket;
use Ticket_User;
use TicketSatisfaction;
use TicketTask;
use TicketTemplate;
use TicketValidation;
use User;

/**
 * Implements glpi_*_ticket* tools directly against GLPI's internal objects.
 * Assumes a valid GLPI session already exists (established by
 * OAuthTokenAuth in front/mcp.php before any tool handler runs) — that
 * session's rights are what actually gates read access; this class
 * doesn't re-implement permission checks, it relies on CommonDBTM/Session
 * doing it as normal.
 *
 * Read helpers below (followups/tasks/solutions/validations/documents/
 * satisfaction) use CommonDBTM::find($condition, $order) directly against
 * the child table's own itemtype/items_id (or tickets_id) columns instead
 * of Search::getDatas(). That's deliberate: these child objects don't carry
 * their own entity, and access is gated by assertTicketReadable() checking
 * rights on the *parent* ticket first — exactly what the GLPI UI does when
 * it shows a ticket's timeline. This differs from listTickets/searchTickets,
 * which query Ticket itself and therefore DO need Search::getDatas() for
 * its automatic entity/sub-entity restriction.
 */
class TicketTools
{
    /**
     * Runs a GLPI Search query and returns matching ticket ids only —
     * Search::getDatas() automatically restricts to the caller's active
     * entities (and sub-entity rights), same as the regular GLPI UI. We
     * then hydrate each id via Ticket::getFromDB() for clean, consistent
     * output instead of parsing Search's internal row format.
     */
    private static function findTicketIds(array $criteria, int $start, int $limit): array
    {
        $data = Search::getDatas('Ticket', [
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
        $ticket = new Ticket();
        if (!$ticket->getFromDB($id)) {
            return null;
        }
        return [
            'id'       => (int) $ticket->fields['id'],
            'name'     => $ticket->fields['name'],
            'status'   => (int) $ticket->fields['status'],
            'priority' => (int) $ticket->fields['priority'],
            'date'     => $ticket->fields['date'],
        ];
    }

    /**
     * Shared "does this ticket exist and can the caller read it" guard used
     * by every read-only sub-resource tool (followups, tasks, solutions,
     * validations, documents, satisfaction). Centralised so each of those
     * doesn't repeat the getFromDB()+can(READ) pair.
     */
    private static function assertTicketReadable(int $ticketId): Ticket
    {
        $ticket = new Ticket();
        if (!$ticket->getFromDB($ticketId)) {
            throw new Exception("ticket $ticketId not found", 404);
        }
        if (!$ticket->can($ticketId, READ)) {
            throw new Exception("not allowed to read ticket $ticketId", 403);
        }
        return $ticket;
    }

    public static function listTickets(array $args): array
    {
        $criteria = [];
        if (isset($args['status'])) {
            $criteria[] = ['link' => 'AND', 'field' => 12, 'searchtype' => 'equals', 'value' => (int) $args['status']];
        }

        $ids = self::findTicketIds(
            $criteria,
            (int) ($args['start'] ?? 0),
            min((int) ($args['limit'] ?? 20), 100)
        );

        return array_values(array_filter(array_map([self::class, 'hydrateSummary'], $ids)));
    }

    /**
     * Deliberately over-fetches on purpose: whenever an AI client asks for
     * ticket detail, it invariably also wants to know who's involved (with
     * name + contact, not just an internal user id) and what already
     * happened (the timeline) — asking the model to chain
     * get_ticket → get_user (×N for every requester/observer/technician) →
     * get_ticket_timeline itself is unreliable (the model may fetch only
     * some of them, or in the wrong order), so that composition is done
     * here, server-side, in one round trip instead of relying on the model
     * to reassemble it correctly across separate tool calls.
     */
    public static function getTicket(array $args): array
    {
        $id     = (int) $args['id'];
        $ticket = self::assertTicketReadable($id);

        $actors = self::resolveActors($id);

        $result = [
            'ticket'                => $ticket->fields,
            'entity'                => self::ticketEntityBlock((int) $ticket->fields['entities_id']),
            'requesters'            => $actors['requesters'],
            'observers'             => $actors['observers'],
            'assigned_technicians'  => $actors['assigned_technicians'],
            'assigned_groups'       => $actors['assigned_groups'],
            'timeline'              => $ticket->getTimelineItems(),
        ];

        // A ticket still in "New" hasn't been triaged yet — this is exactly
        // the point in the process where Incident-vs-Service-Request should
        // be decided, so the same payload glpi_analyze_itil_ticket_type
        // returns is folded in here too, in one round trip, rather than
        // relying on the model to separately think of calling that tool.
        //
        // Both hints are central-only: their instructions point to
        // glpi_analyze_itil_ticket_* — central-only — and classifying a
        // ticket is a technician action.
        // Until 1.4.1 the type hint was also returned to Self-Service
        // (live test 2026-09-26, ticket #261), steering the model toward
        // tools that session cannot call.
        if (
            (int) $ticket->fields['status'] === Ticket::INCOMING
            && Session::getCurrentInterface() === 'central'
        ) {
            // 1.4.3: a lightweight flag, not the analysis payload. The
            // person asked to SEE the ticket; a ready-made decision_choices
            // pair in this response gets rendered as a choice card straight
            // away whatever the instruction says (1.4.2's "details first,
            // choice at the end" wording was live and still ignored on
            // #244, fresh chat outside the project), and claude.ai's choice
            // tool ends the turn, so the details were never printed. With
            // nothing to render here, the choice comes from
            // glpi_analyze_itil_ticket_type on the next turn, where the
            // choice is the answer.
            $result['type_check_hint'] = self::typeCheckHint($ticket);

            // 1.4.0: lightweight category hint, only when there is
            // something to fix — the full candidate list stays in
            // glpi_analyze_itil_ticket_category (too large to fold in).
            $categoryHint = self::categoryCheckHint($ticket);
            if ($categoryHint !== null) {
                $result['category_check_hint'] = $categoryHint;
            }
        }

        return $result;
    }

    /**
     * 1.4.4: the ticket's entity by name, not just entities_id — a bare id
     * gave the model nothing to show (person's report, ticket #244).
     *
     * No `entity` right needed: Ticket::can($id, READ) in
     * assertTicketReadable() already requires access to the ticket's
     * entity, and the ticket form shows the same completename to the same
     * user. Only id / name / completename — configuration stays behind
     * glpi_get_entity and its `entity` READ right (Rule 4).
     */
    private static function ticketEntityBlock(int $entityId): ?array
    {
        $entity = new Entity();
        if (!$entity->getFromDB($entityId)) {
            return null;
        }

        return [
            'id'           => $entityId,
            'name'         => $entity->fields['name'],
            'completename' => $entity->fields['completename'],
        ];
    }

    /**
     * Resolves a ticket's actors into name+contact detail, split by role.
     * Requester/observer/assigned-technician are rows in glpi_tickets_users
     * (Ticket_User, a CommonITILActor with type REQUESTER=1/ASSIGN=2/
     * OBSERVER=3 — verified against src/CommonITILActor.php); an assigned
     * *group* is a separate row in glpi_groups_tickets (Group_Ticket), not
     * a Ticket_User row, so it's fetched and returned separately rather
     * than folded into assigned_technicians.
     */
    private static function resolveActors(int $ticketId): array
    {
        $result = [
            'requesters'           => [],
            'observers'            => [],
            'assigned_technicians' => [],
            'assigned_groups'      => [],
        ];

        $ticketUsers = (new Ticket_User())->find(['tickets_id' => $ticketId]);
        foreach ($ticketUsers as $row) {
            $detail = self::resolveUserDetail((int) $row['users_id']);
            if ($detail === null) {
                continue;
            }
            switch ((int) $row['type']) {
                case CommonITILActor::REQUESTER:
                    $result['requesters'][] = $detail;
                    break;
                case CommonITILActor::OBSERVER:
                    $result['observers'][] = $detail;
                    break;
                case CommonITILActor::ASSIGN:
                    $result['assigned_technicians'][] = $detail;
                    break;
            }
        }

        $groupTickets = (new Group_Ticket())->find([
            'tickets_id' => $ticketId,
            'type'       => CommonITILActor::ASSIGN,
        ]);
        foreach ($groupTickets as $row) {
            $group = new Group();
            if ($group->getFromDB((int) $row['groups_id'])) {
                $result['assigned_groups'][] = [
                    'id'   => (int) $group->fields['id'],
                    'name' => $group->fields['name'],
                ];
            }
        }

        return $result;
    }

    /**
     * Contact detail for one actor. Email deliberately goes through
     * User::getDefaultEmail() (→ UserEmail::getDefaultForUser()) rather
     * than a plain 'email' field — GLPI stores addresses in their own
     * glpi_useremails table, a user row has no 'email' column at all
     * (verified against src/User.php).
     */
    private static function resolveUserDetail(int $userId): ?array
    {
        if (!$userId) {
            return null;
        }
        $user = new User();
        if (!$user->getFromDB($userId)) {
            return null;
        }
        return [
            'id'        => (int) $user->fields['id'],
            'name'      => $user->fields['name'],
            'realname'  => $user->fields['realname'] ?? null,
            'firstname' => $user->fields['firstname'] ?? null,
            'email'     => $user->getDefaultEmail() ?: null,
        ];
    }

    public static function searchTickets(array $args): array
    {
        $criteria = [];

        if (!empty($args['text'])) {
            // 'view' is GLPI's own special search-option id for free-text search
            // (the same one front/search.php uses for the top "globalsearch" box):
            // it expands the WHERE across every displayed column (name, content, ...)
            // instead of a single field. A plain field name like 'name' is NOT a
            // valid search option id — Search's SQLProvider::getWhereCriteria()
            // does `if (!isset($searchopt[$ID]['table'])) { return []; }`, so an
            // unrecognized id silently contributes nothing to the query, which is
            // why this filter previously had no effect at all.
            $criteria[] = ['link' => 'AND', 'field' => 'view', 'searchtype' => 'contains', 'value' => $args['text']];
        }
        if (isset($args['status'])) {
            $criteria[] = ['link' => 'AND', 'field' => 12, 'searchtype' => 'equals', 'value' => (int) $args['status']];
        }
        if (isset($args['requester_id'])) {
            $criteria[] = ['link' => 'AND', 'field' => 4, 'searchtype' => 'equals', 'value' => (int) $args['requester_id']];
        }
        if (isset($args['technician_id'])) {
            $criteria[] = ['link' => 'AND', 'field' => 5, 'searchtype' => 'equals', 'value' => (int) $args['technician_id']];
        }
        if (!empty($args['date_from']) && !empty($args['date_to'])) {
            $criteria[] = [
                'link' => 'AND', 'field' => 15, 'searchtype' => 'between',
                'value' => [$args['date_from'], $args['date_to']],
            ];
        }

        // Verified against glpi-project/glpi src/Search.php: getDatas()
        // param key is "deleted", not "is_deleted" (my earlier guess).
        $ids = self::findTicketIds($criteria, 0, min((int) ($args['limit'] ?? 20), 100));

        return array_values(array_filter(array_map([self::class, 'hydrateSummary'], $ids)));
    }

    /**
     * Tickets whose SLA "time to resolve" has been exceeded and that aren't
     * already solved/closed. Uses search option id 82 ("Time to resolve
     * exceeded", a ready-made boolean computed by GLPI itself from
     * time_to_resolve/solvedate — see CommonITILObject::rawSearchOptions()),
     * rather than hand-rolling a `time_to_resolve < NOW()` comparison, so we
     * inherit GLPI's own definition of "late" (incl. its SLA/OLA edge cases)
     * instead of re-deriving it and risking drift.
     */
    public static function listOverdueTickets(array $args): array
    {
        $criteria = [
            ['link' => 'AND', 'field' => 82, 'searchtype' => 'equals', 'value' => 1],
            ['link' => 'AND', 'field' => 12, 'searchtype' => 'notequals', 'value' => 5], // Solved
            ['link' => 'AND', 'field' => 12, 'searchtype' => 'notequals', 'value' => 6], // Closed
        ];

        $ids = self::findTicketIds($criteria, 0, min((int) ($args['limit'] ?? 20), 100));

        return array_values(array_filter(array_map([self::class, 'hydrateSummary'], $ids)));
    }

    /**
     * Rows of one timeline item class, exactly as the GLPI UI would show
     * them to this session. Security review finding 1 (fixed in
     * 1.3.0): getFollowups()/getTasks()/
     * communicationExcerpt() used to find() the child tables directly and
     * never filtered is_private, while getTicket() — correctly — went
     * through getTimelineItems(). All four now share this one path.
     *
     * Predicate enforced (it is core's, not re-implemented here):
     * CommonITILObject::getTimelineItems() with its default
     * check_view_rights = true restricts followups to `is_private = 0 OR
     * users_id = me` (the users_id branch only on the central interface)
     * unless the session holds followup SEEPRIVATE, restricts tasks the
     * same way on SEEPRIVATE / own / assigned-tech / SEEPRIVATEGROUPS, and
     * then re-checks canViewItem() per row — verified against
     * src/CommonITILObject.php on 11.0/bugfixes.
     *
     * Output shape: each element is the timeline's 'item' array, i.e. the
     * same DB row find() used to return, plus core's can_edit/can_promote
     * flags. Order is re-imposed here (date_creation DESC, id DESC) rather
     * than inherited from the user's timeline-order preference, so these
     * tools keep the order they had before this fix.
     *
     * @param class-string $itemClass ITILFollowup::class, TicketTask::class
     *                                 or ITILSolution::class
     */
    private static function visibleTimelineRows(Ticket $ticket, string $itemClass): array
    {
        $rows = [];
        $timeline = $ticket->getTimelineItems([
            'with_documents'   => false,
            'with_logs'        => false,
            'with_validations' => false,
        ]);
        foreach ($timeline as $entry) {
            if (($entry['type'] ?? null) === $itemClass && isset($entry['item'])) {
                $rows[] = $entry['item'];
            }
        }
        usort(
            $rows,
            static fn(array $a, array $b): int => [(string) ($b['date_creation'] ?? ''), (int) ($b['id'] ?? 0)]
                <=> [(string) ($a['date_creation'] ?? ''), (int) ($a['id'] ?? 0)]
        );
        return $rows;
    }

    public static function getFollowups(array $args): array
    {
        $ticketId = (int) $args['ticket_id'];
        $ticket   = self::assertTicketReadable($ticketId);

        return self::visibleTimelineRows($ticket, ITILFollowup::class);
    }

    public static function getTasks(array $args): array
    {
        $ticketId = (int) $args['ticket_id'];
        $ticket   = self::assertTicketReadable($ticketId);

        // Finding 1: private tasks — see visibleTimelineRows().
        return self::visibleTimelineRows($ticket, TicketTask::class);
    }

    public static function getSolutions(array $args): array
    {
        $ticketId = (int) $args['ticket_id'];
        self::assertTicketReadable($ticketId);

        $rows = (new ITILSolution())->find(
            ['itemtype' => 'Ticket', 'items_id' => $ticketId],
            ['date_creation DESC']
        );
        return array_values($rows);
    }

    public static function getValidations(array $args): array
    {
        $ticketId = (int) $args['ticket_id'];
        self::assertTicketReadable($ticketId);

        $rows = (new TicketValidation())->find(
            ['tickets_id' => $ticketId],
            ['submission_date DESC']
        );
        return array_values($rows);
    }

    public static function getSatisfaction(array $args): array
    {
        $ticketId = (int) $args['ticket_id'];
        self::assertTicketReadable($ticketId);

        $rows = (new TicketSatisfaction())->find(['tickets_id' => $ticketId]);
        return $rows ? array_values($rows)[0] : [];
    }

    public static function getDocuments(array $args): array
    {
        $ticketId = (int) $args['ticket_id'];
        self::assertTicketReadable($ticketId);

        $links = (new Document_Item())->find(['itemtype' => 'Ticket', 'items_id' => $ticketId]);

        $out = [];
        foreach ($links as $link) {
            $doc = new Document();
            if ($doc->getFromDB((int) $link['documents_id'])) {
                $out[] = [
                    'id'       => (int) $doc->fields['id'],
                    'name'     => $doc->fields['name'],
                    'filename' => $doc->fields['filename'],
                    'mime'     => $doc->fields['mime'] ?? null,
                ];
            }
        }
        return $out;
    }

    public static function getTimeline(array $args): array
    {
        $ticketId = (int) $args['id'];
        $ticket   = self::assertTicketReadable($ticketId);

        return $ticket->getTimelineItems();
    }

    /**
     * Deliberately does NOT classify the ticket itself — GLPI/PHP has no
     * NLP capability, and a hardcoded keyword classifier would be brittle
     * and quickly stale. Instead this composes the same "over-fetch"
     * pattern as getTicket(): subject, description and the full
     * communication history in one call, anchored to the plugin's own
     * canonical ITIL definitions (itilTypeCheckPayload()) so the judgment
     * is made by the calling model against a fixed reference, not from
     * whatever it happens to remember about ITIL. The model is expected to
     * state its assessment in conversation; the change itself is made by
     * the user in GLPI (this plugin has no write tools).
     */
    public static function analyzeItilTicketType(array $args): array
    {
        $id     = (int) $args['id'];
        $ticket = self::assertTicketReadable($id);

        return array_merge(
            [
                'ticket_id'     => $id,
                'subject'       => $ticket->fields['name'],
                'description'   => $ticket->fields['content'],
                'communication' => self::communicationExcerpt($ticket),
            ],
            self::itilTypeCheckPayload($ticket)
        );
    }

    /**
     * Shared by analyzeItilTicketType() (standalone, any status) and
     * getTicket() (auto-folded in only while status is New, §ticket
     * lifecycle — see the comment there). Kept as one copy so the ITIL
     * definitions never drift between the two call sites.
     *
     * 1.2.2: added `decision_choices`, a ready-made structured pair the
     * calling model can render directly instead of composing its own
     * two-way-choice wording from prose — structured fields tend to be
     * followed more reliably than natural-language instructions alone.
     * `instruction` was also reworded to front-load and repeat the
     * "offer a choice, don't just describe it" directive, since models
     * weight the first and last sentence of a field more heavily than
     * ones buried in the middle. Neither change forces client-side UI —
     * MCP has no way to guarantee a specific rendering (see
     * PROJECT-REFERENCE.md §3c's 1.2.2 note on why a true client-request
     * elicitation was not pursued this release) — this only makes it
     * easier and more likely for a model to comply, for every AI client,
     * not just an account with a saved personal preference.
     *
     * 1.4.3: used by glpi_analyze_itil_ticket_type only. glpi_get_ticket
     * gets typeCheckHint() instead (see there).
     */
    private static function itilTypeCheckPayload(Ticket $ticket): array
    {
        $currentType = (int) $ticket->fields['type'];
        $otherType   = $currentType === Ticket::INCIDENT_TYPE
            ? Ticket::DEMAND_TYPE
            : Ticket::INCIDENT_TYPE;
        $typeLabel   = static fn(int $type): string => self::typeLabel($type);

        // 1.4.0: category flags. A category is not necessarily valid for
        // both types (ITILCategory is_incident / is_request), and GLPI does
        // NOT enforce that on save — Ticket::isCategoryValid() is only
        // called from Ticket::showForm(), which silently blanks an invalid
        // category the next time the form is opened (verified against
        // src/Ticket.php on 11.0/bugfixes). Changing only the type can
        // therefore leave an inconsistent pair behind; category_conflict
        // tells the model to change both together.
        $category         = self::currentCategoryBlock($ticket);
        $categoryConflict = $category !== null && !$category['valid_for_other_type'];

        return [
            'current_type'       => $currentType,
            'current_type_label' => $typeLabel($currentType),
            'current_category'   => $category,
            'itil_reference' => [
                'incident' => 'An unplanned interruption to an IT service or a reduction '
                    . 'in the quality of an IT service. Failure of a configuration item '
                    . 'that has not yet affected service is also an incident (e.g. failure '
                    . 'of one disk from a mirror set).',
                'service_request' => 'A formal request from a user for something to be '
                    . 'provided — e.g. a request for information or advice, a password '
                    . 'reset, or a new workstation setup.',
            ],
            'other_type'         => $otherType,
            'other_type_label'   => $typeLabel($otherType),
            'category_conflict'  => $categoryConflict,
            'instruction' => 'Compare subject/description/communication against the two definitions in '
                . 'itil_reference to judge whether the current type is correct. State your assessment '
                . 'in plain text. If it looks correct, say so briefly and stop there. If it looks wrong, '
                . 'say which type fits better and why. This server is read-only: tell the user the type '
                . 'has to be changed in GLPI itself (on the ticket form). If category_conflict is true, '
                . 'the current category is not allowed for the other type, so the category has to change '
                . 'together with the type — call glpi_analyze_itil_ticket_category with for_type set to '
                . 'the other type to review candidate categories.',
        ];
    }

    /**
     * 1.4.3: the type hint glpi_get_ticket folds in for a New ticket — a
     * flag and a pointer, deliberately without itil_reference or
     * decision_choices. Same shape as categoryCheckHint(): the full
     * analysis and the choice live in the analyze tool. Rule (see
     * PROJECT-REFERENCE.md §3c): a hint inside a composite read must not
     * carry a ready-made choice, because a model renders it at once and a
     * turn-ending choice tool then swallows the answer the person asked for.
     */
    private static function typeCheckHint(Ticket $ticket): array
    {
        $currentType = (int) $ticket->fields['type'];
        $otherType   = $currentType === Ticket::INCIDENT_TYPE
            ? Ticket::DEMAND_TYPE
            : Ticket::INCIDENT_TYPE;

        return [
            'current_type'       => $currentType,
            'current_type_label' => self::typeLabel($currentType),
            'review_suggested'   => true,
            'suggested_tool'     => 'glpi_analyze_itil_ticket_type',
            'instruction'        => 'This ticket is still New, so its ITIL type has not been reviewed. '
                . 'Answer the user\'s request first and in full (present the ticket details). If the '
                . 'subject and description point to ' . self::typeLabel($otherType) . ' rather than '
                . self::typeLabel($currentType) . ' (Incident = unplanned interruption, degradation or '
                . 'failure; Service request = the user asks for something to be provided), close with ONE '
                . 'sentence saying so and offering to review the type. Do not change anything. '
                . 'If the user wants the review, call glpi_analyze_itil_ticket_type for the details.',
        ];
    }

    /**
     * Chronological followups + solutions content, for analyzeItilTicketType()
     * — the same rows getFollowups()/getSolutions() expose individually,
     * merged and sorted here since the analysis needs them as one ordered
     * conversation, not two separate lists.
     */
    private static function communicationExcerpt(Ticket $ticket): array
    {
        $entries = [];

        // Finding 1: through the same rights-filtered timeline as
        // getFollowups(), so a caller without SEEPRIVATE no longer gets
        // private followup content folded into the analysis payload.
        foreach (self::visibleTimelineRows($ticket, ITILFollowup::class) as $row) {
            $entries[] = [
                'type'    => 'followup',
                'date'    => $row['date_creation'],
                'content' => $row['content'],
            ];
        }
        foreach (self::visibleTimelineRows($ticket, ITILSolution::class) as $row) {
            $entries[] = [
                'type'    => 'solution',
                'date'    => $row['date_creation'],
                'content' => $row['content'],
            ];
        }

        usort($entries, static fn(array $a, array $b) => strcmp($a['date'], $b['date']));

        return $entries;
    }

    // ---- ITIL categories (1.4.0) -----------------------------------------

    private const CATEGORY_DEFAULT_LIMIT   = 50;
    private const CATEGORY_MAX_LIMIT       = 200;
    private const CATEGORY_COMMENT_MAX_LEN = 500;

    private static function typeLabel(int $type): string
    {
        return match ($type) {
            Ticket::INCIDENT_TYPE => 'Incident',
            Ticket::DEMAND_TYPE   => 'Service request',
            default               => 'unknown',
        };
    }

    /**
     * Validates an optional ticket_type / for_type argument: only
     * Incident (1) and Service request (2) exist for tickets.
     */
    private static function ticketTypeArg(array $args, string $key): ?int
    {
        if (!array_key_exists($key, $args) || $args[$key] === null) {
            return null;
        }
        $type = (int) $args[$key];
        if (!in_array($type, [Ticket::INCIDENT_TYPE, Ticket::DEMAND_TYPE], true)) {
            throw new Exception("$key must be 1 (Incident) or 2 (Service request)", 400);
        }
        return $type;
    }

    /** ITILCategory type flag matching a ticket type. */
    private static function typeFlagCriteria(int $type): array
    {
        return $type === Ticket::INCIDENT_TYPE ? ['is_incident' => 1] : ['is_request' => 1];
    }

    /**
     * The ticket's own category, reduced to what the type/category checks
     * need. Shared by itilTypeCheckPayload() (and so by getTicket()'s
     * type_check_hint), categoryCheckHint() and
     * analyzeItilTicketCategory(), so the flags cannot drift between them.
     * Validity uses core's Ticket::isCategoryValid() (type flag + entity
     * visibility, src/Ticket.php) rather than a re-implementation.
     *
     * No itilcategory right is needed: the name and flags of the category
     * of a ticket the caller can already read are shown on that ticket's
     * own form. Configuration (templates, responsibles) stays behind
     * glpi_get_itil_category's right check.
     */
    private static function currentCategoryBlock(Ticket $ticket): ?array
    {
        $categoryId = (int) $ticket->fields['itilcategories_id'];
        if ($categoryId <= 0) {
            return null;
        }
        $category = new ITILCategory();
        if (!$category->getFromDB($categoryId)) {
            return null;
        }

        $currentType = (int) $ticket->fields['type'];
        $otherType   = $currentType === Ticket::INCIDENT_TYPE ? Ticket::DEMAND_TYPE : Ticket::INCIDENT_TYPE;
        $validFor    = static fn(int $type): bool => Ticket::isCategoryValid([
            'itilcategories_id' => $categoryId,
            'type'              => $type,
            'entities_id'       => (int) $ticket->fields['entities_id'],
        ]);

        return [
            'id'                     => $categoryId,
            'completename'           => $category->fields['completename'],
            'is_incident'            => (bool) $category->fields['is_incident'],
            'is_request'             => (bool) $category->fields['is_request'],
            'valid_for_current_type' => $validFor($currentType),
            'valid_for_other_type'   => $validFor($otherType),
        ];
    }

    /**
     * getTicket()'s category hint for New tickets. Returns null (no hint)
     * when the category is set and valid, so the payload only grows when
     * there is something to act on.
     */
    private static function categoryCheckHint(Ticket $ticket): ?array
    {
        $category = self::currentCategoryBlock($ticket);
        if ($category !== null && $category['valid_for_current_type']) {
            return null;
        }

        $reason = $category === null ? 'missing' : 'invalid_for_type';
        return [
            'reason'           => $reason,
            'current_category' => $category,
            'suggested_tool'   => 'glpi_analyze_itil_ticket_category',
            // 1.4.3: passive, like typeCheckHint() — mention, don't act.
            // 1.4.0-1.4.2 said "call glpi_analyze_itil_ticket_category and
            // offer the user a choice", which in glpi_get_ticket can also
            // end the turn on a choice card before the details are shown.
            'instruction'      => ($reason === 'missing'
                    ? 'This ticket has no ITIL category. GLPI requires a category before a ticket can be '
                        . 'solved or closed. '
                    : 'The current category is not allowed for this ticket\'s type or entity. ')
                . 'Answer the user\'s request first and in full, then mention this in ONE sentence and '
                . 'offer to review candidate categories. If the user wants it, call '
                . 'glpi_analyze_itil_ticket_category; if a type review is also pending, settle the type '
                . 'first, since the allowed categories depend on it.',
        ];
    }

    /**
     * Resolves an ITIL category the person names to its id — the ITIL
     * counterpart of KnowbaseTools::searchKnowbaseCategories(), same
     * pattern and same entity rule (finding 15 / Rule 4): CommonDBTM::find()
     * restricted by DbUtils::getEntitiesRestrictCriteria() with recursion,
     * i.e. categories of the active entities plus recursive categories of
     * their ancestors (src/DbUtils.php on 11.0/bugfixes).
     *
     * Self-Service: additionally is_helpdeskvisible = 1, the same condition
     * core puts on the category search option for the helpdesk interface
     * (CommonITILObject::rawSearchOptions(), src/CommonITILObject.php).
     *
     * No itilcategory right: this is what the category dropdown on the
     * ticket form shows the same user.
     */
    public static function searchItilCategories(array $args): array
    {
        $condition = [
            (new DbUtils())->getEntitiesRestrictCriteria(ITILCategory::getTable(), '', '', true),
        ];
        if (Session::getCurrentInterface() === 'helpdesk') {
            $condition['is_helpdeskvisible'] = 1;
        }
        $type = self::ticketTypeArg($args, 'ticket_type');
        if ($type !== null) {
            $condition += self::typeFlagCriteria($type);
        }
        if (!empty($args['text'])) {
            $like = '%' . $args['text'] . '%';
            $condition[] = [
                'OR' => [
                    'name'         => ['LIKE', $like],
                    'completename' => ['LIKE', $like],
                    'code'         => ['LIKE', $like],
                ],
            ];
        }

        $limit = max(1, min((int) ($args['limit'] ?? 20), 100));
        $rows  = (new ITILCategory())->find($condition, ['completename ASC'], $limit + 1);

        $out = [];
        foreach ($rows as $row) {
            if (count($out) === $limit) {
                break;
            }
            $out[] = [
                'id'           => (int) $row['id'],
                'name'         => $row['name'],
                'completename' => $row['completename'],
                'code'         => $row['code'] ?: null,
                'entities_id'  => (int) $row['entities_id'],
                'is_recursive' => (bool) $row['is_recursive'],
                'is_incident'  => (bool) $row['is_incident'],
                'is_request'   => (bool) $row['is_request'],
            ];
        }
        return ['categories' => $out, 'truncated' => count($rows) > $limit];
    }

    /**
     * Full detail of one ITIL category — the equivalent of Setup >
     * Dropdowns > ITIL categories > (item), so it requires
     * ITILCategory::can($id, READ): `itilcategory` READ right
     * (ITILCategory::$rightname) plus the dropdown's entity visibility
     * (CommonDBTM::canViewItem()). Decided in 1.4.0 design: whole detail
     * behind the right, no partial view.
     *
     * Referenced objects (Rule 5): the responsible user/group, KB category
     * and templates are configuration values of this category, shown on
     * the same form the right grants; only id/name are returned for them.
     */
    public static function getItilCategory(array $args): array
    {
        $id       = (int) $args['id'];
        $category = new ITILCategory();
        if (!$category->getFromDB($id)) {
            throw new Exception("ITIL category $id not found", 404);
        }
        if (!$category->can($id, READ)) {
            throw new Exception("not allowed to read ITIL category $id (requires the itilcategory read right and visibility in your entities)", 403);
        }
        $f = $category->fields;

        $parent = null;
        if ((int) $f['itilcategories_id'] > 0) {
            $p = new ITILCategory();
            if ($p->getFromDB((int) $f['itilcategories_id'])) {
                $parent = ['id' => (int) $p->fields['id'], 'completename' => $p->fields['completename']];
            }
        }

        $children = [];
        $rows = (new ITILCategory())->find([
            'itilcategories_id' => $id,
            (new DbUtils())->getEntitiesRestrictCriteria(ITILCategory::getTable(), '', '', true),
        ], ['name ASC']);
        foreach ($rows as $row) {
            $children[] = ['id' => (int) $row['id'], 'completename' => $row['completename']];
        }

        $group = null;
        if ((int) $f['groups_id'] > 0) {
            $g = new Group();
            if ($g->getFromDB((int) $f['groups_id'])) {
                $group = ['id' => (int) $g->fields['id'], 'name' => $g->fields['name']];
            }
        }

        $kbCategory = null;
        if ((int) $f['knowbaseitemcategories_id'] > 0) {
            $k = new KnowbaseItemCategory();
            if ($k->getFromDB((int) $f['knowbaseitemcategories_id'])) {
                $kbCategory = ['id' => (int) $k->fields['id'], 'completename' => $k->fields['completename']];
            }
        }

        $template = static function (string $class, int $templateId): ?array {
            if ($templateId <= 0) {
                return null;
            }
            $t = new $class();
            return $t->getFromDB($templateId)
                ? ['id' => $templateId, 'name' => $t->fields['name']]
                : null;
        };

        return [
            'id'                  => $id,
            'name'                => $f['name'],
            'completename'        => $f['completename'],
            'code'                => $f['code'] ?: null,
            'comment'             => $f['comment'],
            'entities_id'         => (int) $f['entities_id'],
            'is_recursive'        => (bool) $f['is_recursive'],
            'level'               => (int) $f['level'],
            'parent'              => $parent,
            'children'            => $children,
            'is_incident'         => (bool) $f['is_incident'],
            'is_request'          => (bool) $f['is_request'],
            'is_problem'          => (bool) $f['is_problem'],
            'is_change'           => (bool) $f['is_change'],
            'is_helpdeskvisible'  => (bool) $f['is_helpdeskvisible'],
            'responsible_user'    => self::resolveUserDetail((int) $f['users_id']),
            'responsible_group'   => $group,
            'knowbase_category'   => $kbCategory,
            'templates'           => [
                'ticket_incident' => $template(TicketTemplate::class, (int) $f['tickettemplates_id_incident']),
                'ticket_request'  => $template(TicketTemplate::class, (int) $f['tickettemplates_id_demand']),
                'problem'         => $template(ProblemTemplate::class, (int) $f['problemtemplates_id']),
                'change'          => $template(ChangeTemplate::class, (int) $f['changetemplates_id']),
            ],
        ];
    }

    /**
     * Everything needed to propose an ITIL category for a ticket — the
     * category counterpart of analyzeItilTicketType(), same output shape
     * (itil_reference / decision_choices / instruction). Decides and
     * changes nothing itself.
     *
     * Rights: assertTicketReadable() on the ticket; communication goes
     * through communicationExcerpt() and so through the rights-filtered
     * timeline (finding 1). Candidates are the categories valid for the
     * TICKET's entity, not the session's active entities — the same rule
     * Ticket::isCategoryValid() applies (own entity, or a recursive
     * category of an ancestor): getEntitiesRestrictCriteria() with the
     * ticket's entities_id as value and recursion on (src/DbUtils.php).
     * Only name/code/comment/flags are returned — what the ticket form's
     * category dropdown shows — so no itilcategory right is required.
     *
     * for_type lets the model evaluate candidates for a type the ticket
     * is about to be changed to (see itilTypeCheckPayload()'s
     * category_conflict).
     */
    public static function analyzeItilTicketCategory(array $args): array
    {
        $id     = (int) $args['id'];
        $ticket = self::assertTicketReadable($id);

        $currentType = (int) $ticket->fields['type'];
        $evalType    = self::ticketTypeArg($args, 'for_type') ?? $currentType;
        $entityId    = (int) $ticket->fields['entities_id'];
        $limit       = max(1, min((int) ($args['candidate_limit'] ?? self::CATEGORY_DEFAULT_LIMIT), self::CATEGORY_MAX_LIMIT));

        $condition = [
            (new DbUtils())->getEntitiesRestrictCriteria(ITILCategory::getTable(), '', $entityId, true),
        ] + self::typeFlagCriteria($evalType);
        // CommonDBTM::find() returns rows keyed by id.
        $rows = (new ITILCategory())->find($condition, ['completename ASC'], $limit + 1);
        $truncated = count($rows) > $limit;
        $rows      = array_slice($rows, 0, $limit, true);

        // is_leaf: no child category that is itself a valid candidate for
        // this ticket. Leaves are preferred by the instruction (most
        // specific classification), so a parent whose only children are
        // invisible in the ticket's entity or not allowed for the type
        // must count as a leaf. 1.4.1 fix: 1.4.0 counted every child,
        // which (live test 2026-09-26, ticket #260 in OMNICOM > Košice)
        // marked "Sietove sluzby" non-leaf because of two non-recursive
        // root sub-categories that ticket could never use — steering the
        // model away from the best usable category, and revealing that
        // hidden sub-categories exist.
        $parentsWithChildren = [];
        if ($rows !== []) {
            $childRows = (new ITILCategory())->find(
                $condition + ['itilcategories_id' => array_keys($rows)]
            );
            foreach ($childRows as $child) {
                $parentsWithChildren[(int) $child['itilcategories_id']] = true;
            }
        }

        $candidates = [];
        foreach ($rows as $row) {
            $comment = (string) ($row['comment'] ?? '');
            if (mb_strlen($comment) > self::CATEGORY_COMMENT_MAX_LEN) {
                $comment = mb_substr($comment, 0, self::CATEGORY_COMMENT_MAX_LEN) . '…';
            }
            $candidates[] = [
                'id'           => (int) $row['id'],
                'completename' => $row['completename'],
                'code'         => $row['code'] ?: null,
                'comment'      => $comment !== '' ? $comment : null,
                'level'        => (int) $row['level'],
                'is_leaf'      => !isset($parentsWithChildren[(int) $row['id']]),
            ];
        }

        $current   = self::currentCategoryBlock($ticket);
        $keepLabel = $current === null ? 'Leave without category' : 'Keep ' . $current['completename'];
        if ($current !== null) {
            $validForEval = $evalType === $currentType
                ? $current['valid_for_current_type']
                : $current['valid_for_other_type'];
            if (!$validForEval) {
                $keepLabel .= ' (not allowed for ' . self::typeLabel($evalType) . ')';
            }
        }

        return [
            'ticket_id'            => $id,
            'subject'              => $ticket->fields['name'],
            'description'          => $ticket->fields['content'],
            'communication'        => self::communicationExcerpt($ticket),
            'current_type'         => $currentType,
            'current_type_label'   => self::typeLabel($currentType),
            'evaluated_type'       => $evalType,
            'evaluated_type_label' => self::typeLabel($evalType),
            'current_category'     => $current,
            'candidates'           => $candidates,
            'candidates_truncated' => $truncated,
            'itil_reference'       => [
                'categorization' => 'ITIL best practices recommend categorizing by the affected service or '
                    . 'component rather than by the symptom the user describes, and choosing the most '
                    . 'specific category that fits. Categorization drives routing to the right support '
                    . 'group, reporting and trend analysis, and matching against known errors.',
            ],
            'keep_option'          => [
                'category_id' => $current['id'] ?? 0,
                'label'       => $keepLabel,
            ],
            'instruction' => 'Compare subject/description/communication with each candidate\'s completename, '
                . 'code and comment, following itil_reference; prefer candidates with is_leaf=true. '
                . 'Propose 1 to 3 candidates (id and completename) as a short list in plain text, plus the '
                . 'keep option. If the current category already fits, say so briefly and stop. If the ticket '
                . 'type itself looks wrong, run glpi_analyze_itil_ticket_type first, because the type decides '
                . 'which categories are valid. If candidates_truncated is true, narrow down with '
                . 'glpi_search_itil_categories (text from the ticket, ticket_type = evaluated_type) before '
                . 'proposing. This server is read-only: tell the user the category (and the type, when '
                . 'evaluated_type differs from current_type) has to be changed in GLPI itself.',
        ];
    }
}
