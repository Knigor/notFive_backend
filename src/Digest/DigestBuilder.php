<?php

declare(strict_types=1);

namespace App\Digest;

use App\Run\GoRunDispatcher;
use Doctrine\DBAL\Connection;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class DigestBuilder
{
    public function __construct(
        private DigestRepository $repository,
        private GoRunDispatcher $go,
        private DigestRenderer $renderer,
        private Connection $connection,
        private ValidatorInterface $validator,
        private TranslatorInterface $translator,
    ) {
    }

    /** @return array{runId: string, status: string, eventCount: int, warnings: list<string>} */
    public function build(string $runId): array
    {
        $run = $this->repository->run($runId);
        if (null === $run || !$this->repository->claim($runId)) {
            throw new \InvalidArgumentException('Run does not exist or cannot be built.');
        }

        try {
            $materials = $this->repository->materials($runId);
            $input = array_map(static fn (array $row): array => [
                'id' => (string) $row['input_id'],
                'title' => $row['title'],
                'text' => $row['body'],
                'source' => $row['source_name'],
                'url' => $row['url'],
                'publishedAt' => null === $row['published_at'] ? null : (new \DateTimeImmutable((string) $row['published_at']))->format(DATE_ATOM),
                'missing' => json_decode((string) $row['input_missing'], true, 512, JSON_THROW_ON_ERROR),
                'error' => $row['source_error'],
            ], $materials);
            $controlTime = (new \DateTimeImmutable((string) $run['control_time']))->format(DATE_ATOM);
            $result = GoDigestResult::fromArray($this->go->prepareDigest($controlTime, $input));
            if (0 !== count($this->validator->validate($result))) {
                throw new \UnexpectedValueException('Invalid Go digest response.');
            }

            return $this->connection->transactional(function () use ($runId, $materials, $result): array {
                $warnings = $result->warnings;
                $materialIds = [];
                foreach ($materials as $material) {
                    $inputId = (string) $material['input_id'];
                    if (isset($materialIds[$inputId])) {
                        throw new \UnexpectedValueException('Duplicate material ID in run.');
                    }
                    $materialIds[$inputId] = (string) $material['id'];
                }

                $groups = $this->groups($result, $materialIds, $warnings);
                $events = $this->events($result, $groups);
                foreach ($result->recommendedOrder as $index => $groupId) {
                    $group = $groups[$groupId];
                    $this->repository->saveEvent($runId, $index + 1, $group, $events[$groupId], array_intersect_key($materialIds, array_fill_keys($group['ids'], true)));
                }
                $this->decideMaterials($materials, $materialIds, $groups, $events, $result, $warnings);
                $rendered = $this->renderer->render($runId, $result->selectionSummary);
                $warnings = array_values(array_unique([...$warnings, ...$rendered['warnings']]));
                $this->repository->saveArtifacts($runId, $rendered['files'], $rendered['subject']);
                $this->repository->complete($runId, $result, $warnings);

                return ['runId' => $runId, 'status' => 'completed', 'eventCount' => count($result->recommendedOrder), 'warnings' => $warnings];
            });
        } catch (\Throwable $exception) {
            $this->repository->fail($runId, 'Digest build failed.');
            throw $exception;
        }
    }

    /** @param array<string, string> $materialIds
     * @param list<string> $warnings
     *
     * @return array<string, array<string, mixed>>
     */
    private function groups(GoDigestResult $result, array $materialIds, array &$warnings): array
    {
        $groups = [];
        foreach ($result->groups as $item) {
            $id = GoDigestResult::string($item, 'id');
            if ('' === $id || isset($groups[$id])) {
                throw new \UnexpectedValueException('Duplicate or empty group ID.');
            }
            $ids = GoDigestResult::strings($item, 'ids');
            foreach ($ids as $articleId) {
                if (!isset($materialIds[$articleId])) {
                    throw new \UnexpectedValueException('Group references unknown material.');
                }
            }
            $facts = GoDigestResult::records($item, 'facts');
            $normalizedFacts = [];
            foreach ($facts as $fact) {
                $sourceIds = GoDigestResult::strings($fact, 'sourceIds');
                foreach ($sourceIds as $articleId) {
                    if (!isset($materialIds[$articleId]) || !in_array($articleId, $ids, true)) {
                        throw new \UnexpectedValueException('Fact references unknown material.');
                    }
                }
                $evidence = GoDigestResult::records($fact, 'evidence');
                $normalizedEvidence = [];
                foreach ($evidence as $source) {
                    $articleId = GoDigestResult::string($source, 'articleId');
                    $quote = GoDigestResult::string($source, 'quote');
                    if (!isset($materialIds[$articleId]) || !in_array($articleId, $sourceIds, true)) {
                        throw new \UnexpectedValueException('Evidence references unknown material.');
                    }
                    if ('' !== trim($quote)) {
                        $normalizedEvidence[] = ['articleId' => $articleId, 'quote' => $quote];
                    } else {
                        $warnings[] = 'invalid_evidence:'.$id.':'.$articleId;
                    }
                }
                $normalizedFacts[] = [
                    'statement' => GoDigestResult::string($fact, 'statement'),
                    'sourceIds' => $sourceIds,
                    'evidence' => $normalizedEvidence,
                ];
            }
            foreach (GoDigestResult::records($item, 'contradictions') as $contradiction) {
                foreach (GoDigestResult::strings($contradiction, 'sourceIds') as $articleId) {
                    if (!isset($materialIds[$articleId]) || !in_array($articleId, $ids, true)) {
                        throw new \UnexpectedValueException('Contradiction references unknown material.');
                    }
                }
            }
            $groups[$id] = [
                'id' => $id, 'name' => GoDigestResult::string($item, 'name'),
                'ids' => array_values(array_unique($ids)),
                'facts' => $normalizedFacts,
                'selectionReason' => GoDigestResult::string($item, 'selectionReason'),
            ];
        }

        return $groups;
    }

    /** @param array<string, array<string, mixed>> $groups
     * @return array<string, array<string, mixed>>
     */
    private function events(GoDigestResult $result, array $groups): array
    {
        $events = [];
        foreach ($result->events as $item) {
            $id = GoDigestResult::string($item, 'groupId');
            if (isset($events[$id])) {
                throw new \UnexpectedValueException('Duplicate event text.');
            }
            $events[$id] = [
                'title' => GoDigestResult::string($item, 'title'),
                'summary' => GoDigestResult::string($item, 'summary'),
                'consequences' => GoDigestResult::string($item, 'consequences'),
                'post' => GoDigestResult::string($item, 'post'),
                'reason' => GoDigestResult::string($item, 'reason'),
            ];
        }
        if (count($result->recommendedOrder) !== count(array_unique($result->recommendedOrder))) {
            throw new \UnexpectedValueException('Duplicate selected group.');
        }
        foreach ($result->recommendedOrder as $id) {
            if (!isset($groups[$id], $events[$id]) || [] === $groups[$id]['ids']) {
                throw new \UnexpectedValueException('Selected group has no source group or text.');
            }
        }

        return $events;
    }

    /** @param list<array<string, mixed>> $materials
     * @param array<string, string>               $materialIds
     * @param array<string, array<string, mixed>> $groups
     * @param array<string, array<string, mixed>> $events
     * @param list<string>                        $warnings
     */
    private function decideMaterials(array $materials, array $materialIds, array $groups, array $events, GoDigestResult $result, array &$warnings): void
    {
        $excluded = [];
        foreach ($result->excluded as $item) {
            $articleId = GoDigestResult::string($item, 'articleId');
            if (!isset($materialIds[$articleId])) {
                throw new \UnexpectedValueException('Exclusion references unknown material.');
            }
            $excluded[$articleId] = GoDigestResult::string($item, 'reason');
        }
        foreach ($materials as $material) {
            $inputId = (string) $material['input_id'];
            $labels = [];
            $selected = false;
            $primary = false;
            $reasons = [];
            foreach ($groups as $id => $group) {
                if (!in_array($inputId, $group['ids'], true)) {
                    continue;
                }
                $labels[] = $id.' '.$group['name'];
                if (isset($events[$id]) && in_array($id, $result->recommendedOrder, true)) {
                    $selected = true;
                    $primary = $primary || ($group['ids'][0] ?? null) === $inputId;
                    $reasons[] = $events[$id]['reason'];
                }
            }
            $status = $selected ? ($primary ? 'included' : 'merged') : 'excluded';
            $reason = $selected ? implode(' ', array_unique($reasons)) : ($excluded[$inputId] ?? $this->translator->trans('digest_export.not_selected', locale: 'ru'));
            if ($selected && isset($excluded[$inputId])) {
                $warnings[] = 'inclusion_exclusion_conflict:'.$inputId;
            }
            $error = $material['source_error'];
            $missing = json_decode((string) $material['input_missing'], true, 512, JSON_THROW_ON_ERROR);
            if ([] !== $missing) {
                $error = trim($error.' missing: '.implode(', ', $missing));
            }
            $this->repository->decideMaterial((string) $material['id'], $status, [] === $labels ? null : implode('; ', $labels), $reason, $error);
        }
    }
}
