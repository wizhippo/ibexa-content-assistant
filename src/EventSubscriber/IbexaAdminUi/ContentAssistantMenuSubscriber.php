<?php

declare(strict_types=1);

namespace Wizhippo\IbexaContentAssistant\EventSubscriber\IbexaAdminUi;

use Ibexa\AdminUi\Menu\Event\ConfigureMenuEvent;
use Ibexa\AdminUi\Menu\MainMenuBuilder;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Wizhippo\IbexaContentAssistant\Security\ContentAssistantPolicy;

final readonly class ContentAssistantMenuSubscriber implements EventSubscriberInterface
{
    public function __construct(private ContentAssistantPolicy $policy)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [ConfigureMenuEvent::MAIN_MENU => 'configure'];
    }

    public function configure(ConfigureMenuEvent $event): void
    {
        if (!$this->policy->isGranted(ContentAssistantPolicy::FUNCTION_MANAGE)) {
            return;
        }
        $event->getMenu()->getChild(MainMenuBuilder::ITEM_ADMIN)?->addChild('main__admin__wizhippo_ibexa_content_assistant', [
            'route' => 'wizhippo_ibexa_content_assistant_actions', 'label' => 'AI actions',
        ])->setExtra('translation_domain', false);
    }
}
