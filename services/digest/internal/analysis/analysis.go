package analysis

import (
	"context"
	"encoding/json"
	"errors"
	"slices"
	"strings"
	"time"
	"unicode/utf8"

	"github.com/Knigor/notFive_backend/services/digest/internal/collector"
)

var (
	ErrInvalidControlTime = errors.New("invalid_control_time")
	ErrInvalidPeriodStart = errors.New("invalid_period_start")
	ErrDatasetRequired    = errors.New("dataset_required")
	ErrItemsRequired      = errors.New("items_required")
	ErrInvalidArticleIDs  = errors.New("invalid_article_ids")
	ErrInputTooLarge      = errors.New("analysis_input_too_large")
	ErrInvalidModelOutput = errors.New("invalid_model_output")
)

const MaxTextCharacters = 120000

// Prepare validates the envelope and excludes articles with an unusable date or
// text. This does not verify publisher metadata or semantic relevance again.
func Prepare(input Dataset) (Prepared, error) {
	control, err := time.Parse(time.RFC3339Nano, input.ControlTime)
	if err != nil {
		return Prepared{}, ErrInvalidControlTime
	}
	control = control.In(time.FixedZone("Europe/Moscow", 3*60*60))
	start := control.Add(-24 * time.Hour)
	if input.PeriodStart != "" {
		period, err := time.Parse(time.RFC3339Nano, input.PeriodStart)
		if err != nil || !period.Equal(start) {
			return Prepared{}, ErrInvalidPeriodStart
		}
	}
	if strings.TrimSpace(input.Dataset) == "" {
		return Prepared{}, ErrDatasetRequired
	}
	if input.Items == nil {
		return Prepared{}, ErrItemsRequired
	}
	prepared := Prepared{Dataset: input, Excluded: map[string]string{}, Locale: "ru"}
	prepared.Dataset.ControlTime = control.Format(time.RFC3339Nano)
	prepared.Dataset.PeriodStart = start.Format(time.RFC3339Nano)
	prepared.Dataset.Timezone = "Europe/Moscow"
	prepared.Dataset.Items = []Item{}
	seen := map[string]bool{}
	characters := 0
	for _, item := range input.Items {
		if strings.TrimSpace(item.ID) == "" || seen[item.ID] {
			return Prepared{}, ErrInvalidArticleIDs
		}
		seen[item.ID] = true
		reason := ""
		published, dateErr := time.Parse(time.RFC3339Nano, stringValue(item.PublishedAt))
		switch {
		case strings.TrimSpace(stringValue(item.Error)) != "":
			reason = "read_error"
		case strings.TrimSpace(stringValue(item.Text)) == "":
			reason = "missing_text"
		case dateErr != nil:
			reason = "missing_date"
		case !published.After(start) || published.After(control):
			reason = "outside_period"
		}
		if reason != "" {
			prepared.Excluded[item.ID] = reason
			continue
		}
		characters += utf8.RuneCountInString(*item.Text)
		if characters > MaxTextCharacters {
			return Prepared{}, ErrInputTooLarge
		}
		prepared.Dataset.Items = append(prepared.Dataset.Items, item)
	}
	// Description/fields are caller-supplied documentation and are not instructions.
	raw, err := json.Marshal(struct {
		ControlTime string `json:"control_time"`
		PeriodStart string `json:"period_start"`
		Timezone    string `json:"timezone"`
		Items       []Item `json:"untrusted_items"`
	}{prepared.Dataset.ControlTime, prepared.Dataset.PeriodStart, "Europe/Moscow", prepared.Dataset.Items})
	prepared.Input = string(raw)
	return prepared, err
}

