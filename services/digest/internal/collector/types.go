// Package collector defines source articles and the search provider contract.
package collector

import (
	"context"
	"encoding/json"
	"time"
)

type Article struct {
	ID          string `json:"id"`
	Title       string `json:"title"`
	Text        string `json:"text"`
	Source      string `json:"source"`
	URL         string `json:"url"`
	PublishedAt string `json:"published_at"`
	ReadError   string `json:"read_error,omitzero"`
}

type Generation struct {
	Text       string          `json:"text"`
	Model      string          `json:"model"`
	ResponseID string          `json:"response_id"`
	Usage      json.RawMessage `json:"usage,omitzero"`
	Trace      json.RawMessage `json:"trace,omitzero"`
	StartedAt  time.Time       `json:"started_at"`
	DurationMS int64           `json:"duration_ms"`
}

// Provider searches for article links. Collected texts are not sent for synthesis.
type Provider interface {
	Generate(ctx context.Context, instructions, input string) (Generation, error)
}

type domainsContextKey struct{}

func WithDomains(ctx context.Context, domains []string) context.Context {
	return context.WithValue(ctx, domainsContextKey{}, domains)
}

func Domains(ctx context.Context, fallback []string) []string {
	if domains, ok := ctx.Value(domainsContextKey{}).([]string); ok {
		return domains
	}
	return fallback
}
