<?php

declare(strict_types=1);

namespace App\GraphQL\Mutation;

use App\Mail\EmailSubscriptionException;
use App\Mail\EmailSubscriptionService;
use Overblog\GraphQLBundle\Definition\Resolver\MutationInterface;
use Overblog\GraphQLBundle\Error\UserError;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class EmailSubscriptionMutation implements MutationInterface
{
    public function __construct(
        private EmailSubscriptionService $subscriptions,
        private TranslatorInterface $translator,
        private RequestStack $requestStack,
    ) {
    }

    /** @return array{status: string, message: string} */
    public function subscribeEmail(string $email): array
    {
        $request = $this->requestStack->getCurrentRequest();
        $locale = $request?->getPreferredLanguage(['ru', 'en']) ?? 'ru';

        try {
            $this->subscriptions->subscribe($email, $locale, $request?->getClientIp() ?? '');
        } catch (EmailSubscriptionException $exception) {
            throw new UserError($this->translator->trans('email_subscription.'.$exception->getMessage(), locale: $locale));
        }

        return [
            'status' => 'accepted',
            'message' => $this->translator->trans('email_subscription.accepted', locale: $locale),
        ];
    }
}
