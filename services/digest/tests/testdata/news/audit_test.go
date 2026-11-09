package news

import (
	"context"
	"errors"
	"fmt"
	"net/http"
	"net/url"
	"strings"
	"testing"
)

func TestCollectionCatalogListingsDoNotConsumeArticleSlots(t *testing.T) {
	for _, address := range []string{
		"https://3dnews.ru/tags/%D1%83%D1%8F%D0%B7%D0%B2%D0%B8%D0%BC%D0%BE%D1%81%D1%82%D1%8C",
		"https://www.it-world.ru/security/", "https://www.itweek.ru/security/", "https://rb.ru/tag/cybersecurity/",
		"https://www.ixbt.com/live/security/", "https://www.comnews.ru/information_security", "https://ict.moscow/projects/cybersecurity/",
		"https://www.infowatch.ru/analytics/novosti-ib", "https://infobezopasnost.ru/blog/news/", "https://rg.ru/theme/technologies/internet/kiberbezopasnost/",
	} {
		if !IsListingURL(address) {
			t.Errorf("listing accepted: %s", address)
		}
	}
	for _, address := range []string{"https://www.itweek.ru/security/article/detail.php?ID=235879", "https://www.ixbt.com/live/sw/security-report.html", "https://rb.ru/columns/security-report/"} {
		if IsListingURL(address) {
			t.Errorf("article rejected as listing: %s", address)
		}
	}
}

func TestPublicationDateAuditDistinguishesErrorsWithoutGuessing(t *testing.T) {
	for _, sample := range []struct{ metadata, code string }{
		{"", "publication_date_missing"},
		{`<meta property="article:published_time" content="2026-11-09T12:00:00">`, "publication_date_format_or_timezone"},
		{`<meta property="article:published_time" content="2026-11-09T12:00:00+03:00"><meta itemprop="datePublished" content="2026-11-09T13:00:00+03:00">`, "publication_date_conflict"},
	} {
		article, err := parseHTML(strings.NewReader(sample.metadata + `<article><h1>Headline</h1><p>Source text remains available for the audit.</p></article>`))
		if !errors.Is(err, ErrUnknownDate) || ErrorCode(err) != sample.code || article.Text == "" || article.Title != "Headline" {
			t.Fatalf("lost precise date failure/content: %+v %v", article, err)
		}
		if sample.metadata != "" && article.DateEvidence == "" {
			t.Fatal("date metadata lost")
		}
	}
}

func TestSourceHTTPFailureAuditContainsStatus(t *testing.T) {
	c := New([]string{"itweek.ru"})
	c.http.Transport = roundTripFunc(func(req *http.Request) (*http.Response, error) {
		response := htmlResponse(req, "Forbidden")
		response.StatusCode = http.StatusForbidden
		return response, nil
	})
	_, err := c.Fetch(t.Context(), "https://itweek.ru/security/article/detail.php?ID=1")
	if !errors.Is(err, ErrUnavailable) || ErrorCode(err) != "http_status_403" {
		t.Fatalf("lost HTTP status: %v", err)
	}
	_, err = c.DiscoverDetailed(t.Context(), "https://itweek.ru/security/")
	if ErrorCode(err) != "http_status_403" {
		t.Fatalf("lost discovery HTTP status: %v", err)
	}
}

func TestDiscoveryRecordsListingsForeignURLsAndBudgetDecisions(t *testing.T) {
	body := `<a href="/tags/security/">catalog</a><a href="https://foreign.example/article">foreign</a>`
	for i := range 63 {
		body += fmt.Sprintf(`<a href="/news/%d.html">publication</a>`, i)
	}
	report, err := discoveryFixture("3dnews.ru", "text/html", body).DiscoverDetailed(t.Context(), "https://3dnews.ru/security")
	if err != nil || len(report.Links) != 60 || len(report.Rejected) != 5 {
		t.Fatalf("lost discovery decisions: %+v %v", report, err)
	}
	counts := map[string]int{}
	for _, rejected := range report.Rejected {
		counts[rejected.Code]++
	}
	if counts["listing_page"] != 1 || counts["untrusted_url"] != 1 || counts["discovery_limit"] != 3 {
		t.Fatalf("wrong rejection reasons: %v", counts)
	}
}

func TestDiscoveryAuditBoundsHTMLAndFeedInspectedLinksAndReportsOmissions(t *testing.T) {
	for _, contentType := range []string{"text/html", "application/rss+xml"} {
		t.Run(contentType, func(t *testing.T) {
			var body strings.Builder
			if contentType == "application/rss+xml" {
				body.WriteString("<rss><channel>")
			}
			for i := range 2000 {
				if contentType == "text/html" {
					fmt.Fprintf(&body, `<a href="/news/%d.html">publication</a>`, i)
				} else {
					fmt.Fprintf(&body, `<item><link>https://3dnews.ru/news/%d.html</link></item>`, i)
				}
			}
			if contentType == "application/rss+xml" {
				body.WriteString("</channel></rss>")
			}
			report, err := discoveryFixture("3dnews.ru", contentType, body.String()).DiscoverDetailed(t.Context(), "https://3dnews.ru/security")
			if err != nil || len(report.Links) != 60 || len(report.Rejected) != 240 || report.InspectedLinks != 300 || report.UninspectedLinks != 1700 {
				t.Fatalf("unbounded or silently incomplete discovery: links=%d rejected=%d inspected=%d uninspected=%d err=%v", len(report.Links), len(report.Rejected), report.InspectedLinks, report.UninspectedLinks, err)
			}
			if report.Links[0].URL != "https://3dnews.ru/news/0.html" || report.Links[59].URL != "https://3dnews.ru/news/59.html" {
				t.Fatal("bounded audit dropped already discovered candidates")
			}
			for _, rejected := range report.Rejected {
				if rejected.Code != "discovery_limit" {
					t.Fatalf("budget rejection lost its reason: %+v", rejected)
				}
			}
		})
	}
}

func TestDiscoveryAuditStopsOnCancellation(t *testing.T) {
	ctx, cancel := context.WithCancel(t.Context())
	cancel()
	base, _ := url.Parse("https://3dnews.ru/security")
	report, err := New([]string{"3dnews.ru"}).discoveryArticleReportContext(ctx, base, []DiscoveredLink{{URL: "/news/one.html"}, {URL: "/news/two.html"}})
	if !errors.Is(err, context.Canceled) || report.InspectedLinks != 0 || report.UninspectedLinks != 2 {
		t.Fatalf("cancellation not observed: %+v %v", report, err)
	}
}
