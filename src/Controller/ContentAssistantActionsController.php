<?php

declare(strict_types=1);

namespace Wizhippo\IbexaContentAssistant\Controller;

use Ibexa\Contracts\AdminUi\Controller\Controller;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Wizhippo\IbexaContentAssistant\Security\ContentAssistantPolicy;
use Wizhippo\IbexaContentAssistant\Service\ContentAssistantActionRegistry;

#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class ContentAssistantActionsController extends Controller
{
    public function __construct(private readonly ContentAssistantPolicy $policy)
    {
    }

    /**
     * Back office access check, run by the Ibexa admin UI before the action.
     */
    public function performAccessCheck(): void
    {
        parent::performAccessCheck();

        if (!$this->policy->isGranted(ContentAssistantPolicy::FUNCTION_MANAGE)) {
            throw $this->createAccessDeniedException();
        }
    }

    #[Route(
        '/content-assistant/actions',
        name: 'wizhippo_ibexa_content_assistant_actions',
        methods: ['GET', 'POST'],
    )]
    public function index(Request $request, ContentAssistantActionRegistry $registry, LoggerInterface $logger): Response
    {
        $error = null;
        $status = 200;
        $editing = $request->query->getString('edit');
        $draft = null;
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('wizhippo_ibexa_content_assistant_actions', $request->request->getString('_token'))) {
                throw $this->createAccessDeniedException('Your session expired. Reload the page.');
            }
            $identifier = $request->request->getString('identifier');
            $operation = $request->request->getString('operation');
            try {
                if ($operation === 'delete') {
                    $registry->delete($identifier, $request->request->getString('revision'));
                } elseif (in_array($operation, ['create', 'update'], true)) {
                    $draft = [
                        'name' => trim($request->request->getString('name')),
                        'type' => $request->request->getString('type'),
                        'prompt' => trim($request->request->getString('prompt')),
                        'enabled' => $request->request->getBoolean('enabled'),
                    ];
                    $registry->save($identifier, $draft, $request->request->getString('revision'), $operation === 'create');
                } else {
                    throw new \InvalidArgumentException('Unknown action configuration operation.');
                }
                $logger->info('AI action configuration changed.', ['action' => $identifier, 'operation' => $operation]);
                $this->addFlash('success', 'AI action configuration saved.');

                return $this->redirectToRoute('wizhippo_ibexa_content_assistant_actions');
            } catch (\InvalidArgumentException $exception) {
                $error = $exception->getMessage();
                $status = 422;
                $editing = $operation === 'update' ? $identifier : '';
            }
        }
        $all = $registry->all();
        $query = $request->query->getString('q');
        $type = $request->query->getString('type');
        $state = $request->query->getString('status');
        $actions = array_filter($all, static fn(array $action, string $id): bool => ($query === '' || mb_stripos($id . ' ' . $action['name'], $query) !== false)
            && ($type === '' || $type === $action['type'])
            && ($state === '' || $action['enabled'] === ($state === 'enabled')), ARRAY_FILTER_USE_BOTH);

        return $this->render('@WizhippoIbexaContentAssistant/admin/content_assistant_actions.html.twig', [
            'actions' => $actions, 'types' => ContentAssistantActionRegistry::TYPES,
            'revision' => $registry->revision(), 'error' => $error,
            'editing' => $editing, 'identifier' => $identifier ?? $editing,
            'configuration' => $draft ?? ($all[$editing] ?? ['name' => '', 'type' => 'rewrite', 'prompt' => '', 'enabled' => true]),
            'q' => $query, 'type' => $type, 'status' => $state,
        ], new Response(status: $status, headers: ['Cache-Control' => 'no-store']));
    }
}
