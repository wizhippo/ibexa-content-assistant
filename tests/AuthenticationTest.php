<?php

declare(strict_types=1);

namespace Wizhippo\IbexaContentAssistant\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\AuthenticationTrustResolver;
use Symfony\Component\Security\Core\Authentication\Token\NullToken;
use Symfony\Component\Security\Core\Authentication\Token\RememberMeToken;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\AuthenticatedVoter;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Wizhippo\IbexaContentAssistant\Controller\ContentAssistantActionsController;

final class AuthenticationTest extends TestCase
{
    /** @dataProvider sessions */
    public function testControllerSupportsSignedInSessionsAndRejectsAnonymousUsers(string $session, int $expected): void
    {
        $user = new InMemoryUser('editor', null, ['ROLE_USER']);
        $token = match ($session) {
            'remembered' => new RememberMeToken($user, 'ibexa'),
            'full' => new UsernamePasswordToken($user, 'ibexa', $user->getRoles()),
            default => new NullToken(),
        };
        $voter = new AuthenticatedVoter(new AuthenticationTrustResolver());
        $checks = [];
        foreach ((new \ReflectionClass(ContentAssistantActionsController::class))->getAttributes(IsGranted::class) as $attribute) {
            $check = $attribute->newInstance()->attribute;
            if (is_string($check) && str_starts_with($check, 'IS_AUTHENTICATED')) {
                $checks[] = $check;
            }
        }
        self::assertNotEmpty($checks);
        foreach ($checks as $check) {
            self::assertSame($expected, $voter->vote($token, null, [$check]));
        }
    }

    public static function sessions(): iterable
    {
        yield 'remembered login' => ['remembered', VoterInterface::ACCESS_GRANTED];
        yield 'fresh login' => ['full', VoterInterface::ACCESS_GRANTED];
        yield 'anonymous' => ['anonymous', VoterInterface::ACCESS_DENIED];
    }
}
