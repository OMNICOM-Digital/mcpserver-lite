<p align="center">
  <img src="https://raw.githubusercontent.com/OMNICOM-Digital/mcpserver/main/oc-icon.png" width="120" alt="OMNICOM s.r.o." />
</p>

<h1 align="center">MCP Server Lite for GLPI</h1>

<p align="center">
  <a href="https://github.com/OMNICOM-Digital/mcpserver-lite/releases/latest"><img src="https://img.shields.io/badge/release-1.1.0-13A688" alt="Release 1.1.0" /></a>
  <img src="https://img.shields.io/badge/license-GPLv3-13A688" alt="GPLv3 license" />
  <img src="https://img.shields.io/badge/GLPI-11.0.11--11.9-13A688" alt="GLPI 11.0.11-11.9" />
</p>

**The free, read-only edition of [MCP Server for GLPI](https://github.com/OMNICOM-Digital/mcpserver).**

A GLPI plugin that exposes GLPI as an [MCP](https://modelcontextprotocol.io) (Model Context Protocol) server, so AI assistants can work with GLPI conversationally.

Built and maintained by [OMNICOM s.r.o.](https://omnicom.digital), an ITSM/ESM consultancy.

## Download

Install MCP Server Lite from the GLPI Marketplace (**Setup > Plugins > Marketplace**), or download the latest `mcpserverlite-<version>.zip` from [Releases](https://github.com/OMNICOM-Digital/mcpserver-lite/releases/latest). Both are free for everyone. Each release lists SHA-256 checksums in `SHA256SUMS`.

For the full toolset, the human-in-the-loop write confirmation and support, see [MCP Server for GLPI](https://github.com/OMNICOM-Digital/mcpserver).

## Features

- **Read-only by construction:** the plugin contains no code that creates, updates or deletes GLPI data. No write tools, no database tables, no GLPI right, no admin page.
- **33 read-only tools:** tickets and ITIL categories (15), knowledge base (4), service catalog forms (2), projects (7), and users, groups, entities and session context (5)
- **Each user's own rights:** every person signs in to GLPI through OAuth, and every call runs as that GLPI user, so normal profile and entity rights apply
- **Self-Service aware:** Self-Service profiles see only the tools that fit them
- **Ticket review helpers:** gather what is needed to review a ticket's type or category. They are advisory only: the AI gives its assessment, and any change is made in GLPI
- **Runs alongside the full plugin:** different plugin key and endpoint, so both can be active on the same GLPI

## Installation

1. **Recommended:** in GLPI, go to **Setup > Plugins > Marketplace**, tab **Discover**, search for `MCP Server Lite`, then click **Download**, **Install** and **Enable**. Self-hosted GLPI needs a GLPI Network registration key (**Setup > General > GLPI Network**) to use the Marketplace.
   **Manual:** extract the zip from [Releases](https://github.com/OMNICOM-Digital/mcpserver-lite/releases/latest) into `<glpi>/plugins/` (the folder must be named `mcpserverlite`), then install and activate the plugin in **Setup > Plugins**.
   Either way, the plugin creates nothing in the database.
2. In **Setup > General > OAuth clients**, add a client with the redirect URI your AI client requires, all grant types enabled (including `client_credentials`) and at least the `api` scope. Copy the client ID and the client secret; GLPI OAuth clients are always confidential, so the secret is required. Redirect URIs: Claude `https://claude.ai/api/mcp/auth_callback`, Copilot Studio the callback URL it shows when you create the tool (see the [Copilot Studio guide](docs/connect-copilot-studio.md)).
3. In your AI client, add a custom connector with the URL `https://<your-glpi>/plugins/mcpserverlite/front/mcp.php`, the client ID and secret from step 2, and the Streamable HTTP transport. Step-by-step: [Claude](docs/connect-claude.md), [Microsoft Copilot Studio](docs/connect-copilot-studio.md).
4. Each person signs in to GLPI in their own browser on first connection and gets their own token and their own rights.

After an update that adds or removes tools, disconnect and reconnect the connector in the AI client. Full setup notes and the architecture: [docs/technical-overview.md](docs/technical-overview.md).

### Connect your AI client

- [Claude](docs/connect-claude.md)
- [Microsoft Copilot Studio](docs/connect-copilot-studio.md)

## Source code

The plugin source is in this repository (`setup.php`, `src/`, `front/`); it is the folder GLPI expects as `<glpi>/plugins/mcpserverlite`. `main` can contain changes that are not released yet, so for installation use the zip from [Releases](https://github.com/OMNICOM-Digital/mcpserver-lite/releases/latest). Issues and pull requests are welcome; for security problems follow [SECURITY.md](SECURITY.md).

## Compatibility

- GLPI 11.0.11 - 11.9.99, on-premise
- PHP 8.1 or later

## Known limitations

- `glpi_list_users`, `glpi_search_knowbase_categories` and `glpi_search_project_dropdowns` do not apply entity restriction.
- Stateless request/response transport only, no SSE streaming.

## Security

Security support is defined per release line: the 1.x line is supported until at least **2 November 2031**, five years from its latest release, and every new release extends it. Security fixes are free and published here as security releases with an advisory.

To report a vulnerability, see [SECURITY.md](SECURITY.md) or e-mail security@omnicom.digital. Please do not open public issues for security problems.

## License

GPL v3.0 or later, see [LICENSE](LICENSE).

## Get in touch

MCP Server Lite comes without a support SLA. Questions about MCP Server Lite or the full plugin: sales@omnicom.digital or [omnicom.digital](https://omnicom.digital).
