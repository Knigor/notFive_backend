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
	seen := map[string]bool{}
	for _, id := range a.RecommendedOrder {
		if _, exists := a.Groups[id]; !exists || seen[id] {
			return Prepared{}, errors.New("invalid_selection")
		}
		seen[id] = true
	}
	verified, issues, checks := verifiedAnalysis(a, news)
	prepared := Prepared{Request: request, Locale: "ru", issues: issues, EvidenceChecks: checks}
	prepared.Request.Analysis = verified
	prepared.Request.Analysis.ControlTime = news.Dataset.ControlTime
	prepared.Request.Analysis.PeriodStart = news.Dataset.PeriodStart
	selected := map[string]analysis.Group{}
	sourceIDs := map[string]bool{}
	for _, id := range verified.RecommendedOrder {
		group := verified.Groups[id]
		selected[id] = group
		for _, articleID := range group.IDs {
			sourceIDs[articleID] = true
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
	}{news.Dataset.ControlTime, news.Dataset.PeriodStart, verified.RecommendedOrder, selected, articles, prepared.issues})
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
	result := Result{Events: []EventText{}, Warnings: append([]string{}, prepared.Request.Analysis.Warnings...)}
	generation := collector.Generation{}
	for _, id := range slices.Sorted(maps.Keys(prepared.issues)) {
		key := "filtered_group"
		if _, retained := prepared.Request.Analysis.Groups[id]; !retained {
			key = "excluded_group"
		}
		result.Warnings = append(result.Warnings, message(prepared.Locale, key, id, strings.Join(prepared.issues[id], ", ")))
	}
	response := modelResult{Events: []EventText{}}
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
	texts := map[string]EventText{}
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
	for _, id := range prepared.Request.Analysis.RecommendedOrder {
		text, exists := texts[id]
		if !exists || strings.TrimSpace(text.Title) == "" || strings.TrimSpace(text.Summary) == "" || strings.TrimSpace(text.Consequences) == "" || strings.TrimSpace(text.Post) == "" || strings.TrimSpace(text.Reason) == "" {
			return result, generation, ErrInvalidModelOutput
		}
		result.Events = append(result.Events, text)
	}
	result.SelectionSummary = response.SelectionSummary
	if len(prepared.Request.Analysis.RecommendedOrder) == 0 {
		result.SelectionSummary = message(prepared.Locale, "no_selected_events")
		if len(prepared.issues) > 0 {
			result.SelectionSummary = message(prepared.Locale, "no_verified_events")
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
