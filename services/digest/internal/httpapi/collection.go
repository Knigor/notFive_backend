package httpapi

import (
	"slices"

	"github.com/Knigor/notFive_backend/services/digest/internal/collector"
)

// Collection contains source material for the caller's processing pipeline.
// It does not rank events, summarize articles, or claim that their facts are true.
type Collection struct {
	ControlTime      string             `json:"control_time"`
	WindowStart      string             `json:"window_start"`
	Articles         []CollectedArticle `json:"articles"`
	ExcludedArticles []ResearchSource   `json:"excluded_articles"`
	Counts           CollectionCounts   `json:"counts"`
	Warnings         []string           `json:"warnings"`
}

type CollectedArticle struct {
	collector.Article
	DateEvidence  string `json:"date_evidence"`
	TextTruncated bool   `json:"text_truncated"`
}

type CollectionCounts struct {
	Candidates int `json:"candidates"`
	Accepted   int `json:"accepted"`
	Excluded   int `json:"excluded"`
}

func collectArticles(articles []collector.Article, research *Research) *Collection {
	collection := &Collection{
		ControlTime: research.ControlTime, WindowStart: research.WindowStart,
		Articles: []CollectedArticle{}, ExcludedArticles: []ResearchSource{},
		Counts:   CollectionCounts{Candidates: len(articles), Excluded: len(research.ExcludedSources)},
		Warnings: slices.Clone(research.Warnings),
	}
	metadata := make(map[string]ResearchSource, len(research.Sources))
	for _, source := range research.Sources {
		metadata[source.ID] = source
	}
	seen := map[string]bool{}
	for _, article := range articles {
		if source, ok := metadata[article.ID]; ok && article.ReadError == "" && !seen[article.ID] {
			seen[article.ID] = true
			collection.Articles = append(collection.Articles, CollectedArticle{Article: article, DateEvidence: source.DateEvidence, TextTruncated: source.TextTruncated})
		}
	}
	for _, source := range research.ExcludedSources {
		if seen[source.ID] {
			continue
		}
		seen[source.ID] = true
		collection.ExcludedArticles = append(collection.ExcludedArticles, source)
	}
	for _, decision := range research.CandidateAudit {
		if decision.Status == "accepted" || seen[decision.ID] {
			continue
		}
		seen[decision.ID] = true
		collection.ExcludedArticles = append(collection.ExcludedArticles, ResearchSource{
			ID: decision.ID, URL: decision.URL, RequestedURL: decision.URL, Status: decision.Status,
			Stage: decision.Stage, Reason: decision.Reason, Error: decision.Error,
		})
	}
	collection.Counts.Accepted = len(collection.Articles)
	collection.Counts.Excluded = len(collection.ExcludedArticles)
	collection.Counts.Candidates = collection.Counts.Accepted + collection.Counts.Excluded
	return collection
}
