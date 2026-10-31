<?php

declare(strict_types=1);

namespace Wizhippo\IbexaContentAssistant\Tests\Security;

use Ibexa\Bundle\Core\DependencyInjection\Configuration\ConfigBuilderInterface;
use Ibexa\Core\MVC\Symfony\Security\Authorization\Attribute;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Wizhippo\IbexaContentAssistant\Security\ContentAssistantPolicy;
use Wizhippo\IbexaContentAssistant\Security\PolicyProvider;

final class ContentAssistantPolicyTest extends TestCase
{
    public function testCanonicalModuleGrantsAccess(): void
    {
        $policy = $this->policy(['wizhippo_ibexa_content_assistant' => ['use']]);

        self::assertTrue($policy->isGranted(ContentAssistantPolicy::FUNCTION_USE));
        self::assertFalse($policy->isGranted(ContentAssistantPolicy::FUNCTION_MANAGE));
    }

    public function testUnrelatedModuleDoesNotGrantAccess(): void
    {
        $policy = $this->policy(['content' => ['use']]);

        self::assertFalse($policy->isGranted(ContentAssistantPolicy::FUNCTION_USE));
    }

    public function testChecksUseIbexaAuthorizationAttributes(): void
    {
        $attributes = [];
        $checker = $this->createMock(AuthorizationCheckerInterface::class);
        $checker->method('isGranted')->willReturnCallback(static function ($attribute) use (&$attributes): bool {
            $attributes[] = $attribute;

            return false;
        });

        (new ContentAssistantPolicy($checker))->isGranted(ContentAssistantPolicy::FUNCTION_USE);

        self::assertCount(1, $attributes);
        self::assertInstanceOf(Attribute::class, $attributes[0]);
        self::assertSame(ContentAssistantPolicy::FUNCTION_USE, $attributes[0]->function);
        self::assertSame(ContentAssistantPolicy::MODULE, $attributes[0]->module);
    }

    public function testPolicyProviderRegistersBundleModule(): void
    {
        $config = [];
        $builder = $this->createMock(ConfigBuilderInterface::class);
        $builder->method('addConfig')->willReturnCallback(static function (array $value) use (&$config) {
            $config = $value;
        });

        (new PolicyProvider())->addPolicies($builder);

        self::assertSame([
            ContentAssistantPolicy::MODULE => [
                ContentAssistantPolicy::FUNCTION_USE => null,
                ContentAssistantPolicy::FUNCTION_MANAGE => null,
            ],
        ], $config);
    }

    /** @param array<string, list<string>> $granted */
    private function policy(array $granted): ContentAssistantPolicy
    {
        $checker = $this->createMock(AuthorizationCheckerInterface::class);
        $checker->method('isGranted')->willReturnCallback(static function ($attribute) use ($granted): bool {
            self::assertInstanceOf(Attribute::class, $attribute);

            return in_array($attribute->function, $granted[$attribute->module] ?? [], true);
        });

        return new ContentAssistantPolicy($checker);
    }
}
