package news

import (
	"bytes"
	"context"
	"encoding/xml"
	"errors"
	"fmt"
	"io"
	"mime"
	"net/http"
	"net/url"
	"regexp"
	"slices"
	"strings"
	"time"

	"golang.org/x/net/html"
	"golang.org/x/net/html/charset"
)

// DiscoveredLink is a candidate, not a verified article. Feed publication dates
// are only discovery hints; Fetch must independently check the source page.
type DiscoveredLink struct {
	URL         string
	PublishedAt time.Time
}

// Discover reads one feed or listing page and returns article candidates. It
// never follows discovered links; Fetch must independently verify their dates.
type DiscoveryRejection struct {
	URL  string `json:"url"`
	Code string `json:"error"`
}

type DiscoveryResult struct {
	Links            []DiscoveredLink
	Rejected         []DiscoveryRejection
	InspectedLinks   int
	UninspectedLinks int
}

func (c *Client) Discover(ctx context.Context, rawURL string) ([]DiscoveredLink, error) {
	result, err := c.DiscoverDetailed(ctx, rawURL)
	return result.Links, err
}

func (c *Client) DiscoverDetailed(ctx context.Context, rawURL string) (DiscoveryResult, error) {
	u, err := url.Parse(rawURL)
	if err != nil {
		return DiscoveryResult{}, ErrUntrustedURL
	}
	if err := c.trustedURL(u); err != nil {
		return DiscoveryResult{}, err
	}
	u.Fragment = ""
	req, err := http.NewRequestWithContext(ctx, http.MethodGet, u.String(), nil)
	if err != nil {
		return DiscoveryResult{}, ErrUntrustedURL
	}
	req.Header.Set("User-Agent", "notFive-news-verifier/1.0")
	req.Header.Set("Accept", "application/rss+xml, application/atom+xml, application/xml, text/xml, text/html, application/xhtml+xml")
	response, err := c.http.Do(req)
	if err != nil {
		if errors.Is(err, ErrUntrustedURL) {
			return DiscoveryResult{}, ErrUntrustedURL
		}
		if detail, ok := errors.AsType[*SourceError](err); ok {
			return DiscoveryResult{}, detail
		}
		if timed, ok := errors.AsType[interface {
			error
			Timeout() bool
		}](err); ok && timed.Timeout() {
			return DiscoveryResult{}, failure(ErrUnavailable, "request_timeout", "")
		}
		if ctx.Err() != nil {
			return DiscoveryResult{}, errors.Join(ErrUnavailable, ctx.Err())
		}
		return DiscoveryResult{}, failure(ErrUnavailable, "transport_error", "")
	}
	defer response.Body.Close()
	if response.StatusCode != http.StatusOK {
		return DiscoveryResult{}, failure(ErrUnavailable, fmt.Sprintf("http_status_%d", response.StatusCode), "")
	}
	if response.ContentLength > maxBodyBytes {
		return DiscoveryResult{}, failure(ErrUnavailable, "body_too_large", "")
	}
	body, err := io.ReadAll(io.LimitReader(response.Body, maxBodyBytes+1))
	if len(body) > maxBodyBytes {
		return DiscoveryResult{}, failure(ErrUnavailable, "body_too_large", "")
	}
	if err != nil {
		return DiscoveryResult{}, failure(errors.Join(ErrUnavailable, ctx.Err()), "body_read_error", "")
	}
	contentType := response.Header.Get("Content-Type")
	if contentType == "" {
		contentType = http.DetectContentType(body)
	}
	mediaType, _, err := mime.ParseMediaType(contentType)
	if err != nil {
		return DiscoveryResult{}, ErrUnavailable
	}
	var links []DiscoveredLink
	if mediaType == "text/html" || mediaType == "application/xhtml+xml" {
		reader, err := charset.NewReader(bytes.NewReader(body), contentType)
		if err != nil {
			return DiscoveryResult{}, ErrUnavailable
		}
		document, err := html.Parse(reader)
		if err != nil {
			return DiscoveryResult{}, ErrUnavailable
		}
		for node := range document.Descendants() {
			if node.Type == html.ElementNode && node.Data == "a" {
				links = append(links, DiscoveredLink{URL: attribute(node, "href")})
			}
		}
	} else if mediaType == "application/xml" || mediaType == "text/xml" || strings.HasSuffix(mediaType, "+xml") || bytes.HasPrefix(bytes.TrimSpace(body), []byte("<?xml")) {
		links, err = discoveryFeedLinks(body)
		if err != nil {
			return DiscoveryResult{}, ErrUnavailable
		}
	} else {
		return DiscoveryResult{}, ErrUnavailable
	}
	return c.discoveryArticleReportContext(ctx, response.Request.URL, links)
}

