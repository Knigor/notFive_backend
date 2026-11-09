package httpapi

import (
	"context"
	"encoding/json"
	"fmt"
	"slices"
	"strings"
	"sync"
	"testing"
	"time"

	"github.com/Knigor/notFive_backend/services/digest/graph/model"
	"github.com/Knigor/notFive_backend/services/digest/internal/collector"
	"github.com/Knigor/notFive_backend/services/digest/internal/news"
)

func TestCollectionUsesCitationArticlesAndReplenishesAfterDateRejection(t *testing.T) {
	now := time.Date(2026, 11, 9, 6, 9, 0, 0, time.UTC)
	calls := 0
	provider := providerFunc(func(_ context.Context, _, input string) (collector.Generation, error) {
		calls++
		if calls == 1 {
			return collector.Generation{Text: `{"candidates":[{"url":"https://3dnews.ru/tags/security"},{"url":"https://itweek.ru/security/"},{"url":"https://rb.ru/columns/old/"}]}`, Trace: json.RawMessage(`{"output":[{"type":"web_search_call"},{"type":"message","content":[{"annotations":[{"type":"url_citation","url":"https://ixbt.com/live/sw/fresh.html"}]}]}]}`)}, nil
		}
		if calls != 2 || !strings.Contains(input, "already_seen_urls") || !strings.Contains(input, "2026-11-09T09:09:00+03:00") {
			t.Fatalf("unbounded or changed-period followup: %d %s", calls, input)
		}
		return collector.Generation{Text: `{"candidates":[{"url":"https://itweek.ru/security/article/detail.php?ID=99"}]}`}, nil
	})
	_, cfg := newServer(t, provider)
	cfg.SearchDomains = []string{"3dnews.ru", "itweek.ru", "rb.ru", "ixbt.com"}
	var mu sync.Mutex
	fetched := []string{}
	cfg.SourceFetcher = researchFetchFunc(func(_ context.Context, address string) (news.Article, error) {
		mu.Lock()
		fetched = append(fetched, address)
		mu.Unlock()
		if news.IsListingURL(address) {
			t.Errorf("catalog fetched as article: %s", address)
		}
		date := now
		if strings.Contains(address, "old") {
			date = now.Add(-48 * time.Hour)
		}
		return news.Article{URL: address, Title: "Headline", Text: "Article facts", PublishedAt: date}, nil
	})
	server, err := New(cfg, provider)
	if err != nil {
		t.Fatal(err)
	}
	result, err := server.Collect(t.Context(), model.CollectInput{Kind: model.InputKindSites, ControlTime: now.Format(time.RFC3339), Sites: []string{"https://3dnews.ru", "https://itweek.ru", "https://rb.ru", "https://ixbt.com"}})
	if err != nil || len(result.Articles) != 2 || len(result.Excluded) != 3 || len(fetched) != 3 || calls != 2 {
		t.Fatalf("incomplete result: %+v err=%v fetched=%v calls=%d", result, err, fetched, calls)
	}
	var audit Research
	if result.ResearchJSON == nil || json.Unmarshal([]byte(*result.ResearchJSON), &audit) != nil {
		t.Fatal("missing research audit")
	}
	if len(audit.CandidateAudit) != 5 || len(audit.RejectedCandidates) != 2 {
		t.Fatalf("lost per-URL decisions: %+v", audit.CandidateAudit)
	}
	for _, excluded := range result.Excluded {
		if excluded.Reason == "" || excluded.ID == "" {
			t.Fatalf("incomplete journal decision: %+v", excluded)
		}
	}
	if !slices.Contains(fetched, "https://ixbt.com/live/sw/fresh.html") {
		t.Fatal("citation-only publication lost")
	}
}

