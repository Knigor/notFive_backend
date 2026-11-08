<?php

declare(strict_types=1);

namespace App\Security;

use Overblog\GraphQLBundle\Error\UserError;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class RequireAdmin
{
    public function __construct(
        private AuthorizationCheckerInterface $authorizationChecker,
        private TranslatorInterface $translator,
        private RequestStack $requestStack,
    ) {
    }

    public function check(): void
    {
        if ($this->authorizationChecker->isGranted('ROLE_ADMIN')) {
            return;
        }

        $locale = $this->requestStack->getCurrentRequest()?->getPreferredLanguage(['ru', 'en']) ?? 'ru';
        throw new UserError($this->translator->trans('auth.forbidden', locale: $locale));
    }
}
