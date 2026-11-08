<?php

declare(strict_types=1);

namespace App\Mail;

use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final readonly class EmailSubscriptionComposer
{
    public function __construct(
        private TranslatorInterface $translator,
        private Environment $twig,
        private EmailSubscriptionLinks $links,
    ) {
    }

    public function confirmation(string $token, string $locale): DigestEmailPreview
    {
        $url = $this->links->confirm($token);
        $subject = $this->translator->trans('email_subscription.confirmation.subject', locale: $locale);
        $body = $this->translator->trans('email_subscription.confirmation.body', ['%url%' => $url], locale: $locale);
        $action = $this->translator->trans('email_subscription.confirmation.action', locale: $locale);

        return new DigestEmailPreview(
            $subject,
            $body,
            $this->twig->render('mail/subscription.html.twig', [
                'locale' => $locale,
                'subject' => $subject,
                'body' => $body,
                'action' => $action,
                'url' => $url,
            ]),
        );
    }
}