func TestCollectionFetchBudgetRecordsEveryUnprocessedURL(t *testing.T) {
	candidates := []searchCandidate{}
	for i := range 40 {
		candidates = append(candidates, searchCandidate{URL: fmt.Sprintf("https://itweek.ru/security/article/detail.php?ID=%d", i)})
	}
	raw, _ := json.Marshal(map[string]any{"candidates": candidates})
	provider := providerFunc(func(context.Context, string, string) (collector.Generation, error) {
		return collector.Generation{Text: string(raw)}, nil
	})
	_, cfg := newServer(t, provider)
	cfg.SearchDomains = []string{"itweek.ru"}
	cfg.SourceFetcher = researchFetchFunc(func(context.Context, string) (news.Article, error) { return news.Article{}, news.ErrUnavailable })
	server, err := New(cfg, provider)
	if err != nil {
		t.Fatal(err)
	}
	run, err := server.collectRun(t.Context(), researchRequest{ControlTime: "2026-11-09T09:09:00+03:00", Query: "news"}, cfg.SearchDomains)
	if err != nil || run.Collection.Counts.Accepted != 0 || len(run.Collection.ExcludedArticles) != 40 || len(run.Research.ExcludedSources) != maxResearchFetches {
		t.Fatalf("bad budget: %+v %v", run.Collection, err)
	}
	unprocessed := 0
	for _, entry := range run.Research.CandidateAudit {
		if entry.Status == "not_processed" && entry.Error == "fetch_limit" {
			unprocessed++
		}
	}
	if unprocessed != 16 || len(newsDataset(run.Collection, "ru").Items) != 40 {
		t.Fatalf("missing budget decisions: %d", unprocessed)
	}
}

func TestGraphQLDiscoveryUsesSelectedCatalogueSections(t *testing.T) {
	domains := []string{"3dnews.ru", "it-world.ru", "itweek.ru", "rb.ru", "ixbt.com"}
	var mu sync.Mutex
	addresses := []string{}
	fetcher := discoveryTestFetcher{discover: func(_ context.Context, address string) ([]news.DiscoveredLink, error) {
		mu.Lock()
		addresses = append(addresses, address)
		mu.Unlock()
		return nil, nil
	}}
	server := Server{cfg: Config{SearchDomains: domains}, fetcher: fetcher}
	research := Research{AllowedDomains: domains}
	server.discoverResearchSources(t.Context(), &research, time.Now())
	want := []string{"https://3dnews.ru/security", "https://www.it-world.ru/security/", "https://www.itweek.ru/security/", "https://rb.ru/tag/cybersecurity/", "https://www.ixbt.com/live/security/"}
	slices.Sort(addresses)
	slices.Sort(want)
	if !slices.Equal(addresses, want) {
		t.Fatalf("wrong discovery scope: %v", addresses)
	}
}

func TestCollectionRefinementPrecedesStaleDiscoveryRemainder(t *testing.T) {
	now := time.Date(2026, 11, 9, 6, 9, 0, 0, time.UTC)
	fresh := "https://3dnews.ru/news/fresh.html"
	calls := 0
	provider := providerFunc(func(context.Context, string, string) (collector.Generation, error) {
		calls++
		if calls == 1 {
			return collector.Generation{Text: `{"candidates":[]}`}, nil
		}
		if calls > 2 {
			t.Fatal("unbounded followup")
		}
		return collector.Generation{Text: `{"candidates":[{"url":"` + fresh + `"}]}`}, nil
	})
	fetcher := discoveryTestFetcher{
		discover: func(context.Context, string) ([]news.DiscoveredLink, error) {
			links := []news.DiscoveredLink{}
			for i := range 25 {
				links = append(links, news.DiscoveredLink{URL: fmt.Sprintf("https://3dnews.ru/news/old-%d.html", i)})
			}
			return links, nil
		},
		fetch: func(_ context.Context, address string) (news.Article, error) {
			date := now.Add(-48 * time.Hour)
			if address == fresh {
				date = now
			}
			return news.Article{URL: address, Title: "Headline", Text: "Source text", PublishedAt: date}, nil
		},
	}
	_, cfg := newServer(t, provider)
	cfg.SourceFetcher = fetcher
	cfg.SearchDomains = []string{"3dnews.ru"}
	server, err := New(cfg, provider)
	if err != nil {
		t.Fatal(err)
	}
	run, err := server.collectRun(t.Context(), researchRequest{ControlTime: now.Format(time.RFC3339), Query: "news"}, cfg.SearchDomains)
	if err != nil || calls != 2 || len(run.Collection.Articles) != 1 || run.Collection.Articles[0].URL != fresh {
		t.Fatalf("fresh refinement crowded out: %+v calls=%d err=%v", run.Collection, calls, err)
	}
	if len(run.Research.Sources)+len(run.Research.ExcludedSources) != 24 || len(run.Collection.ExcludedArticles) != 25 {
		t.Fatalf("incorrect total budget/audit: %+v", run.Collection.Counts)
	}
}

