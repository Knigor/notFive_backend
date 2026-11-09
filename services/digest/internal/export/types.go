// Package export asks the model for structured publication prose.
package export

import "github.com/Knigor/notFive_backend/services/digest/internal/analysis"

type Request struct {
	Analysis analysis.Result  `json:"analysis"`
	News     analysis.Dataset `json:"news"`
}

type Result struct {
	SelectionSummary string      `json:"selection_summary"`
	Events           []EventText `json:"events"`
	Warnings         []string    `json:"warnings"`
}

type Prepared struct {
	Request        Request
	Input          string
	Locale         string
	EvidenceChecks []analysis.EvidenceCheck
	issues         map[string][]string
}

type EventText struct {
	GroupID      string `json:"group_id"`
	Title        string `json:"title"`
	Summary      string `json:"summary"`
	Consequences string `json:"consequences"`
	Post         string `json:"post"`
	Reason       string `json:"reason"`
}

type modelResult struct {
	SelectionSummary string      `json:"selection_summary"`
	Events           []EventText `json:"events"`
}
