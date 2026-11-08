<?php

declare(strict_types=1);

namespace App\Telegram;

use App\Digest\PublishedDigest;
use App\Digest\PublishedEvent;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class TelegramMessages
{
    private const DIGEST_PART_LIMIT = 3800;
    private const EVENT_HEADING = '(?:##\h+)?(\d+)\.\h+(\S[^\n]*)';

    public function __construct(private TranslatorInterface $translator)
    {
    }

    public function home(string $locale, string $status): string
    {
        $subscription = $this->translate('home.'.('active' === $status ? 'active' : 'inactive'), $locale);

        return $this->translate('home.body', $locale, ['%subscription%' => $subscription]);
    }

    public function settings(string $locale, string $status): string
    {
        $state = $this->translate('settings.'.('active' === $status ? 'active' : 'inactive'), $locale);

        return $this->translate('settings.body', $locale, ['%status%' => $state]);
    }

    public function noDigest(string $locale): string
    {
        return $this->translate('no_digest', $locale);
    }

    public function noEvent(string $locale): string
    {
        return $this->translate('no_event', $locale);
    }

    public function profileDescription(string $locale): string
    {
        return $this->translate('profile.description', $locale);
    }

    public function profileShortDescription(string $locale): string
    {
        return $this->translate('profile.short_description', $locale);
    }

    /** @return non-empty-list<string> */
    public function digest(PublishedDigest $digest, string $locale): array
    {
        $text = trim(str_replace(["\r\n", "\r"], "\n", $digest->content));
        if (null !== $digest->subject && '' !== trim($digest->subject)) {
            $subjectLine = 'Тема: '.$digest->subject."\n";
            if (str_starts_with($text, $subjectLine)) {
                $text = ltrim(substr($text, strlen($subjectLine)));
            }
            $text = '# '.$digest->subject."\n\n".$text;
        }
        if ('' === trim($text)) {
            return [$this->digestHtml($this->noDigest($locale))];
        }

        $text = "# 🟩 NOTFIVE / DIGEST\n\n".trim($text);
        $sections = preg_split('/\n+(?='.self::EVENT_HEADING.')/u', $text) ?: [$text];
        if (count($sections) > 1) {
            $intro = array_shift($sections);
            $sections[0] = $intro."\n\n".$sections[0];
        }

        $parts = [];
        foreach ($sections as $section) {
            foreach ($this->splitOversizedSection(trim($section)) as $block) {
                $parts[] = $this->digestHtml($block);
            }
        }

        return $parts ?: [$this->digestHtml($text)];
    }

    /** @return iterable<string> */
    private function splitOversizedSection(string $section): iterable
    {
        while ($this->digestLength($section) > self::DIGEST_PART_LIMIT) {
            $utf16 = mb_convert_encoding($section, 'UTF-16LE', 'UTF-8');
            $prefix = mb_convert_encoding(mb_strcut($utf16, 0, 2 * self::DIGEST_PART_LIMIT, 'UTF-16LE'), 'UTF-8', 'UTF-16LE');
            $split = mb_strrpos($prefix, "\n") ?: mb_strrpos($prefix, ' ');
            $block = false === $split || 0 === $split ? $prefix : mb_substr($prefix, 0, $split);
            yield rtrim($block);
            $section = ltrim(mb_substr($section, mb_strlen($block)));
        }
        yield $section;
    }

    private function digestLength(string $text): int
    {
        return intdiv(strlen(mb_convert_encoding($text, 'UTF-16LE', 'UTF-8')), 2);
    }

    private function digestHtml(string $text): string
    {
        $escaped = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $numbered = preg_replace_callback(
            '/^'.self::EVENT_HEADING.'$/mu',
            static fn (array $heading): string => '<b>'.str_pad($heading[1], 2, '0', STR_PAD_LEFT)."</b>\n<b>".$heading[2].'</b>',
            $escaped,
        ) ?? $escaped;
        $headings = preg_replace('/^#{1,6}\h+(.+)$/m', '<b>$1</b>', $numbered) ?? $numbered;

        return preg_replace('/^(Суть|Последствия|Источники|Обоснование|Период|Контрольное время):/mu', '<b>$1:</b>', $headings) ?? $headings;
    }

    public function event(PublishedEvent $event, string $locale): string
    {
        $text = $this->translate('event.body', $locale, [
            '%rank%' => (string) $event->rank,
            '%title%' => $event->title,
            '%summary%' => $event->summary,
            '%consequences%' => $event->consequences,
        ]);

        if ([] !== $event->sources) {
            $text .= "\n\n".$this->translate('event.sources', $locale)."\n".implode("\n", $event->sources);
        }

        return $this->shorten($text, 3800, $locale);
    }

    /** @return list<list<array{ text: string, callback_data: string, style?: 'primary'|'success'|'danger' }>> */
    public function homeButtons(string $locale, string $status): array
    {
        $subscription = 'active' === $status
            ? ['text' => $this->translate('button.pause', $locale), 'callback_data' => 'pause', 'style' => 'danger']
            : ['text' => $this->translate('button.subscribe', $locale), 'callback_data' => 'subscribe', 'style' => 'primary'];

        return [
            [['text' => $this->translate('button.latest', $locale), 'callback_data' => 'latest', 'style' => 'success']],
            [$subscription],
            [['text' => $this->translate('button.settings', $locale), 'callback_data' => 'settings']],
        ];
    }

    /** @return list<list<array{ text: string, callback_data: string, style?: 'primary'|'success'|'danger' }>> */
    public function settingsButtons(string $locale, string $status): array
    {
        return [
            [
                ['text' => $this->translate('button.language_ru', $locale), 'callback_data' => 'lang:ru', 'style' => 'ru' === $locale ? 'success' : 'primary'],
                ['text' => $this->translate('button.language_en', $locale), 'callback_data' => 'lang:en', 'style' => 'en' === $locale ? 'success' : 'primary'],
            ],
            ['active' === $status
                ? ['text' => $this->translate('button.pause', $locale), 'callback_data' => 'pause', 'style' => 'danger']
                : ['text' => $this->translate('button.resume', $locale), 'callback_data' => 'subscribe', 'style' => 'primary']],
            [['text' => $this->translate('button.back', $locale), 'callback_data' => 'home']],
        ];
    }

    /** @return list<list<array{ text: string, callback_data: string, style?: 'primary'|'success'|'danger' }>> */
    public function digestButtons(PublishedDigest $digest, string $locale): array
    {
        $buttons = [];

        foreach ($digest->events as $event) {
            $buttons[] = [[
                'text' => $this->translate('button.event', $locale, ['%rank%' => (string) $event->rank]),
                'callback_data' => 'event:'.$event->id,
                'style' => 'primary',
            ]];
        }

        $buttons[] = [['text' => $this->translate('button.back', $locale), 'callback_data' => 'home']];

        return $buttons;
    }

    /** @return list<list<array{ text: string, callback_data: string, style?: 'primary'|'success'|'danger' }>> */
    public function backButton(string $locale): array
    {
        return [[['text' => $this->translate('button.back_to_digest', $locale), 'callback_data' => 'latest']]];
    }

    /** @return list<list<array{ text: string, callback_data: string, style?: 'primary'|'success'|'danger' }>> */
    public function homeButton(string $locale): array
    {
        return [[['text' => $this->translate('button.back', $locale), 'callback_data' => 'home']]];
    }

    /** @param array<string, string> $parameters */
    private function translate(string $key, string $locale, array $parameters = []): string
    {
        return $this->translator->trans('telegram.'.$key, $parameters, 'messages', $locale);
    }

    private function shorten(string $text, int $length, string $locale): string
    {
        if (grapheme_strlen($text) <= $length) {
            return $text;
        }

        $short = grapheme_substr($text, 0, $length);

        return (false === $short ? '' : $short)."\n\n".$this->translate('text_shortened', $locale);
    }
}
