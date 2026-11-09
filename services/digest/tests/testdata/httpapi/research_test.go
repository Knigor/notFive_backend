package httpapi

import (
	"context"
	"encoding/json"
	"strings"
	"testing"
	"time"

	"github.com/Knigor/notFive_backend/services/digest/internal/collector"
	"github.com/Knigor/notFive_backend/services/digest/internal/news"
)

type researchFetchFunc func(context.Context, string) (news.Article, error)

func (f researchFetchFunc) Fetch(ctx context.Context, address string) (news.Article, error) {
	return f(ctx, address)
}

func TestResearchPublicationWindowAndModelIsolation(t *testing.T) {
	control := time.Date(2026, 11, 8, 13, 1, 59, 0, time.FixedZone("MSK", 3*60*60))
	dates := map[string]time.Time{
		"lower":   control.Add(-24 * time.Hour),
		"inside":  control.Add(-24*time.Hour + time.Nanosecond),
		"upper":   control,
		"future":  control.Add(time.Nanosecond),
		"old":     control.Add(-48 * time.Hour),
		"unknown": {},
	}
	candidates := []searchCandidate{}
	for _, name := range []string{"lower", "inside", "upper", "future", "old", "unknown"} {
		candidates = append(candidates, searchCandidate{URL: "https://securitylab.ru/news/" + name, Title: "MODEL_ONLY_" + name})
	}
	raw, _ := json.Marshal(map[string]any{"candidates": candidates})
	calls := 0
	provider := providerFunc(func(_ context.Context, instructions, input string) (collector.Generation, error) {
		calls++
		if calls > 2 || !strings.Contains(instructions, "web_search") || strings.Contains(input, "SOURCE_FACT_") {
			t.Fatal("collector should only request search candidates")
		}
		return collector.Generation{Text: string(raw), Trace: json.RawMessage(`{"output":[{"type":"web_search_call"}]}`)}, nil
	})
	server, cfg := newServer(t, provider)
	cfg.SourceFetcher = researchFetchFunc(func(_ context.Context, address string) (news.Article, error) {
		name := strings.TrimPrefix(address, "https://securitylab.ru/news/")
		return news.Article{URL: address, Title: "PAGE_" + name, Text: "SOURCE_FACT_" + name, PublishedAt: dates[name], DateEvidence: "test publication metadata"}, nil
	})
	server, err := New(cfg, provider)
	if err != nil {
		t.Fatal(err)
	}
	response := call(server, "POST", "/v1/collect", `{"control_time":"2026-11-08T13:01:59+03:00","query":"cybersecurity news"}`, serviceToken)
	if response.Code != 201 {
		t.Fatalf("status %d: %s", response.Code, response.Body.String())
	}
	run := savedRunFromCollection(t, server, response)
	if calls != 2 || run.Collection == nil || len(run.Collection.Articles) != 2 || len(run.Collection.ExcludedArticles) != 4 || len(run.Research.Sources) != 2 {
		t.Fatalf("wrong selection: calls=%d collection=%+v", calls, run.Collection)
	}
	for _, article := range run.Collection.Articles {
		if article.Text != "SOURCE_FACT_inside" && article.Text != "SOURCE_FACT_upper" {
			t.Fatalf("old or unverified source reached model: %+v", article)
		}
		if strings.HasPrefix(article.Title, "MODEL_ONLY_") {
			t.Fatal("model title replaced source title")
		}
	}
}

func TestResearchOldForecastAllowsOneBoundedFollowup(t *testing.T) {
	calls := 0
	provider := providerFunc(func(_ context.Context, _, _ string) (collector.Generation, error) {
		calls++
		if calls > 2 {
			t.Fatal("collection must stop after one bounded followup")
		}
		return collector.Generation{Text: "OLD_ANNUAL_FORECAST_MUST_NOT_APPEAR", Trace: json.RawMessage(`{"output":[{"type":"web_search_call"},{"type":"message","content":[{"annotations":[{"type":"url_citation","url":"https://ptsecurity.com/research/old"}]}]}]}`)}, nil
	})
	_, cfg := newServer(t, provider)
	cfg.SourceFetcher = researchFetchFunc(func(_ context.Context, address string) (news.Article, error) {
		return news.Article{URL: address, Title: "Annual forecast", Text: "Old report", PublishedAt: time.Date(2025, 12, 1, 0, 0, 0, 0, time.UTC)}, nil
	})
	server, err := New(cfg, provider)
	if err != nil {
		t.Fatal(err)
	}
	response := call(server, "POST", "/v1/collect", `{"control_time":"2026-11-08T13:01:59+03:00","query":"news"}`, serviceToken)
	if response.Code != 201 {
		t.Fatalf("status %d: %s", response.Code, response.Body.String())
	}
	run := savedRunFromCollection(t, server, response)
	if calls != 2 || run.Collection == nil || len(run.Collection.Articles) != 0 || len(run.Collection.ExcludedArticles) != 1 || len(run.Research.ExcludedSources) != 1 {
		t.Fatalf("wrong empty collection: %+v", run)
	}
}
