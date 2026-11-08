// Package news fetches allowlisted public HTTPS pages and extracts publication
// metadata from the page itself. Page metadata is evidence, not proof of truth.
package news

import (
	"bytes"
	"context"
	"errors"
	"fmt"
	"io"
	"mime"
	"net"
	"net/http"
	"net/netip"
	"net/url"
	"strings"
	"time"

	"golang.org/x/net/html/charset"
)

const (
	maxBodyBytes = 2 * 1024 * 1024
	maxTextRunes = 12000
)

var (
	ErrUnknownDate  = errors.New("publication date is unknown or conflicting")
	ErrUntrustedURL = errors.New("source URL is not trusted")
	ErrUnavailable  = errors.New("source page is unavailable")
)

type Article struct {
	URL           string
	Title         string
	Text          string
	PublishedAt   time.Time
	DateEvidence  string
	TextTruncated bool
}

type Client struct {
	domains []string
	http    *http.Client
	lookup  func(context.Context, string, string) ([]netip.Addr, error)
	dial    func(context.Context, string, string) (net.Conn, error)
}

func New(domains []string) *Client {
	c := &Client{lookup: net.DefaultResolver.LookupNetIP, dial: (&net.Dialer{Timeout: 8 * time.Second}).DialContext}
	for _, domain := range domains {
		domain = strings.ToLower(strings.TrimSuffix(strings.TrimSpace(domain), "."))
		if domain != "" && !strings.ContainsAny(domain, "/:@[]\\ \t\r\n") {
			c.domains = append(c.domains, domain)
		}
	}
	transport := &http.Transport{
		Proxy: nil, DialContext: c.dialPublic, ForceAttemptHTTP2: true,
		TLSHandshakeTimeout: 8 * time.Second, ResponseHeaderTimeout: 10 * time.Second,
		MaxResponseHeaderBytes: 64 * 1024, MaxIdleConns: 8, IdleConnTimeout: 30 * time.Second,
	}
	c.http = &http.Client{Transport: transport, Timeout: 12 * time.Second, CheckRedirect: func(req *http.Request, via []*http.Request) error {
		if len(via) >= 5 {
			return fmt.Errorf("%w: redirect limit", ErrUnavailable)
		}
		return c.trustedURL(req.URL)
	}}
	return c
}

func (c *Client) Fetch(ctx context.Context, rawURL string) (Article, error) {
	u, err := url.Parse(rawURL)
	if err != nil {
		return Article{}, ErrUntrustedURL
	}
	if err := c.trustedURL(u); err != nil {
		return Article{}, err
	}
	u.Fragment = ""
	req, err := http.NewRequestWithContext(ctx, http.MethodGet, u.String(), nil)
	if err != nil {
		return Article{}, ErrUntrustedURL
	}
	req.Header.Set("User-Agent", "notFive-news-verifier/1.0")
	req.Header.Set("Accept", "text/html, application/xhtml+xml")
	response, err := c.http.Do(req)
	if err != nil {
		if errors.Is(err, ErrUntrustedURL) {
			return Article{}, ErrUntrustedURL
		}
		if ctx.Err() != nil {
			return Article{}, errors.Join(ErrUnavailable, ctx.Err())
		}
		return Article{}, ErrUnavailable
	}
	defer response.Body.Close()
	if response.StatusCode != http.StatusOK || response.ContentLength > maxBodyBytes {
		return Article{}, ErrUnavailable
	}
	body, err := io.ReadAll(io.LimitReader(response.Body, maxBodyBytes+1))
	if err != nil || len(body) > maxBodyBytes {
		if ctx.Err() != nil {
			return Article{}, errors.Join(ErrUnavailable, ctx.Err())
		}
		return Article{}, ErrUnavailable
	}
	contentType := response.Header.Get("Content-Type")
	if contentType == "" {
		contentType = http.DetectContentType(body)
	}
	mediaType, _, err := mime.ParseMediaType(contentType)
	if err != nil || (mediaType != "text/html" && mediaType != "application/xhtml+xml") {
		return Article{}, ErrUnavailable
	}
	reader, err := charset.NewReader(bytes.NewReader(body), contentType)
	if err != nil {
		return Article{}, ErrUnavailable
	}
	article, err := parseHTML(reader)
	if err != nil {
		return Article{}, err
	}
	article.URL = response.Request.URL.String()
	return article, nil
}

func (c *Client) trustedURL(u *url.URL) error {
	if u == nil || u.Scheme != "https" || u.User != nil || u.Opaque != "" || (u.Port() != "" && u.Port() != "443") {
		return ErrUntrustedURL
	}
	host := strings.ToLower(strings.TrimSuffix(u.Hostname(), "."))
	if host == "" || strings.ContainsAny(host, "\\% \t\r\n") {
		return ErrUntrustedURL
	}
	if ip, err := netip.ParseAddr(host); err == nil && !publicIP(ip) {
		return ErrUntrustedURL
	}
	for _, domain := range c.domains {
		if host == domain || strings.HasSuffix(host, "."+domain) {
			return nil
		}
	}
	return ErrUntrustedURL
}

// The exact DNS addresses checked here are dialled numerically. The HTTP
// transport still verifies TLS against the original hostname, preventing a
// second DNS lookup between validation and connection establishment.
func (c *Client) dialPublic(ctx context.Context, network, address string) (net.Conn, error) {
	host, port, err := net.SplitHostPort(address)
	if err != nil || port != "443" {
		return nil, ErrUntrustedURL
	}
	if err := c.trustedURL(&url.URL{Scheme: "https", Host: address}); err != nil {
		return nil, err
	}
	addresses, err := c.lookup(ctx, "ip", strings.TrimSuffix(host, "."))
	if err != nil || len(addresses) == 0 {
		return nil, ErrUnavailable
	}
	for _, ip := range addresses {
		if !publicIP(ip) {
			return nil, ErrUntrustedURL
		}
	}
	for _, ip := range addresses {
		connection, err := c.dial(ctx, network, net.JoinHostPort(ip.Unmap().String(), port))
		if err == nil {
			return connection, nil
		}
		if ctx.Err() != nil {
			return nil, ctx.Err()
		}
	}
	return nil, ErrUnavailable
}

var nonPublicRanges = []netip.Prefix{
	netip.MustParsePrefix("0.0.0.0/8"), netip.MustParsePrefix("100.64.0.0/10"),
	netip.MustParsePrefix("168.63.129.16/32"), // Azure's special virtual infrastructure address.
	netip.MustParsePrefix("192.0.0.0/24"), netip.MustParsePrefix("192.0.2.0/24"),
	netip.MustParsePrefix("192.88.99.0/24"), netip.MustParsePrefix("198.18.0.0/15"),
	netip.MustParsePrefix("198.51.100.0/24"), netip.MustParsePrefix("203.0.113.0/24"),
	netip.MustParsePrefix("240.0.0.0/4"), netip.MustParsePrefix("2001::/23"),
	netip.MustParsePrefix("2001:db8::/32"), netip.MustParsePrefix("2002::/16"),
	netip.MustParsePrefix("3fff::/20"),
}

func publicIP(address netip.Addr) bool {
	ip := address.Unmap()
	if !ip.IsValid() || ip.Zone() != "" || !ip.IsGlobalUnicast() || ip.IsPrivate() || ip.IsLoopback() || ip.IsLinkLocalUnicast() {
		return false
	}
	if ip.Is6() && !netip.MustParsePrefix("2000::/3").Contains(ip) {
		return false
	}
	for _, prefix := range nonPublicRanges {
		if prefix.Contains(ip) {
			return false
		}
	}
	return true
}
