<?php

declare(strict_types=1);

namespace App\Controller;

use App\Mail\DigestEmailComposer;
use App\Mail\DigestEmailRequest;
use App\Mail\DigestEmailSender;
use OpenApi\Attributes as OA;
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
    #[OA\Post(
        summary: 'Preview a digest email (development only)',
        description: 'Renders the email without sending it. Available only in the dev environment.',
        tags: ['Development mail'],
        parameters: [new OA\Parameter(name: 'Accept-Language', in: 'header', description: 'Response language: ru or en; defaults to ru.', schema: new OA\Schema(type: 'string', enum: ['ru', 'en']))],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['subject', 'digest'],
                properties: [
                    new OA\Property(property: 'subject', type: 'string', maxLength: 200, example: 'Daily digest'),
                    new OA\Property(property: 'digest', type: 'string', maxLength: 20000, example: 'One verified event.'),
                    new OA\Property(property: 'recipient', type: 'string', format: 'email', description: 'Optional for preview.', example: 'reader@example.invalid'),
                ],
            ),
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Rendered email.',
                content: new OA\JsonContent(
                    required: ['subject', 'text', 'html'],
                    properties: [
                        new OA\Property(property: 'subject', type: 'string'),
                        new OA\Property(property: 'text', type: 'string'),
                        new OA\Property(property: 'html', type: 'string'),
                    ],
                ),
            ),
            new OA\Response(
                response: 400,
                description: 'Invalid content.',
                content: new OA\JsonContent(
                    required: ['error', 'violations'],
                    properties: [
                        new OA\Property(property: 'error', type: 'string'),
                        new OA\Property(
                            property: 'violations',
                            type: 'array',
                            items: new OA\Items(
                                required: ['field', 'message'],
                                properties: [
                                    new OA\Property(property: 'field', type: 'string'),
                                    new OA\Property(property: 'message', type: 'string'),
                                ],
                            ),
                        ),
                    ],
                ),
            ),
        ],
    )]
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
    #[OA\Post(
        summary: 'Send a digest email (development only)',
        description: 'Sends one email using the configured mail transport. Available only in the dev environment.',
        tags: ['Development mail'],
        parameters: [new OA\Parameter(name: 'Accept-Language', in: 'header', description: 'Response language: ru or en; defaults to ru.', schema: new OA\Schema(type: 'string', enum: ['ru', 'en']))],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['subject', 'digest', 'recipient'],
                properties: [
                    new OA\Property(property: 'subject', type: 'string', maxLength: 200, example: 'Daily digest'),
                    new OA\Property(property: 'digest', type: 'string', maxLength: 20000, example: 'One verified event.'),
                    new OA\Property(property: 'recipient', type: 'string', format: 'email', example: 'reader@example.invalid'),
                ],
            ),
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Email accepted by the configured mail transport.',
                content: new OA\JsonContent(
                    required: ['status'],
                    properties: [new OA\Property(property: 'status', type: 'string', example: 'sent')],
                ),
            ),
            new OA\Response(
                response: 400,
                description: 'Invalid request.',
                content: new OA\JsonContent(
                    required: ['error', 'violations'],
                    properties: [
                        new OA\Property(property: 'error', type: 'string'),
                        new OA\Property(
                            property: 'violations',
                            type: 'array',
                            items: new OA\Items(
                                required: ['field', 'message'],
                                properties: [
                                    new OA\Property(property: 'field', type: 'string'),
                                    new OA\Property(property: 'message', type: 'string'),
                                ],
                            ),
                        ),
                    ],
                ),
            ),
        ],
    )]
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
