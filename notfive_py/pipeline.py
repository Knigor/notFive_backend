from collections import Counter
from datetime import datetime, timedelta, timezone
import re
from typing import Callable

from .domain import Article, RunInput, parse_time, url_has_credentials, valid_url
from .exports import render_artifacts
from .fingerprints import json_sha256, run_input_data
from .prompts import PROMPT_VERSION
from .provider import ProviderError, YandexProvider


CVE_PATTERN = re.compile(r'\bCVE-\d{4}-\d{4,}\b', re.IGNORECASE)
MAX_RELATED_FACTS = 6


def quoted_span(text: str, evidence: str) -> tuple[str, str] | None:
    evidence = evidence.strip()
    if not evidence:
        return None
    start = text.find(evidence)
    if start < 0:
        matches = list(re.finditer(re.escape(evidence), text, re.IGNORECASE))
        if len(matches) != 1:
            return None
        start, end = matches[0].span()
    else:
        end = start + len(evidence)
    return text[start:end], text[max(0, start - 140):end + 140]


def input_problem(article: Article, start: datetime, end: datetime) -> tuple[str, str] | None:
    if article.url_credentials_removed or url_has_credentials(article.url):
        return 'Ссылка содержит данные доступа. Материал исключён.', 'Укажи публичный URL публикации.'
    published = parse_time(article.published_at)
    if published is None:
        return 'Дата публикации не подтверждена.', ''
    if not start.timestamp() < published.timestamp() <= end.timestamp():
        return 'Публикация вне контрольного периода.', ''
    if article.text is None:
        return 'Текст публикации недоступен.', 'Не удалось прочитать текст.'
    if not article.text.strip():
        return 'Остался только заголовок или пустой текст. Данных для пересказа недостаточно.', ''
    if not (article.source or '').strip() or not valid_url(article.url):
        return 'Для ссылки на публикацию недостаточно данных.', 'Нет названия источника или корректного URL.'
    return None


def collect_facts(articles, replies, journal) -> dict:
    facts = {}
    for article, reply in zip(articles, replies, strict=True):
        row = journal[article.id]
        if isinstance(reply, ProviderError):
            row.update(reason='Разбор публикации не получен.', error=str(reply))
            continue
        if reply.source_id != article.id:
            row.update(reason='Ответ модели не привязан к публикации.', error='ID в ответе модели не совпал с исходным.')
            continue
        if not reply.relevant:
            row['reason'] = reply.reason or 'Материал вне темы или данных недостаточно.'
            continue
        accepted = 0
        for claim in reply.claims:
            span = quoted_span(article.text or '', claim.evidence)
            if span is None:
                continue
            evidence, context = span
            fact_id = f'f{len(facts) + 1}'
            facts[fact_id] = {
                'id': fact_id, 'source_id': article.id, 'source': article.source, 'source_type': article.source_type,
                'published_at': article.published_at, 'claim': claim.claim,
                'evidence': evidence, 'context': context,
            }
            accepted += 1
        row['reason'] = 'Подтверждённые фрагменты не выделены.' if not accepted else 'Материал не вошёл в выбранные события.'
        if not accepted:
            row['error'] = 'Разбор релевантной статьи не содержит цитат из исходного текста.'
        elif accepted < len(reply.claims):
            row['error'] = 'Часть цитат не совпала с текстом публикации.'
    return facts


def collect_candidates(grouping, facts, journal):
    candidates = []
    groups = set()
    for draft in grouping.events:
        event = draft.model_dump()
        ids = frozenset(draft.fact_ids)
        if ids in groups:
            continue
        source_ids = list(dict.fromkeys(facts[fact_id]['source_id'] for fact_id in draft.fact_ids if fact_id in facts))
        has_model_links = any(re.search(r'https?://', event[field], re.IGNORECASE)
                              for field in ('title', 'summary', 'post', 'consequences'))
        if not ids <= facts.keys() or has_model_links:
            for source_id in source_ids:
                journal[source_id]['error'] = 'Группа содержит неизвестный ID факта или ссылку от модели.'
            continue
        groups.add(ids)
        candidates.append({
            **event, 'event_id': f'e{len(candidates) + 1}', 'source_ids': source_ids,
            'score': sum(event['priority'].values()),
        })
    return candidates


def sources_for(event, articles):
    sources = [articles[source_id] for source_id in event['source_ids']]
    sources.sort(key=lambda article: article.source_type not in ('primary', 'первичный'))
    return [{
        'id': article.id, 'source': article.source, 'url': article.url,
        'published_at': parse_time(article.published_at).isoformat(),
    } for article in sources]


def related_facts(candidate, facts):
    def cves(fact):
        return {match.upper() for match in CVE_PATTERN.findall(fact['context'])}

    anchors = set().union(*(cves(facts[fact_id]) for fact_id in candidate['fact_ids']))
    if not anchors:
        return []
    sources = set(candidate['source_ids'])
    related = []
    for fact in facts.values():
        if fact['source_id'] not in sources and anchors & cves(fact):
            related.append(fact)
            sources.add(fact['source_id'])
            if len(related) == MAX_RELATED_FACTS:
                break
    return related


