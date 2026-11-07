import hashlib
import json

from .domain import RunInput


def json_sha256(value: object) -> str:
    data = json.dumps(value, ensure_ascii=False, sort_keys=True, separators=(',', ':'))
    return hashlib.sha256(data.encode('utf-8')).hexdigest()


def run_input_data(payload: RunInput) -> dict:
    data = payload.model_dump()
    for article, fields in zip(payload.articles, data['articles'], strict=True):
        if article.url_credentials_removed:
            fields['url_credentials_removed'] = True
    return data
