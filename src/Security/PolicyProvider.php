<?php

declare(strict_types=1);

namespace Wizhippo\IbexaContentAssistant\Security;

use Ibexa\Bundle\Core\DependencyInjection\Configuration\ConfigBuilderInterface;
use Ibexa\Bundle\Core\DependencyInjection\Security\PolicyProvider\PolicyProviderInterface;

final class PolicyProvider implements PolicyProviderInterface
{
    public function addPolicies(ConfigBuilderInterface $configBuilder): void
    {
        $configBuilder->addConfig([
            ContentAssistantPolicy::MODULE => [
                ContentAssistantPolicy::FUNCTION_USE => null,
                ContentAssistantPolicy::FUNCTION_MANAGE => null,
            ],
        ]);
    }
}
