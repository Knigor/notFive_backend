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

	"github.com/Knigor/notFive_backend/services/digest/internal/collector"
	"github.com/Knigor/notFive_backend/services/digest/internal/news"
)

type discoveryTestFetcher struct {
	fetch    func(context.Context, string) (news.Article, error)
	discover func(context.Context, string) ([]news.DiscoveredLink, error)
}

func (f discoveryTestFetcher) Fetch(ctx context.Context, address string) (news.Article, error) {
	return f.fetch(ctx, address)
}

func (f discoveryTestFetcher) Discover(ctx context.Context, address string) ([]news.DiscoveredLink, error) {
	return f.discover(ctx, address)
}

func TestResearchDiscoveryPrioritizesFreshFeedAndStillVerifiesHTMLDate(t *testing.T) {
	control := time.Date(2026, 11, 8, 13, 48, 16, 0, time.FixedZone("MSK", 3*60*60))
	article := func(name string) string { return "https://www.securitylab.ru/news/" + name + ".php" }
	habr := "https://habr.com/ru/news/1105010/"
	stale := []searchCandidate{}
	for i := range 12 {
		stale = append(stale, searchCandidate{URL: article(fmt.Sprintf("model%d", i))})
	}
	raw, _ := json.Marshal(map[string]any{"candidates": stale})
	searchCalls := 0
	provider := providerFunc(func(_ context.Context, _, _ string) (collector.Generation, error) {
		searchCalls++
		return collector.Generation{Text: string(raw), Trace: json.RawMessage(`{"output":[{"type":"web_search_call"}]}`)}, nil
	})
	var mu sync.Mutex
	fetched, discovered := []string{}, []string{}
	fetcher := discoveryTestFetcher{
		discover: func(_ context.Context, address string) ([]news.DiscoveredLink, error) {
			mu.Lock()
			discovered = append(discovered, address)
			mu.Unlock()
			switch {
			case strings.Contains(address, "securitylab.ru"):
				return []news.DiscoveredLink{
					{URL: article("upper"), PublishedAt: control},
					{URL: article("badhtml"), PublishedAt: control.Add(-time.Hour)},
					{URL: article("unknown")},
					{URL: article("inside"), PublishedAt: control.Add(-24*time.Hour + time.Nanosecond)},
					{URL: article("lower"), PublishedAt: control.Add(-24 * time.Hour)},
					{URL: article("future"), PublishedAt: control.Add(time.Nanosecond)},
				}, nil
			case strings.Contains(address, "habr.com"):
				return []news.DiscoveredLink{{URL: habr, PublishedAt: control.Add(-time.Hour)}}, nil
			case strings.Contains(address, "xakep.ru"):
				return nil, news.ErrUnavailable
			default:
				t.Errorf("discovery visited an unconfigured source: %s", address)
				return nil, news.ErrUntrustedURL
			}
		},
		fetch: func(_ context.Context, address string) (news.Article, error) {
			mu.Lock()
			fetched = append(fetched, address)
			mu.Unlock()
			date := control.Add(-time.Hour)
			if strings.Contains(address, "model") || address == article("badhtml") {
				date = control.Add(-48 * time.Hour)
			}
			if address == article("upper") {
				date = control
			}
			if address == article("inside") {
				date = control.Add(-24*time.Hour + time.Nanosecond)
			}
			return news.Article{URL: address, Title: "Source title", Text: "Source text", PublishedAt: date, DateEvidence: "HTML datePublished"}, nil
		},
	}
	_, cfg := newServer(t, provider)
	cfg.SearchDomains = []string{"securitylab.ru", "habr.com", "xakep.ru"}
	cfg.SourceFetcher = fetcher
	server, err := New(cfg, provider)
	if err != nil {
		t.Fatal(err)
	}
	response := call(server, "POST", "/v1/collect", `{"control_time":"2026-11-08T13:48:16+03:00","query":"news"}`, serviceToken)
	if response.Code != 201 {
		t.Fatalf("discovery request failed: %d %s", response.Code, response.Body.String())
	}
	run := savedRunFromCollection(t, server, response)
	if searchCalls != 2 || len(fetched) != 17 || len(run.Collection.Articles) != 4 {
		t.Fatalf("bad prioritization: search=%d fetched=%v articles=%+v", searchCalls, fetched, run.Collection.Articles)
	}
	for _, expected := range []string{article("upper"), article("inside"), article("unknown"), habr} {
		if !slices.ContainsFunc(run.Collection.Articles, func(a CollectedArticle) bool { return a.URL == expected }) {
			t.Errorf("fresh discovery missing from model input: %s", expected)
		}
	}
	for _, forbidden := range []string{article("lower"), article("future")} {
		if slices.Contains(fetched, forbidden) {
			t.Errorf("known out-of-window feed item fetched: %s", forbidden)
		}
	}
	if !slices.Contains(fetched, article("badhtml")) || !slices.Contains(fetched, article("unknown")) {
		t.Fatal("feed date bypassed HTML verification")
	}
	for _, a := range run.Collection.Articles {
		if a.URL == article("badhtml") || strings.Contains(a.URL, "model") {
			t.Errorf("old HTML date reached model: %+v", a)
		}
	}
	if len(discovered) != 3 || len(run.Research.Discovery) != 3 {
		t.Fatalf("wrong source catalogue scope: %v / %+v", discovered, run.Research.Discovery)
	}
	outside, failures := 0, 0
	for _, attempt := range run.Research.Discovery {
		outside += attempt.OutsideWindow
		if attempt.Error != "" {
			failures++
		}
	}
	if outside != 2 || failures != 1 {
		t.Fatalf("discovery audit lost exclusions/errors: %+v", run.Research.Discovery)
	}
}

