package httpapi

import (
	"context"
	"encoding/json"
	"slices"
	"strings"
	"sync"
	"testing"
	"time"

	"github.com/Knigor/notFive_backend/services/digest/internal/collector"
	"github.com/Knigor/notFive_backend/services/digest/internal/news"
	"github.com/Knigor/notFive_backend/services/digest/internal/yandex"
)

// URL selection from the user's saved search response, without source texts.
const refineInitialCandidates = `{"candidates":[{"url":"https://habr.com/ru/news/1105010/","title":"Beget"},{"url":"https://xakep.ru/tag/news/","title":"Новости"},{"url":"https://ptsecurity.com/about/news/","title":"Новости"},{"url":"https://habr.com/ru/news/top/daily/","title":"Лучшие новости"}]}`

func TestResearchRefinesListingsOnceAndUsesOnlyUniqueArticleTexts(t *testing.T) {
	firstURL := "https://habr.com/ru/news/1105010/"
	secondURL := "https://securitylab.ru/news/579900.php"
	searchCalls := 0
	provider := providerFunc(func(_ context.Context, _, input string) (collector.Generation, error) {
		searchCalls++
		if searchCalls == 1 {
			return collector.Generation{Text: "```\n" + refineInitialCandidates + "\n```", ResponseID: "initial-response", Trace: json.RawMessage(`{"output":[{"type":"web_search_call","action":{"query":"initial query"}},{"type":"message","content":[{"annotations":[{"type":"url_citation","url":"https://habr.com/ru/news/1105010/"}]}]}]}`)}, nil
		}
		if searchCalls != 2 {
			t.Fatal("refinement must be bounded to one additional search")
		}
		_, raw, found := strings.Cut(input, "\n")
		var plan struct {
			Start     string   `json:"window_start_exclusive"`
			End       string   `json:"window_end_inclusive"`
			Queries   []string `json:"site_queries"`
			Seen      []string `json:"already_seen_urls"`
			Remaining int      `json:"max_candidates"`
		}
		if !found || json.Unmarshal([]byte(raw), &plan) != nil {
			t.Fatal("invalid refinement plan")
		}
		if plan.Start != "2026-11-07T13:48:16+03:00" || plan.End != "2026-11-08T13:48:16+03:00" || len(plan.Queries) != 5 || len(plan.Seen) != 4 || !slices.Contains(plan.Seen, firstURL) || plan.Remaining != 11 {
			t.Fatalf("refinement lost original window or seen URLs: %+v", plan)
		}
		for _, query := range plan.Queries {
			if !strings.Contains(query, "site:") || !strings.Contains(query, "date:20261107..20261108") {
				t.Fatalf("query lacks site/date scope: %s", query)
			}
		}
		return collector.Generation{Text: "```JSON\n{\"candidates\":[{\"url\":\"" + secondURL + "\"},{\"url\":\"" + firstURL + "#comments\"},{\"url\":\"https://xakep.ru/\"}]}\n```", ResponseID: "refined-response", Trace: json.RawMessage(`{"output":[{"type":"web_search_call","action":{"query":"refined query"}},{"type":"message","content":[{"annotations":[{"type":"url_citation","url":"https://securitylab.ru/news/579900.php"}]}]}]}`)}, nil
	})
	_, cfg := newServer(t, provider)
	var mu sync.Mutex
	var fetched []string
	cfg.SourceFetcher = researchFetchFunc(func(_ context.Context, address string) (news.Article, error) {
		mu.Lock()
		fetched = append(fetched, address)
		mu.Unlock()
		if news.IsListingURL(address) {
			t.Errorf("listing was fetched as an article: %s", address)
			return news.Article{}, news.ErrListingPage
		}
		return news.Article{URL: address, Title: "Source title", Text: "Source facts from " + address, PublishedAt: time.Date(2026, 11, 8, 8, 0, 0, 0, time.UTC), DateEvidence: "test datePublished"}, nil
	})
	server, err := New(cfg, provider)
	if err != nil {
		t.Fatal(err)
	}
	response := call(server, "POST", "/v1/collect", `{"control_time":"2026-11-08T13:48:16+03:00","query":"news"}`, serviceToken)
	if response.Code != 201 {
		t.Fatalf("status %d: %s", response.Code, response.Body.String())
	}
	run := savedRunFromCollection(t, server, response)
	if searchCalls != 2 || len(fetched) != 2 || len(run.Collection.Articles) != 2 || len(run.Research.Sources) != 2 {
		t.Fatalf("unexpected calls/selections: search=%d fetched=%v articles=%+v", searchCalls, fetched, run.Collection.Articles)
	}
	if !slices.Contains(fetched, firstURL) || !slices.Contains(fetched, secondURL) {
		t.Fatalf("wrong URLs fetched: %v", fetched)
	}
	for _, article := range run.Collection.Articles {
		if article.URL != firstURL && article.URL != secondURL {
			t.Fatalf("non-article reached collection: %+v", article)
		}
	}
	audit := run.Research
	if len(audit.SearchAttempts) != 2 || len(audit.SearchAttempts[0].Candidates) != 4 || len(audit.SearchAttempts[1].Candidates) != 3 || len(audit.RejectedCandidates) != 4 || len(audit.SearchCalls) != 2 {
		t.Fatalf("search audit lost decisions: %+v", audit)
	}
	if audit.Generation.ResponseID != "initial-response" || audit.SearchAttempts[1].Generation.ResponseID != "refined-response" || !slices.Contains(audit.CitationURLs, secondURL) {
		t.Fatal("generation or citation provenance lost")
	}
}

func TestResearchRefinementFailurePreservesInitialArticle(t *testing.T) {
	searchCalls, fetchCalls := 0, 0
	provider := providerFunc(func(_ context.Context, _, _ string) (collector.Generation, error) {
		searchCalls++
		if searchCalls == 1 {
			return collector.Generation{Text: "```json\n" + refineInitialCandidates + "\n```", Trace: json.RawMessage(`{"output":[{"type":"web_search_call"}]}`)}, nil
		}
		if searchCalls != 2 {
			t.Fatal("failed refinement must not cause a search loop")
		}
		return collector.Generation{ResponseID: "failed-refinement"}, &yandex.UpstreamError{Status: 502}
	})
	_, cfg := newServer(t, provider)
	cfg.SourceFetcher = researchFetchFunc(func(_ context.Context, address string) (news.Article, error) {
		fetchCalls++
		return news.Article{URL: address, Title: "Source", Text: "Source facts", PublishedAt: time.Date(2026, 11, 8, 8, 0, 0, 0, time.UTC)}, nil
	})
	server, err := New(cfg, provider)
	if err != nil {
		t.Fatal(err)
	}
	response := call(server, "POST", "/v1/collect", `{"control_time":"2026-11-08T13:48:16+03:00","query":"news"}`, serviceToken)
	if response.Code != 201 {
		t.Fatalf("refinement error discarded initial result: %d %s", response.Code, response.Body.String())
	}
	run := savedRunFromCollection(t, server, response)
	if searchCalls != 2 || fetchCalls != 1 || len(run.Research.Sources) != 1 || len(run.Research.SearchAttempts) != 2 {
		t.Fatalf("wrong recovery: search=%d fetch=%d", searchCalls, fetchCalls)
	}
	if attempt := run.Research.SearchAttempts[1]; attempt.Error == "" || attempt.Generation.ResponseID != "failed-refinement" || len(run.Research.Warnings) == 0 {
		t.Fatal("refinement failure missing from audit")
	}
}
