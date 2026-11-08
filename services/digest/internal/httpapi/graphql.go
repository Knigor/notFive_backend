package httpapi

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"net"
	"net/url"
	"slices"
	"strings"
	"time"

	"github.com/Knigor/notFive_backend/services/digest/graph/model"
	"github.com/Knigor/notFive_backend/services/digest/internal/collector"
)

const collectionQuery = "Найди новости кибербезопасности за заданные 24 часа: инциденты, уязвимости, утечки и защитные меры. Укажи исходные публикации и даты."

func (s *Server) Collect(ctx context.Context, input model.CollectInput) (*model.CollectResult, error) {
	control, err := time.Parse(time.RFC3339, input.ControlTime)
	if err != nil {
		return nil, errors.New("controlTime must be RFC3339 with a timezone")
	}
	control = control.In(time.FixedZone("Europe/Moscow", 3*60*60))
	if err := validateCollectInput(input); err != nil {
		return nil, err
	}

	if input.Kind == model.InputKindDataset {
		return s.collectDataset(input, control)
	}

	domains := slices.Clone(s.cfg.SearchDomains)
	if input.Kind == model.InputKindSites {
		domains, err = siteDomains(input.Sites)
		if err != nil {
			return nil, err
		}
	}
	select {
	case s.slots <- struct{}{}:
		defer func() { <-s.slots }()
	default:
		return nil, errors.New("all collection slots are busy")
	}
	ctx, cancel := context.WithTimeout(ctx, s.cfg.Timeout)
	defer cancel()
	run, err := s.collectRun(ctx, researchRequest{ControlTime: control.Format(time.RFC3339), Query: collectionQuery}, domains)
	run.CompletedAt = time.Now().UTC()
	if err != nil {
		run.Status = "failed"
		run.Error = "Collection failed"
	}
	if saveErr := s.save(run); saveErr != nil {
		return nil, fmt.Errorf("save collection: %w", saveErr)
	}
	if err != nil {
		return nil, fmt.Errorf("collection %s failed: %w", run.ID, err)
	}
	return resultFromRun(run, input.Kind), nil
}

func validateCollectInput(input model.CollectInput) error {
	switch input.Kind {
	case model.InputKindDataset:
		if input.Items == nil || len(input.Sites) != 0 {
			return errors.New("dataset requires items and no sites")
		}
	case model.InputKindSites:
		if len(input.Sites) == 0 || input.Items != nil {
			return errors.New("sites requires sites and no items")
		}
	case model.InputKindDefault:
		if len(input.Sites) != 0 || input.Items != nil {
			return errors.New("default input accepts neither sites nor items")
		}
	default:
		return errors.New("unsupported input kind")
	}
	return nil
}

func siteDomains(sites []string) ([]string, error) {
	if len(sites) < 1 || len(sites) > 5 {
		return nil, errors.New("provide one to five sites")
	}
	domains := make([]string, 0, len(sites))
	for _, site := range sites {
		u, err := url.Parse(site)
		if err != nil || u.Scheme != "https" || u.User != nil || u.Port() != "" || u.RawQuery != "" || u.Fragment != "" || (u.Path != "" && u.Path != "/") {
			return nil, errors.New("sites must be HTTPS root URLs")
		}
		host := strings.ToLower(u.Hostname())
		if host == "" || !strings.Contains(host, ".") || net.ParseIP(host) != nil || slices.Contains(domains, host) {
			return nil, errors.New("sites must have distinct public hostnames")
		}
		domains = append(domains, host)
	}
	return domains, nil
}

func (s *Server) collectDataset(input model.CollectInput, control time.Time) (*model.CollectResult, error) {
	run := s.newRun("dataset", input, "")
	run.CompletedAt = time.Now().UTC()
	articles := make([]*model.Article, 0, len(input.Items))
	stored := make([]CollectedArticle, 0, len(input.Items))
	for _, item := range input.Items {
		if item == nil || strings.TrimSpace(item.ID) == "" {
			return nil, errors.New("dataset items require an id")
		}
		articles = append(articles, &model.Article{ID: item.ID, Title: item.Title, Text: item.Text, Source: item.Source, URL: item.URL, PublishedAt: item.PublishedAt, Missing: item.Missing, Error: item.Error})
		stored = append(stored, CollectedArticle{Article: collector.Article{ID: item.ID, Title: deref(item.Title), Text: deref(item.Text), Source: deref(item.Source), URL: deref(item.URL), PublishedAt: deref(item.PublishedAt)}})
	}
	run.Collection = &Collection{ControlTime: control.Format(time.RFC3339), WindowStart: control.Add(-24 * time.Hour).Format(time.RFC3339), Articles: stored, ExcludedArticles: []ResearchSource{}, Counts: CollectionCounts{Candidates: len(stored), Accepted: len(stored)}, Warnings: []string{}}
	if err := s.save(run); err != nil {
		return nil, fmt.Errorf("save dataset: %w", err)
	}
	return &model.CollectResult{RunID: run.ID, Mode: model.InputKindDataset, Sites: []string{}, Articles: articles, Excluded: []*model.ExcludedArticle{}, Warnings: []string{}}, nil
}

func resultFromRun(run Run, mode model.InputKind) *model.CollectResult {
	result := &model.CollectResult{RunID: run.ID, Mode: mode, Sites: []string{}, Articles: []*model.Article{}, Excluded: []*model.ExcludedArticle{}, Warnings: []string{}}
	if run.Research != nil {
		for _, domain := range run.Research.AllowedDomains {
			result.Sites = append(result.Sites, "https://"+domain)
		}
	}
	if run.Collection != nil {
		for _, article := range run.Collection.Articles {
			result.Articles = append(result.Articles, &model.Article{ID: article.ID, Title: &article.Title, Text: &article.Text, Source: &article.Source, URL: &article.URL, PublishedAt: &article.PublishedAt, Missing: []string{}, DateEvidence: &article.DateEvidence, TextTruncated: article.TextTruncated})
		}
		for _, excluded := range run.Collection.ExcludedArticles {
			result.Excluded = append(result.Excluded, &model.ExcludedArticle{ID: excluded.ID, URL: excluded.URL, Reason: excluded.Reason})
		}
		result.Warnings = slices.Clone(run.Collection.Warnings)
	}
	if run.Research != nil {
		if raw, err := json.Marshal(run.Research); err == nil {
			text := string(raw)
			result.ResearchJSON = &text
		}
	}
	return result
}

func deref(value *string) string {
	if value == nil {
		return ""
	}
	return *value
}
