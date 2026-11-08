<?php

declare(strict_types=1);

namespace App\Telegram;

use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class TelegramMessages
{
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

    public function digest(PublishedDigest $digest, string $locale): string
    {
        return $this->shorten($digest->content, 3500, $locale);
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
