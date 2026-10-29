# Security Policy

This policy covers **MCP Server Lite** (`mcpserverlite`), developed and maintained by OMNICOM s.r.o. ("OMNICOM") and licensed under GPL-3.0-or-later.

MCP Server Lite is published in this public repository, https://github.com/OMNICOM-Digital/mcpserver-lite, together with its changelog, security advisories and security releases. The full plugin, MCP Server for GLPI, has its own policy: https://github.com/OMNICOM-Digital/mcpserver/blob/main/SECURITY.md

## Supported versions

Security support is defined per **release line**, not per individual release. All releases within a supported line receive security fixes.

| Line | GLPI | First published | Security support until |
|---|---|---|---|
| 1.x | 11.0 to 11.9 | not released yet | **five years from the first release**\* |

\* The exact date is added here with the first release. It is extended whenever a new release is published in the line, so that every release in the line is supported for at least five years from its own release date.

Feature releases within a line (for example, new MCP tools that fit the existing permission model) do not start a new support period. A new line (for example 2.0) is created only when the security model, authentication or intended use changes substantially, and it receives its own support period announced in advance.

## Reporting a vulnerability

**Please do not open public issues for security problems.**

Report privately by either of these channels:

- E-mail: **security@omnicom.digital**
- GitHub private vulnerability reporting on this repository: [Report a vulnerability](https://github.com/OMNICOM-Digital/mcpserver-lite/security/advisories/new)

Please include:

- affected version of the plugin, and the GLPI version
- a description of the issue and its impact
- steps to reproduce or a proof of concept
- whether you believe the issue is already being exploited

Reports are welcome from anyone: researchers, users and downstream integrators.

### What to expect

| Step | Target |
|---|---|
| Acknowledgement of your report | within **3** business days |
| Initial assessment and severity rating | within **10** business days |
| Fix or mitigation for confirmed issues | prioritised by severity; actively exploited vulnerabilities are handled first |
| Advisory published with the fix | on release of the fix |

We will keep you informed of progress and credit you in the advisory unless you prefer otherwise.

### Coordinated disclosure

We ask for **90** days from your report to prepare and release a fix before public disclosure. We will agree a shorter or longer period with you where the circumstances justify it (for example, active exploitation or a complex fix).

## How security fixes are delivered

- Security fixes are issued as **separate security releases** (patch releases such as `1.x.y`), distinguishable from feature updates in the changelog (entries marked `Security:`).
- Security fixes apply to **every release in the supported line**, not only the latest one.
- **Security fixes are free of charge** and available to everyone.
- Each fix is accompanied by an advisory describing the issue, affected versions, severity, and the action users should take.

### Where to get security releases

[GitHub Releases](https://github.com/OMNICOM-Digital/mcpserver-lite/releases) and [Security Advisories](https://github.com/OMNICOM-Digital/mcpserver-lite/security/advisories) of this repository.

Where a vulnerability affects code shared with MCP Server for GLPI, the fix for MCP Server for GLPI is made available no later than the public fix in MCP Server Lite.

## Scope

In scope: the plugin code, its MCP endpoint, authentication and authorisation logic, and the plugin's own dependencies.

Out of scope: vulnerabilities in GLPI core itself (please report these to the GLPI project), in the AI client connecting to the plugin, or in the customer's own infrastructure and configuration, unless the plugin makes the issue materially worse.

## Our commitments

- We document, triage and remediate reported vulnerabilities and track them to closure.
- We notify the competent authorities where required, including reporting actively exploited vulnerabilities and severe incidents under the EU Cyber Resilience Act (Regulation (EU) 2024/2847).
- We cooperate with market surveillance authorities on request.
- We share information about fixed vulnerabilities with affected users and, where relevant, the wider open-source community.

## Safe harbour

We will not pursue legal action against researchers who act in good faith, avoid access to or disclosure of other people's data, do not degrade services, and report to us privately and promptly under this policy.

---

*Last updated: 28 October 2026. Contact: security@omnicom.digital*
