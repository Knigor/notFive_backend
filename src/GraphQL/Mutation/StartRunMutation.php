<?php

declare(strict_types=1);

namespace App\GraphQL\Mutation;

use App\Dataset\DatasetImportException;
use App\Run\RunStarter;
use App\Security\RequireAdmin;
use Overblog\GraphQLBundle\Definition\Resolver\MutationInterface;
use Overblog\GraphQLBundle\Error\UserError;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class StartRunMutation implements MutationInterface
{
    public function __construct(private RunStarter $starter, private TranslatorInterface $translator, private RequestStack $requestStack, private RequireAdmin $requireAdmin)
    {
    }

    /** @param array{file?: mixed, sourceIds?: list<string>, controlTime?: string} $input
     * @return array{runId: string, imported: int, rejected: int, siteCount: int, inputKind: string, dispatchStatus: string, status: string}
     */
    public function startRun(array $input): array
    {
        $this->requireAdmin->check();

        $file = $input['file'] ?? null;
        $locale = $this->requestStack->getCurrentRequest()?->getPreferredLanguage(['ru', 'en']) ?? 'ru';

        if (null !== $file && !$file instanceof UploadedFile) {
            throw new UserError($this->translator->trans('run_start.invalid_file', locale: $locale));
        }

        try {
            return $this->starter->start($file, $input['sourceIds'] ?? [], $input['controlTime'] ?? null);
        } catch (DatasetImportException $exception) {
            throw new UserError($this->translator->trans('dataset_import.error.'.$exception->reason, locale: $locale), previous: $exception);
        } catch (\InvalidArgumentException $exception) {
            throw new UserError($this->translator->trans('run_start.'.$exception->getMessage(), locale: $locale), previous: $exception);
        }
    }
}
