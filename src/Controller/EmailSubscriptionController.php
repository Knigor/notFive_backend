<?php

declare(strict_types=1);

namespace App\Controller;

use App\Mail\EmailSubscriptionLinks;
use App\Mail\EmailSubscriptionRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final readonly class EmailSubscriptionController
{
    public function __construct(
        private EmailSubscriptionRepository $subscriptions,
        private EmailSubscriptionLinks $links,
        private TranslatorInterface $translator,
        private Environment $twig,
    ) {
    }

    #[Route('/email/confirm', name: 'email_subscription_confirm', methods: ['GET', 'POST'])]
    public function confirm(Request $request): Response
    {
        $token = $this->token($request);

        if ($request->isMethod('POST')) {
            return $this->page($request, $this->subscriptions->confirm($token) ? 'confirmed' : 'invalid');
        }

        $state = $this->subscriptions->confirmationState($token);

        return match ($state) {
            'pending' => $this->page($request, 'confirm', $this->links->confirm($token)),
            'active' => $this->page($request, 'confirmed'),
            default => $this->page($request, 'invalid'),
        };
    }

    #[Route('/email/unsubscribe', name: 'email_subscription_unsubscribe', methods: ['GET', 'POST'])]
    public function unsubscribe(Request $request): Response
    {
        $token = $this->token($request);

        if ($request->isMethod('POST')) {
            return $this->page($request, $this->subscriptions->unsubscribe($token) ? 'unsubscribed' : 'invalid');
        }

        $state = $this->subscriptions->unsubscribeState($token);

        return match ($state) {
            'unsubscribed' => $this->page($request, 'unsubscribed'),
            'invalid' => $this->page($request, 'invalid'),
            default => $this->page($request, 'unsubscribe', $this->links->unsubscribe($token)),
        };
    }

    private function token(Request $request): string
    {
        $parameters = $request->query->all();
        $token = $parameters['token'] ?? null;

        return is_string($token) ? $token : '';
    }

    private function page(Request $request, string $state, ?string $actionUrl = null): Response
    {
        $locale = $request->getPreferredLanguage(['ru', 'en']) ?? 'ru';
        $title = $this->translator->trans('email_subscription.page.'.$state.'_title', locale: $locale);
        $body = $this->translator->trans('email_subscription.page.'.$state.'_body', locale: $locale);
        $button = null === $actionUrl ? null : $this->translator->trans('email_subscription.page.'.$state.'_button', locale: $locale);

        return new Response($this->twig->render('mail/subscription_page.html.twig', [
            'locale' => $locale,
            'title' => $title,
            'body' => $body,
            'action_url' => $actionUrl,
            'button' => $button,
        ]), 'invalid' === $state ? Response::HTTP_BAD_REQUEST : Response::HTTP_OK, [
            'Cache-Control' => 'no-store',
            'Referrer-Policy' => 'no-referrer',
            'Content-Language' => $locale,
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'",
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
