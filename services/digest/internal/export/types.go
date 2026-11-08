// Package export turns a saved analysis and its source dataset into deliverable files.
package export

import "github.com/Knigor/notFive_backend/services/digest/internal/analysis"

type Request struct {
	Analysis analysis.Result  `json:"analysis"`
	News     analysis.Dataset `json:"news"`
}

type Result struct {
	Files    map[string]string `json:"files"`
	Warnings []string          `json:"warnings"`
}

type Prepared struct {
	Request  Request
	Input    string
	Locale   string
	issues   map[string][]string
	excluded map[string]string
}

type eventText struct {
	GroupID      string `json:"group_id"`
	Title        string `json:"title"`
	Summary      string `json:"summary"`
	Consequences string `json:"consequences"`
	Post         string `json:"post"`
	Reason       string `json:"reason"`
}

type modelResult struct {
	SelectionSummary string      `json:"selection_summary"`
	Events           []eventText `json:"events"`
}
