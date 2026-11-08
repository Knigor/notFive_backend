<?php

declare(strict_types=1);

namespace App\Controller;

use App\Mail\DigestEmailComposer;
use App\Mail\DigestEmailRequest;
use App\Mail\DigestEmailSender;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\ConstraintViolationListInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class DigestEmailController
{
    public function __construct(
        private DigestEmailComposer $composer,
        private DigestEmailSender $sender,
        private ValidatorInterface $validator,
        private TranslatorInterface $translator,
    ) {
    }

    #[Route('/api/digest-email/preview', name: 'digest_email_preview', methods: ['POST'], env: 'dev')]
    public function preview(Request $request): JsonResponse
    {
        $draft = $this->readRequest($request);
        $locale = $this->locale($request);
        $violations = $this->validator->validate($draft);

        if (0 !== count($violations)) {
            return $this->invalidResponse($violations, $locale, 'digest_email.invalid_content');
        }

        $preview = $this->composer->compose($draft->subject, $draft->digest, $locale);

        return new JsonResponse([
            'subject' => $preview->subject,
            'text' => $preview->text,
            'html' => $preview->html,
        ], 200, ['Cache-Control' => 'no-store']);
    }

    #[Route('/api/digest-email/send', name: 'digest_email_send', methods: ['POST'], env: 'dev')]
    public function send(Request $request): JsonResponse
    {
        $draft = $this->readRequest($request);
        $locale = $this->locale($request);
        $violations = $this->validator->validate($draft, groups: ['Default', 'send']);

        if (0 !== count($violations)) {
            return $this->invalidResponse($violations, $locale, 'digest_email.invalid_request');
        }

        $this->sender->send($draft->recipient, $this->composer->compose($draft->subject, $draft->digest, $locale));

        return new JsonResponse(['status' => 'sent'], 200, ['Cache-Control' => 'no-store']);
    }

    private function readRequest(Request $request): DigestEmailRequest
    {
        $payload = $request->toArray();
        $subject = $payload['subject'] ?? null;
        $digest = $payload['digest'] ?? null;
        $recipient = $payload['recipient'] ?? null;

        return new DigestEmailRequest(
            is_string($subject) ? $subject : '',
            is_string($digest) ? $digest : '',
            is_string($recipient) ? $recipient : '',
        );
    }

    private function locale(Request $request): string
    {
        return $request->getPreferredLanguage(['ru', 'en']) ?? 'ru';
    }

    private function invalidResponse(ConstraintViolationListInterface $violations, string $locale, string $message): JsonResponse
    {
        $errors = [];

        foreach ($violations as $violation) {
            $errors[] = [
                'field' => $violation->getPropertyPath(),
                'message' => $this->translator->trans($violation->getMessageTemplate(), $violation->getParameters(), 'validators', $locale),
            ];
        }

        return new JsonResponse([
            'error' => $this->translator->trans($message, locale: $locale),
            'violations' => $errors,
        ], 400, ['Cache-Control' => 'no-store']);
    }
}
