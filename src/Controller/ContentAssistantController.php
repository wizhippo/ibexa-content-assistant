<?php

declare(strict_types=1);

namespace Wizhippo\IbexaContentAssistant\Controller;

use Ibexa\Contracts\Core\Repository\Exceptions\NotFoundException;
use Ibexa\Contracts\Core\Repository\Exceptions\UnauthorizedException;
use Ibexa\Contracts\Core\Repository\Repository;
use Psr\Log\LoggerInterface;
use Symfony\AI\Platform\Exception\AuthenticationException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Wizhippo\IbexaContentAssistant\Exception\InvalidRichTextSuggestion;
use Wizhippo\IbexaContentAssistant\Service\ContentAssistantAccess;
use Wizhippo\IbexaContentAssistant\Service\ContentAssistantActionRegistry;
use Wizhippo\IbexaContentAssistant\Service\ContentAssistantService;
use Wizhippo\IbexaContentAssistant\Service\RichTextContentAssistantService;

final class ContentAssistantController extends AbstractController
{
    #[Route(
        '/content-assistant/suggest',
        name: 'wizhippo_ibexa_content_assistant_suggest',
        methods: ['POST'],
    )]
    public function suggest(
        Request $request,
        ContentAssistantAccess $access,
        ContentAssistantService $assistant,
        RichTextContentAssistantService $richTextAssistant,
        Repository $repository,
        LoggerInterface $logger,
        #[Autowire(service: 'limiter.wizhippo_ibexa_content_assistant')] RateLimiterFactory $limiter,
        ContentAssistantActionRegistry $actions,
    ): JsonResponse {
        if (!$access->isAvailable()) {
            return $this->json(['error' => 'The content assistant is unavailable for this account.'], 403);
        }
        if (!$this->isCsrfTokenValid('wizhippo_ibexa_content_assistant', $request->headers->get('X-CSRF-Token'))) {
            return $this->json(['error' => 'Your session expired. Reload the editor.'], 403);
        }
        if (strlen($request->getContent()) > 262144) {
            return $this->json(['error' => 'The request is too large.'], 413);
        }
        try {
            $data = $request->toArray();
        } catch (\JsonException|\Symfony\Component\HttpFoundation\Exception\JsonException) {
            return $this->json(['error' => 'Invalid JSON request.'], 400);
        }
        foreach (['operation', 'instructions', 'source', 'language', 'field'] as $key) {
            if (!isset($data[$key]) || !is_string($data[$key])) {
                return $this->json(['error' => 'Missing or invalid request fields.'], 400);
            }
        }
        if (!isset($data['contentId'], $data['versionNo'])
            || !is_int($data['contentId']) || $data['contentId'] <= 0
            || !is_int($data['versionNo']) || $data['versionNo'] <= 0) {
            return $this->json(['error' => 'A content draft is required.'], 400);
        }
        if (array_key_exists('segments', $data)) {
            return $this->json(['error' => 'Reload the editor to use formatted RichText generation.'], 400);
        }
        if (array_key_exists('richText', $data) && (!is_string($data['richText']) || !isset($data['editorCapabilities']) || !is_array($data['editorCapabilities']))) {
            return $this->json(['error' => 'Invalid RichText request. Reload the editor.'], 400);
        }

        try {
            $content = $repository->getContentService()->loadContent($data['contentId'], null, $data['versionNo']);
            $contentType = $repository->getContentTypeService()->loadContentType($content->getContentInfo()->contentTypeId);
            $repository->getContentLanguageService()->loadLanguage($data['language']);
            if (!isset($access->fields($content, $contentType, $data['language'])[$data['field']])) {
                return $this->json(['error' => 'The assistant cannot edit this field.'], 403);
            }
            if (isset($data['richText']) !== ($contentType->getFieldDefinition($data['field'])->getFieldTypeIdentifier() === 'ibexa_richtext')) {
                return $this->json(['error' => 'This field requires the matching editor format. Reload the editor.'], 422);
            }
        } catch (NotFoundException|UnauthorizedException) {
            return $this->json(['error' => 'This draft is unavailable.'], 403);
        }

        if (!$limiter->create((string)$repository->getPermissionResolver()->getCurrentUserReference()->getUserId())->consume()->isAccepted()) {
            return $this->json(['error' => 'Too many requests. Please try again in a minute.'], 429);
        }

        try {
            if (isset($data['action']) && !is_string($data['action'])) {
                throw new \InvalidArgumentException('Invalid AI action identifier.');
            }
            $action = $actions->get($data['action'] ?? $data['operation']);
            $data['operation'] = $action['type'];
            $context = [
                'configuration' => $action,
                'runtime' => ['language' => $data['language'], 'field' => $data['field']],
            ];
            if (isset($data['richText'])) {
                $richTextAssistant->validate($data['operation'], $data['instructions'], $data['richText'], $data['language'], $data['editorCapabilities']);
            } else {
                $assistant->validate($data['operation'], $data['instructions'], $data['source'], $data['language']);
            }
        } catch (\InvalidArgumentException $exception) {
            return $this->json(['error' => $exception->getMessage()], 422);
        }

        try {
            $result = isset($data['richText'])
                ? $richTextAssistant->suggest($data['operation'], $data['instructions'], $data['richText'], $data['language'], $data['editorCapabilities'], $context)
                : ['suggestion' => $assistant->suggest($data['operation'], $data['instructions'], $data['source'], $data['language'], $context)];
        } catch (InvalidRichTextSuggestion $exception) {
            return $this->json(['error' => $exception->getMessage()], 502);
        } catch (\Throwable $exception) {
            // Provider exceptions may contain source text or credentials. Never log their messages.
            $logger->warning('Content assistant request failed.', ['exception_class' => $exception::class]);

            if ($exception instanceof AuthenticationException) {
                return $this->json([
                    'error' => 'The AI provider rejected the configured API credentials. An administrator must update OPENAI_API_KEY in the application environment. Your draft has not changed.',
                ], 502);
            }

            return $this->json(['error' => 'The assistant is unavailable. Your draft has not changed. Please retry.'], 502);
        }

        $logger->info('Content assistant suggestion generated.', [
            'user_id' => $repository->getPermissionResolver()->getCurrentUserReference()->getUserId(),
            'content_id' => $data['contentId'],
            'operation' => $data['operation'],
            'action' => $action['identifier'],
        ]);

        return $this->json($result, 200, ['Cache-Control' => 'no-store']);
    }
}