func discoveryFeedLinks(body []byte) ([]DiscoveredLink, error) {
	type feedLink struct {
		Href string `xml:"href,attr"`
		Rel  string `xml:"rel,attr"`
		Text string `xml:",chardata"`
	}
	type entry struct {
		Links     []feedLink `xml:"link"`
		PubDate   string     `xml:"pubDate"`
		Date      string     `xml:"date"`
		Published string     `xml:"published"`
	}
	var feed struct {
		XMLName xml.Name
		Channel struct {
			Items []entry `xml:"item"`
		} `xml:"channel"`
		Items   []entry `xml:"item"`
		Entries []entry `xml:"entry"`
	}
	decoder := xml.NewDecoder(bytes.NewReader(body))
	decoder.CharsetReader = charset.NewReaderLabel
	// encoding/xml does not fetch external entities. Unknown entity references
	// fail decoding instead of being expanded or fetched over the network.
	if err := decoder.Decode(&feed); err != nil {
		return nil, err
	}
	if !slices.Contains([]string{"rss", "feed", "RDF"}, feed.XMLName.Local) {
		return nil, ErrUnavailable
	}
	links := []DiscoveredLink{}
	for _, entries := range [][]entry{feed.Channel.Items, feed.Items, feed.Entries} {
		for _, item := range entries {
			var publishedAt time.Time
			for _, raw := range []string{item.PubDate, item.Published, item.Date} {
				if publishedAt = discoveryPublicationDate(raw); !publishedAt.IsZero() {
					break
				}
			}
			for _, link := range item.Links {
				if link.Rel != "" && link.Rel != "alternate" {
					continue
				}
				if link.Href != "" {
					links = append(links, DiscoveredLink{URL: link.Href, PublishedAt: publishedAt})
				} else {
					links = append(links, DiscoveredLink{URL: link.Text, PublishedAt: publishedAt})
				}
			}
		}
	}
	return links, nil
}

func discoveryPublicationDate(raw string) time.Time {
	raw = strings.TrimSpace(raw)
	for _, layout := range []string{time.RFC3339, time.RFC1123Z} {
		if parsed, err := time.Parse(layout, raw); err == nil {
			return parsed
		}
	}
	// Go interprets unknown named zones as UTC. Accept only unambiguous names;
	// date-only values, local timestamps and Atom updated are not publication dates.
	if strings.HasSuffix(raw, " GMT") || strings.HasSuffix(raw, " UTC") {
		if parsed, err := time.Parse(time.RFC1123, raw); err == nil {
			return parsed
		}
	}
	return time.Time{}
}

var (
	securityLabArticlePath = regexp.MustCompile(`^/news/[0-9]+\.php/?$`)
	xakepArticlePath       = regexp.MustCompile(`^/[0-9]{4}/[0-9]{2}/[0-9]{2}/[^/]+/?$`)
	habrArticlePath        = regexp.MustCompile(`^/(ru|en)/(news|articles)/[0-9]+/?$|^/(ru|en)/companies/[^/]+/(news|articles)/[0-9]+/?$`)
	ptArticlePath          = regexp.MustCompile(`^/(about/news|research/analytics)/[^/]+/?$`)
	kasperskyArticlePath   = regexp.MustCompile(`^/about/press-releases/[^/]+/?$|^/blog/[^/]+(/[0-9]+)?/?$`)
)

func (c *Client) discoveryArticleLinks(base *url.URL, links []DiscoveredLink) []DiscoveredLink {
	return c.discoveryArticleReport(base, links).Links
}

func (c *Client) discoveryArticleReport(base *url.URL, links []DiscoveredLink) DiscoveryResult {
	result, _ := c.discoveryArticleReportContext(context.Background(), base, links)
	return result
}

func (c *Client) discoveryArticleReportContext(ctx context.Context, base *url.URL, links []DiscoveredLink) (DiscoveryResult, error) {
	result := DiscoveryResult{Links: []DiscoveredLink{}, Rejected: []DiscoveryRejection{}}
	seen := map[string]bool{}
	for i, candidate := range links {
		if err := ctx.Err(); err != nil {
			result.UninspectedLinks = len(links) - i
			return result, err
		}
		result.InspectedLinks++
		raw := strings.TrimSpace(candidate.URL)
		if raw == "" || strings.HasPrefix(raw, "#") {
			continue
		}
		ref, err := url.Parse(raw)
		if err != nil {
			continue
		}
		link := base.ResolveReference(ref)
		if link.Scheme != "https" && link.Scheme != "http" {
			continue
		}
		link.Fragment, link.RawFragment = "", ""
		trustErr := c.trustedURL(link)
		if link.User != nil {
			link.User = nil
			link.RawQuery = ""
		}
		host := strings.TrimSuffix(strings.ToLower(link.Hostname()), ".")
		if link.Port() == "" || link.Port() == "443" {
			link.Host = host
		} else {
			link.Host = strings.ToLower(link.Host)
		}
		query := link.Query()
		for key := range query {
			if strings.HasPrefix(strings.ToLower(key), "utm_") {
				query.Del(key)
			}
		}
		link.RawQuery = query.Encode()
		address := link.String()
		if len(address) > 2048 {
			continue
		}
		key := address
		if trustErr != nil {
			key += "#untrusted"
		}
		if seen[key] {
			continue
		}
		seen[key] = true
		reject := func(code string) {
			result.Rejected = append(result.Rejected, DiscoveryRejection{URL: address, Code: code})
		}
		if trustErr != nil {
			reject("untrusted_url")
			continue
		}
		if IsListingURL(address) {
			reject("listing_page")
			continue
		}
		isHost := func(domain string) bool { return host == domain || strings.HasSuffix(host, "."+domain) }
		article := true
		switch {
		case isHost("securitylab.ru"):
			article = securityLabArticlePath.MatchString(link.Path)
		case isHost("xakep.ru"):
			article = xakepArticlePath.MatchString(link.Path)
		case isHost("habr.com"):
			article = habrArticlePath.MatchString(link.Path)
		case isHost("ptsecurity.com"):
			article = ptArticlePath.MatchString(link.Path)
		case isHost("kaspersky.ru"):
			article = kasperskyArticlePath.MatchString(link.Path)
		}
		if !article {
			reject("not_article_path")
			continue
		}
		result.Links = append(result.Links, DiscoveredLink{URL: address, PublishedAt: candidate.PublishedAt})
	}
	return result, nil
}
