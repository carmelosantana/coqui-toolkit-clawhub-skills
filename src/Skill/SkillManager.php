<?php

declare(strict_types=1);

namespace CoquiBot\Toolkits\ClawHubSkills\Skill;

use CoquiBot\Toolkits\ClawHubSkills\Api\ClawHubClient;

final class SkillManager
{
    private const ORIGIN_FILE = '.clawhub-origin.json';

    public function __construct(
        private readonly ClawHubClient $client,
        private readonly string $skillsDirectory,
    ) {}

    /**
     * @return array{name: string, path: string, slug: string, version: ?string, status: string}
     */
    public function install(string $slug, ?string $version = null, bool $force = false): array
    {
        $archive = $this->client->downloadSkillArchive($slug, $version);

        $tmpRoot = $this->skillsDirectory . '/.tmp';
        if (!is_dir($tmpRoot) && !mkdir($tmpRoot, 0755, true) && !is_dir($tmpRoot)) {
            throw new \RuntimeException('Failed to create temporary directory for skill download.');
        }

        $tmpZip = $tmpRoot . '/skill_' . bin2hex(random_bytes(8)) . '.zip';
        $tmpExtractDir = $tmpRoot . '/extract_' . bin2hex(random_bytes(8));

        file_put_contents($tmpZip, $archive['bytes']);

        try {
            $skillRoot = $this->extractArchive($tmpZip, $tmpExtractDir);
            $skillName = $this->resolveSkillName($skillRoot, $slug);
            $targetDir = $this->skillsDirectory . '/' . $skillName;

            if (is_dir($targetDir) && !$force) {
                throw new \RuntimeException("Skill '{$skillName}' already exists. Use force=true to overwrite.");
            }

            if (is_dir($targetDir) && $force) {
                $this->removeDirectory($targetDir);
            }

            $this->copyDirectory($skillRoot, $targetDir);

            $resolvedVersion = $version;
            if ($resolvedVersion === null || $resolvedVersion === '') {
                $resolvedVersion = $this->client->latestVersion($slug);
            }

            $this->writeOriginMetadata($targetDir, [
                'source' => 'clawhub',
                'slug' => $slug,
                'version' => $resolvedVersion,
                'downloadedAt' => gmdate(DATE_ATOM),
                'suggestedFilename' => $archive['suggestedFilename'],
            ]);

            return [
                'name' => $skillName,
                'path' => $targetDir,
                'slug' => $slug,
                'version' => $resolvedVersion,
                'status' => 'installed',
            ];
        } finally {
            if (file_exists($tmpZip)) {
                unlink($tmpZip);
            }

            if (is_dir($tmpExtractDir)) {
                $this->removeDirectory($tmpExtractDir);
            }
        }
    }

    /**
     * @return list<array{name: string, slug: ?string, version: ?string, status: string, path: string}>
     */
    public function listInstalled(): array
    {
        $this->ensureSkillsDirectory();

        $entries = scandir($this->skillsDirectory);
        if ($entries === false) {
            return [];
        }

        $skills = [];

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..' || $entry === '.tmp') {
                continue;
            }

            $path = $this->skillsDirectory . '/' . $entry;
            if (!is_dir($path)) {
                continue;
            }

            $isDisabled = str_ends_with($entry, '.disabled');
            $name = $isDisabled ? substr($entry, 0, -9) : $entry;
            $status = $isDisabled ? 'disabled' : 'enabled';

            $metadata = $this->readOriginMetadata($path);

