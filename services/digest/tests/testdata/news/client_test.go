package news

import (
	"context"
	"errors"
	"io"
	"net"
	"net/http"
	"net/netip"
	"strings"
	"testing"
	"time"
	"unicode/utf8"
)

type roundTripFunc func(*http.Request) (*http.Response, error)

func (f roundTripFunc) RoundTrip(req *http.Request) (*http.Response, error) { return f(req) }

// Technical HTML fixtures exercise parsing only; no live sources or model calls.
const validHTML = `<!doctype html><html><head><meta property="article:published_time" content="2026-11-07T11:00:00+03:00"><meta property="og:title" content="Заголовок &amp; детали"></head><body><nav>Не статья</nav><article><h1>Запасной заголовок</h1><p>Первый <strong>абзац</strong>.</p><p>Второй абзац.</p><div class="advertisement">Реклама</div><script>секрет скрипта</script><aside>Похожая новость</aside><footer>Подвал</footer></article></body></html>`

func htmlResponse(req *http.Request, body string) *http.Response {
	return &http.Response{StatusCode: http.StatusOK, Header: http.Header{"Content-Type": {"text/html; charset=utf-8"}}, Body: io.NopCloser(strings.NewReader(body)), Request: req, ContentLength: -1}
}

func fixtureClient(body string) *Client {
	client := New([]string{"news.example"})
	client.http.Transport = roundTripFunc(func(req *http.Request) (*http.Response, error) { return htmlResponse(req, body), nil })
	return client
}

func TestFetchParsesPublishedDateAndReadableArticle(t *testing.T) {
	article, err := fixtureClient(validHTML).Fetch(t.Context(), "https://news.example/item#fragment")
	if err != nil {
		t.Fatal(err)
	}
	if article.URL != "https://news.example/item" || article.Title != "Заголовок & детали" || article.PublishedAt.Format(time.RFC3339) != "2026-11-07T11:00:00+03:00" || article.DateEvidence == "" {
		t.Fatalf("bad metadata: %+v", article)
	}
	if !strings.Contains(article.Text, "Первый абзац. Второй абзац.") {
		t.Fatalf("lost paragraph or inline structure: %q", article.Text)
	}
	for _, ignored := range []string{"Не статья", "Реклама", "секрет скрипта", "Похожая новость", "Подвал"} {
		if strings.Contains(article.Text, ignored) {
			t.Fatalf("included excluded page section %q", ignored)
		}
	}
}

func TestURLAllowlistRejectsSSRFAndUnexpectedSchemes(t *testing.T) {
	for _, target := range []string{
		"http://news.example/a", "https://evil.example/a", "https://news.example.evil.test/a", "https://evilnews.example/a",
		"https://user:password@news.example/a", "https://news.example:8443/a", "https://news.example:0443/a",
		"https://127.0.0.1/a", "https://[::1]/a", "https://169.254.169.254/latest/meta-data", "file:///etc/passwd",
	} {
		t.Run(target, func(t *testing.T) {
			client := New([]string{"news.example", "127.0.0.1", "169.254.169.254"})
			client.http.Transport = roundTripFunc(func(*http.Request) (*http.Response, error) {
				t.Fatal("untrusted URL reached transport")
				return nil, nil
			})
			_, err := client.Fetch(t.Context(), target)
			if !errors.Is(err, ErrUntrustedURL) {
				t.Fatalf("want untrusted URL, got %v", err)
			}
		})
	}
	for _, target := range []string{"https://news.example/a", "https://sub.news.example/a", "https://NEWS.EXAMPLE:443/a"} {
		if _, err := fixtureClient(validHTML).Fetch(t.Context(), target); err != nil {
			t.Fatalf("trusted target rejected: %s %v", target, err)
		}
	}
}

func TestRedirectsUseSameAllowlist(t *testing.T) {
	for _, target := range []string{"https://evil.example/a", "http://news.example/a", "https://169.254.169.254/a", "https://user@news.example/a"} {
		t.Run(target, func(t *testing.T) {
			client := New([]string{"news.example"})
			calls := 0
			client.http.Transport = roundTripFunc(func(req *http.Request) (*http.Response, error) {
				calls++
				response := htmlResponse(req, "")
				response.StatusCode = http.StatusFound
				response.Header.Set("Location", target)
				return response, nil
			})
			_, err := client.Fetch(t.Context(), "https://news.example/start")
			if !errors.Is(err, ErrUntrustedURL) || calls != 1 {
				t.Fatalf("redirect escaped allowlist: calls=%d err=%v", calls, err)
			}
		})
	}
	client := New([]string{"news.example"})
	calls := 0
	client.http.Transport = roundTripFunc(func(req *http.Request) (*http.Response, error) {
		calls++
		if calls == 1 {
			response := htmlResponse(req, "")
			response.StatusCode = http.StatusMovedPermanently
			response.Header.Set("Location", "https://sub.news.example/final")
			return response, nil
		}
		return htmlResponse(req, validHTML), nil
	})
	article, err := client.Fetch(t.Context(), "https://news.example/start")
	if err != nil || calls != 2 || article.URL != "https://sub.news.example/final" {
		t.Fatalf("trusted redirect failed: %+v %v", article, err)
	}
}

