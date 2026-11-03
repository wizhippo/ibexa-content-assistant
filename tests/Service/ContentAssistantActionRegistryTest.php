<?php

declare(strict_types=1);

namespace Wizhippo\IbexaContentAssistant\Tests\Service;

use Ibexa\Contracts\Core\Repository\SettingService;
use Ibexa\Contracts\Core\Repository\Values\Setting\Setting;
use Ibexa\Contracts\Core\Repository\Values\Setting\SettingCreateStruct;
use Ibexa\Contracts\Core\Repository\Values\Setting\SettingUpdateStruct;
use Ibexa\Core\Base\Exceptions\NotFoundException;
use PHPUnit\Framework\TestCase;
use Wizhippo\IbexaContentAssistant\Service\ContentAssistantActionRegistry;

final class ContentAssistantActionRegistryTest extends TestCase
{
    private const array ACTION = ['type' => 'rewrite', 'name' => 'Friendly tone', 'prompt' => 'Use a friendly tone.', 'enabled' => true];

    public function testConfigurationLifecycleAndDisabledExecution(): void
    {
        $registry = $this->registry();
        self::assertSame(self::ACTION, $registry->all()['rewrite']);
        $registry->save('friendly', self::ACTION, $registry->revision(), true);
        self::assertSame('friendly', $registry->get('friendly')['identifier']);
        $registry->save('friendly', array_replace(self::ACTION, ['enabled' => false]), $registry->revision(), false);
        self::assertArrayNotHasKey('friendly', $registry->all(true));
        try {
            $registry->get('friendly');
            self::fail('Disabled configuration must not execute.');
        } catch (\InvalidArgumentException) {
        }
        $registry->delete('friendly', $registry->revision());
        self::assertArrayNotHasKey('friendly', $registry->all());
        $registry->delete('rewrite', $registry->revision());
        self::assertSame([], $registry->all(), 'Deleting the defaults must not reintroduce them.');
    }

    public function testStaleAdministrationFormCannotOverwriteChanges(): void
    {
        $registry = $this->registry();
        $revision = $registry->revision();
        $registry->save('friendly', self::ACTION, $revision, true);
        $this->expectException(\InvalidArgumentException::class);
        $registry->delete('rewrite', $revision);
    }

    /** @dataProvider invalidConfigurations */
    public function testInvalidConfigurationIsNotPersisted(string $identifier, array $configuration): void
    {
        $registry = $this->registry();
        $this->expectException(\InvalidArgumentException::class);
        $registry->save($identifier, $configuration, $registry->revision(), true);
    }

    public static function invalidConfigurations(): iterable
    {
        yield 'invalid id' => ['../path', self::ACTION];
        yield 'taxonomy' => ['taxonomy', array_replace(self::ACTION, ['type' => 'taxonomy'])];
        yield 'image actions' => ['image', array_replace(self::ACTION, ['type' => 'image_to_text'])];
        yield 'missing name' => ['friendly', array_replace(self::ACTION, ['name' => ''])];
        yield 'missing prompt' => ['friendly', array_replace(self::ACTION, ['prompt' => ''])];
        yield 'duplicate id' => ['rewrite', self::ACTION];
    }

    public function testLongInstructionsCanBeStoredWithoutAnEditorialCharacterCap(): void
    {
        $registry = $this->registry();
        $action = array_replace(self::ACTION, ['prompt' => str_repeat('Detailed instructions. ', 500)]);
        $registry->save('long_prompt', $action, $registry->revision(), true);
        self::assertSame($action['prompt'], $registry->get('long_prompt')['prompt']);
    }

    private function registry(): ContentAssistantActionRegistry
    {
        $value = null;
        $settings = $this->createMock(SettingService::class);
        $settings->method('loadSetting')->willReturnCallback(static function () use (&$value): Setting {
            if ($value === null) {
                throw new NotFoundException('Setting', 'actions');
            }

            return new Setting(['value' => $value]);
        });
        $settings->method('newSettingCreateStruct')->willReturnCallback(static fn (array $properties): SettingCreateStruct => new SettingCreateStruct($properties));
        $settings->method('newSettingUpdateStruct')->willReturnCallback(static fn (array $properties): SettingUpdateStruct => new SettingUpdateStruct($properties));
        $settings->method('createSetting')->willReturnCallback(static function (SettingCreateStruct $struct) use (&$value): Setting {
            $value = $struct->value;

            return new Setting(['value' => $value]);
        });
        $settings->method('updateSetting')->willReturnCallback(static function (Setting $setting, SettingUpdateStruct $struct) use (&$value): Setting {
            $value = $struct->value;

            return new Setting(['value' => $value]);
        });

        return new ContentAssistantActionRegistry($settings, ['rewrite' => self::ACTION], sys_get_temp_dir());
    }
}
