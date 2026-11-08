package export

import (
	"context"
	"encoding/json"
	"errors"
	"maps"
	"net/url"
	"slices"
	"strings"
	"time"

	"github.com/Knigor/notFive_backend/services/digest/internal/analysis"
	"github.com/Knigor/notFive_backend/services/digest/internal/collector"
)

var ErrInvalidModelOutput = errors.New("invalid_model_output")

func Prepare(request Request) (Prepared, error) {
	news, err := analysis.Prepare(request.News)
	if err != nil {
		return Prepared{}, err
	}
	a := request.Analysis
	if a.Dataset != request.News.Dataset || !sameInstant(a.ControlTime, news.Dataset.ControlTime) || !sameInstant(a.PeriodStart, news.Dataset.PeriodStart) || a.Timezone != "Europe/Moscow" || (request.News.Timezone != "" && request.News.Timezone != "Europe/Moscow") {
		return Prepared{}, errors.New("export_metadata_mismatch")
	}
	if a.Groups == nil || a.Excluded == nil {
		return Prepared{}, errors.New("invalid_analysis")
	}
	if a.RecommendedOrder == nil || len(a.RecommendedOrder) > 5 {
		return Prepared{}, errors.New("invalid_selection")
	}
	prepared := Prepared{Request: request, Locale: "ru", issues: map[string][]string{}, excluded: news.Excluded}
	prepared.Request.Analysis.ControlTime = news.Dataset.ControlTime
	prepared.Request.Analysis.PeriodStart = news.Dataset.PeriodStart
	items := make(map[string]analysis.Item, len(request.News.Items))
	for _, item := range request.News.Items {
		items[item.ID] = item
	}
	selected := map[string]analysis.Group{}
	sourceIDs := map[string]bool{}
	for _, id := range a.RecommendedOrder {
		group, exists := a.Groups[id]
		if _, duplicate := selected[id]; !exists || duplicate {
			return Prepared{}, errors.New("invalid_selection")
		}
		selected[id] = group
		issues := []string{}
		if len(group.IDs) == 0 || len(group.Facts) == 0 {
			issues = append(issues, "missing_facts_or_sources")
		}
		for _, articleID := range group.IDs {
			item, exists := items[articleID]
			if !exists {
				issues = append(issues, "unknown_article:"+articleID)
				continue
			}
			if reason := news.Excluded[articleID]; reason != "" {
				issues = append(issues, reason+":"+articleID)
			}
			if !validURL(item.URL) {
				issues = append(issues, "invalid_source_url:"+articleID)
			}
		}
		for _, fact := range group.Facts {
			valid := len(fact.SourceIDs) > 0 && len(fact.Evidence) > 0
			for _, articleID := range fact.SourceIDs {
				if !slices.Contains(group.IDs, articleID) {
					valid = false
				}
			}
			for _, evidence := range fact.Evidence {
				item, exists := items[evidence.ArticleID]
				if !exists || item.Text == nil || strings.TrimSpace(evidence.Quote) == "" || !strings.Contains(*item.Text, evidence.Quote) || !slices.Contains(fact.SourceIDs, evidence.ArticleID) || !slices.Contains(group.IDs, evidence.ArticleID) {
					valid = false
				}
			}
			if !valid {
				issues = append(issues, "unverified_evidence")
			}
		}
		if len(issues) > 0 {
			slices.Sort(issues)
			prepared.issues[id] = slices.Compact(issues)
			// Keep the original analysis in the audit. Do not send unsupported
			// claims as prose material: the model must describe the issue itself.
			selected[id] = analysis.Group{Name: group.Name, IDs: slices.Clone(group.IDs), Facts: []analysis.Fact{}, Contradictions: []analysis.Contradiction{}}
		} else {
			for _, articleID := range group.IDs {
				sourceIDs[articleID] = true
			}
		}
	}
	articles := []analysis.Item{}
	for _, item := range request.News.Items {
		if sourceIDs[item.ID] {
			articles = append(articles, item)
		}
	}
	// Only the selected groups and their original sources are needed for prose.
	input, err := json.Marshal(struct {
		ControlTime string                    `json:"control_time"`
		PeriodStart string                    `json:"period_start"`
		Order       []string                  `json:"recommended_order"`
		Groups      map[string]analysis.Group `json:"untrusted_analysis"`
		Articles    []analysis.Item           `json:"untrusted_articles"`
		Issues      map[string][]string       `json:"group_issues"`
	}{news.Dataset.ControlTime, news.Dataset.PeriodStart, a.RecommendedOrder, selected, articles, prepared.issues})
	prepared.Input = string(input)
	return prepared, err
}

