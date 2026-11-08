package httpapi

import (
	"context"
	"crypto/sha256"
	"encoding/hex"
	"errors"
	"net/url"
	"slices"
	"strings"
	"sync"
	"time"
	"unicode"
	"unicode/utf8"

	"github.com/Knigor/notFive_backend/services/digest/internal/collector"
	"github.com/Knigor/notFive_backend/services/digest/internal/news"
)

func (s *Server) fetchResearchSources(ctx context.Context, candidates []searchCandidate, control time.Time, research *Research) []collector.Article {
	type outcome struct {
		page news.Article
		err  error
	}
	outcomes := make([]outcome, len(candidates))
	jobs := make(chan int, len(candidates))
	for i := range candidates {
		jobs <- i
	}
	close(jobs)
	var workers sync.WaitGroup
	for range min(4, len(candidates)) {
		workers.Go(func() {
			for i := range jobs {
				if err := ctx.Err(); err != nil {
					outcomes[i].err = err
					continue
				}
				normalized, err := researchSourceURL(candidates[i].URL, s.cfg.SearchDomains)
				if err != nil {
					outcomes[i].err = err
					continue
				}
				if news.IsListingURL(normalized) {
					outcomes[i].err = news.ErrListingPage
					continue
				}
				outcomes[i].page, outcomes[i].err = s.fetcher.Fetch(ctx, normalized)
			}
		})
	}
	workers.Wait()
	articles := make([]collector.Article, len(candidates))
	sources := make([]ResearchSource, len(candidates))
	finalURLs := map[string]bool{}
	for i, candidate := range candidates {
		hash := sha256.Sum256([]byte(candidate.URL))
		id := "web-" + hex.EncodeToString(hash[:8])
		source := ResearchSource{ID: id, RequestedURL: clipResearchText(candidate.URL, 2048), URL: clipResearchText(candidate.URL, 2048), Title: candidate.Title, Status: "excluded"}
		page, err := outcomes[i].page, outcomes[i].err
		if err != nil {
			source.Reason = sourceErrorReason(err)
		} else {
			source.URL, err = researchSourceURL(page.URL, s.cfg.SearchDomains)
			source.Title = clipResearchText(page.Title, 500)
			source.TextTruncated = page.TextTruncated
			source.TextCharacters = utf8.RuneCountInString(page.Text)
			source.DateEvidence = clipResearchText(page.DateEvidence, 2000)
			if !page.PublishedAt.IsZero() {
				source.PublishedAt = page.PublishedAt.In(control.Location()).Format(time.RFC3339Nano)
			}
			switch {
			case err != nil:
				source.Reason = sourceErrorReason(err)
			case news.IsListingURL(source.URL):
				source.Reason = sourceErrorReason(news.ErrListingPage)
			case page.PublishedAt.IsZero():
				source.Reason = "Дата публикации неизвестна или не имеет подтверждённого часового пояса."
			case !page.PublishedAt.After(control.Add(-24 * time.Hour)):
				source.Reason = "Публикация не новее нижней границы окна (T-24h, T]."
			case page.PublishedAt.After(control):
				source.Reason = "Публикация позднее контрольного времени T."
			case strings.TrimSpace(page.Text) == "" || strings.TrimSpace(source.Title) == "":
				source.Reason = "Недостаточно текста или отсутствует заголовок исходной публикации."
			case finalURLs[source.URL]:
				source.Reason = "Другой кандидат уже привёл к этой публикации после перенаправления."
			default:
				source.Status = "eligible"
				finalURLs[source.URL] = true
			}
		}
		if parsed, err := url.Parse(source.URL); err == nil {
			source.Source = parsed.Hostname()
		}
		if source.Source == "" {
			source.Source = "Источник не определён"
		}
		article := collector.Article{ID: id, Title: source.Title, Source: source.Source, URL: source.URL, PublishedAt: source.PublishedAt, ReadError: source.Reason}
		if source.Status == "eligible" {
			article.Text = page.Text
		}
		articles[i], sources[i] = article, source
	}
	// Preserve every considered article, including exclusion reasons, in the audit.
	for i := range sources {
		if sources[i].Status == "eligible" {
			research.Sources = append(research.Sources, sources[i])
		} else {
			research.ExcludedSources = append(research.ExcludedSources, sources[i])
		}
	}
	return articles
}

func researchSourceURL(raw string, domains []string) (string, error) {
	if len(raw) > 2048 || strings.ContainsFunc(raw, unicode.IsControl) {
		return "", news.ErrUntrustedURL
	}
	parsed, err := url.Parse(raw)
	if err != nil || parsed.Scheme != "https" || parsed.User != nil || parsed.Opaque != "" || (parsed.Port() != "" && parsed.Port() != "443") {
		return "", news.ErrUntrustedURL
	}
	host := strings.ToLower(strings.TrimSuffix(parsed.Hostname(), "."))
	if host == "" {
		return "", news.ErrUntrustedURL
	}
	allowed := slices.ContainsFunc(domains, func(domain string) bool { return host == domain || strings.HasSuffix(host, "."+domain) })
	if !allowed {
		return "", news.ErrUntrustedURL
	}
	parsed.Host, parsed.Fragment = host, ""
	return parsed.String(), nil
}

func sourceErrorReason(err error) string {
	switch {
	case errors.Is(err, news.ErrListingPage):
		return "Главная страница или раздел сайта, а не отдельная публикация."
	case errors.Is(err, news.ErrUnknownDate):
		return "Дата публикации отсутствует, не содержит часового пояса или противоречит другим метаданным страницы."
	case errors.Is(err, news.ErrUntrustedURL):
		return "URL не является разрешённой публичной HTTPS-публикацией выбранных источников."
	case errors.Is(err, context.Canceled), errors.Is(err, context.DeadlineExceeded):
		return "Получение публикации прервано по таймауту или отмене запроса."
	default:
		return "Не удалось получить доступный HTML-текст публикации."
	}
}

func clipResearchText(value string, limit int) string {
	runes := []rune(value)
	return string(runes[:min(len(runes), limit)])
}
