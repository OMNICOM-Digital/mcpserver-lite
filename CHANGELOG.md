# Changelog

Release history of MCP Server Lite. Releases are published under [Releases](https://github.com/OMNICOM-Digital/mcpserver-lite/releases), free for everyone.

## Support line and release convention

- **Support line:** 1.x, security support until at least **2031-11-02** (five years from the latest release), extended with every new release. See [SECURITY.md](SECURITY.md).
- **Feature releases** (`1.x.0`): new tools and features within the existing permission model.
- **Patch releases** (`1.x.y`): fixes only, no new features.
- **Security releases:** a patch release that fixes a vulnerability, published as a GitHub Release with a Security Advisory.
- **Tags** are the plain version number without a prefix (for example `1.1.0`), matching the version in `setup.php`.
- Entries prefixed **Security:** fix a vulnerability or close a security gap.

## Unreleased

- Plugin source code published in this repository
- Author shown in Setup > Plugins is now OMNICOM s.r.o.

## 1.1.0

First public release, published 2026-11-02. Supported with security fixes until 2031-11-02.

- 33 read-only tools: tickets and ITIL categories (15), knowledge base (4), service catalog forms (2), projects (7), and users, groups, entities and session context (5)
- Read-only by construction: no write tools, no database tables, no GLPI right, no admin page
- Every call runs as the GLPI user who signed in through OAuth, with that user's profile and entity rights
- Self-Service profiles see only the tools that fit them
- Ticket review helpers for type and category are advisory only: the AI gives its assessment, and any change is made in GLPI
- Runs side by side with the full plugin on the same GLPI (different plugin key and endpoint)
- Requires GLPI 11.0.11 to 11.9.x (on-premise) and PHP 8.1 or later

1.0.0 was an internal build and was not published.
