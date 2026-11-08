<?php

declare(strict_types=1);

namespace App\Mail;

use Twig\Environment;

final readonly class DigestEmailComposer
{
    public function __construct(private Environment $twig)
    {
    }

    public function compose(string $subject, string $digest, string $locale = 'ru'): DigestEmailPreview
    {
        return new DigestEmailPreview(
            $subject,
            $digest,
            $this->twig->render('mail/digest.html.twig', ['subject' => $subject, 'digest' => $digest, 'locale' => $locale]),
        );
    }
}
