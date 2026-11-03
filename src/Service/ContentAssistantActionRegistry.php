<?php

declare(strict_types=1);

namespace Wizhippo\IbexaContentAssistant\Service;

use Ibexa\Contracts\Core\Repository\Exceptions\NotFoundException;
use Ibexa\Contracts\Core\Repository\SettingService;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;

/** Application AI Actions configuration, backed by Ibexa repository settings. */
final class ContentAssistantActionRegistry
{
    public const array TYPES = [
        'author' => 'Write content', 'continue' => 'Continue writing',
        'rewrite' => 'Refine content', 'shorten' => 'Shorten content',
        'expand' => 'Expand content', 'correct' => 'Correct spelling and grammar',
    ];

    public function __construct(
        private readonly SettingService $settings,
        #[Autowire(param: 'wizhippo_ibexa_content_assistant.actions')] private readonly array $defaults,
        #[Autowire('%kernel.project_dir%/var')] private readonly string $lockDirectory,
    ) {
    }

    public function all(bool $enabledOnly = false): array
    {
        try {
            $actions = $this->settings->loadSetting('wizhippo_ibexa_content_assistant', 'actions')->value;
        } catch (NotFoundException) {
            $actions = $this->defaults;
        }

        return $enabledOnly ? array_filter($actions, static fn (array $action): bool => $action['enabled']) : $actions;
    }

    public function get(string $identifier): array
    {
        $action = $this->all()[$identifier] ?? null;
        if ($action === null || !$action['enabled']) {
            throw new \InvalidArgumentException('This AI action is unavailable. Reload the editor.');
        }

        return $action + ['identifier' => $identifier];
    }

    public function revision(): string
    {
        return hash('sha256', json_encode($this->all(), JSON_THROW_ON_ERROR));
    }

    public function save(string $identifier, array $action, string $revision, bool $create): void
    {
        if (!preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $identifier)
            || !isset(self::TYPES[$action['type'] ?? ''])
            || !is_string($action['name'] ?? null) || trim($action['name']) === '' || mb_strlen($action['name']) > 100
            || !is_string($action['prompt'] ?? null) || trim($action['prompt']) === ''
            || !is_bool($action['enabled'] ?? null)) {
            throw new \InvalidArgumentException('Provide an identifier, a name (up to 100 characters), an editor action type and non-empty instructions.');
        }
        $this->change($revision, static function (array $actions) use ($identifier, $action, $create): array {
            if ($create === isset($actions[$identifier])) {
                throw new \InvalidArgumentException($create ? 'This identifier already exists.' : 'This action no longer exists. Reload the page.');
            }
            $actions[$identifier] = array_intersect_key($action, array_flip(['type', 'name', 'prompt', 'enabled']));

            return $actions;
        });
    }

    public function delete(string $identifier, string $revision): void
    {
        $this->change($revision, static function (array $actions) use ($identifier): array {
            unset($actions[$identifier]);

            return $actions;
        });
    }

    private function change(string $revision, callable $change): void
    {
        $lock = (new LockFactory(new FlockStore($this->lockDirectory)))->createLock('wizhippo-ibexa-content-assistant-actions');
        $lock->acquire(true);
        try {
            if (!hash_equals($this->revision(), $revision)) {
                throw new \InvalidArgumentException('Another administrator changed the actions. Reload the page before saving.');
            }
            $actions = $change($this->all());
            try {
                $setting = $this->settings->loadSetting('wizhippo_ibexa_content_assistant', 'actions');
            } catch (NotFoundException) {
                $this->settings->createSetting($this->settings->newSettingCreateStruct([
                    'group' => 'wizhippo_ibexa_content_assistant', 'identifier' => 'actions', 'value' => $actions,
                ]));

                return;
            }
            $this->settings->updateSetting($setting, $this->settings->newSettingUpdateStruct(['value' => $actions]));
        } finally {
            $lock->release();
        }
    }
}
