package analysis

import (
	"errors"
	"slices"
	"strings"
	"testing"
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

func TestPrepareAllowsTwelveFullArticlesWithoutTruncation(t *testing.T) {
	dataset := Dataset{Dataset: "test", ControlTime: "2026-11-09T09:00:00+03:00", Items: []Item{}}
	for i := range 12 {
		dataset.Items = append(dataset.Items, Item{ID: string(rune('A' + i)), Text: new(strings.Repeat("я", 12000)), PublishedAt: new("2026-11-09T08:00:00+03:00")})
	}
	prepared, err := Prepare(dataset)
	if err != nil || len(prepared.Dataset.Items) != 12 {
		t.Fatalf("full collection rejected: %v", err)
	}
	dataset.Items[0].Text = new(*dataset.Items[0].Text + "я")
	if _, err := Prepare(dataset); !errors.Is(err, ErrInputTooLarge) {
		t.Fatalf("input beyond 144000 characters accepted: %v", err)
	}
}
