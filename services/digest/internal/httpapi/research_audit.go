package httpapi

import (
	"crypto/sha256"
	"encoding/hex"
	"net/url"
	"strings"
)

type CandidateAudit struct {
	ID     string `json:"id"`
	URL    string `json:"url"`
	Stage  string `json:"stage"`
	Status string `json:"status"`
	Reason string `json:"reason"`
	Error  string `json:"error,omitzero"`
}

func candidateID(address string) string {
	hash := sha256.Sum256([]byte(address))
	return "web-" + hex.EncodeToString(hash[:8])
}

func canonicalCandidateURL(raw string) string {
	parsed, err := url.Parse(strings.TrimSpace(raw))
	if err != nil || parsed.Host == "" {
		return clipResearchText(strings.TrimSpace(raw), 2048)
	}
	parsed.Fragment, parsed.RawFragment = "", ""
	parsed.Host = strings.ToLower(parsed.Host)
	if parsed.Scheme == "https" && parsed.Port() == "443" {
		parsed.Host = parsed.Hostname()
	}
	if parsed.User != nil {
		parsed.User = nil
		parsed.RawQuery = ""
	}
	query := parsed.Query()
	for key := range query {
		if strings.HasPrefix(strings.ToLower(key), "utm_") {
			query.Del(key)
		}
	}
	parsed.RawQuery = query.Encode()
	return clipResearchText(parsed.String(), 2048)
}

func (r *Research) recordCandidate(address, stage, status, reason, code string) {
	entry := CandidateAudit{ID: candidateID(address), URL: address, Stage: stage, Status: status, Reason: reason, Error: code}
	if r.candidateIndexes == nil {
		r.candidateIndexes = make(map[string]int, len(r.CandidateAudit))
		for i, previous := range r.CandidateAudit {
			r.candidateIndexes[previous.ID] = i
		}
	}
	if i, exists := r.candidateIndexes[entry.ID]; exists {
		if r.CandidateAudit[i].Status != "accepted" {
			r.CandidateAudit[i] = entry
		}
		return
	}
	r.candidateIndexes[entry.ID] = len(r.CandidateAudit)
	r.CandidateAudit = append(r.CandidateAudit, entry)
}
