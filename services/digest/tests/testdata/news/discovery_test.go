package news

import (
	"errors"
	"fmt"
	"net/http"
	"slices"
	"strings"
	"testing"
	"time"
)

func discoveryFixture(domain, contentType, body string) *Client {
	client := New([]string{domain})
	client.http.Transport = roundTripFunc(func(req *http.Request) (*http.Response, error) {
		response := htmlResponse(req, body)
		response.Header.Set("Content-Type", contentType)
		return response, nil
	})
	return client
}

func TestDiscoverHTMLResolvesArticleLinksAndSkipsListingsAndForeignHosts(t *testing.T) {
	body := `<a href="/ru/news/1105010/?utm_source=feed">article</a><a href="/ru/news/1105010/#comments">duplicate</a><a href="/ru/companies/pt/articles/1008318/?page=2&amp;utm_medium=ref">company article</a><a href="/ru/hubs/infosecurity/news/">listing</a><a href="https://evil.example/ru/news/123/">external</a><a href="http://habr.com/ru/news/123/">insecure</a><a href="/ru/users/test/">profile</a><a href="https://user:secret@habr.com/ru/news/123/">credentials</a>`
	got, err := discoveryFixture("habr.com", "text/html", body).Discover(t.Context(), "https://habr.com/ru/hubs/infosecurity/news/")
	want := []DiscoveredLink{{URL: "https://habr.com/ru/news/1105010/"}, {URL: "https://habr.com/ru/companies/pt/articles/1008318/?page=2"}}
	if err != nil || !slices.Equal(got, want) {
		t.Fatalf("got %v, err %v", got, err)
	}
}

func TestDiscoverRSSAndAtom(t *testing.T) {
	for _, test := range []struct{ name, kind, body string }{
		{"rss", "application/rss+xml", `<rss><channel><item><link>https://www.securitylab.ru/news/579794.php</link><pubDate>Sun, 08 Nov 2026 08:25:05 +0300</pubDate></item><item><link>/news/579790.php</link><pubDate>2026-11-08</pubDate></item></channel></rss>`},
		{"atom", "application/atom+xml", `<feed xmlns="http://www.w3.org/2005/Atom"><entry><link rel="alternate" href="https://www.securitylab.ru/news/579794.php"/><link rel="self" href="https://www.securitylab.ru/atom/item/579794"/><published>2026-11-08T08:25:05+03:00</published></entry><entry><link href="/news/579790.php"/><updated>2026-11-08T09:25:05+03:00</updated></entry></feed>`},
	} {
		t.Run(test.name, func(t *testing.T) {
			got, err := discoveryFixture("securitylab.ru", test.kind, test.body).Discover(t.Context(), "https://www.securitylab.ru/news/rss/")
			published, _ := time.Parse(time.RFC3339, "2026-11-08T08:25:05+03:00")
			if err != nil || len(got) != 2 || got[0].URL != "https://www.securitylab.ru/news/579794.php" || !got[0].PublishedAt.Equal(published) || got[1].URL != "https://www.securitylab.ru/news/579790.php" || !got[1].PublishedAt.IsZero() {
				t.Fatalf("got %v, err %v", got, err)
			}
		})
	}
}

func TestDiscoverKnownPublisherArticlePaths(t *testing.T) {
	for _, test := range []struct{ domain, article, rejected string }{
		{"xakep.ru", "/2026/11/08/security-news/", "/tag/security/"},
		{"ptsecurity.com", "/about/news/security-news/", "/products/"},
		{"ptsecurity.com", "/research/analytics/security-report/", "/research/analytics/"},
		{"kaspersky.ru", "/about/press-releases/2026-security-news", "/about/press-releases/"},
		{"kaspersky.ru", "/blog/security-news/12345/", "/products/"},
	} {
		t.Run(test.domain+test.article, func(t *testing.T) {
			body := `<a href="` + test.article + `">article</a><a href="` + test.rejected + `">section</a>`
			got, err := discoveryFixture(test.domain, "text/html", body).Discover(t.Context(), "https://"+test.domain+"/")
			if err != nil || len(got) != 1 || got[0].URL != "https://"+test.domain+test.article || !got[0].PublishedAt.IsZero() {
				t.Fatalf("got %v, err %v", got, err)
			}
		})
	}
}

