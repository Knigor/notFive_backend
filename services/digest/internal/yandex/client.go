package yandex

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net/http"
	"net/url"
	"strings"
	"time"

	"github.com/Knigor/notFive_backend/services/digest/internal/collector"
)

const DefaultBaseURL = "https://ai.api.cloud.yandex.net/v1"

// Medium permits multi-step discovery across the agreed sources.
const SearchContextSize = "medium"

// Config is server-owned. Callers cannot choose credentials, endpoints or tools.
type Config struct {
	APIKey          string
	FolderID        string
	Model           string
	BaseURL         string
	Domains         []string
	MaxOutputTokens int
	Timeout         time.Duration
}

type Client struct {
	cfg  Config
	http *http.Client
}

type UpstreamError struct{ Status int }

func (e *UpstreamError) Error() string { return fmt.Sprintf("Yandex API returned HTTP %d", e.Status) }

func New(cfg Config) (*Client, error) {
	if strings.TrimSpace(cfg.APIKey) == "" || strings.TrimSpace(cfg.FolderID) == "" {
		return nil, errors.New("YANDEX_API_KEY and YANDEX_FOLDER_ID are required")
	}
	if cfg.Model == "" {
		cfg.Model = "yandexgpt-5.1"
	}
	if cfg.BaseURL == "" {
		cfg.BaseURL = DefaultBaseURL
	}
	u, err := url.Parse(cfg.BaseURL)
	if err != nil || u.Host == "" || u.User != nil || u.RawQuery != "" || u.Fragment != "" || (u.Scheme != "https" && !(u.Scheme == "http" && (u.Hostname() == "localhost" || u.Hostname() == "127.0.0.1" || u.Hostname() == "::1"))) {
		return nil, errors.New("YANDEX_BASE_URL must be HTTPS (HTTP only for loopback tests)")
	}
	if cfg.MaxOutputTokens == 0 {
		cfg.MaxOutputTokens = 8000
	}
	if cfg.MaxOutputTokens < 100 || cfg.MaxOutputTokens > 16000 {
		return nil, errors.New("MAX_OUTPUT_TOKENS must be 100..16000")
	}
	if cfg.Timeout == 0 {
		cfg.Timeout = 180 * time.Second
	}
	if len(cfg.Domains) < 3 || len(cfg.Domains) > 5 {
		return nil, errors.New("YANDEX_SEARCH_DOMAINS requires 3 to 5 agreed source domains")
	}
	seen := map[string]bool{}
	for _, domain := range cfg.Domains {
		if domain != strings.ToLower(domain) || strings.ContainsAny(domain, "/:?#@ *\t\r\n") || !strings.Contains(domain, ".") || seen[domain] {
			return nil, errors.New("search domains must be unique lowercase hostnames without scheme/path")
		}
		seen[domain] = true
	}
	return &Client{cfg: cfg, http: &http.Client{Timeout: cfg.Timeout, CheckRedirect: func(*http.Request, []*http.Request) error { return http.ErrUseLastResponse }}}, nil
}

func (c *Client) Generate(ctx context.Context, instructions, input string) (gen collector.Generation, returnErr error) {
	started := time.Now().UTC()
	defer func() { gen.DurationMS = time.Since(started).Milliseconds() }()
	body := map[string]any{"model": "gpt://" + c.cfg.FolderID + "/" + c.cfg.Model, "instructions": instructions, "input": input, "temperature": 0.1, "max_output_tokens": c.cfg.MaxOutputTokens, "store": false}
	body["tools"] = []any{map[string]any{"type": "web_search", "search_context_size": SearchContextSize, "filters": map[string]any{"allowed_domains": c.cfg.Domains}, "user_location": map[string]any{"region": "213"}}}
	body["tool_choice"] = "required"
	encoded, err := json.Marshal(body)
	if err != nil {
		return collector.Generation{}, err
	}
	req, err := http.NewRequestWithContext(ctx, http.MethodPost, strings.TrimRight(c.cfg.BaseURL, "/")+"/responses", bytes.NewReader(encoded))
	if err != nil {
		return collector.Generation{}, err
	}
	req.Header.Set("Authorization", "Bearer "+c.cfg.APIKey)
	req.Header.Set("x-project", c.cfg.FolderID)
	req.Header.Set("Content-Type", "application/json")
	gen = collector.Generation{Model: body["model"].(string), StartedAt: started}
	resp, err := c.http.Do(req)
	if err != nil {
		if ctx.Err() != nil {
			return gen, ctx.Err()
		}
		return gen, errors.New("Yandex request failed or timed out")
	}
	defer resp.Body.Close()
	if resp.StatusCode != http.StatusOK {
		return gen, &UpstreamError{Status: resp.StatusCode}
	}
	raw, err := io.ReadAll(io.LimitReader(resp.Body, 4*1024*1024+1))
	if err != nil {
		if ctx.Err() != nil {
			return gen, ctx.Err()
		}
		return gen, errors.New("cannot read Yandex response")
	}
	if len(raw) > 4*1024*1024 {
		return gen, errors.New("Yandex response exceeds size limit")
	}
	var result struct {
		ID                string          `json:"id"`
		Model             string          `json:"model"`
		Status            string          `json:"status"`
		Error             json.RawMessage `json:"error"`
		IncompleteDetails struct {
			Reason string `json:"reason"`
		} `json:"incomplete_details"`
		Usage  json.RawMessage `json:"usage"`
		Output []struct {
			Type    string `json:"type"`
			Content []struct {
				Type string `json:"type"`
				Text string `json:"text"`
			} `json:"content"`
		} `json:"output"`
	}
	if err := json.Unmarshal(raw, &result); err != nil {
		return gen, errors.New("invalid Yandex response JSON")
	}
	gen.Trace = json.RawMessage(raw)
	gen.ResponseID = result.ID
	gen.Usage = result.Usage
	if result.Model != "" {
		gen.Model = result.Model
	}
	if len(result.Error) > 0 && string(result.Error) != "null" {
		return gen, errors.New("Yandex response contains an error")
	}
	if result.Status != "completed" {
		return gen, errors.New("Yandex response is not completed")
	}
	var parts []string
	for _, item := range result.Output {
		if item.Type != "message" {
			continue
		}
		for _, content := range item.Content {
			if content.Type == "refusal" {
				return gen, errors.New("model refused request")
			}
			if content.Type == "output_text" {
				parts = append(parts, content.Text)
			}
		}
	}
	gen.Text = strings.Join(parts, "\n")
	if strings.TrimSpace(gen.Text) == "" {
		return gen, errors.New("Yandex returned no output text")
	}
	return gen, nil
}