def select_events(candidates, audit, facts, articles, journal, retrieved):
    counts = Counter(item.event_id for item in audit.verdicts)
    verdicts = {item.event_id: item for item in audit.verdicts}
    verified = []
    for candidate in candidates:
        event_id = candidate['event_id']
        verdict = verdicts.get(event_id) if counts[event_id] == 1 else None
        error = ''
        if verdict is None:
            error = 'Проверка должна вернуть одно решение по группе.'
        elif not verdict.supported:
            error = verdict.reason or 'Проверка отклонила группу.'
        elif not set(verdict.related_fact_ids) <= retrieved[event_id]:
            error = 'Проверка указала неизвестный ID дополнительного факта.'
        if error:
            for source_id in candidate['source_ids']:
                journal[source_id].update(reason='Кандидат не прошёл сверку с источниками.', error=error)
            continue
        fact_ids = list(dict.fromkeys([*candidate['fact_ids'], *verdict.related_fact_ids]))
        source_ids = list(dict.fromkeys(facts[fact_id]['source_id'] for fact_id in fact_ids))
        verified.append({**candidate, 'fact_ids': fact_ids, 'source_ids': source_ids})
    verified.sort(key=lambda event: (-event['score'], min(int(fact_id[1:]) for fact_id in event['fact_ids'])))
    unique = {}
    for event in verified:
        unique.setdefault(frozenset(event['fact_ids']), event)
    selected = []
    for rank, event in enumerate(unique.values(), 1):
        if rank > 5:
            for source_id in event['source_ids']:
                if journal[source_id]['status'] == 'исключён':
                    journal[source_id]['reason'] = 'Событие заняло место ниже пятого по результатам ранжирования.'
            continue
        sources = sources_for(event, articles)
        priority = event['priority']
        scale = f'{priority["scale"]}/3' if priority['scale'] else 'данных нет'
        reason = (f'Приоритет {event["score"]}/12 с учётом последствий ({priority["impact"]}/3), '
                  f'масштаба ({scale}), срочности ({priority["urgency"]}/3) '
                  f'и важности для ИБ ({priority["relevance"]}/3).')
        selected.append({
            **event, 'rank': rank, 'reason': reason, 'sources': sources,
            'facts': [{key: value for key, value in facts[fact_id].items() if key != 'context'}
                      for fact_id in dict.fromkeys(event['fact_ids'])],
        })
        for index, source in enumerate(sources):
            row = journal[source['id']]
            row['groups'].append(str(rank))
            if index == 0:
                row.update(status='включён', reason=reason)
            elif row['status'] != 'включён':
                row.update(status='объединён', reason='Публикация относится к тому же событию. Ссылка сохранена.')
    return selected


async def analyze_articles(payload: RunInput, provider: YandexProvider, progress: Callable[[str], None]) -> dict:
    end = parse_time(payload.control_time)
    assert end is not None
    start = (end.astimezone(timezone.utc) - timedelta(hours=24)).astimezone(end.tzinfo)
    control_time = end.isoformat()
    period = {'start_exclusive': start.isoformat(), 'end_inclusive': control_time, 'timezone': 'Europe/Moscow'}
    journal = {article.id: {
        'id': article.id, 'status': 'исключён', 'groups': [], 'reason': '', 'error': '',
    } for article in payload.articles}
    eligible = []
    for article in payload.articles:
        problem = input_problem(article, start, end)
        if problem:
            journal[article.id].update(reason=problem[0], error=problem[1])
        else:
            eligible.append(article)

    events = []
    if eligible:
        progress('extraction')
        replies = await provider.extract(eligible, control_time)
        if all(isinstance(reply, ProviderError) for reply in replies):
            raise ProviderError(f'Не удалось обработать ни одной публикации. {replies[0]}')
        facts = collect_facts(eligible, replies, journal)
        if facts:
            progress('ranking')
            grouping = await provider.group({'period': period, 'facts': list(facts.values())})
            candidates = collect_candidates(grouping, facts, journal)
            if grouping.events and not candidates:
                raise ProviderError('Модель не вернула ни одной группы с корректными ссылками на факты.')
            if candidates:
                progress('verification')
                checks = [{
                    **candidate, 'evidence': [facts[fact_id] for fact_id in dict.fromkeys(candidate['fact_ids'])],
                    'related_evidence': related_facts(candidate, facts),
                } for candidate in candidates]
                audit = await provider.audit({'events': checks})
                retrieved = {item['event_id']: {fact['id'] for fact in item['related_evidence']} for item in checks}
                events = select_events(candidates, audit, facts, {article.id: article for article in eligible}, journal,
                                       retrieved)

    errors = sum(bool(row['error']) for row in journal.values())
    return {
        'control_time': control_time, 'period': period, 'events': events, 'event_count': len(events),
        'input_sha256': json_sha256(run_input_data(payload)),
        'journal': [{**{key: value for key, value in row.items() if key != 'groups'}, 'group': '|'.join(row['groups'])}
                    for row in journal.values()],
        'outcome': 'partial' if errors else ('nonempty' if events else 'empty'),
        'prompt_version': PROMPT_VERSION, 'model_calls': provider.calls, 'cost': None,
        'workflow_execution_id': provider.workflow_execution_id,
    }


async def run_pipeline(payload: RunInput, provider: YandexProvider, progress: Callable[[str], None]) -> dict:
    result = await analyze_articles(payload, provider, progress)
    progress('export')
    result['artifacts'] = render_artifacts(result)
    return result
