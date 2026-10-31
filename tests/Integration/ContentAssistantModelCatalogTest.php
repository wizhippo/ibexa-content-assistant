<?php

declare(strict_types=1);

namespace Wizhippo\IbexaContentAssistant\Tests\Integration;

use Symfony\AI\Platform\Bridge\OpenResponses\ResponsesModel;
use Symfony\AI\Platform\ModelCatalog\ModelCatalogInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ContentAssistantModelCatalogTest extends KernelTestCase
{
    public function testConfiguredCatalogResolvesProviderModelsAndPreservesModelOptions(): void
    {
        /** @var ModelCatalogInterface $catalog */
        $catalog = self::getContainer()->get('wizhippo_ibexa_content_assistant.model_catalog');
        $model = $catalog->getModel('gpt-6.1-terra?max_output_tokens=4000');

        self::assertInstanceOf(ResponsesModel::class, $model);
        self::assertSame('gpt-6.1-terra', $model->getName());
        self::assertSame(4000, $model->getOptions()['max_output_tokens']);
        self::assertSame('openai/gpt-4.1-mini', $catalog->getModel('openai/gpt-4.1-mini')->getName());
    }
}
