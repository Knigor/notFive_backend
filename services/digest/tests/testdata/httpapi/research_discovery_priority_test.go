package httpapi

import (
	"context"
	"fmt"
	"slices"
	"strings"
	"testing"
	"time"

	"github.com/Knigor/notFive_backend/services/digest/internal/collector"
	"github.com/Knigor/notFive_backend/services/digest/internal/news"
)

func TestResearchHTMLListingsCannotCrowdOutFreshFeedsOrWebSearch(t *testing.T) {
	control := time.Date(2026, 11, 8, 13, 48, 16, 0, time.FixedZone("MSK", 3*60*60))
	unknown := "https://www.securitylab.ru/news/600099.php"
	habr := "https://habr.com/ru/news/1105010/"
	model := "https://xakep.ru/2026/11/08/new-incident/"
	provider := providerFunc(func(_ context.Context, _, _ string) (collector.Generation, error) {
		return collector.Generation{Text: `{"candidates":[{"url":"` + model + `"}]}`}, nil
	})
	fetcher := discoveryTestFetcher{
		discover: func(_ context.Context, address string) ([]news.DiscoveredLink, error) {
			switch {
			case strings.Contains(address, "securitylab.ru"):
				links := []news.DiscoveredLink{{URL: unknown}}
				for i := range 5 {
					links = append(links, news.DiscoveredLink{URL: fmt.Sprintf("https://www.securitylab.ru/news/%d.php", 600000+i), PublishedAt: control})
				}
				return links, nil
			case strings.Contains(address, "habr.com"):
				return []news.DiscoveredLink{{URL: habr, PublishedAt: control}}, nil
			case strings.Contains(address, "ptsecurity.com"), strings.Contains(address, "kaspersky.ru"):
				prefix := "https://ptsecurity.com/about/news/"
				if strings.Contains(address, "kaspersky.ru") {
					prefix = "https://www.kaspersky.ru/about/press-releases/"
				}
				links := []news.DiscoveredLink{}
				for i := range 60 {
					links = append(links, news.DiscoveredLink{URL: fmt.Sprintf("%sarticle-%d", prefix, i)})
				}
				return links, nil
			default:
				return nil, nil
			}
		},
		fetch: func(_ context.Context, address string) (news.Article, error) {
			return news.Article{URL: address, Title: "Publication", Text: "Publication text", PublishedAt: control}, nil
		},
	}
	_, cfg := newServer(t, provider)
	cfg.SearchDomains = []string{"securitylab.ru", "xakep.ru", "habr.com", "ptsecurity.com", "kaspersky.ru"}
	cfg.SourceFetcher = fetcher
	server, err := New(cfg, provider)
	if err != nil {
		t.Fatal(err)
	}
	response := call(server, "POST", "/v1/collect", `{"control_time":"2026-11-08T13:48:16+03:00","query":"news"}`, serviceToken)
	if response.Code != 201 {
		t.Fatalf("status %d: %s", response.Code, response.Body.String())
	}
	run := savedRunFromCollection(t, server, response)
	got := []string{}
	for _, source := range run.Research.Sources {
		got = append(got, source.URL)
	}
	want := []string{"https://www.securitylab.ru/news/600000.php", habr}
	for i := 1; i < 5; i++ {
		want = append(want, fmt.Sprintf("https://www.securitylab.ru/news/%d.php", 600000+i))
	}
	want = append(want, unknown, model,
		"https://ptsecurity.com/about/news/article-0", "https://www.kaspersky.ru/about/press-releases/article-0",
		"https://ptsecurity.com/about/news/article-1", "https://www.kaspersky.ru/about/press-releases/article-1")
	if len(got) != 128 || !slices.Equal(got[:min(len(got), len(want))], want) {
		t.Fatalf("HTML listings consumed higher-priority article slots: got %v, want %v", got, want)
	}
}
