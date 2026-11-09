package news

import (
	"errors"
	"net/url"
	"path"
	"slices"
	"strings"
)

var ErrListingPage = errors.New("source is a home page or listing, not an individual publication")

// IsListingURL recognizes obvious home pages and known source listings before
// fetching them as articles. False means unknown, not a confirmed article:
// publication metadata and the requested time window still need verification.
func IsListingURL(rawURL string) bool {
	u, err := url.Parse(rawURL)
	if err != nil || u.Hostname() == "" || (u.Scheme != "https" && u.Scheme != "http") {
		return false
	}
	// URL.Path is already percent-decoded. Query and fragment never identify a
	// particular publication on these configured sources.
	p := strings.Trim(path.Clean("/"+u.Path), "/")
	if p == "" {
		return true
	}
	host := strings.TrimSuffix(strings.ToLower(u.Hostname()), ".")
	isHost := func(domain string) bool { return host == domain || strings.HasSuffix(host, "."+domain) }
	if (p == "index.html" || p == "index.php") && u.RawQuery == "" {
		return true
	}
	parts := strings.Split(p, "/")
	if slices.Contains([]string{"ru", "en", "ru-ru"}, parts[0]) {
		parts = parts[1:]
		p = strings.Join(parts, "/")
		if p == "" {
			return true
		}
	}
	if slices.Contains([]string{"tag", "tags", "category", "categories", "theme", "themes"}, parts[0]) && len(parts) <= 2 {
		return true
	}
	if slices.Contains([]string{"news", "security", "information_security", "kiberbezopasnost", "tech", "feed", "rss", "search", "login", "contacts", "about", "products"}, p) {
		return true
	}
	knownSections := map[string][]string{
		"infowatch.ru": {"analytics/novosti-ib"}, "infobezopasnost.ru": {"blog/news"},
		"ict.moscow": {"projects/cybersecurity"}, "aif.ru": {"techno/web"},
		"itweek.ru": {"security/article"}, "ixbt.com": {"live/security"},
		"rg.ru": {"theme/technologies/internet/kiberbezopasnost"}, "rt-solar.ru": {"events/news"},
	}
	for domain, paths := range knownSections {
		if isHost(domain) && slices.Contains(paths, p) {
			return true
		}
	}
	if len(parts) >= 2 && parts[0] == "news" && parts[1] == "categories" {
		return true
	}
	switch {
	case isHost("securitylab.ru"):
		return slices.Contains([]string{"news", "news/index.php", "news/index.html"}, p)
	case isHost("xakep.ru"):
		if p == "news" {
			return true
		}
		return len(parts) == 2 && slices.Contains([]string{"tag", "category"}, parts[0])
	case isHost("habr.com"):
		if slices.Contains([]string{"news", "articles", "hubs", "flows", "all"}, p) {
			return true
		}
		if slices.Contains([]string{"news", "articles", "all"}, parts[0]) {
			return len(parts) == 2 && parts[1] == "all" || len(parts) == 3 && parts[1] == "top" && slices.Contains([]string{"daily", "weekly", "monthly", "yearly", "alltime"}, parts[2])
		}
		if slices.Contains([]string{"hubs", "companies", "flows"}, parts[0]) {
			return len(parts) == 2 || len(parts) == 3 && slices.Contains([]string{"news", "articles"}, parts[2])
		}
	case isHost("ptsecurity.com"):
		return slices.Contains([]string{"research", "research/analytics", "about", "about/news"}, p)
	case isHost("kaspersky.ru"):
		return slices.Contains([]string{"about", "about/press-releases", "blog"}, p)
	}
	return false
}