func TestDiscoveryDatesRequireTimeAndUnambiguousTimezone(t *testing.T) {
	for _, raw := range []string{"2026-11-08", "2026-11-08T08:25:05", "Sun, 08 Nov 2026 08:25:05 MSK", "invalid"} {
		if got := discoveryPublicationDate(raw); !got.IsZero() {
			t.Errorf("ambiguous date %q accepted: %v", raw, got)
		}
	}
	for _, raw := range []string{"Sun, 08 Nov 2026 05:25:05 GMT", "Sun, 08 Nov 2026 05:25:05 UTC", "Sun, 08 Nov 2026 08:25:05 +0300", "2026-11-08T08:25:05+03:00"} {
		if got := discoveryPublicationDate(raw); got.IsZero() {
			t.Errorf("explicit date %q rejected", raw)
		}
	}
}

func TestDiscoverLimitsHTMLAndFeedLinksAndRejectsMalformedOrOversizedDocuments(t *testing.T) {
	var body strings.Builder
	for i := range 100 {
		fmt.Fprintf(&body, `<a href="/news/%d.php">article</a>`, i)
	}
	got, err := discoveryFixture("securitylab.ru", "text/html", body.String()).Discover(t.Context(), "https://www.securitylab.ru/news/")
	if err != nil || len(got) != 60 {
		t.Fatalf("HTML discovery exceeded the candidate budget: %d %v", len(got), err)
	}
	body.Reset()
	body.WriteString("<rss><channel>")
	for i := range 100 {
		fmt.Fprintf(&body, `<item><link>https://www.securitylab.ru/news/%d.php</link></item>`, i)
	}
	body.WriteString("</channel></rss>")
	got, err = discoveryFixture("securitylab.ru", "application/rss+xml", body.String()).Discover(t.Context(), "https://www.securitylab.ru/feed/")
	if err != nil || len(got) != 60 {
		t.Fatalf("feed discovery exceeded the candidate budget: %d %v", len(got), err)
	}
	for _, body := range []string{`<rss><channel>`, strings.Repeat("x", maxBodyBytes+1), `<!DOCTYPE rss [<!ENTITY secret SYSTEM "file:///etc/passwd">]><rss><channel><item><link>&secret;</link></item></channel></rss>`} {
		if _, err := discoveryFixture("securitylab.ru", "application/rss+xml", body).Discover(t.Context(), "https://securitylab.ru/feed/"); !errors.Is(err, ErrUnavailable) {
			t.Fatalf("bad XML or size accepted: %v", err)
		}
	}
}

func TestDiscoverUsesExistingAllowlistAndRedirectValidation(t *testing.T) {
	client := New([]string{"habr.com"})
	calls := 0
	client.http.Transport = roundTripFunc(func(req *http.Request) (*http.Response, error) {
		calls++
		response := htmlResponse(req, "")
		response.StatusCode = http.StatusFound
		response.Header.Set("Location", "https://169.254.169.254/latest/meta-data")
		return response, nil
	})
	if _, err := client.Discover(t.Context(), "https://not-habr.example/"); !errors.Is(err, ErrUntrustedURL) || calls != 0 {
		t.Fatalf("untrusted input reached transport: %d %v", calls, err)
	}
	if _, err := client.Discover(t.Context(), "https://habr.com/ru/news/"); !errors.Is(err, ErrUntrustedURL) || calls != 1 {
		t.Fatalf("untrusted redirect reached transport: %d %v", calls, err)
	}
}
