<?php

declare(strict_types=1);

namespace App\GraphQL\Mutation;

use App\Digest\DigestPublisher;
use App\Security\RequireAdmin;
use Overblog\GraphQLBundle\Definition\Resolver\MutationInterface;
use Overblog\GraphQLBundle\Error\UserError;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class PublishDigestMutation implements MutationInterface
{
    public function __construct(
        private DigestPublisher $publisher,
        private RequireAdmin $requireAdmin,
        private TranslatorInterface $translator,
        private RequestStack $requestStack,
    ) {
    }

    /** @return array{runId: string, publishedAt: string, alreadyPublished: bool, emailSent: int, emailFailed: int, emailPending: int} */
    public function publishDigest(string $runId): array
    {
        $this->requireAdmin->check();
        try {
            return $this->publisher->publish($runId);
        } catch (\InvalidArgumentException $exception) {
            $locale = $this->requestStack->getCurrentRequest()?->getPreferredLanguage(['ru', 'en']) ?? 'ru';
            throw new UserError($this->translator->trans('digest_publish.'.$exception->getMessage(), locale: $locale), previous: $exception);
        }
    }
}
