package httpapi

import (
	"errors"
	"maps"
	"net/netip"
	"net/url"
	"slices"
	"strings"
	"unicode"

	"github.com/Knigor/notFive_backend/services/digest/internal/news"
	"golang.org/x/net/idna"
	"golang.org/x/net/publicsuffix"
)

var (
	ErrInvalidSites     = errors.New("invalid_sites")
	ErrSitesUnavailable = errors.New("sites_unavailable")
)

// scopeSites creates isolated search and fetch clients for this request. The
// parent configuration, clients and shared concurrency channel are not mutated.
func (s *Server) scopeSites(sites []string) (*Server, error) {
	if len(sites) < 1 || len(sites) > 5 {
		return nil, ErrInvalidSites
	}
	domains := make([]string, 0, len(sites))
	addresses := make([]string, 0, len(sites))
	sources := make([]researchDiscoverySource, 0, len(sites))
	for _, site := range sites {
		domain, address, err := normalizeSite(site)
		if err != nil || slices.Contains(domains, domain) {
			return nil, ErrInvalidSites
		}
		domains = append(domains, domain)
		addresses = append(addresses, address)
		sources = append(sources, discoveryForSite(domain, address))
	}
	if s.cfg.SearchProviderFactory == nil {
		return nil, ErrSitesUnavailable
	}
	provider, err := s.cfg.SearchProviderFactory(slices.Clone(domains))
	if err != nil || provider == nil {
		return nil, ErrSitesUnavailable
	}
	var fetcher SourceFetcher
	if s.cfg.SourceFetcherFactory == nil {
		fetcher = news.New(domains)
	} else {
		fetcher = s.cfg.SourceFetcherFactory(slices.Clone(domains))
	}
	if fetcher == nil {
		return nil, ErrSitesUnavailable
	}
	scoped := *s
	scoped.cfg = s.cfg
	scoped.cfg.SearchDomains = domains
	scoped.cfg.SourceFetcher = fetcher
	scoped.cfg.ProviderSettings = maps.Clone(s.cfg.ProviderSettings)
	if scoped.cfg.ProviderSettings == nil {
		scoped.cfg.ProviderSettings = map[string]any{}
	}
	scoped.cfg.ProviderSettings["allowed_domains"] = slices.Clone(domains)
	scoped.cfg.ProviderSettings["requested_sites"] = addresses
	discovery := make([]map[string]string, 0, len(sources))
	for _, source := range sources {
		discovery = append(discovery, map[string]string{"url": source.url, "kind": source.kind})
	}
	scoped.cfg.ProviderSettings["discovery_sources"] = discovery
	scoped.provider, scoped.fetcher, scoped.discoverySources = provider, fetcher, sources
	return &scoped, nil
}

func normalizeSite(raw string) (string, string, error) {
	raw = strings.TrimSpace(raw)
	if raw == "" || len(raw) > 2048 || strings.ContainsFunc(raw, unicode.IsSpace) || strings.ContainsFunc(raw, unicode.IsControl) {
		return "", "", ErrInvalidSites
	}
	if !strings.Contains(raw, "://") {
		raw = "https://" + raw
	}
	u, err := url.Parse(raw)
	if err != nil || u.Scheme != "https" || u.Host == "" || u.User != nil || u.Opaque != "" || (u.Port() != "" && u.Port() != "443") {
		return "", "", ErrInvalidSites
	}
	host, err := idna.Lookup.ToASCII(strings.ToLower(strings.TrimSuffix(u.Hostname(), ".")))
	if err != nil || !publicDomain(host) {
		return "", "", ErrInvalidSites
	}
	domain := strings.TrimPrefix(host, "www.")
	if !publicDomain(domain) {
		return "", "", ErrInvalidSites
	}
	u.Host, u.Fragment, u.RawFragment = host, "", ""
	if u.Path == "" {
		u.Path = "/"
	}
	return domain, u.String(), nil
}

func publicDomain(host string) bool {
	if len(host) > 253 || !strings.Contains(host, ".") {
		return false
	}
	if _, err := netip.ParseAddr(host); err == nil {
		return false
	}
	for label := range strings.SplitSeq(host, ".") {
		if len(label) == 0 || len(label) > 63 || strings.HasPrefix(label, "-") || strings.HasSuffix(label, "-") {
			return false
		}
		for _, char := range label {
			if char != '-' && (char < 'a' || char > 'z') && (char < '0' || char > '9') {
				return false
			}
		}
	}
	_, suffix, _ := strings.Cut(host, ".")
	for strings.Contains(suffix, ".") {
		_, suffix, _ = strings.Cut(suffix, ".")
	}
	if strings.Trim(suffix, "0123456789") == "" || slices.Contains([]string{"localhost", "local", "internal", "test", "invalid", "example", "onion", "home", "lan"}, suffix) {
		return false
	}
	// Reject a public suffix such as co.uk or github.io as a search scope.
	_, err := publicsuffix.EffectiveTLDPlusOne(host)
	return err == nil
}

func discoveryForSite(domain, address string) researchDiscoverySource {
	parsed, _ := url.Parse(address) // normalizeSite has already validated the URL.
	for _, known := range researchDiscoverySources {
		if address == known.url {
			return known
		}
		if (parsed.Path == "" || parsed.Path == "/") && parsed.RawQuery == "" {
			if _, err := researchSourceURL(known.url, []string{domain}); err == nil {
				return known
			}
		}
	}
	// Explicit sections and unknown domains are read at their supplied URL.
	// Search is domain-wide; the path selects discovery, not an access boundary.
	return researchDiscoverySource{url: address, kind: "html"}
}
