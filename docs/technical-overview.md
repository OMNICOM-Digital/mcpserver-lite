# MCP Server Lite: technical overview

A native, **read-only** MCP (Model Context Protocol) endpoint inside GLPI 11
(`front/mcp.php`). AI clients such as Claude can look up tickets, projects,
knowledge base articles, forms, users, groups and entities, without an
external Node/Python MCP server and without a shared API token. Every call
runs under the real GLPI user who signed in through OAuth, so normal
profile and entity rights apply.

**Read-only by construction, not by a switch.** The plugin contains no code
that creates, updates or deletes GLPI data: no write tools, no database
tables, no GLPI right, no admin page. Nothing an AI client does through it
can change your data, and there is nothing to misconfigure.

Free and open source (GPLv3+). Issues and pull requests on GitHub; no
support SLA is included.

## Tools (33, all read-only)

| Module | Tools | Also in Self-Service |
|---|---|---|
| Tickets (15) | `glpi_list_tickets`, `glpi_get_ticket`, `glpi_search_tickets`, `glpi_list_overdue_tickets`, `glpi_get_ticket_timeline`, `glpi_analyze_itil_ticket_type`, `glpi_analyze_itil_ticket_category`, `glpi_search_itil_categories`, `glpi_get_itil_category`, `glpi_get_ticket_followups`, `glpi_get_ticket_tasks`, `glpi_get_ticket_solutions`, `glpi_get_ticket_validations`, `glpi_get_ticket_documents`, `glpi_get_ticket_satisfaction` | `glpi_list_tickets`, `glpi_get_ticket`, `glpi_search_tickets`, `glpi_get_ticket_timeline`, `glpi_search_itil_categories`, `glpi_get_ticket_followups`, `glpi_get_ticket_solutions`, `glpi_get_ticket_validations`, `glpi_get_ticket_documents`, `glpi_get_ticket_satisfaction` |
| Administration (5) | `glpi_list_users`, `glpi_search_groups`, `glpi_list_entities`, `glpi_get_entity`, `glpi_get_session_context` | `glpi_list_entities`, `glpi_get_session_context` |
| Knowledge base (4) | `glpi_list_knowbase`, `glpi_get_knowbase_item`, `glpi_search_knowbase`, `glpi_search_knowbase_categories` | `glpi_list_knowbase`, `glpi_get_knowbase_item`, `glpi_search_knowbase` |
| Service catalog (2) | `glpi_list_forms`, `glpi_get_form_schema` | `glpi_list_forms`, `glpi_get_form_schema` |
| Projects (7) | `glpi_list_projects`, `glpi_search_projects`, `glpi_get_project`, `glpi_get_project_tasks`, `glpi_get_project_team`, `glpi_get_project_costs`, `glpi_search_project_dropdowns` | none |

Self-Service profiles see only the tools marked in the last column;
Standard (central) profiles see all of them. This is a convenience filter:
GLPI's own `can()` checks inside every handler remain the real permission
enforcement.

The two `glpi_analyze_itil_ticket_*` tools gather everything needed to
review a ticket's type or category (communication history, ITIL reference,
candidate categories) and let the AI give an assessment. They never change
anything: if a type or category should change, the person changes it in GLPI.

## Architecture

```
AI client (Claude, Copilot Studio, ...)
        │  JSON-RPC 2.0 over HTTPS POST (Authorization: Bearer <token>)
        ▼
front/mcp.php                  ← transport (initialize / tools/list / tools/call)
        │
src/OAuthTokenAuth.php         ← bearer = a real GLPI OAuth2 access token → Session::init()
        │
src/ToolRegistry.php           ← tool catalog (name, JSON schema, handler, interfaces)
        │
src/Tools/TicketTools.php      ← tickets, ITIL categories, analysis helpers
src/Tools/ProjectTools.php     ← projects, tasks, team, costs
src/Tools/KnowbaseTools.php    ← knowledge base
src/Tools/FormTools.php        ← native Forms (service catalog), read-only
src/Tools/UserTools.php        ← users, groups
src/Tools/EntityTools.php      ← entities, session context
```

