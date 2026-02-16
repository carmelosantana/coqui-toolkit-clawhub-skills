# Coqui ClawHub Skills Toolkit

ClawHub skills toolkit for Coqui. It lets agents discover and manage AgentSkills from ClawHub directly from tool calls.

## Features

- Search ClawHub skills by keyword
- Fetch details and recent versions for a skill
- Download and install skills into `.workspace/skills/`
- List installed skills with enabled/disabled status
- Update installed ClawHub-backed skills
- Disable skills by renaming to `.disabled`
- Re-enable disabled skills
- Remove skills with safe default (`purge=false` disables, `purge=true` deletes)

## Requirements

- PHP 8.4+
- `ext-zip`
- `symfony/http-client`
- `carmelosantana/php-agents`

## Credentials

Declare/set credential in Coqui:

- `CLAWHUB_API_TOKEN` — ClawHub API token (required for protected endpoints; read endpoints may still work anonymously depending on server policy)

Optional:

- `CLAWHUB_REGISTRY` — override ClawHub base URL (default: `https://clawhub.ai`)

## Tools

- `clawhub_search`
- `clawhub_skill_details`
- `clawhub_install_skill`
- `clawhub_list_installed`
- `clawhub_update_skill`
- `clawhub_disable_skill`
- `clawhub_enable_skill`
- `clawhub_remove_skill`

## Development

```bash
composer install
composer test
composer analyse
```
