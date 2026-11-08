package httpapi

import (
	"context"
	"strings"
	"sync/atomic"
	"testing"
	"time"

	"github.com/Knigor/notFive_backend/services/digest/internal/news"
)

func TestResearchSourcesExcludeListingsBeforeFetchAndAfterRedirect(t *testing.T) {
	control := time.Date(2026, 11, 8, 13, 0, 0, 0, time.FixedZone("MSK", 3*60*60))
	for _, test := range []struct {
		name, requested, final string
		wantFetches            int32
	}{
		{"home", "https://habr.com/", "", 0},
		{"section", "https://habr.com/ru/hubs/infosecurity/news/", "", 0},
		{"redirect to home", "https://habr.com/ru/news/1105010/", "https://habr.com/", 1},
		{"redirect to section", "https://habr.com/ru/news/1105010/", "https://habr.com/ru/news/top/daily/", 1},
	} {
		t.Run(test.name, func(t *testing.T) {
			var fetched atomic.Int32
			server := Server{
				cfg: Config{SearchDomains: []string{"habr.com"}},
				fetcher: researchFetchFunc(func(context.Context, string) (news.Article, error) {
					fetched.Add(1)
					return news.Article{URL: test.final, Title: "Section", Text: "A listing can contain fresh excerpts.", PublishedAt: control, DateEvidence: "fresh metadata on a listing"}, nil
				}),
			}
			research := Research{}
			articles := server.fetchResearchSources(t.Context(), []searchCandidate{{URL: test.requested}}, control, &research)
			if fetched.Load() != test.wantFetches {
				t.Fatalf("fetches=%d, want %d", fetched.Load(), test.wantFetches)
			}
			if len(articles) != 1 || articles[0].Text != "" || !strings.Contains(articles[0].ReadError, "не отдельная публикация") {
				t.Fatalf("listing text reached collection: %+v", articles)
			}
			if len(research.Sources) != 0 || len(research.ExcludedSources) != 1 || research.ExcludedSources[0].Status != "excluded" {
				t.Fatalf("missing listing exclusion: %+v", research)
			}
		})
	}
}
