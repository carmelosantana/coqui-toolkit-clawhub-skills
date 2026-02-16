<?php

declare(strict_types=1);

namespace CoquiBot\Toolkits\ClawHubSkills;

use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Contract\ToolkitInterface;
use CarmeloSantana\PHPAgents\Tool\Parameter\BoolParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\NumberParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\StringParameter;
use CarmeloSantana\PHPAgents\Tool\Tool;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use CoquiBot\Toolkits\ClawHubSkills\Api\ClawHubClient;
use CoquiBot\Toolkits\ClawHubSkills\Skill\SkillManager;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class ClawHubSkillsToolkit implements ToolkitInterface
{
    private readonly ClawHubClient $client;
    private readonly SkillManager $skillManager;

    public function __construct(
        string $apiToken = '',
        ?string $registryBaseUrl = null,
        ?HttpClientInterface $httpClient = null,
    ) {
        $baseUrl = $registryBaseUrl ?? $this->resolveRegistryBaseUrl();
        $token = $apiToken !== '' ? $apiToken : $this->resolveApiToken();

        $this->client = new ClawHubClient(
            token: $token,
            baseUrl: $baseUrl,
            httpClient: $httpClient,
        );

        $this->skillManager = new SkillManager(
            client: $this->client,
            skillsDirectory: $this->resolveSkillsDirectory(),
        );
    }

    public static function fromEnv(): self
    {
        $token = getenv('CLAWHUB_API_TOKEN');
        $registry = getenv('CLAWHUB_REGISTRY');

        return new self(
            apiToken: $token !== false ? $token : '',
            registryBaseUrl: $registry !== false ? $registry : null,
        );
    }

    public function tools(): array
    {
        return [
            $this->searchTool(),
            $this->skillDetailsTool(),
            $this->installSkillTool(),
            $this->listInstalledTool(),
            $this->updateSkillTool(),
            $this->disableSkillTool(),
            $this->enableSkillTool(),
            $this->removeSkillTool(),
        ];
    }

    public function guidelines(): string
    {
        return <<<'GUIDELINES'
            <CLAWHUB-SKILLS-GUIDELINES>
            Use these tools to discover and manage AgentSkills from ClawHub.

            - Use `clawhub_search` to find skills by keyword.
            - Use `clawhub_skill_details` to inspect one skill before installing.
            - Use `clawhub_install_skill` to install into `.workspace/skills/`.
            - Use `clawhub_list_installed` to see installed skills and status.
            - Use `clawhub_update_skill` to update an installed ClawHub skill.
            - Use `clawhub_disable_skill` to disable (rename to `.disabled`).
            - Use `clawhub_enable_skill` to re-enable.
            - Use `clawhub_remove_skill` with `purge=true` for permanent deletion.

            Safety defaults:
            - Install/update fail if destination exists unless `force=true`.
            - Remove defaults to disable-only (`purge=false`).
            - API base URL defaults to `https://clawhub.ai` and supports `CLAWHUB_REGISTRY` override.
            </CLAWHUB-SKILLS-GUIDELINES>
            GUIDELINES;
    }

    private function searchTool(): ToolInterface
    {
        return new Tool(
            name: 'clawhub_search',
            description: 'Search ClawHub skills by keyword. Returns slug, title, description, and author-like metadata when available.',
            parameters: [
                new StringParameter('query', 'Search query keyword(s).', required: true),
                new NumberParameter('limit', 'Max results (1-50, default 10).', required: false, integer: true, minimum: 1, maximum: 50),
                new StringParameter('cursor', 'Pagination cursor from previous response.', required: false),
            ],
            callback: function (array $input): ToolResult {
                $query = trim((string) ($input['query'] ?? ''));
                if ($query === '') {
                    return ToolResult::error('Query is required.');
                }

                $limit = (int) ($input['limit'] ?? 10);
                $cursor = isset($input['cursor']) && is_string($input['cursor']) ? $input['cursor'] : null;

                try {
                    $result = $this->client->search($query, $limit, $cursor);
                } catch (\Throwable $e) {
                    return ToolResult::error($e->getMessage());
                }

                $items = $result['items'];
                if ($items === []) {
                    return ToolResult::success("No skills found for query: {$query}");
                }

                $output = "## ClawHub Search\n\n";
                $output .= "**Query:** {$query}\n";
                if ($result['total'] !== null) {
                    $output .= "**Total:** {$result['total']}\n";
                }

                if ($result['nextCursor'] !== null) {
                    $output .= "**Next cursor:** {$result['nextCursor']}\n";
                }

                $output .= "\n| # | Slug | Name | Description |\n";
                $output .= "|---|------|------|-------------|\n";

                foreach ($items as $index => $item) {
                    $slug = (string) ($item['slug'] ?? $item['id'] ?? 'unknown');
                    $name = (string) ($item['name'] ?? $item['title'] ?? $slug);
                    $description = $this->truncate((string) ($item['description'] ?? 'No description'), 100);

                    $output .= sprintf("| %d | %s | %s | %s |\n", $index + 1, $slug, $name, str_replace('|', '\\|', $description));
                }

                return ToolResult::success($output);
            },
        );
    }

    private function skillDetailsTool(): ToolInterface
    {
        return new Tool(
            name: 'clawhub_skill_details',
            description: 'Get full metadata and recent versions for a ClawHub skill by slug.',
            parameters: [
                new StringParameter('slug', 'Skill slug on ClawHub.', required: true),
            ],
            callback: function (array $input): ToolResult {
                $slug = trim((string) ($input['slug'] ?? ''));
                if ($slug === '') {
                    return ToolResult::error('Slug is required.');
                }

                try {
                    $details = $this->client->skillDetails($slug);
                    $versions = $this->client->skillVersions($slug, limit: 5);
                } catch (\Throwable $e) {
                    return ToolResult::error($e->getMessage());
                }

                $name = (string) ($details['name'] ?? $details['title'] ?? $slug);
                $description = (string) ($details['description'] ?? 'No description provided.');
                $author = $this->resolveAuthor($details);
                $downloads = $details['downloads'] ?? $details['installs'] ?? null;

                $output = "## {$name}\n\n";
                $output .= "**Slug:** {$slug}\n";
                $output .= "**Author:** {$author}\n";
                if (is_int($downloads) || is_float($downloads) || (is_string($downloads) && $downloads !== '')) {
                    $output .= "**Downloads:** {$downloads}\n";
                }
                $output .= "\n{$description}\n\n";
                $output .= "### Latest Versions\n\n";

                $versionItems = $versions['items'];
                if ($versionItems === []) {
                    $output .= "No versions available.\n";
                } else {
                    foreach ($versionItems as $item) {
                        $version = (string) ($item['version'] ?? $item['tag'] ?? 'unknown');
                        $createdAt = (string) ($item['createdAt'] ?? $item['publishedAt'] ?? 'unknown');
                        $output .= "- {$version} ({$createdAt})\n";
                    }
                }

                return ToolResult::success($output);
            },
        );
    }

    private function installSkillTool(): ToolInterface
    {
        return new Tool(
            name: 'clawhub_install_skill',
            description: 'Download and install a ClawHub skill into .workspace/skills.',
            parameters: [
                new StringParameter('slug', 'ClawHub skill slug to install.', required: true),
                new StringParameter('version', 'Optional version/tag. Defaults to latest.', required: false),
                new BoolParameter('force', 'Overwrite existing skill directory if true.', required: false),
            ],
            callback: function (array $input): ToolResult {
                $slug = trim((string) ($input['slug'] ?? ''));
                if ($slug === '') {
                    return ToolResult::error('Slug is required.');
                }

                $version = isset($input['version']) && is_string($input['version']) && $input['version'] !== ''
                    ? $input['version']
                    : null;
                $force = (bool) ($input['force'] ?? false);

                try {
                    $installed = $this->skillManager->install($slug, $version, $force);
                } catch (\Throwable $e) {
                    return ToolResult::error($e->getMessage());
                }

                $output = "## Skill Installed\n\n";
                $output .= "**Name:** {$installed['name']}\n";
                $output .= "**Slug:** {$installed['slug']}\n";
                $output .= "**Version:** " . ($installed['version'] ?? 'unknown') . "\n";
                $output .= "**Path:** {$installed['path']}\n";
                $output .= "\nThis skill is now available to Coqui skill discovery.";

                return ToolResult::success($output);
            },
        );
    }

    private function listInstalledTool(): ToolInterface
    {
        return new Tool(
            name: 'clawhub_list_installed',
            description: 'List local skills installed from ClawHub and show enabled/disabled status.',
            parameters: [],
            callback: function (array $input): ToolResult {
                try {
                    $skills = $this->skillManager->listInstalled();
                } catch (\Throwable $e) {
                    return ToolResult::error($e->getMessage());
                }

                if ($skills === []) {
                    return ToolResult::success('No skills installed in .workspace/skills.');
                }

                $output = "## Installed Skills\n\n";
                $output .= "| Skill | Slug | Version | Status |\n";
                $output .= "|-------|------|---------|--------|\n";

                foreach ($skills as $skill) {
                    $output .= sprintf(
                        "| %s | %s | %s | %s |\n",
                        $skill['name'],
                        $skill['slug'] ?? '-',
                        $skill['version'] ?? '-',
                        $skill['status'],
                    );
                }

                return ToolResult::success($output);
            },
        );
    }

    private function updateSkillTool(): ToolInterface
    {
        return new Tool(
            name: 'clawhub_update_skill',
            description: 'Update an installed ClawHub skill to latest or specific version.',
            parameters: [
                new StringParameter('name', 'Local skill directory name (without .disabled).', required: true),
                new StringParameter('version', 'Optional target version/tag.', required: false),
                new BoolParameter('force', 'Force reinstall even when versions appear equal.', required: false),
            ],
            callback: function (array $input): ToolResult {
                $name = trim((string) ($input['name'] ?? ''));
                if ($name === '') {
                    return ToolResult::error('Skill name is required.');
                }

                $version = isset($input['version']) && is_string($input['version']) && $input['version'] !== ''
                    ? $input['version']
                    : null;
                $force = (bool) ($input['force'] ?? false);

                try {
                    $updated = $this->skillManager->update($name, $version, $force);
                } catch (\Throwable $e) {
                    return ToolResult::error($e->getMessage());
                }

                $output = "## Skill Update\n\n";
                $output .= "**Name:** {$updated['name']}\n";
                $output .= "**Slug:** {$updated['slug']}\n";
                $output .= "**From:** " . ($updated['fromVersion'] ?? 'unknown') . "\n";
                $output .= "**To:** " . ($updated['toVersion'] ?? 'unknown') . "\n";
                $output .= "**Status:** {$updated['status']}\n";

                return ToolResult::success($output);
            },
        );
    }

    private function disableSkillTool(): ToolInterface
    {
        return new Tool(
            name: 'clawhub_disable_skill',
            description: 'Disable a local skill by renaming folder to .disabled suffix.',
            parameters: [
                new StringParameter('name', 'Local skill name to disable.', required: true),
            ],
            callback: function (array $input): ToolResult {
                $name = trim((string) ($input['name'] ?? ''));
                if ($name === '') {
                    return ToolResult::error('Skill name is required.');
                }

                try {
                    $result = $this->skillManager->disable($name);
                } catch (\Throwable $e) {
                    return ToolResult::error($e->getMessage());
                }

                return ToolResult::success("Skill '{$result['name']}' disabled at {$result['path']}");
            },
        );
    }

    private function enableSkillTool(): ToolInterface
    {
        return new Tool(
            name: 'clawhub_enable_skill',
            description: 'Enable a previously disabled local skill by removing .disabled suffix.',
            parameters: [
                new StringParameter('name', 'Local skill name to enable.', required: true),
            ],
            callback: function (array $input): ToolResult {
                $name = trim((string) ($input['name'] ?? ''));
                if ($name === '') {
                    return ToolResult::error('Skill name is required.');
                }

                try {
                    $result = $this->skillManager->enable($name);
                } catch (\Throwable $e) {
                    return ToolResult::error($e->getMessage());
                }

                return ToolResult::success("Skill '{$result['name']}' enabled at {$result['path']}");
            },
        );
    }

    private function removeSkillTool(): ToolInterface
    {
        return new Tool(
            name: 'clawhub_remove_skill',
            description: 'Remove local skill. Default behavior disables the skill; set purge=true for permanent deletion.',
            parameters: [
                new StringParameter('name', 'Local skill name to remove.', required: true),
                new BoolParameter('purge', 'Permanently delete skill files if true.', required: false),
            ],
            callback: function (array $input): ToolResult {
                $name = trim((string) ($input['name'] ?? ''));
                if ($name === '') {
                    return ToolResult::error('Skill name is required.');
                }

                $purge = (bool) ($input['purge'] ?? false);

                try {
                    $result = $this->skillManager->remove($name, $purge);
                } catch (\Throwable $e) {
                    return ToolResult::error($e->getMessage());
                }

                $verb = $purge ? 'purged' : 'disabled';

                return ToolResult::success("Skill '{$result['name']}' {$verb}. Path: {$result['path']}");
            },
        );
    }

    private function resolveApiToken(): string
    {
        $env = getenv('CLAWHUB_API_TOKEN');

        return $env !== false ? $env : '';
    }

    private function resolveRegistryBaseUrl(): string
    {
        $env = getenv('CLAWHUB_REGISTRY');

        if ($env === false || $env === '') {
            return 'https://clawhub.ai';
        }

        return rtrim($env, '/');
    }

    private function resolveSkillsDirectory(): string
    {
        $workspacePath = getenv('COQUI_WORKSPACE_PATH');
        if (is_string($workspacePath) && $workspacePath !== '') {
            return rtrim($workspacePath, '/') . '/skills';
        }

        $cwd = getcwd();
        if ($cwd !== false && is_dir($cwd . '/.workspace')) {
            return $cwd . '/.workspace/skills';
        }

        $root = $cwd !== false ? $cwd : __DIR__;
        $candidate = $this->findNearestWorkspaceDir($root);

        return $candidate . '/skills';
    }

    private function findNearestWorkspaceDir(string $start): string
    {
        $current = realpath($start) ?: $start;

        while (true) {
            $workspace = $current . '/.workspace';
            if (is_dir($workspace)) {
                return $workspace;
            }

            $parent = dirname($current);
            if ($parent === $current) {
                return $start . '/.workspace';
            }

            $current = $parent;
        }
    }

    private function truncate(string $value, int $max): string
    {
        if (mb_strlen($value) <= $max) {
            return $value;
        }

        return mb_substr($value, 0, $max - 1) . '…';
    }

    /**
     * @param array<string, mixed> $details
     */
    private function resolveAuthor(array $details): string
    {
        $candidate = $details['author'] ?? $details['owner'] ?? 'unknown';

        if (is_string($candidate) && $candidate !== '') {
            return $candidate;
        }

        if (is_array($candidate)) {
            $name = $candidate['name'] ?? $candidate['username'] ?? $candidate['slug'] ?? null;
            if (is_string($name) && $name !== '') {
                return $name;
            }
        }

        return 'unknown';
    }
}