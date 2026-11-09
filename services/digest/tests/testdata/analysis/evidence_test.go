package analysis

import (
	"context"
	"slices"
	"strings"
	"testing"
	"unicode/utf8"

	"github.com/Knigor/notFive_backend/services/digest/internal/collector"
)

func TestMatchQuoteRestoresSourceTypographyWithoutFuzzyMatching(t *testing.T) {
	source := "Веб-сервис получил 6 отчетов. Исправление не выпущено."
	for _, test := range []struct{ name, quote, method, sourceQuote string }{
		{"exact", "Исправление не выпущено.", "exact", "Исправление не выпущено."},
		{"typography", "Веб‑сервис получил 6 отчётов.", "typographic", "Веб-сервис получил 6 отчетов."},
		{"changed number", "Веб-сервис получил 7 отчетов.", "unmatched", ""},
		{"removed negation", "Исправление выпущено.", "unmatched", ""},
		{"different word", "Веб-сервис получил 6 запросов.", "unmatched", ""},
		{"joined fragments", "Веб-сервис получил Исправление", "unmatched", ""},
		{"empty", " ", "unmatched", ""},
	} {
		t.Run(test.name, func(t *testing.T) {
			got := MatchQuote(source, test.quote)
			if got.Method != test.method || got.SourceQuote != test.sourceQuote {
				t.Fatalf("unexpected quote verification: %+v", got)
			}
			if test.method == "typographic" && (!slices.Contains(got.Transformations, "yo_to_e") || !slices.Contains(got.Transformations, "nonbreaking_hyphen_to_hyphen")) {
				t.Fatalf("normalization trace missing: %+v", got)
			}
		})
	}
}

func TestPrepareAcceptsExactly144000UnicodeCharactersWithoutTruncation(t *testing.T) {
	dataset := Dataset{Dataset: "test", ControlTime: "2026-11-09T09:00:00+03:00", Items: []Item{
		{ID: "A", Text: new(strings.Repeat("я", 72001)), PublishedAt: new("2026-11-09T08:00:00+03:00")},
		{ID: "B", Text: new(strings.Repeat("ё", 71999)), PublishedAt: new("2026-11-09T08:00:00+03:00")},
	}}
	prepared, err := Prepare(dataset)
	if err != nil || len(prepared.Dataset.Items) != 2 || len(prepared.Excluded) != 0 {
		t.Fatalf("exact Unicode boundary rejected: %v, accepted=%d, excluded=%v", err, len(prepared.Dataset.Items), prepared.Excluded)
	}
	characters := 0
	for i, item := range prepared.Dataset.Items {
		if item.ID != dataset.Items[i].ID || *item.Text != *dataset.Items[i].Text {
			t.Fatal("article was reordered or truncated")
		}
		characters += utf8.RuneCountInString(*item.Text)
	}
	if characters != 144000 {
		t.Fatalf("wrong Unicode character total: %d", characters)
	}
}

func TestPrepareSkipsWholeOversizedArticlesAndContinuesWithinRemainingBudget(t *testing.T) {
	dataset := Dataset{Dataset: "test", ControlTime: "2026-11-09T09:00:00+03:00", Items: []Item{
		{ID: "too-large", Text: new(strings.Repeat("я", 144001)), PublishedAt: new("2026-11-09T08:00:00+03:00")},
		{ID: "accepted", Text: new(strings.Repeat("ё", 143999)), PublishedAt: new("2026-11-09T08:00:00+03:00")},
		{ID: "remaining-overflow", Text: new("аб"), PublishedAt: new("2026-11-09T08:00:00+03:00")},
		{ID: "last-fits", Text: new("ю"), PublishedAt: new("2026-11-09T08:00:00+03:00")},
	}}
	prepared, err := Prepare(dataset)
	if err != nil || len(prepared.Dataset.Items) != 2 || len(prepared.Excluded) != 2 {
		t.Fatalf("overflow aborted the dataset: %v, accepted=%d, excluded=%v", err, len(prepared.Dataset.Items), prepared.Excluded)
	}
	if prepared.Dataset.Items[0].ID != "accepted" || prepared.Dataset.Items[1].ID != "last-fits" || *prepared.Dataset.Items[0].Text != *dataset.Items[1].Text || *prepared.Dataset.Items[1].Text != "ю" {
		t.Fatal("overflow consumed budget, truncated text or stopped subsequent articles")
	}
	for _, id := range []string{"too-large", "remaining-overflow"} {
		if prepared.Excluded[id] != "analysis_budget_exceeded" {
			t.Fatalf("article %s lacks explicit budget reason: %v", id, prepared.Excluded)
		}
	}
}

type budgetProviderFunc func(context.Context, string, string) (collector.Generation, error)

func (f budgetProviderFunc) Generate(ctx context.Context, instructions, input string) (collector.Generation, error) {
	return f(ctx, instructions, input)
}

func TestAnalyzeAllBudgetExclusionsReturnsReasonsWithoutCallingModel(t *testing.T) {
	for _, locale := range []string{"ru", "en"} {
		t.Run(locale, func(t *testing.T) {
			prepared, err := Prepare(Dataset{Dataset: "test", ControlTime: "2026-11-09T09:00:00+03:00", Items: []Item{
				{ID: "large", Text: new(strings.Repeat("я", 144001)), PublishedAt: new("2026-11-09T08:00:00+03:00")},
			}})
			if err != nil {
				t.Fatal(err)
			}
			prepared.Locale = locale
			result, generation, err := Analyze(t.Context(), budgetProviderFunc(func(context.Context, string, string) (collector.Generation, error) {
				t.Fatal("model called with no eligible article")
				return collector.Generation{}, nil
			}), prepared)
			if err != nil || len(result.Groups) != 0 || len(result.RecommendedOrder) != 0 || generation.Text != "" || len(result.Excluded) != 1 {
				t.Fatalf("empty-budget result failed: %v %+v", err, result)
			}
			word := map[string]string{"ru": "бюджет", "en": "budget"}[locale]
			if !strings.Contains(result.Excluded["large"], "144000") || !strings.Contains(result.Excluded["large"], word) || !strings.Contains(strings.Join(result.Warnings, "\n"), "large") {
				t.Fatalf("localized budget reason or warning missing: %+v", result)
			}
		})
	}
}