            $skills[] = [
                'name' => $name,
                'slug' => $metadata['slug'] ?? null,
                'version' => $metadata['version'] ?? null,
                'status' => $status,
                'path' => $path,
            ];
        }

        usort($skills, static fn(array $a, array $b): int => strcmp($a['name'], $b['name']));

        return $skills;
    }

    /**
     * @return array{name: string, path: string, status: string}
     */
    public function disable(string $name): array
    {
        $source = $this->skillsDirectory . '/' . $name;
        $target = $source . '.disabled';

        if (!is_dir($source)) {
            throw new \RuntimeException("Skill '{$name}' is not installed or is already disabled.");
        }

        if (is_dir($target)) {
            throw new \RuntimeException("Disabled skill '{$name}.disabled' already exists.");
        }

        if (!rename($source, $target)) {
            throw new \RuntimeException("Failed to disable skill '{$name}'.");
        }

        return [
            'name' => $name,
            'path' => $target,
            'status' => 'disabled',
        ];
    }

    /**
     * @return array{name: string, path: string, status: string}
     */
    public function enable(string $name): array
    {
        $source = $this->skillsDirectory . '/' . $name . '.disabled';
        $target = $this->skillsDirectory . '/' . $name;

        if (!is_dir($source)) {
            throw new \RuntimeException("Disabled skill '{$name}.disabled' not found.");
        }

        if (is_dir($target)) {
            throw new \RuntimeException("Enabled skill '{$name}' already exists.");
        }

        if (!rename($source, $target)) {
            throw new \RuntimeException("Failed to enable skill '{$name}'.");
        }

        return [
            'name' => $name,
            'path' => $target,
            'status' => 'enabled',
        ];
    }

    /**
     * @return array{name: string, status: string, path: string}
     */
    public function remove(string $name, bool $purge = false): array
    {
        $enabledPath = $this->skillsDirectory . '/' . $name;
        $disabledPath = $enabledPath . '.disabled';

        $path = null;

        if (is_dir($enabledPath)) {
            $path = $enabledPath;
        } elseif (is_dir($disabledPath)) {
            $path = $disabledPath;
        }

        if ($path === null) {
            throw new \RuntimeException("Skill '{$name}' not found.");
        }

        if (!$purge) {
            if (str_ends_with($path, '.disabled')) {
                return [
                    'name' => $name,
                    'status' => 'disabled',
                    'path' => $path,
                ];
            }

            return $this->disable($name);
        }

        $this->removeDirectory($path);

        return [
            'name' => $name,
            'status' => 'purged',
            'path' => $path,
        ];
    }

    /**
     * @return array{name: string, slug: string, fromVersion: ?string, toVersion: ?string, status: string}
     */
    public function update(string $name, ?string $targetVersion = null, bool $force = false): array
    {
        $skillPath = $this->findSkillPath($name);
        if ($skillPath === null) {
            throw new \RuntimeException("Skill '{$name}' not found.");
        }

        $metadata = $this->readOriginMetadata($skillPath);
        $slug = $metadata['slug'] ?? $name;

        if (!is_string($slug) || $slug === '') {
            throw new \RuntimeException(
                "Skill '{$name}' has no ClawHub origin metadata. Reinstall it from ClawHub before updating.",
            );
        }

        $currentVersion = isset($metadata['version']) && is_string($metadata['version']) ? $metadata['version'] : null;
        $nextVersion = $targetVersion;

        if ($nextVersion === null || $nextVersion === '') {
            $nextVersion = $this->client->latestVersion($slug);
        }

        if ($nextVersion !== null && $currentVersion !== null && $nextVersion === $currentVersion && !$force) {
            return [
                'name' => $name,
                'slug' => $slug,
                'fromVersion' => $currentVersion,
                'toVersion' => $nextVersion,
                'status' => 'up-to-date',
            ];
        }

        $installed = $this->install($slug, $nextVersion, force: true);

        return [
            'name' => $installed['name'],
            'slug' => $slug,
            'fromVersion' => $currentVersion,
            'toVersion' => $installed['version'],
            'status' => 'updated',
        ];
    }

    private function ensureSkillsDirectory(): void
    {
        if (!is_dir($this->skillsDirectory)) {
            mkdir($this->skillsDirectory, 0755, true);
        }
    }

    private function findSkillPath(string $name): ?string
    {
        $enabled = $this->skillsDirectory . '/' . $name;
        if (is_dir($enabled)) {
            return $enabled;
        }

        $disabled = $enabled . '.disabled';
        if (is_dir($disabled)) {
            return $disabled;
        }

        return null;
    }

    private function extractArchive(string $zipPath, string $extractDir): string
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new \RuntimeException('ZIP extension is not available. Install ext-zip to install ClawHub skills.');
        }

        $zip = new \ZipArchive();
        $openResult = $zip->open($zipPath);
        if ($openResult !== true) {
            throw new \RuntimeException('Failed to open downloaded ZIP archive.');
        }

        if (!mkdir($extractDir, 0755, true) && !is_dir($extractDir)) {
            $zip->close();
            throw new \RuntimeException('Failed to create extraction directory.');
        }

        if (!$zip->extractTo($extractDir)) {
            $zip->close();
            throw new \RuntimeException('Failed to extract ClawHub skill archive.');
        }

        $zip->close();

        $detected = $this->detectSkillRoot($extractDir);
        if ($detected === null) {
            throw new \RuntimeException('Downloaded archive does not contain a valid SKILL.md structure.');
        }

        return $detected;
    }

    private function detectSkillRoot(string $extractDir): ?string
    {
        $directSkill = $extractDir . '/SKILL.md';
        if (is_file($directSkill)) {
            return $extractDir;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($extractDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $fileInfo) {
            if (!$fileInfo->isFile()) {
                continue;
            }

            if ($fileInfo->getFilename() !== 'SKILL.md') {
                continue;
            }

            return dirname($fileInfo->getPathname());
        }

        return null;
    }

    private function resolveSkillName(string $skillRoot, string $fallbackSlug): string
    {
        $path = $skillRoot . '/SKILL.md';
        $content = file_get_contents($path);

        if ($content === false) {
            return $this->sanitizeName($fallbackSlug);
        }

        if (preg_match('/^name:\s*([a-z0-9-]+)\s*$/mi', $content, $matches) === 1) {
            return $this->sanitizeName($matches[1]);
        }

        return $this->sanitizeName($fallbackSlug);
    }

    private function sanitizeName(string $name): string
    {
        $name = strtolower(trim($name));
        $name = preg_replace('/[^a-z0-9-]+/', '-', $name) ?? $name;
        $name = trim($name, '-');

        return $name !== '' ? $name : 'skill-' . substr(bin2hex(random_bytes(3)), 0, 6);
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function writeOriginMetadata(string $skillDir, array $metadata): void
    {
        file_put_contents(
            $skillDir . '/' . self::ORIGIN_FILE,
            json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function readOriginMetadata(string $skillDir): array
    {
        $path = $skillDir . '/' . self::ORIGIN_FILE;
        if (!is_file($path)) {
            return [];
        }

        $content = file_get_contents($path);
        if ($content === false || $content === '') {
            return [];
        }

        try {
            $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);

            return is_array($decoded) ? $decoded : [];
        } catch (\JsonException) {
            return [];
        }
    }

    private function copyDirectory(string $source, string $target): void
    {
        if (!is_dir($source)) {
            throw new \RuntimeException('Source directory does not exist for copy operation.');
        }

        if (!is_dir($target) && !mkdir($target, 0755, true) && !is_dir($target)) {
            throw new \RuntimeException('Failed to create destination skill directory.');
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $item) {
            $targetPath = $target . '/' . $iterator->getSubPathName();

            if ($item->isDir()) {
                if (!is_dir($targetPath) && !mkdir($targetPath, 0755, true) && !is_dir($targetPath)) {
                    throw new \RuntimeException("Failed to create directory '{$targetPath}'.");
                }

                continue;
            }

            if (!copy($item->getPathname(), $targetPath)) {
                throw new \RuntimeException("Failed to copy file '{$targetPath}'.");
            }
        }
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }

        rmdir($path);
    }
}