package news

import (
	"bytes"
	"context"
	"encoding/xml"
	"errors"
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

const maxDiscoveredLinks = 60

// DiscoveredLink is a candidate, not a verified article. Feed publication dates
// are only discovery hints; Fetch must independently check the source page.
type DiscoveredLink struct {
	URL         string
	PublishedAt time.Time
}

// Discover reads one feed or listing page and returns article candidates. It
// never follows discovered links; Fetch must independently verify their dates.
func (c *Client) Discover(ctx context.Context, rawURL string) ([]DiscoveredLink, error) {
	u, err := url.Parse(rawURL)
	if err != nil {
		return nil, ErrUntrustedURL
	}
	if err := c.trustedURL(u); err != nil {
		return nil, err
	}
	u.Fragment = ""
	req, err := http.NewRequestWithContext(ctx, http.MethodGet, u.String(), nil)
	if err != nil {
		return nil, ErrUntrustedURL
	}
	req.Header.Set("User-Agent", "notFive-news-verifier/1.0")
	req.Header.Set("Accept", "application/rss+xml, application/atom+xml, application/xml, text/xml, text/html, application/xhtml+xml")
	response, err := c.http.Do(req)
	if err != nil {
		if errors.Is(err, ErrUntrustedURL) {
			return nil, ErrUntrustedURL
		}
		return nil, errors.Join(ErrUnavailable, ctx.Err())
	}
	defer response.Body.Close()
	if response.StatusCode != http.StatusOK || response.ContentLength > maxBodyBytes {
		return nil, ErrUnavailable
	}
	body, err := io.ReadAll(io.LimitReader(response.Body, maxBodyBytes+1))
	if err != nil || len(body) > maxBodyBytes {
		return nil, errors.Join(ErrUnavailable, ctx.Err())
	}
	contentType := response.Header.Get("Content-Type")
	if contentType == "" {
		contentType = http.DetectContentType(body)
	}
	mediaType, _, err := mime.ParseMediaType(contentType)
	if err != nil {
		return nil, ErrUnavailable
	}
	var links []DiscoveredLink
	if mediaType == "text/html" || mediaType == "application/xhtml+xml" {
		reader, err := charset.NewReader(bytes.NewReader(body), contentType)
		if err != nil {
			return nil, ErrUnavailable
		}
		document, err := html.Parse(reader)
		if err != nil {
			return nil, ErrUnavailable
		}
		for node := range document.Descendants() {
			if node.Type == html.ElementNode && node.Data == "a" {
				links = append(links, DiscoveredLink{URL: attribute(node, "href")})
			}
		}
	} else if mediaType == "application/xml" || mediaType == "text/xml" || strings.HasSuffix(mediaType, "+xml") || bytes.HasPrefix(bytes.TrimSpace(body), []byte("<?xml")) {
		links, err = discoveryFeedLinks(body)
		if err != nil {
			return nil, ErrUnavailable
		}
	} else {
		return nil, ErrUnavailable
	}
	return c.discoveryArticleLinks(response.Request.URL, links), nil
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
	result := []DiscoveredLink{}
	seen := map[string]bool{}
	for _, candidate := range links {
		ref, err := url.Parse(strings.TrimSpace(candidate.URL))
		if err != nil || strings.TrimSpace(candidate.URL) == "" {
			continue
		}
		link := base.ResolveReference(ref)
		link.Fragment = ""
		if c.trustedURL(link) != nil || IsListingURL(link.String()) {
			continue
		}
		host := strings.TrimSuffix(strings.ToLower(link.Hostname()), ".")
		isHost := func(domain string) bool { return host == domain || strings.HasSuffix(host, "."+domain) }
		switch {
		case isHost("securitylab.ru") && !securityLabArticlePath.MatchString(link.Path):
			continue
		case isHost("xakep.ru") && !xakepArticlePath.MatchString(link.Path):
			continue
		case isHost("habr.com") && !habrArticlePath.MatchString(link.Path):
			continue
		case isHost("ptsecurity.com") && !ptArticlePath.MatchString(link.Path):
			continue
		case isHost("kaspersky.ru") && !kasperskyArticlePath.MatchString(link.Path):
			continue
		}
		link.Host = host
		query := link.Query()
		for key := range query {
			if strings.HasPrefix(strings.ToLower(key), "utm_") {
				query.Del(key)
			}
		}
		link.RawQuery = query.Encode()
		address := link.String()
		if seen[address] {
			continue
		}
		seen[address] = true
		result = append(result, DiscoveredLink{URL: address, PublishedAt: candidate.PublishedAt})
		if len(result) == maxDiscoveredLinks {
			break
		}
	}
	return result
}