func TestCollectionCancellationIsNotReportedAsCandidateLimit(t *testing.T) {
	ctx, cancel := context.WithCancel(t.Context())
	defer cancel()
	candidates := []searchCandidate{}
	for i := range 20 {
		candidates = append(candidates, searchCandidate{URL: fmt.Sprintf("https://3dnews.ru/news/article-%d.html", i)})
	}
	raw, _ := json.Marshal(map[string]any{"candidates": candidates})
	provider := providerFunc(func(context.Context, string, string) (collector.Generation, error) {
		return collector.Generation{Text: string(raw)}, nil
	})
	_, cfg := newServer(t, provider)
	cfg.SearchDomains = []string{"3dnews.ru"}
	cfg.SourceFetcher = researchFetchFunc(func(context.Context, string) (news.Article, error) { cancel(); return news.Article{}, context.Canceled })
	server, err := New(cfg, provider)
	if err != nil {
		t.Fatal(err)
	}
	run, err := server.collectRun(ctx, researchRequest{ControlTime: "2026-11-09T09:09:00+03:00", Query: "news"}, cfg.SearchDomains)
	if err != context.Canceled {
		t.Fatalf("cancellation lost: %v", err)
	}
	remaining := 0
	for _, entry := range run.Research.CandidateAudit {
		if entry.Status == "not_processed" {
			remaining++
			if entry.Error != "request_cancelled" {
				t.Fatalf("wrong reason: %+v", entry)
			}
		}
	}
	if remaining != 8 {
		t.Fatalf("pending candidates lost: %d", remaining)
	}
}

func TestCollectionCanonicalURLIsFetchedOnceAfterUnsafeVariant(t *testing.T) {
	address := "https://3dnews.ru/news/one.html"
	provider := providerFunc(func(context.Context, string, string) (collector.Generation, error) {
		return collector.Generation{Text: `{"candidates":[{"url":"https://user:pass@3dnews.ru/news/one.html"},{"url":"https://3dnews.ru/news/one.html"},{"url":"https://3dnews.ru/news/one.html?utm_source=search"}]}`}, nil
	})
	_, cfg := newServer(t, provider)
	cfg.SearchDomains = []string{"3dnews.ru"}
	fetched := 0
	cfg.SourceFetcher = researchFetchFunc(func(_ context.Context, raw string) (news.Article, error) {
		fetched++
		if raw != address {
			t.Errorf("unexpected fetch: %s", raw)
		}
		return news.Article{URL: address, Title: "Headline", Text: "Source text", PublishedAt: time.Date(2026, 11, 9, 6, 9, 0, 0, time.UTC)}, nil
	})
	server, err := New(cfg, provider)
	if err != nil {
		t.Fatal(err)
	}
	run, err := server.collectRun(t.Context(), researchRequest{ControlTime: "2026-11-09T09:09:00+03:00", Query: "news"}, cfg.SearchDomains)
	if err != nil || fetched != 1 || len(run.Collection.Articles) != 1 || len(run.Collection.ExcludedArticles) != 0 || len(newsDataset(run.Collection, "ru").Items) != 1 {
		t.Fatalf("canonical duplicate entered collection: fetched=%d collection=%+v err=%v", fetched, run.Collection, err)
	}
	if len(run.Research.CandidateAudit) != 1 || run.Research.CandidateAudit[0].Status != "accepted" {
		t.Fatalf("accepted audit overwritten by unsafe variant: %+v", run.Research.CandidateAudit)
	}
}

type boundedDiscoveryFetcher struct{ discoveryTestFetcher }

func (boundedDiscoveryFetcher) DiscoverDetailed(context.Context, string) (news.DiscoveryResult, error) {
	return news.DiscoveryResult{Links: []news.DiscoveredLink{{URL: "https://3dnews.ru/news/one.html"}}, InspectedLinks: 300, UninspectedLinks: 1700}, nil
}
func TestResearchDiscoveryMakesScanLimitVisible(t *testing.T) {
	domains := []string{"3dnews.ru"}
	server := Server{cfg: Config{SearchDomains: domains}, fetcher: boundedDiscoveryFetcher{}}
	research := Research{AllowedDomains: domains}
	_, candidates := server.discoverResearchSources(t.Context(), &research, time.Now())
	if len(candidates) != 1 || len(research.Discovery) != 1 || research.Discovery[0].UninspectedLinks != 1700 || len(research.Warnings) != 1 || !strings.Contains(research.Warnings[0], "1700") {
		t.Fatalf("scan limit is silent: %+v", research)
	}
}