func TestPublicIPsExcludeReservedAndEmbeddedPrivateNetworks(t *testing.T) {
	for _, raw := range []string{"0.0.0.0", "0.1.2.3", "10.0.0.1", "100.64.0.1", "127.0.0.1", "169.254.169.254", "172.16.0.1", "192.168.1.1", "192.0.0.9", "192.0.2.1", "198.18.0.1", "198.51.100.1", "203.0.113.1", "224.0.0.1", "240.0.0.1", "255.255.255.255", "::", "::1", "fc00::1", "fe80::1", "ff02::1", "::ffff:127.0.0.1", "64:ff9b::7f00:1", "2001::1", "2001:db8::1", "2002:7f00:1::1", "3fff::1"} {
		if publicIP(netip.MustParseAddr(raw)) {
			t.Errorf("non-public address accepted: %s", raw)
		}
	}
	for _, raw := range []string{"8.8.8.8", "1.1.1.1", "2606:4700:4700::1111", "2001:4860:4860::8888"} {
		if !publicIP(netip.MustParseAddr(raw)) {
			t.Errorf("public address rejected: %s", raw)
		}
	}
}

func TestDialPinsCheckedDNSAddressAndRejectsMixedAnswers(t *testing.T) {
	client := New([]string{"news.example"})
	lookups := 0
	client.lookup = func(context.Context, string, string) ([]netip.Addr, error) {
		lookups++
		if lookups > 1 {
			return []netip.Addr{netip.MustParseAddr("127.0.0.1")}, nil
		}
		return []netip.Addr{netip.MustParseAddr("8.8.8.8")}, nil
	}
	client.dial = func(_ context.Context, network, address string) (net.Conn, error) {
		if network != "tcp" || address != "8.8.8.8:443" {
			t.Fatalf("address was not pinned: %s %s", network, address)
		}
		left, right := net.Pipe()
		right.Close()
		return left, nil
	}
	connection, err := client.dialPublic(t.Context(), "tcp", "news.example:443")
	if err != nil {
		t.Fatal(err)
	}
	connection.Close()
	if lookups != 1 {
		t.Fatal("DNS was queried again before dialing")
	}
	client.lookup = func(context.Context, string, string) ([]netip.Addr, error) {
		return []netip.Addr{netip.MustParseAddr("8.8.8.8"), netip.MustParseAddr("10.0.0.1")}, nil
	}
	client.dial = func(context.Context, string, string) (net.Conn, error) {
		t.Fatal("mixed public/private DNS answer reached dialer")
		return nil, nil
	}
	if _, err := client.dialPublic(t.Context(), "tcp", "news.example:443"); !errors.Is(err, ErrUntrustedURL) {
		t.Fatalf("private DNS accepted: %v", err)
	}
	transport := New([]string{"news.example"}).http.Transport.(*http.Transport)
	if transport.Proxy != nil || transport.DialTLSContext != nil {
		t.Fatal("proxy or TLS dial bypasses the checked dialer")
	}
}

func TestTimeoutContentTypeAndBodyBounds(t *testing.T) {
	client := New([]string{"news.example"})
	client.http.Transport = roundTripFunc(func(req *http.Request) (*http.Response, error) {
		<-req.Context().Done()
		return nil, req.Context().Err()
	})
	ctx, cancel := context.WithTimeout(t.Context(), 10*time.Millisecond)
	defer cancel()
	_, err := client.Fetch(ctx, "https://news.example/a")
	if !errors.Is(err, ErrUnavailable) || !errors.Is(err, context.DeadlineExceeded) {
		t.Fatalf("timeout not preserved: %v", err)
	}
	for _, kind := range []string{"oversized", "pdf", "status"} {
		t.Run(kind, func(t *testing.T) {
			client := New([]string{"news.example"})
			client.http.Transport = roundTripFunc(func(req *http.Request) (*http.Response, error) {
				response := htmlResponse(req, validHTML)
				switch kind {
				case "oversized":
					response.Body = io.NopCloser(strings.NewReader(strings.Repeat("x", maxBodyBytes+1)))
				case "pdf":
					response.Header.Set("Content-Type", "application/pdf")
				case "status":
					response.StatusCode = http.StatusForbidden
				}
				return response, nil
			})
			if _, err := client.Fetch(t.Context(), "https://news.example/a"); !errors.Is(err, ErrUnavailable) {
				t.Fatalf("unsupported response accepted: %v", err)
			}
		})
	}
}

func TestUnicodeTruncationIsExplicit(t *testing.T) {
	text := strings.Repeat("Кириллица ", 2000)
	body := `<meta property="article:published_time" content="2026-11-07T09:00:00Z"><article><p>` + text + `</p></article>`
	article, err := fixtureClient(body).Fetch(t.Context(), "https://news.example/a")
	if err != nil {
		t.Fatal(err)
	}
	if !article.TextTruncated || !utf8.ValidString(article.Text) || utf8.RuneCountInString(article.Text) > maxTextRunes || !strings.HasPrefix(text, article.Text) {
		t.Fatalf("invalid truncation: %d runes, %+v", utf8.RuneCountInString(article.Text), article.TextTruncated)
	}
}

func TestLegacyCharsetIsDecodedBeforeHTMLExtraction(t *testing.T) {
	client := New([]string{"news.example"})
	body := `<meta property="article:published_time" content="2026-11-07T09:00:00Z"><article><p>` + string([]byte{0xcf, 0xf0, 0xe8, 0xe2, 0xe5, 0xf2}) + `</p></article>`
	client.http.Transport = roundTripFunc(func(req *http.Request) (*http.Response, error) {
		response := htmlResponse(req, body)
		response.Header.Set("Content-Type", "text/html; charset=windows-1251")
		return response, nil
	})
	article, err := client.Fetch(t.Context(), "https://news.example/a")
	if err != nil || article.Text != "Привет" {
		t.Fatalf("charset failed: %q %v", article.Text, err)
	}
}
