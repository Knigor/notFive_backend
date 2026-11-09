<?php

declare(strict_types=1);

namespace App\GraphQL\Query;

use App\Digest\DigestRepository;
use App\Security\RequireAdmin;
use Overblog\GraphQLBundle\Definition\Resolver\QueryInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class RunStatusQuery implements QueryInterface
{
    public function __construct(private DigestRepository $repository, private RequireAdmin $requireAdmin, private TranslatorInterface $translator, private RequestStack $requestStack)
    {
    }

    /** @return array<string, mixed>|null */
    public function runStatus(string $runId): ?array
    {
        $this->requireAdmin->check();
        if (1 !== preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $runId)) {
            return null;
        }

        $locale = $this->requestStack->getCurrentRequest()?->getPreferredLanguage(['ru', 'en']) ?? 'ru';
        $status = $this->repository->status($runId, $locale);
        if (null !== $status && null !== $status['error']) {
            $status['error'] = $this->translator->trans('run_status.failed', locale: $locale);
        }

        return $status;
    }
}
