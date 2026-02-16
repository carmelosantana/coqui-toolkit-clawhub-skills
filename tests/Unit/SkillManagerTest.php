<?php

declare(strict_types=1);

use CoquiBot\Toolkits\ClawHubSkills\Api\ClawHubClient;
use CoquiBot\Toolkits\ClawHubSkills\Skill\SkillManager;

it('lists installed skills with enabled and disabled statuses', function (): void {
    $root = sys_get_temp_dir() . '/clawhub-skill-test-' . bin2hex(random_bytes(4));
    $skillsDir = $root . '/.workspace/skills';

    mkdir($skillsDir . '/alpha-skill', 0755, true);
    mkdir($skillsDir . '/beta-skill.disabled', 0755, true);

    file_put_contents(
        $skillsDir . '/alpha-skill/.clawhub-origin.json',
        json_encode(['slug' => 'author/alpha-skill', 'version' => '1.0.0'], JSON_THROW_ON_ERROR),
    );

    file_put_contents(
        $skillsDir . '/beta-skill.disabled/.clawhub-origin.json',
        json_encode(['slug' => 'author/beta-skill', 'version' => '2.0.0'], JSON_THROW_ON_ERROR),
    );

    $manager = new SkillManager(
        client: new ClawHubClient(),
        skillsDirectory: $skillsDir,
    );

    $skills = $manager->listInstalled();

    expect($skills)->toHaveCount(2);
    expect($skills[0]['name'])->toBe('alpha-skill');
    expect($skills[0]['status'])->toBe('enabled');
    expect($skills[1]['name'])->toBe('beta-skill');
    expect($skills[1]['status'])->toBe('disabled');

    cleanupDirectory($root);
});

it('disables and re-enables an installed skill', function (): void {
    $root = sys_get_temp_dir() . '/clawhub-skill-test-' . bin2hex(random_bytes(4));
    $skillsDir = $root . '/.workspace/skills';

    mkdir($skillsDir . '/my-skill', 0755, true);

    $manager = new SkillManager(
        client: new ClawHubClient(),
        skillsDirectory: $skillsDir,
    );

    $disabled = $manager->disable('my-skill');

    expect($disabled['status'])->toBe('disabled');
    expect(is_dir($skillsDir . '/my-skill.disabled'))->toBeTrue();

    $enabled = $manager->enable('my-skill');

    expect($enabled['status'])->toBe('enabled');
    expect(is_dir($skillsDir . '/my-skill'))->toBeTrue();

    cleanupDirectory($root);
});

it('remove defaults to disable and purge deletes files', function (): void {
    $root = sys_get_temp_dir() . '/clawhub-skill-test-' . bin2hex(random_bytes(4));
    $skillsDir = $root . '/.workspace/skills';

    mkdir($skillsDir . '/remove-me', 0755, true);

    $manager = new SkillManager(
        client: new ClawHubClient(),
        skillsDirectory: $skillsDir,
    );

    $disabled = $manager->remove('remove-me', purge: false);

    expect($disabled['status'])->toBe('disabled');
    expect(is_dir($skillsDir . '/remove-me.disabled'))->toBeTrue();

    $purged = $manager->remove('remove-me', purge: true);

    expect($purged['status'])->toBe('purged');
    expect(is_dir($skillsDir . '/remove-me.disabled'))->toBeFalse();

    cleanupDirectory($root);
});

function cleanupDirectory(string $path): void
{
    if (!is_dir($path)) {
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
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