func sameInstant(left, right string) bool {
	l, err := time.Parse(time.RFC3339Nano, left)
	r, otherErr := time.Parse(time.RFC3339Nano, right)
	return err == nil && otherErr == nil && l.Equal(r)
}

func validURL(value string) bool {
	u, err := url.Parse(value)
	return err == nil && (u.Scheme == "https" || u.Scheme == "http") && u.Hostname() != "" && u.User == nil && !strings.ContainsAny(value, "\r\n\t <>")
}

func Export(ctx context.Context, provider collector.Provider, prepared Prepared) (Result, collector.Generation, error) {
	result := Result{Files: map[string]string{}, Warnings: append([]string{}, prepared.Request.Analysis.Warnings...)}
	generation := collector.Generation{}
	response := modelResult{Events: []eventText{}}
	if len(prepared.Request.Analysis.RecommendedOrder) > 0 {
		var err error
		generation, err = provider.Generate(ctx, Instructions, prepared.Input)
		if err != nil {
			return result, generation, err
		}
		if err := json.Unmarshal([]byte(jsonPayload(generation.Text)), &response); err != nil || response.Events == nil {
			return result, generation, ErrInvalidModelOutput
		}
	}
	texts := map[string]eventText{}
	for _, event := range response.Events {
		if event.GroupID == "" {
			return result, generation, ErrInvalidModelOutput
		}
		if _, duplicate := texts[event.GroupID]; duplicate {
			return result, generation, ErrInvalidModelOutput
		}
		if !slices.Contains(prepared.Request.Analysis.RecommendedOrder, event.GroupID) {
			result.Warnings = append(result.Warnings, message(prepared.Locale, "extra_event", event.GroupID))
			continue
		}
		texts[event.GroupID] = event
	}
	issues := maps.Clone(prepared.issues)
	for _, id := range prepared.Request.Analysis.RecommendedOrder {
		text, exists := texts[id]
		if !exists || strings.TrimSpace(text.Title) == "" || strings.TrimSpace(text.Summary) == "" || strings.TrimSpace(text.Consequences) == "" || strings.TrimSpace(text.Post) == "" || strings.TrimSpace(text.Reason) == "" {
			return result, generation, ErrInvalidModelOutput
		}
		if len(issues[id]) > 0 {
			result.Warnings = append(result.Warnings, message(prepared.Locale, "unconfirmed_group", id, strings.Join(issues[id], ", ")))
		}
	}
	prepared.issues = issues
	result.Files = render(prepared, texts, response.SelectionSummary)
	for _, file := range []struct {
		name  string
		limit int
	}{{"digest.md", 4000}, {"post.md", 2000}} {
		if length := visibleLength(result.Files[file.name]); length > file.limit {
			result.Warnings = append(result.Warnings, message(prepared.Locale, "length_limit", file.name, length, file.limit))
		}
	}
	return result, generation, nil
}

func jsonPayload(text string) string {
	text = strings.TrimSpace(strings.TrimPrefix(text, "\ufeff"))
	if rest, fenced := strings.CutPrefix(text, "```"); fenced {
		label, body, newline := strings.Cut(rest, "\n")
		if newline && (strings.TrimSpace(label) == "json" || strings.TrimSpace(label) == "") {
			if payload, closed := strings.CutSuffix(strings.TrimSpace(body), "```"); closed {
				return strings.TrimSpace(payload)
			}
		}
	}
	return text
}
