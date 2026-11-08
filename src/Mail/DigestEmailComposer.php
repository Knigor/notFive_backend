<?php

declare(strict_types=1);

namespace App\Mail;

use Twig\Environment;

final readonly class DigestEmailComposer
{
    public function __construct(private Environment $twig)
    {
    }

    public function compose(
        string $subject,
        string $digest,
        string $locale = 'ru',
        ?string $unsubscribeUrl = null,
        ?string $unsubscribeLabel = null,
    ): DigestEmailPreview {
        return new DigestEmailPreview(
            $subject,
            null === $unsubscribeUrl ? $digest : $digest."\n\n".$unsubscribeLabel.': '.$unsubscribeUrl,
            $this->twig->render('mail/digest.html.twig', [
                'subject' => $subject,
                'sections' => $this->sections($subject, $digest),
                'locale' => $locale,
                'unsubscribe_url' => $unsubscribeUrl,
                'unsubscribe_label' => $unsubscribeLabel,
            ]),
        );
    }

    /** @return list<array{number: ?string, title: ?string, lines: list<array{label: ?string, parts: list<string>}>}> */
    private function sections(string $subject, string $digest): array
    {
        $sections = [];
        $section = ['number' => null, 'title' => null, 'lines' => []];
        foreach (preg_split('/\R/u', $digest) ?: [] as $line) {
            $line = trim($line);
            if ('' === $line || 'Тема: '.$subject === $line) {
                continue;
            }
            if (1 === preg_match('/^##\h+(\d+)\.\h+(.+)$/u', $line, $heading)) {
                if (null !== $section['title'] || [] !== $section['lines']) {
                    $sections[] = $section;
                }
                $section = ['number' => $heading[1], 'title' => $heading[2], 'lines' => []];

                continue;
            }

            $line = preg_replace('/^#\h+/u', '', $line) ?? $line;
            $label = null;
            if (1 === preg_match('/^(Период|Контрольное время|Суть|Последствия|Источники|Обоснование):\h*(.*)$/u', $line, $field)) {
                $label = $field[1];
                $line = $field[2];
            }
            // Odd entries are URLs. Twig escapes both the link text and the attribute.
            $parts = preg_split('~(https?://[^\s<>"]+?(?=[,;]?(?:\s|$)))~iu', $line, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$line];
            $section['lines'][] = ['label' => $label, 'parts' => $parts];
        }
        if (null !== $section['title'] || [] !== $section['lines']) {
            $sections[] = $section;
        }

        return $sections;
    }
}
