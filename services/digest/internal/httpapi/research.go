package httpapi

import (
	"encoding/json"
	"net/url"
)

func extractSearchTrace(research *Research) {
	var trace struct {
		Output []json.RawMessage `json:"output"`
	}
	if json.Unmarshal(research.Generation.Trace, &trace) != nil {
		return
	}
	seen := map[string]bool{}
	refs := map[string]bool{}
	for _, raw := range trace.Output {
		var item struct {
			Type    string `json:"type"`
			Content []struct {
				Annotations []struct {
					Type string `json:"type"`
					URL  string `json:"url"`
				} `json:"annotations"`
			} `json:"content"`
		}
		if json.Unmarshal(raw, &item) != nil {
			continue
		}
		if item.Type == "web_search_call" {
			research.SearchObserved = true
			research.SearchCalls = append(research.SearchCalls, raw)
		}
		if item.Type != "message" {
			continue
		}
		for _, content := range item.Content {
			for _, annotation := range content.Annotations {
				if annotation.Type != "url_citation" {
					continue
				}
				if annotation.URL != "" && !refs[annotation.URL] {
					research.CitationRefs = append(research.CitationRefs, annotation.URL)
					refs[annotation.URL] = true
				}
				u, err := url.Parse(annotation.URL)
				if err != nil || (u.Scheme != "https" && u.Scheme != "http") || u.Host == "" || u.User != nil || seen[annotation.URL] {
					continue
				}
				seen[annotation.URL] = true
				research.CitationURLs = append(research.CitationURLs, annotation.URL)
			}
		}
	}
}