func Analyze(ctx context.Context, provider collector.Provider, prepared Prepared) (Result, collector.Generation, error) {
	result := Result{
		Dataset: prepared.Dataset.Dataset, ControlTime: prepared.Dataset.ControlTime,
		PeriodStart: prepared.Dataset.PeriodStart, Timezone: "Europe/Moscow",
		Groups: map[string]Group{}, RecommendedOrder: []string{}, Excluded: map[string]string{}, Warnings: []string{},
	}
	for id, reason := range prepared.Excluded {
		result.Excluded[id] = message(prepared.Locale, reason)
	}
	if len(prepared.Dataset.Items) == 0 {
		result.Warnings = append(result.Warnings, message(prepared.Locale, "no_eligible_items"))
		return result, collector.Generation{}, nil
	}
	generation, err := provider.Generate(ctx, Instructions, prepared.Input)
	if err != nil {
		return result, generation, err
	}
	var response struct {
		Groups []struct {
			ID string `json:"id"`
			Group
		} `json:"groups"`
		RecommendedOrder []string `json:"recommended_order"`
		Excluded         []struct {
			ArticleID string `json:"article_id"`
			Reason    string `json:"reason"`
		} `json:"excluded"`
	}
	if json.Unmarshal([]byte(jsonPayload(generation.Text)), &response) != nil || response.Groups == nil || response.RecommendedOrder == nil || response.Excluded == nil {
		return result, generation, ErrInvalidModelOutput
	}
	for _, group := range response.Groups {
		if strings.TrimSpace(group.ID) == "" {
			return result, generation, ErrInvalidModelOutput
		}
		if _, duplicate := result.Groups[group.ID]; duplicate {
			return result, generation, ErrInvalidModelOutput
		}
		result.Groups[group.ID] = group.Group
	}
	seenExclusions := map[string]bool{}
	for _, exclusion := range response.Excluded {
		if strings.TrimSpace(exclusion.ArticleID) == "" || seenExclusions[exclusion.ArticleID] {
			return result, generation, ErrInvalidModelOutput
		}
		seenExclusions[exclusion.ArticleID] = true
		if _, predefined := result.Excluded[exclusion.ArticleID]; !predefined {
			result.Excluded[exclusion.ArticleID] = exclusion.Reason
		}
	}
	seenWarnings := map[string]bool{}
	warn := func(key string, args ...any) {
		value := message(prepared.Locale, key, args...)
		if !seenWarnings[value] {
			result.Warnings = append(result.Warnings, value)
			seenWarnings[value] = true
		}
	}
	for _, id := range response.RecommendedOrder {
		if _, exists := result.Groups[id]; !exists {
			warn("unknown_selected_group", id)
			continue
		}
		if slices.Contains(result.RecommendedOrder, id) {
			warn("duplicate_selected_group", id)
			continue
		}
		if len(result.RecommendedOrder) == 5 {
			warn("selection_limit")
			continue
		}
		result.RecommendedOrder = append(result.RecommendedOrder, id)
	}
	inspectReferences(&result, prepared, warn)
	if len(result.RecommendedOrder) == 0 {
		warn("no_selected_events")
	}
	return result, generation, nil
}

func inspectReferences(result *Result, prepared Prepared, warn func(string, ...any)) {
	items := make(map[string]Item, len(prepared.Dataset.Items))
	assigned := map[string]bool{}
	for _, item := range prepared.Dataset.Items {
		items[item.ID] = item
	}
	for groupID, group := range result.Groups {
		group.IDs = array(group.IDs)
		group.Facts = array(group.Facts)
		group.Contradictions = array(group.Contradictions)
		for i := range group.Facts {
			group.Facts[i].SourceIDs = array(group.Facts[i].SourceIDs)
			group.Facts[i].Evidence = array(group.Facts[i].Evidence)
		}
		for i := range group.Contradictions {
			group.Contradictions[i].SourceIDs = array(group.Contradictions[i].SourceIDs)
		}
		result.Groups[groupID] = group
		if group.Name == "" || len(group.IDs) == 0 || len(group.Facts) == 0 {
			warn("incomplete_group", groupID)
		}
		for _, id := range group.IDs {
			if _, ok := items[id]; !ok {
				warn("unknown_article", groupID, id)
				continue
			}
			assigned[id] = true
			if _, excluded := result.Excluded[id]; excluded {
				warn("group_and_excluded", id)
			}
		}
		for _, fact := range group.Facts {
			if fact.Statement == "" || len(fact.SourceIDs) == 0 || len(fact.Evidence) == 0 {
				warn("unverified_fact", groupID)
			}
			for _, id := range fact.SourceIDs {
				if _, ok := items[id]; !ok || !slices.Contains(group.IDs, id) {
					warn("unknown_article", groupID, id)
				}
			}
			for _, evidence := range fact.Evidence {
				item, ok := items[evidence.ArticleID]
				if !ok || evidence.Quote == "" || !slices.Contains(fact.SourceIDs, evidence.ArticleID) || !strings.Contains(stringValue(item.Text), evidence.Quote) {
					warn("unverified_quote", groupID, evidence.ArticleID)
				}
			}
		}
		for _, contradiction := range group.Contradictions {
			for _, id := range contradiction.SourceIDs {
				if _, ok := items[id]; !ok || !slices.Contains(group.IDs, id) {
					warn("unknown_article", groupID, id)
				}
			}
		}
	}
	for id := range result.Excluded {
		if _, known := items[id]; !known {
			if _, predefined := prepared.Excluded[id]; !predefined {
				warn("unknown_excluded", id)
			}
		}
	}
	for id := range items {
		if _, excluded := result.Excluded[id]; !excluded && !assigned[id] {
			result.Excluded[id] = message(prepared.Locale, "model_omitted_item")
			warn("unassigned_item", id)
		}
	}
}

func stringValue(value *string) string {
	if value == nil {
		return ""
	}
	return *value
}

func array[T any](items []T) []T {
	if items == nil {
		return []T{}
	}
	return items
}

func jsonPayload(text string) string {
	trim := func(value string) string {
		return strings.TrimSpace(strings.TrimPrefix(strings.TrimSpace(value), "\uFEFF"))
	}
	text = trim(text)
	lines := strings.Split(text, "\n")
	if len(lines) < 3 || strings.TrimSpace(lines[len(lines)-1]) != "```" {
		return text
	}
	info, fenced := strings.CutPrefix(strings.TrimSpace(lines[0]), "```")
	info = strings.TrimSpace(info)
	if !fenced || (info != "" && !strings.EqualFold(info, "json")) {
		return text
	}
	return trim(strings.Join(lines[1:len(lines)-1], "\n"))
}
