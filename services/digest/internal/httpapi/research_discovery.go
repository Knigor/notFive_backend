package httpapi

import (
	"context"
	"fmt"
	"sync"
	"time"

	"github.com/Knigor/notFive_backend/services/digest/internal/news"
)

type sourceDiscoverer interface {
	Discover(context.Context, string) ([]news.DiscoveredLink, error)
}

type detailedSourceDiscoverer interface {
	DiscoverDetailed(context.Context, string) (news.DiscoveryResult, error)
}

type ResearchDiscoveryAttempt struct {
	URL              string                       `json:"url"`
	Kind             string                       `json:"kind"`
	RequestedAt      string                       `json:"requested_at"`
	Found            int                          `json:"found"`
	OutsideWindow    int                          `json:"outside_window"`
	InspectedLinks   int                          `json:"inspected_links"`
	UninspectedLinks int                          `json:"uninspected_links"`
	Candidates       []searchCandidate            `json:"candidates"`
	Error            string                       `json:"error,omitzero"`
	ErrorCode        string                       `json:"error_code,omitzero"`
	Rejected         []RejectedDiscoveryCandidate `json:"rejected"`
}

type RejectedDiscoveryCandidate struct {
	URL    string `json:"url"`
	Reason string `json:"reason"`
	Error  string `json:"error"`
}

// These entry points belong to the configured sources. A feed is a way to
// discover article URLs; its dates never replace verification of the article.
type researchDiscoverySource struct{ url, kind string }

var researchDiscoverySources = []researchDiscoverySource{
	{"https://www.securitylab.ru/_services/export/rss/news/", "rss"},
	{"https://xakep.ru/feed/", "rss"},
	{"https://habr.com/ru/rss/hubs/infosecurity/news/?fl=ru", "rss"},
	{"https://ptsecurity.com/about/news/", "html"},
	{"https://www.kaspersky.ru/about/press-releases", "html"},
	{"https://www.comnews.ru/information_security", "html"},
	{"https://www.kommersant.ru/theme/1267", "html"},
	{"https://tass.ru/", "html"},
	{"https://ria.ru/kiberbezopasnost", "html"},
	{"https://www.it-world.ru/security/", "html"},
	{"https://securitymedia.org/", "html"},
	{"https://www.infowatch.ru/analytics/novosti-ib", "html"},
	{"https://infobezopasnost.ru/blog/news/", "html"},
	{"https://ict.moscow/projects/cybersecurity/", "html"},
	{"https://www.gazeta.ru/tech", "html"},
	{"https://aif.ru/techno/web", "html"},
	{"https://safe.cnews.ru/", "html"},
	{"https://rb.ru/tag/cybersecurity/", "html"},
	{"https://3dnews.ru/security", "html"},
	{"https://www.computerra.ru/tag/security/", "html"},
	{"https://www.itweek.ru/security/", "html"},
	{"https://www.ixbt.com/live/security/", "html"},
	{"https://overclockers.ru/tag/security", "html"},
	{"https://rg.ru/theme/technologies/internet/kiberbezopasnost/", "html"},
	{"https://iz.ru/tag/kiberbezopasnost", "html"},
	{"https://rt-solar.ru/events/news/", "html"},
	{"https://www.usergate.com/ru", "html"},
	{"https://infotecs.ru/", "html"},
}

func (s *Server) discoverResearchSources(ctx context.Context, research *Research, control time.Time) (feedCandidates, htmlCandidates []searchCandidate) {
	discoverer, ok := s.fetcher.(sourceDiscoverer)
	if !ok {
		return nil, nil
	}
	attempts := []ResearchDiscoveryAttempt{}
	sources := s.discoverySources
	if sources == nil {
		for _, domain := range s.cfg.SearchDomains {
			sources = append(sources, discoveryForSite(domain, "https://"+domain+"/"))
		}
	}
	for _, source := range sources {
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
			var links []news.DiscoveredLink
			var err error
			if detailed, ok := s.fetcher.(detailedSourceDiscoverer); ok {
				var report news.DiscoveryResult
				report, err = detailed.DiscoverDetailed(ctx, attempt.URL)
				links = report.Links
				attempt.InspectedLinks, attempt.UninspectedLinks = report.InspectedLinks, report.UninspectedLinks
				for _, rejected := range report.Rejected {
					attempt.Rejected = append(attempt.Rejected, RejectedDiscoveryCandidate{URL: canonicalCandidateURL(rejected.URL), Reason: discoveryErrorReason(rejected.Code), Error: rejected.Code})
				}
			} else {
				links, err = discoverer.Discover(ctx, attempt.URL)
			}
			if err != nil {
				attempt.Error, attempt.ErrorCode = sourceErrorReason(err), news.ErrorCode(err)
			}
			attempt.Found = len(links)
			for _, link := range links {
				if !link.PublishedAt.IsZero() && (!link.PublishedAt.After(control.Add(-24*time.Hour)) || link.PublishedAt.After(control)) {
					attempt.OutsideWindow++
					attempt.Rejected = append(attempt.Rejected, RejectedDiscoveryCandidate{URL: canonicalCandidateURL(link.URL), Reason: "Дата в ленте вне окна (T−24h, T]; HTML статьи не загружался.", Error: "feed_outside_period"})
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
	for _, attempt := range attempts {
		if attempt.UninspectedLinks > 0 {
			research.Warnings = append(research.Warnings, fmt.Sprintf("В %s осталось %d непросмотренных ссылок: бюджет просмотра — 300 адресов на раздел или ленту; таймаут также может прервать просмотр. Повторяющиеся ссылки учитываются отдельно.", attempt.URL, attempt.UninspectedLinks))
		}
		if attempt.Error != "" {
			research.recordCandidate(canonicalCandidateURL(attempt.URL), "discovery", "excluded", attempt.Error, attempt.ErrorCode)
		}
		for _, rejected := range attempt.Rejected {
			research.recordCandidate(rejected.URL, "discovery", "excluded", rejected.Reason, rejected.Error)
		}
	}
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

func discoveryErrorReason(code string) string {
	switch code {
	case "listing_page":
		return "Ссылка ведёт на главную страницу или раздел, а не публикацию."
	case "untrusted_url":
		return "Ссылка вне разрешённых публичных HTTPS-источников; не загружалась."
	case "discovery_limit":
		return "Превышен лимит 60 ссылок из одного раздела или ленты; публикация не загружалась."
	case "not_article_path":
		return "Адрес не соответствует публикациям этого источника; не загружался."
	default:
		return "Ссылка не прошла проверку на этапе поиска публикаций."
	}
}
