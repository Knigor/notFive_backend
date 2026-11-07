from pathlib import Path
import re


_WORD = re.compile(r'[^\W_]+')
_RUSSIAN_INFLECTION = re.compile(
    r'^([а-я]{4,}?)(?:иями|ями|ами|ыми|ими|ого|его|ому|ему|иях|иям|ией|ых|их|ым|им|'
    r'ая|яя|ое|ее|ые|ие|ия|ию|ии|ий|ой|ей|ую|юю|ом|ем|ов|ев|ам|ям|ах|ях|[аеёиоуыэюяь])$'
)
_ALIASES = {
    'повышение привилегий': ('повысить привилегии',),
    'обход аутентификации': ('без аутентификации',),
}


def _tokens(value: str) -> list[str]:
    return [
        _RUSSIAN_INFLECTION.sub(r'\1', word)
        for word in _WORD.findall(value.casefold().replace('ё', 'е'))
    ]


_glossary = Path(__file__).with_name('glossary.txt').read_text(encoding='utf-8').strip()
*_paragraphs, _CONTEXT_RULE = _glossary.split('\n\n')
_SECTIONS = []
for _paragraph in _paragraphs:
    _name, _term_list = _paragraph.split(': ', 1)
    _terms = _term_list.rstrip('.').split(', ')
    _SECTIONS.append((_name, {
        term: {tuple(_tokens(variant)) for variant in (term, *_ALIASES.get(term, ()))}
        for term in _terms
    }))
_PHRASE_LENGTHS = sorted({
    len(phrase) for _, terms in _SECTIONS for variants in terms.values() for phrase in variants
})


def _phrases(value: str) -> set[tuple[str, ...]]:
    tokens = _tokens(value)
    return {
        tuple(tokens[start:start + length])
        for length in _PHRASE_LENGTHS
        for start in range(len(tokens) - length + 1)
    }


def retrieve_glossary(title: str | None, text: str) -> dict:
    """Return case glossary sections with whole-word and phrase matches."""
    title_phrases = _phrases(title or '')
    text_phrases = _phrases(text)
    ranked = []
    for name, terms in _SECTIONS:
        title_hits = {term for term, variants in terms.items() if not variants.isdisjoint(title_phrases)}
        body_hits = {term for term, variants in terms.items() if not variants.isdisjoint(text_phrases)}
        matched = title_hits | body_hits
        if matched:
            score = len(matched) + len(title_hits)
            section = {'name': name, 'terms': list(terms), 'matched_terms': [term for term in terms if term in matched]}
            ranked.append((score, section))
    ranked.sort(key=lambda item: item[0], reverse=True)
    return {'sections': [section for _, section in ranked[:3]], 'context_rule': _CONTEXT_RULE}
