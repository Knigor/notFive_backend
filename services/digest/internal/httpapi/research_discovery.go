package httpapi

import (
	"context"
	"sync"
	"time"

	"github.com/Knigor/notFive_backend/services/digest/internal/news"
)

type sourceDiscoverer interface {
	Discover(context.Context, string) ([]news.DiscoveredLink, error)
}

type ResearchDiscoveryAttempt struct {
	URL           string            `json:"url"`
	Kind          string            `json:"kind"`
	RequestedAt   string            `json:"requested_at"`
	Found         int               `json:"found"`
	OutsideWindow int               `json:"outside_window"`
	Candidates    []searchCandidate `json:"candidates"`
	Error         string            `json:"error,omitzero"`
}

// These entry points belong to the configured sources. A feed is a way to
// discover article URLs; its dates never replace verification of the article.
var researchDiscoverySources = []struct{ url, kind string }{
	{"https://www.securitylab.ru/_services/export/rss/news/", "rss"},
	{"https://xakep.ru/feed/", "rss"},
	{"https://habr.com/ru/rss/hubs/infosecurity/news/?fl=ru", "rss"},
	{"https://ptsecurity.com/about/news/", "html"},
	{"https://www.kaspersky.ru/about/press-releases", "html"},
}

func (s *Server) discoverResearchSources(ctx context.Context, research *Research, control time.Time) (feedCandidates, htmlCandidates []searchCandidate) {
	discoverer, ok := s.fetcher.(sourceDiscoverer)
	if !ok {
		return nil, nil
	}
	attempts := []ResearchDiscoveryAttempt{}
	for _, source := range researchDiscoverySources {
		if _, err := researchSourceURL(source.url, s.cfg.SearchDomains); err == nil {
			attempts = append(attempts, ResearchDiscoveryAttempt{URL: source.url, Kind: source.kind, Candidates: []searchCandidate{}})
		}
	}
	freshFeeds := make([][]searchCandidate, len(attempts))
	undatedFeeds := make([][]searchCandidate, len(attempts))
	htmlPages := make([][]searchCandidate, len(attempts))
	var workers sync.WaitGroup
	for i := range attempts {
		workers.Go(func() {
			attempt := &attempts[i]
			attempt.RequestedAt = time.Now().UTC().Format(time.RFC3339Nano)
			links, err := discoverer.Discover(ctx, attempt.URL)
			if err != nil {
				attempt.Error = "Не удалось прочитать ленту или раздел; обработка остальных источников продолжена."
				return
			}
			attempt.Found = len(links)
			for _, link := range links {
				if !link.PublishedAt.IsZero() && (!link.PublishedAt.After(control.Add(-24*time.Hour)) || link.PublishedAt.After(control)) {
					attempt.OutsideWindow++
					continue
				}
				candidate := searchCandidate{URL: link.URL}
				attempt.Candidates = append(attempt.Candidates, candidate)
				switch {
				case attempt.Kind == "html":
					htmlPages[i] = append(htmlPages[i], candidate)
				case link.PublishedAt.IsZero():
					undatedFeeds[i] = append(undatedFeeds[i], candidate)
				default:
					freshFeeds[i] = append(freshFeeds[i], candidate)
				}
			}
		})
	}
	workers.Wait()
	research.Discovery = attempts
	// Fresh feed hints have priority, then undated feed items. HTML listings are
	// a fallback after web search so old section links cannot fill every slot.
	feedCandidates = interleaveDiscoveryCandidates(freshFeeds)
	feedCandidates = append(feedCandidates, interleaveDiscoveryCandidates(undatedFeeds)...)
	return feedCandidates, interleaveDiscoveryCandidates(htmlPages)
}

// Interleave sources within a priority group so one feed cannot crowd out the rest.
func interleaveDiscoveryCandidates(groups [][]searchCandidate) []searchCandidate {
	var candidates []searchCandidate
	for index := 0; ; index++ {
		added := false
		for _, group := range groups {
			if index < len(group) {
				candidates = append(candidates, group[index])
				added = true
			}
		}
		if !added {
			return candidates
		}
	}
}
