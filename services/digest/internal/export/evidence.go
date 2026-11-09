package export

import (
	"maps"
	"slices"
	"strings"

	"github.com/Knigor/notFive_backend/services/digest/internal/analysis"
)

func verifiedAnalysis(original analysis.Result, news analysis.Prepared) (analysis.Result, map[string][]string, []analysis.EvidenceCheck) {
	result := original
	result.Groups = map[string]analysis.Group{}
	result.RecommendedOrder = []string{}
	result.Excluded = maps.Clone(original.Excluded)
	for id, reason := range news.Excluded {
		if reason == "analysis_budget_exceeded" {
			result.Excluded[id] = message("ru", "source_"+reason, analysis.MaxTextCharacters)
			continue
		}
		result.Excluded[id] = message("ru", "source_"+reason)
	}
	issues := map[string][]string{}
	checks := []analysis.EvidenceCheck{}
	items := make(map[string]analysis.Item, len(news.Dataset.Items))
	for _, item := range news.Dataset.Items {
		items[item.ID] = item
	}
	rejectedIDs := map[string]string{}
	for _, id := range slices.Sorted(maps.Keys(original.Groups)) {
		group := original.Groups[id]
		validIDs := []string{}
		groupIssues := []string{}
		for _, articleID := range group.IDs {
			item, exists := items[articleID]
			switch {
			case !exists:
				groupIssues = append(groupIssues, "unavailable_article:"+articleID)
				rejectedIDs[articleID] = message("ru", "invalid_source_reason")
			case result.Excluded[articleID] != "":
				groupIssues = append(groupIssues, "excluded_article:"+articleID)
			case !validURL(item.URL):
				groupIssues = append(groupIssues, "invalid_source_url:"+articleID)
				rejectedIDs[articleID] = message("ru", "invalid_source_reason")
			default:
				if !slices.Contains(validIDs, articleID) {
					validIDs = append(validIDs, articleID)
				}
			}
		}
		facts := []analysis.Fact{}
		for factIndex, fact := range group.Facts {
			verified, factChecks, valid := verifiedFact(id, factIndex, fact, validIDs, items)
			checks = append(checks, factChecks...)
			if valid {
				facts = append(facts, verified)
			} else {
				groupIssues = append(groupIssues, "unverified_evidence")
			}
		}
		contradictions := []analysis.Contradiction{}
		unsafeContradiction := false
		for _, contradiction := range group.Contradictions {
			valid := strings.TrimSpace(contradiction.Description) != "" && len(contradiction.SourceIDs) > 0
			for _, articleID := range contradiction.SourceIDs {
				if !slices.Contains(validIDs, articleID) {
					valid = false
				}
			}
			if !valid {
				unsafeContradiction = true
				groupIssues = append(groupIssues, "unverified_contradiction")
			}
			contradictions = append(contradictions, contradiction)
		}
		if len(facts) == 0 {
			groupIssues = append(groupIssues, "no_verified_facts")
		}
		if len(groupIssues) > 0 {
			slices.Sort(groupIssues)
			issues[id] = slices.Compact(groupIssues)
		}
		if len(facts) == 0 || unsafeContradiction {
			reason := "no_verified_facts_reason"
			if unsafeContradiction {
				reason = "unverified_contradiction_reason"
			}
			for _, articleID := range group.IDs {
				rejectedIDs[articleID] = message("ru", reason)
			}
			continue
		}
		group.IDs = validIDs
		group.Facts = facts
		group.Contradictions = contradictions
		if len(groupIssues) > 0 {
			group.SelectionReason = ""
		}
		result.Groups[id] = group
	}
	retainedIDs := map[string]bool{}
	for _, group := range result.Groups {
		for _, id := range group.IDs {
			retainedIDs[id] = true
		}
	}
	for id, reason := range rejectedIDs {
		if !retainedIDs[id] && result.Excluded[id] == "" {
			result.Excluded[id] = reason
		}
	}
	for _, id := range original.RecommendedOrder {
		if _, exists := result.Groups[id]; exists {
			result.RecommendedOrder = append(result.RecommendedOrder, id)
		}
	}
	result.EvidenceChecks = checks
	return result, issues, checks
}

func verifiedFact(groupID string, factIndex int, original analysis.Fact, groupIDs []string, items map[string]analysis.Item) (analysis.Fact, []analysis.EvidenceCheck, bool) {
	fact := original
	fact.SourceIDs = slices.Clone(original.SourceIDs)
	fact.Evidence = []analysis.Evidence{}
	checks := []analysis.EvidenceCheck{}
	valid := strings.TrimSpace(fact.Statement) != "" && len(fact.SourceIDs) > 0 && len(original.Evidence) > 0
	supported := map[string]bool{}
	for _, id := range fact.SourceIDs {
		if !slices.Contains(groupIDs, id) {
			valid = false
		}
	}
	for _, evidence := range original.Evidence {
		item, exists := items[evidence.ArticleID]
		source := ""
		if item.Text != nil {
			source = *item.Text
		}
		match := analysis.MatchQuote(source, evidence.Quote)
		check := analysis.EvidenceCheck{GroupID: groupID, FactIndex: factIndex + 1, ArticleID: evidence.ArticleID, ModelQuote: evidence.Quote, SourceQuote: match.SourceQuote, Match: match.Method, Transformations: match.Transformations}
		switch {
		case !exists || !slices.Contains(groupIDs, evidence.ArticleID) || !slices.Contains(fact.SourceIDs, evidence.ArticleID):
			check.Reason = "invalid_source_reference"
		case match.Method == "unmatched":
			check.Reason = "quote_not_found"
		default:
			supported[evidence.ArticleID] = true
			fact.Evidence = append(fact.Evidence, analysis.Evidence{ArticleID: evidence.ArticleID, Quote: match.SourceQuote})
		}
		checks = append(checks, check)
		if check.Reason != "" {
			valid = false
		}
	}
	for _, id := range fact.SourceIDs {
		if !supported[id] {
			valid = false
		}
	}
	return fact, checks, valid
}
