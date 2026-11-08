<?php

declare(strict_types=1);

namespace App\GraphQL\Query;

use App\Digest\DigestView;
use App\Digest\DigestViewRepository;
use Overblog\GraphQLBundle\Definition\Resolver\QueryInterface;
use Overblog\GraphQLBundle\Error\UserError;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class DigestQuery implements QueryInterface
{
    public function __construct(
        private DigestViewRepository $repository,
        private TranslatorInterface $translator,
        private RequestStack $requestStack,
    ) {
    }

    /** @return list<string> */
    public function publishedDigestDates(): array
    {
        return $this->repository->publishedDates();
    }

    public function digest(string $date): ?DigestView
    {
        $day = false;
        if (1 === preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $date) && checkdate((int) substr($date, 5, 2), (int) substr($date, 8, 2), (int) substr($date, 0, 4))) {
            $day = \DateTimeImmutable::createFromFormat('!Y-m-d', $date, new \DateTimeZone('Europe/Moscow'));
        }
        if (false === $day || $day->format('Y-m-d') !== $date) {
            $locale = $this->requestStack->getCurrentRequest()?->getPreferredLanguage(['ru', 'en']) ?? 'ru';
            throw new UserError($this->translator->trans('digest_query.invalid_date', locale: $locale));
        }

        return $this->repository->publishedForDate($day);
    }
}
