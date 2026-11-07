<?php

declare(strict_types=1);

namespace App\Controller;

use App\Mail\DigestEmailComposer;
use App\Mail\DigestEmailRequest;
use App\Mail\DigestEmailSender;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final readonly class DigestEmailController
{
    public function __construct(
        private DigestEmailComposer $composer,
        private DigestEmailSender $sender,
        private ValidatorInterface $validator,
    ) {
    }

    #[Route('/api/digest-email/preview', name: 'digest_email_preview', methods: ['POST'], env: 'dev')]
    public function preview(Request $request): JsonResponse
    {
        $draft = $this->readRequest($request);

        if (0 !== count($this->validator->validate($draft))) {
            return new JsonResponse(['error' => 'Invalid digest email content.'], 400, ['Cache-Control' => 'no-store']);
        }

        $preview = $this->composer->compose($draft->subject, $draft->digest);

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

        if (0 !== count($this->validator->validate($draft, groups: ['Default', 'send']))) {
            return new JsonResponse(['error' => 'Invalid digest email request.'], 400, ['Cache-Control' => 'no-store']);
        }

        $this->sender->send($draft->recipient, $this->composer->compose($draft->subject, $draft->digest));

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
}
