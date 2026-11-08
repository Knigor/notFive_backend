<?php

declare(strict_types=1);

namespace App\GraphQL\Mutation;

use App\Security\AdminAuthenticator;
use Overblog\GraphQLBundle\Definition\Resolver\MutationInterface;
use Overblog\GraphQLBundle\Error\UserError;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class AdminLoginMutation implements MutationInterface
{
    public function __construct(
        private AdminAuthenticator $authenticator,
        private TranslatorInterface $translator,
        private RequestStack $requestStack,
    ) {
    }

    /** @param array{username: string, password: string} $input
     * @return array{token: string, expiresIn: int}
     */
    public function login(array $input): array
    {
        try {
            return $this->authenticator->login($input['username'], $input['password']);
        } catch (\InvalidArgumentException $exception) {
            $locale = $this->requestStack->getCurrentRequest()?->getPreferredLanguage(['ru', 'en']) ?? 'ru';
            throw new UserError($this->translator->trans('auth.'.$exception->getMessage(), locale: $locale), previous: $exception);
        }
    }
}