## Permission enforcement in `front/*` files

- **`front/mcp.php`** is an API endpoint, not a page reached from a browser
  session. It does not call `Session::checkRight()` because no GLPI session
  exists until the request has authenticated itself; permission is enforced
  in three layers before any GLPI data is touched:
  1. `OAuthTokenAuth::authenticate()` validates the bearer as a real GLPI
     OAuth2 access token and establishes a session for the resolved user
     (mirroring core's `Glpi\Api\HL\Router::startTemporarySession()`). No
     valid token → `401 authentication_failed`, no tool code runs.
  2. `ToolRegistry::isAllowedForInterface()`: per-tool Standard vs.
     Self-Service check, repeated at `tools/call` (`403
     tool_not_available_for_interface`). A convenience filter, not the
     security boundary.
  3. Every `Tools\*` handler uses GLPI's own rights checks (`can()`,
     visibility criteria, entity restrictions) on the objects it reads,
     the real, object-level enforcement.
- **`front/oauth-protected-resource.php`** has no permission check on
  purpose: it is a public RFC 9728 discovery document containing only
  static server metadata.

## Installation

1. Copy the `mcpserverlite` folder to `<glpi>/plugins/`, then **Setup >
   Plugins**: install and activate. (The plugin creates nothing in the
   database.)
2. **Setup > General > OAuth clients** → new client:
   - redirect URI exactly `https://claude.ai/api/mcp/auth_callback`
     (for other clients, whatever redirect URI they require);
   - grant types: enable all of them, including **client_credentials**;
     the consent screen can succeed and the token exchange still fail if a
     required grant type is missing;
   - scope at least `api`;
   - GLPI OAuth clients are always confidential: fill in the client secret
     even if the AI client's UI calls it optional.
3. In Claude: add a custom connector.
   - URL: `https://<your-glpi>/plugins/mcpserverlite/front/mcp.php`
   - Authentication: always required → use your own OAuth client
     (client ID and secret from step 2)
   - Transport: **Streamable HTTP** (if auto-detect picks "SSE (legacy)",
     switch it manually)
   - If asked for endpoints: `<glpi>/api.php/v2/authorize` and
     `<glpi>/api.php/v2/token`.
4. Each person signs in to GLPI in their own browser on first connection
   and gets their own token and their own rights.

The OAuth discovery document (`/.well-known/oauth-authorization-server`) is
served by the plugin itself while it is active. If the full MCP Server
plugin is active on the same GLPI, both answer that path with the same
document; whichever loads first responds, so there is no conflict. Both
plugins can run side by side: different plugin key, different endpoint URL
(register a separate OAuth client for each).

After an update that adds or removes tools, **disconnect and reconnect the
connector** in the AI client. The server cannot push a changed tool list
to an already-connected client (no SSE).

## Limitations

- GLPI 11 only (declared range 11.0.11 to 11.9.99); GLPI 10 has not been tested.
- Stateless request/response transport only, no SSE streaming.
- `glpi_list_users`, `glpi_search_knowbase_categories` and
  `glpi_search_project_dropdowns` do not apply entity restriction (stated in
  each file's docblock).
- No automated tests and no translations (`locales/`) yet.

## Need to write, not just read?

MCP Server Lite is the free, read-only edition. The full **MCP Server**
plugin from OMNICOM s.r.o. adds write tools: creating and updating
tickets, followups, tasks, solutions, assignments and validations,
documents, knowledge base articles, users and groups, projects and form
submissions, with write confirmation, a language check, per-tool on/off
switches with an audit history, and a subscription for updates and support.
More at https://omnicom.digital.

## References

- https://github.com/pluginsGLPI/example: plugin structure and conventions
- https://github.com/glpi-project/glpi: GLPI core. Verification for
  anything version-sensitive defaults to the `11.0/bugfixes` branch, not
  `main`, which is already moving towards GLPI 12.

## License

GPLv3+ (`GPL-3.0-or-later`), matching GLPI core. The plugin calls GLPI's own
GPLv3 classes directly, so matching the core license removes any ambiguity
about the combined work. Full text in `LICENSE`.