func TestResearchDiscoveryRoundRobinKeepsOtherSourcesWithinArticleBudget(t *testing.T) {
	control := time.Date(2026, 11, 8, 13, 48, 16, 0, time.FixedZone("MSK", 3*60*60))
	provider := providerFunc(func(_ context.Context, _, _ string) (collector.Generation, error) {
		return collector.Generation{Text: `{"candidates":[]}`}, nil
	})
	fetcher := discoveryTestFetcher{
		discover: func(_ context.Context, address string) ([]news.DiscoveredLink, error) {
			if strings.Contains(address, "securitylab.ru") {
				links := []news.DiscoveredLink{}
				for i := range 20 {
					links = append(links, news.DiscoveredLink{URL: fmt.Sprintf("https://www.securitylab.ru/news/%d.php", 600000+i), PublishedAt: control})
				}
				return links, nil
			}
			if strings.Contains(address, "habr.com") {
				return []news.DiscoveredLink{{URL: "https://habr.com/ru/news/1105010/", PublishedAt: control}}, nil
			}
			return nil, nil
		},
		fetch: func(_ context.Context, address string) (news.Article, error) {
			return news.Article{URL: address, Title: "Source", Text: "Source text", PublishedAt: control}, nil
		},
	}
	_, cfg := newServer(t, provider)
	cfg.SourceFetcher = fetcher
	cfg.SearchDomains = []string{"securitylab.ru", "habr.com", "xakep.ru"}
	server, err := New(cfg, provider)
	if err != nil {
		t.Fatal(err)
	}
	response := call(server, "POST", "/v1/collect", `{"control_time":"2026-11-08T13:48:16+03:00","query":"news"}`, serviceToken)
	if response.Code != 201 {
		t.Fatalf("status %d: %s", response.Code, response.Body.String())
	}
	run := savedRunFromCollection(t, server, response)
	if len(run.Research.Sources) != 12 || !slices.ContainsFunc(run.Research.Sources, func(s ResearchSource) bool { return s.URL == "https://habr.com/ru/news/1105010/" }) {
		t.Fatalf("large first feed crowded out another source: %+v", run.Research.Sources)
	}
	unprocessed := 0
	for _, candidate := range run.Research.CandidateAudit {
		if candidate.Status == "not_processed" && candidate.Error == "fetch_limit" && candidate.Reason != "" {
			unprocessed++
		}
	}
	if unprocessed != 9 {
		t.Fatalf("unprocessed discovery candidates lost their budget reasons: %d", unprocessed)
	}
}
