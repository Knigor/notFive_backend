package analysis

import (
	"slices"
	"strings"
	"unicode/utf8"
)

type EvidenceCheck struct {
	GroupID         string   `json:"group_id"`
	FactIndex       int      `json:"fact_index"`
	ArticleID       string   `json:"article_id"`
	ModelQuote      string   `json:"model_quote"`
	SourceQuote     string   `json:"source_quote"`
	Match           string   `json:"match"`
	Transformations []string `json:"transformations"`
	Reason          string   `json:"reason,omitzero"`
}

type QuoteMatch struct {
	SourceQuote     string
	Method          string
	Transformations []string
}

func MatchQuote(source, quote string) QuoteMatch {
	if strings.TrimSpace(quote) == "" {
		return QuoteMatch{Method: "unmatched", Transformations: []string{}}
	}
	if strings.Contains(source, quote) {
		return QuoteMatch{SourceQuote: quote, Method: "exact", Transformations: []string{}}
	}
	normalize := func(r rune) rune {
		switch r {
		case 'ё':
			return 'е'
		case 'Ё':
			return 'Е'
		case '‑':
			return '-'
		default:
			return r
		}
	}
	normalizedSource := strings.Map(normalize, source)
	normalizedQuote := strings.Map(normalize, quote)
	start := strings.Index(normalizedSource, normalizedQuote)
	if start < 0 {
		return QuoteMatch{Method: "unmatched", Transformations: []string{}}
	}
	first := utf8.RuneCountInString(normalizedSource[:start])
	quoteRunes := []rune(quote)
	sourceRunes := []rune(source)[first : first+len(quoteRunes)]
	transformations := []string{}
	for i, r := range quoteRunes {
		if r == sourceRunes[i] {
			continue
		}
		transformation := "yo_to_e"
		if r == '‑' || sourceRunes[i] == '‑' {
			transformation = "nonbreaking_hyphen_to_hyphen"
		}
		if !slices.Contains(transformations, transformation) {
			transformations = append(transformations, transformation)
		}
	}
	return QuoteMatch{SourceQuote: string(sourceRunes), Method: "typographic", Transformations: transformations}
}
