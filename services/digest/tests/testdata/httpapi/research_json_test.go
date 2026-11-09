package httpapi

import (
	"testing"
)

func TestResearchCandidatesAcceptsJSONAndWholeMarkdownFence(t *testing.T) {
	const payload = `{"candidates":[{"url":"https://habr.com/ru/news/1105010/","title":"Publication"}]}`
	for name, input := range map[string]string{
		"bare":                      payload,
		"spaces and BOM":            " \n\uFEFF " + payload + "\n ",
		"plain fence":               "```\n" + payload + "\n```",
		"json fence":                "```json\n" + payload + "\n```",
		"uppercase CRLF and spaces": " \uFEFF \r\n  ``` JSON \r\n" + payload + "\r\n  ``` \r\n ",
	} {
		t.Run(name, func(t *testing.T) {
			research := Research{CitationURLs: []string{"https://xakep.ru/"}}
			candidates := researchCandidates(input, &research)
			if len(candidates) != 2 || candidates[0].URL != "https://habr.com/ru/news/1105010/" || candidates[1].URL != "https://xakep.ru/" || len(research.Warnings) != 0 {
				t.Fatalf("valid JSON used fallback: candidates=%+v warnings=%v", candidates, research.Warnings)
			}
		})
	}
}

func TestResearchCandidatesRealMarkdownResponseKeepsFourModelURLs(t *testing.T) {
	// Short search response from run 793056fc33a9689ca0941b5c7cdb9560.
	// The full audit contains source texts and is intentionally not a fixture.
	const payload = `{"candidates":[{"url":"https://habr.com/ru/news/1105010/","title":"Beget: панель управления работает частично, идёт запуск первых серверов под автоматическое восстановление из бэкапов / Хабр"},{"url":"https://xakep.ru/tag/news/","title":"Новости — Хакер"},{"url":"https://ptsecurity.com/about/news/","title":"Новости"},{"url":"https://habr.com/ru/news/top/daily/","title":"Лучшие IT-новости за сутки / Хабр"}]}`
	research := Research{CitationURLs: []string{
		"https://habr.com/ru/news/1105010/", "https://xakep.ru/", "https://xakep.ru/tag/news/",
		"https://habr.com/ru/hubs/infosecurity/news/", "https://ptsecurity.com/research/analytics/",
		"https://habr.com/ru/news/top/daily/", "https://ptsecurity.com/about/news/",
	}}
	candidates := researchCandidates("```\n"+payload+"\n```", &research)
	if len(candidates) != 7 || len(research.Warnings) != 0 {
		t.Fatalf("expected the model's four URLs without annotation fallback: %+v / %v", candidates, research.Warnings)
	}
	if candidates[1].URL != "https://xakep.ru/tag/news/" || candidates[0].Title == "" {
		t.Fatalf("candidate order or title lost: %+v", candidates)
	}
}

func TestResearchCandidatesEmptyArrayStillChecksAnnotatedURLs(t *testing.T) {
	for _, input := range []string{`{"candidates":[]}`, "```json\n{\"candidates\":[]}\n```"} {
		research := Research{CitationURLs: []string{"https://xakep.ru/"}}
		if candidates := researchCandidates(input, &research); len(candidates) != 1 || len(research.Warnings) != 0 {
			t.Fatalf("explicit empty result replaced with annotations: %+v / %v", candidates, research.Warnings)
		}
	}
}

func TestResearchCandidatesMalformedOrAmbiguousJSONUsesFallback(t *testing.T) {
	for name, input := range map[string]string{
		"truncated":         "```json\n{\"candidates\":[",
		"missing array":     "```json\n{}\n```",
		"null array":        "```\n{\"candidates\":null}\n```",
		"surrounding prose": "Here is a result:\n```json\n{\"candidates\":[]}\n```",
		"two documents":     "```json\n{\"candidates\":[]} {}\n```",
		"two fences":        "```json\n{\"candidates\":[]}\n```\n```json\n{}\n```",
		"wrong language":    "```python\n{\"candidates\":[]}\n```",
	} {
		t.Run(name, func(t *testing.T) {
			research := Research{CitationURLs: []string{"https://habr.com/ru/news/1105010/"}}
			candidates := researchCandidates(input, &research)
			if len(candidates) != 1 || candidates[0].URL != research.CitationURLs[0] || len(research.Warnings) != 1 {
				t.Fatalf("expected safe annotation fallback: %+v / %v", candidates, research.Warnings)
			}
		})
	}
}
