from datetime import datetime
import re
from typing import Annotated
from urllib.parse import unquote, urlsplit
from zoneinfo import ZoneInfo

from pydantic import BaseModel, Field, PrivateAttr, StrictStr, field_validator, model_validator


MOSCOW = ZoneInfo('Europe/Moscow')
MAX_ARTICLES = 50
_SECRET_URL_KEYS = {
    'accesskey', 'auth', 'awsaccesskeyid', 'clientassertion', 'googleaccessid',
    'key', 'ossaccesskeyid', 'passwd', 'sig',
}
_SECRET_URL_SUFFIXES = ('apikey', 'token', 'secret', 'password', 'signature', 'credential', 'authorization')
_JWT = re.compile(r'\beyJ[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\b')


def parse_time(value: str | None) -> datetime | None:
    if not value or not re.fullmatch(r'\d{4}-\d\d-\d\d[Tt ]\d\d:\d\d(?::\d\d(?:\.\d{1,6})?)?(?:[Zz]|[+-]\d\d:\d\d)', value):
        return None
    try:
        return datetime.fromisoformat(value.replace('Z', '+00:00').replace('z', '+00:00')).astimezone(MOSCOW)
    except (ValueError, OverflowError):
        return None


def url_has_credentials(value: str | None) -> bool:
    if not value:
        return False
    authority = re.match(r'^\s*(?:[a-z][a-z0-9+.-]*:)?//([^/?#]*)', value, re.IGNORECASE)
    if authority and '@' in authority[1]:
        return True
    address, _, fragment = value.partition('#')
    _, _, query = address.partition('?')
    for component in (query, fragment):
        for _ in range(2):
            component = unquote(component)
        if _JWT.search(component):
            return True
        keys = {
            re.sub(r'[^a-z0-9]', '', part.split('=', 1)[0].partition('[')[0].casefold())
            for part in re.split(r'[&;?]', component) if '=' in part
        }
        if keys & _SECRET_URL_KEYS or any(key.endswith(_SECRET_URL_SUFFIXES) for key in keys):
            return True
        if {'code', 'state'} <= keys:
            return True
    return False


def valid_url(value: str | None) -> bool:
    if not value or any(ord(character) < 32 or character.isspace() or character in '<>\\' for character in value):
        return False
    try:
        parsed = urlsplit(value)
        return (parsed.scheme in ('http', 'https') and bool(parsed.hostname) and
                parsed.username is None and not url_has_credentials(value))
    except ValueError:
        return False


class Article(BaseModel):
    id: Annotated[StrictStr, Field(min_length=1, max_length=80)]
    title: str | None = Field(default=None, max_length=400)
    text: str | None = Field(default=None, max_length=24_000)
    source: str | None = Field(default=None, max_length=150)
    url: str | None = Field(default=None, max_length=2_000)
    published_at: str | None = Field(default=None, max_length=64)
    source_type: str | None = Field(default=None, max_length=60)
    _url_credentials_removed: bool = PrivateAttr(default=False)

    @model_validator(mode='after')
    def remove_url_credentials(self) -> 'Article':
        if url_has_credentials(self.url):
            self._url_credentials_removed = True
            self.url = None
        return self

    @property
    def url_credentials_removed(self) -> bool:
        return self._url_credentials_removed

    @field_validator('id')
    @classmethod
    def check_id(cls, value: str) -> str:
        if not value.strip() or any(ord(character) < 32 for character in value):
            raise ValueError('Укажите непустой ID статьи.')
        return value


class RunInput(BaseModel):
    control_time: str = Field(max_length=64)
    articles: list[Article] = Field(max_length=MAX_ARTICLES)

    @field_validator('control_time')
    @classmethod
    def check_time(cls, value: str) -> str:
        if parse_time(value) is None:
            raise ValueError('Укажите контрольное время ISO 8601 с часовым поясом.')
        return value

    @field_validator('articles')
    @classmethod
    def check_ids(cls, value: list[Article]) -> list[Article]:
        if len({article.id for article in value}) != len(value):
            raise ValueError('ID статей должны быть уникальными.')
        return value


class ExtractedClaim(BaseModel):
    claim: str = Field(min_length=1, max_length=400)
    evidence: str = Field(min_length=1, max_length=500)


class Extraction(BaseModel):
    source_id: str
    relevant: bool
    reason: str | None = None
    claims: list[ExtractedClaim] = Field(max_length=8)


class Priority(BaseModel):
    impact: int = Field(ge=0, le=3)
    scale: int = Field(ge=0, le=3)
    urgency: int = Field(ge=0, le=3)
    relevance: int = Field(ge=0, le=3)


class EventDraft(BaseModel):
    title: str = Field(min_length=1, max_length=240)
    summary: str = Field(min_length=1, max_length=800)
    post: str = Field(min_length=1, max_length=400)
    consequences: str = Field(min_length=1, max_length=400)
    fact_ids: list[str] = Field(min_length=1)
    priority: Priority


class Grouping(BaseModel):
    events: list[EventDraft] = Field(max_length=MAX_ARTICLES * 3)


class Verdict(BaseModel):
    event_id: str
    supported: bool
    reason: str
    related_fact_ids: list[str] = Field(default_factory=list, max_length=6)


class Audit(BaseModel):
    verdicts: list[Verdict]
