<?php

declare(strict_types=1);

namespace App\Digest;

use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class DigestRenderer
{
    public function __construct(private DigestRepository $repository, private TranslatorInterface $translator)
    {
    }

    /** @return array{files: array<string, string>, subject: string, warnings: list<string>} */
    public function render(string $runId, string $selectionSummary): array
    {
        $run = $this->repository->run($runId) ?? throw new \InvalidArgumentException('Unknown run.');
        $control = new \DateTimeImmutable((string) $run['control_time']);
        $moscow = new \DateTimeZone('Europe/Moscow');
        $end = $control->setTimezone($moscow)->format('d.m.Y H:i');
        $start = $control->modify('-24 hours')->setTimezone($moscow)->format('d.m.Y H:i');
        $period = $start.' - '.$end.' '.$this->trans('digest_export.msk');
        $events = $this->repository->events($runId);
        $count = count($events);
        $intro = match ($count) {
            0 => $this->trans('digest_export.no_events'),
            1 => $this->trans('digest_export.count_one'),
            2, 3, 4 => $this->trans('digest_export.count_few', ['%count%' => $count]),
            default => $this->trans('digest_export.count_many', ['%count%' => $count]),
        };
        if ($count < 5 && '' !== trim($selectionSummary)) {
            $intro .= ' '.self::plain($selectionSummary);
        }
        $digest = '# '.$this->trans('digest_export.heading', ['%period%' => $period])."\n\n";
        $digest .= $this->trans('digest_export.control_time', ['%time%' => $end])."\n\n".$intro."\n\n";
        $post = $this->trans('digest_export.post_heading', ['%period%' => $period])."\n\n".$intro."\n\n";

        foreach ($events as $event) {
            $sources = [];
            $links = [];
            foreach ($this->repository->eventSources((string) $event['id']) as $source) {
                $url = (string) ($source['url'] ?? '');
                if (!self::validUrl($url)) {
                    continue;
                }
                $date = null === $source['published_at'] ? $this->trans('digest_export.unknown_date') : (new \DateTimeImmutable((string) $source['published_at']))->setTimezone($moscow)->format('d.m.Y H:i');
                $sources[] = self::plain((string) ($source['source_name'] ?? '')).', '.$date.', '.$url.', ID '.self::plain((string) $source['input_id']);
                $links[] = $url;
            }
            $digest .= '## '.$event['rank'].'. '.self::plain((string) $event['title'])."\n";
            $digest .= $this->trans('digest_export.essence').': '.self::plain((string) $event['summary'])."\n";
            $digest .= $this->trans('digest_export.consequences').': '.self::plain((string) $event['consequences'])."\n";
            $digest .= $this->trans('digest_export.sources').': '.implode('; ', $sources)."\n";
            $digest .= $this->trans('digest_export.reason').': '.self::plain((string) $event['selection_reason'])."\n\n";
            $post .= $event['rank'].'. '.self::plain((string) $event['title'])."\n";
            $post .= self::plain((string) ($event['post_text'] ?? $event['summary']))."\n";
            $post .= implode("\n", array_unique($links))."\n\n";
        }

        $digest = trim($digest)."\n";
        $post = trim($post)."\n";
        $subject = $this->trans('digest_export.subject', ['%time%' => $end]);
        $email = $this->trans('digest_export.email_subject').': '.$subject."\n";
        $email .= $this->trans('digest_export.period').': '.$period."\n\n".$digest;
        $journal = $this->journal($runId);
        $warnings = [];
        foreach (['digest.md' => [$digest, 4000], 'post.md' => [$post, 2000]] as $name => [$content, $limit]) {
            if ($this->visibleLength($content) > $limit) {
                $warnings[] = $this->trans('digest_export.length_warning', ['%file%' => $name, '%limit%' => $limit]);
            }
        }

        return ['files' => ['digest.md' => $digest, 'post.md' => $post, 'email.md' => $email, 'journal.csv' => $journal], 'subject' => $subject, 'warnings' => $warnings];
    }

    private function journal(string $runId): string
    {
        $stream = fopen('php://temp', 'w+');
        if (false === $stream) {
            throw new \RuntimeException('Cannot create journal.');
        }
        fwrite($stream, "\xEF\xBB\xBF");
        fputcsv($stream, ['id', 'status', 'group', 'reason', 'error'], ';', '"', '');
        foreach ($this->repository->materials($runId) as $material) {
            $status = (string) ($material['journal_status'] ?? 'excluded');
            fputcsv($stream, [
                $material['input_id'], $this->trans('digest_export.status.'.$status),
                $material['journal_group'] ?? '', $material['journal_reason'] ?? '',
                $material['journal_error'] ?? $material['source_error'] ?? '',
            ], ';', '"', '');
        }
        foreach ($this->repository->importErrors($runId) as $error) {
            fputcsv($stream, [
                $error['input_id'] ?? '', $this->trans('digest_export.status.excluded'), '',
                $this->trans('digest_export.import_error'), $error['error'],
            ], ';', '"', '');
        }
        rewind($stream);
        $content = stream_get_contents($stream);
        fclose($stream);

        return false === $content ? '' : $content;
    }

    /** @param array<string, scalar> $parameters */
    private function trans(string $key, array $parameters = []): string
    {
        return $this->translator->trans($key, $parameters, locale: 'ru');
    }

    private static function validUrl(string $url): bool
    {
        $scheme = parse_url($url, PHP_URL_SCHEME);

        return in_array($scheme, ['http', 'https'], true) && false !== filter_var($url, FILTER_VALIDATE_URL);
    }

    private static function plain(string $text): string
    {
        $text = str_replace(['—', '―'], '-', $text);
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim(strip_tags($text));
    }

    private function visibleLength(string $text): int
    {
        $text = preg_replace('~https?://[^\s<>]+~u', '', $text) ?? $text;
        $text = preg_replace('/(?m)^\s*#+\s*/u', '', $text) ?? $text;
        $text = str_replace(['**', '__', "\r", "\n"], '', $text);

        $length = iconv_strlen($text, 'UTF-8');

        if (false === $length) {
            throw new \UnexpectedValueException('Invalid UTF-8 in digest text.');
        }

        return $length;
    }
}
