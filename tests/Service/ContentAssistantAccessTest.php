<?php

declare(strict_types=1);

namespace Wizhippo\IbexaContentAssistant\Tests\Service;

use Ibexa\Contracts\Core\Limitation\Target\Version;
use Ibexa\Contracts\Core\Repository\PermissionResolver;
use Ibexa\Contracts\Core\Repository\Repository;
use Ibexa\Contracts\Core\Repository\Values\Content\Content;
use Ibexa\Contracts\Core\Repository\Values\Content\ContentInfo;
use Ibexa\Core\Repository\Values\Content\VersionInfo;
use Ibexa\Core\Repository\Values\ContentType\ContentType;
use Ibexa\Core\Repository\Values\ContentType\FieldDefinition;
use Ibexa\Core\Repository\Values\ContentType\FieldDefinitionCollection;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Wizhippo\IbexaContentAssistant\Security\ContentAssistantPolicy;
use Wizhippo\IbexaContentAssistant\Service\ContentAssistantAccess;

final class ContentAssistantAccessTest extends TestCase
{
    public function testDisabledFeatureDoesNotCheckPermissions(): void
    {
        $security = $this->createMock(Security::class);
        $security->expects(self::never())->method('isGranted');
        $checker = $this->createMock(AuthorizationCheckerInterface::class);
        $checker->expects(self::never())->method('isGranted');
        $access = new ContentAssistantAccess(
            $this->createMock(Repository::class),
            $security,
            new ContentAssistantPolicy($checker),
            false,
            []
        );

        self::assertFalse($access->isAvailable());
    }

    /** @dataProvider fieldAccessCases */
    public function testRequiresAllowlistDraftAndContentPermission(bool $enabled, bool $granted, bool $draft, array $allowlist, bool $canEdit, array $expected): void
    {
        $security = $this->createMock(Security::class);
        $security->method('isGranted')->with('IS_AUTHENTICATED_REMEMBERED')->willReturn(true);
        $resolver = $this->createMock(PermissionResolver::class);
        $resolver->method('canUser')->willReturnCallback(static function ($module, $function, $content, $targets) use ($canEdit): bool {
            self::assertSame('content', $module);
            self::assertSame('edit', $function);
            self::assertInstanceOf(Version::class, $targets[0]);
            self::assertSame(['eng-GB'], $targets[0]->forUpdateLanguageCodesList);

            return $canEdit;
        });
        $repository = $this->createMock(Repository::class);
        $repository->method('getPermissionResolver')->willReturn($resolver);
        $content = $this->createMock(Content::class);
        $content->method('getVersionInfo')->willReturn(new VersionInfo([
            'status' => $draft ? 0 : 1,
            'contentInfo' => new ContentInfo(['mainLanguageCode' => 'eng-GB']),
        ]));
        $type = new ContentType([
            'identifier' => 'article',
            'fieldDefinitions' => new FieldDefinitionCollection([
                new FieldDefinition([
                    'identifier' => 'body',
                    'fieldTypeIdentifier' => 'ibexa_richtext',
                    'isTranslatable' => true,
                    'names' => ['eng-GB' => 'Body'],
                ]),
                new FieldDefinition(['identifier' => 'credentials', 'fieldTypeIdentifier' => 'ibexa_text']),
                new FieldDefinition(['identifier' => 'image', 'fieldTypeIdentifier' => 'ibexa_image']),
            ]),
        ]);

        self::assertSame($expected, (new ContentAssistantAccess(
            $repository,
            $security,
            $this->policy($granted),
            $enabled,
            $allowlist
        ))->fields($content, $type, 'eng-GB'));
    }

    public static function fieldAccessCases(): iterable
    {
        yield 'disabled' => [false, true, true, ['article' => ['body']], true, []];
        yield 'no policy' => [true, false, true, ['article' => ['body']], true, []];
        yield 'published version' => [true, true, false, ['article' => ['body']], true, []];
        yield 'empty allowlist' => [true, true, true, [], true, []];
        yield 'edit denied' => [true, true, true, ['article' => ['body']], false, []];
        yield 'only allowed supported fields' => [true, true, true, ['article' => ['body', 'image']], true, ['body' => 'Body']];
    }

    private function policy(bool $granted): ContentAssistantPolicy
    {
        $checker = $this->createMock(AuthorizationCheckerInterface::class);
        $checker->method('isGranted')->willReturn($granted);

        return new ContentAssistantPolicy($checker);
    }
}
