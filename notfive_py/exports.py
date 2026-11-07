import csv
from datetime import datetime
from html import escape
import io
import re
import unicodedata


ARTIFACT_TYPES = {
    'digest.md': 'text/markdown', 'post.md': 'text/markdown',
    'email.html': 'text/html', 'journal.csv': 'text/csv',
}


class ExportError(Exception):
    pass


def clean(value: str) -> str:
    return ' '.join(value.replace('—', '-').split())


def markdown(value: str) -> str:
    return re.sub(r'([\\`*_[\]])', r'\\\1', escape(clean(value), quote=False))


def date_label(value: str) -> str:
    return datetime.fromisoformat(value).strftime('%d.%m.%Y %H:%M:%S')


def source_label(source: dict) -> str:
    return f'{source["source"]}, {date_label(source["published_at"])} МСК, ID {source["id"]}'


def visible_length(markdown_text: str) -> int:
    without_urls = re.sub(r'(?<=<)https?://[^>]+', '', markdown_text)
    return len(without_urls)


def csv_cell(value: str) -> str:
    probe = unicodedata.normalize('NFKC', value)
    first = next((char for char in probe if not char.isspace() and unicodedata.category(char) not in ('Cc', 'Cf')), '')
    if first in ('=', '+', '-', '@', '−') or value.startswith(('\t', '\r', '\n')):
        value = "'" + value
    return value.replace('\r\n', '\n').replace('\r', '\n')


def render_journal(journal: list[dict]) -> str:
    buffer = io.StringIO(newline='')
    fields = ('id', 'status', 'group', 'reason', 'error')
    writer = csv.DictWriter(buffer, fields, lineterminator='\n')
    writer.writeheader()
    writer.writerows({field: csv_cell(row[field]) for field in fields} for row in journal)
    return buffer.getvalue()


def render_artifacts(result: dict) -> dict[str, str]:
    period = result['period']
    start, end = (date_label(period[key]) for key in ('start_exclusive', 'end_inclusive'))
    period_label = f'({start}, {end}] МСК'
    subject = f'Дайджест ИБ за {period_label}'
    heading = f'# {subject}. Контрольное время {end} МСК'
    count = result['event_count']
    if result['outcome'] == 'partial':
        summary = f'В выпуск вошло событий: {count}. Для части материалов обработка или проверка не завершилась. Подробности в журнале.'
    elif not count:
        summary = 'За выбранный период подходящих событий не найдено.'
    elif count < 5:
        summary = f'Подходящих событий с подтверждающими материалами найдено: {count}.'
    else:
        summary = 'В выпуск вошли пять событий с наивысшим приоритетом.'

    digest = [heading, summary]
    post = [f'# Дайджест ИБ · {end} МСК', summary]
    email = [f'<h1>{escape(subject)}</h1>', f'<p>Контрольное время {end} МСК</p>', f'<p>{escape(summary)}</p>']
    for event in result['events']:
        title = f'{event["rank"]}. {event["title"]}'
        full_links = ', '.join(f'[{markdown(source_label(source))}](<{source["url"]}>)' for source in event['sources'])
        short_links = ' '.join(f'[{index}](<{source["url"]}>)' for index, source in enumerate(event['sources'], 1))
        digest.append('\n'.join((
            f'## {markdown(title)}', '', f'Суть: {markdown(event["summary"])}',
            f'Последствия: {markdown(event["consequences"])}', f'Источники: {full_links}',
            f'Обоснование: {markdown(event["reason"])}',
        )))
        post.append(f'**{markdown(title)}**\n{markdown(event["post"])}\nИсточники: {short_links}')
        html_links = ', '.join(
            f'<a href="{escape(source["url"], quote=True)}">{escape(source_label(source))}</a>'
            for source in event['sources']
        )
        email.append(
            f'<h2>{escape(clean(title))}</h2><p><b>Суть:</b> {escape(clean(event["summary"]))}</p>'
            f'<p><b>Последствия:</b> {escape(clean(event["consequences"]))}</p><p><b>Источники:</b> {html_links}</p>'
            f'<p><b>Обоснование:</b> {escape(clean(event["reason"]))}</p>'
        )

    digest_text = '\n\n'.join(digest) + '\n'
    post_text = '\n\n'.join(post) + '\n'
    if visible_length(digest_text) > 4000 or visible_length(post_text) > 2000:
        raise ExportError('Выпуск превысил лимит дайджеста или поста. Проверь длину текста и списка источников.')

    return {
        'digest.md': digest_text, 'post.md': post_text, 'journal.csv': render_journal(result['journal']),
        'email.html': '<!doctype html><html lang="ru"><meta charset="utf-8">'
                      f'<title>{escape(subject)}</title><body>{"".join(email)}</body></html>\n',
    }
