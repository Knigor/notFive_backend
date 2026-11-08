package yandex

import (
	"context"
	"encoding/json"
	"errors"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
	"time"
)

const completed = `{"id":"r1","model":"model-version","status":"completed","usage":{"input_tokens":123,"output_tokens":10},"output":[{"type":"web_search_call","action":{"type":"search","queries":["news"]}},{"type":"message","content":[{"type":"output_text","text":"first","annotations":[]},{"type":"output_text","text":"second"}]}]}`

func testClient(t *testing.T, h http.HandlerFunc) *Client {
	t.Helper()
	server := httptest.NewServer(h)
	t.Cleanup(server.Close)
	c, err := New(Config{APIKey: "test-api-secret", FolderID: "test-folder", BaseURL: server.URL + "/v1", Domains: []string{"securitylab.ru", "habr.com", "xakep.ru"}})
	if err != nil {
		t.Fatal(err)
	}
	return c
}

func TestRequestContract(t *testing.T) {
	client := testClient(t, func(w http.ResponseWriter, r *http.Request) {
		if r.Method != "POST" || r.URL.Path != "/v1/responses" {
			t.Errorf("wrong request %s %s", r.Method, r.URL.Path)
		}
		if r.Header.Get("Authorization") != "Bearer test-api-secret" || r.Header.Get("x-project") != "test-folder" {
			t.Error("wrong credentials headers")
		}
		var body map[string]any
		if err := json.NewDecoder(r.Body).Decode(&body); err != nil {
			t.Fatal(err)
		}
		if body["model"] != "gpt://test-folder/yandexgpt-5.1" || body["instructions"] != "trusted instructions" || body["input"] != "untrusted input" || body["store"] != false {
			t.Errorf("bad payload %v", body)
		}
		if body["tool_choice"] != "required" {
			t.Error("search tool must be required")
		}
		items, ok := body["tools"].([]any)
		if !ok || len(items) != 1 {
			t.Fatal("missing search tool")
		}
		tool := items[0].(map[string]any)
		if tool["type"] != "web_search" || tool["search_context_size"] != "medium" {
			t.Error("wrong search tool")
		}
		domains := tool["filters"].(map[string]any)["allowed_domains"].([]any)
		if len(domains) != 3 || domains[0] != "securitylab.ru" || domains[1] != "habr.com" || domains[2] != "xakep.ru" {
			t.Error("wrong domain filter")
		}
		if tool["user_location"].(map[string]any)["region"] != "213" {
			t.Error("wrong search region")
		}
		if _, exists := body["text"]; exists {
			t.Error("cannot combine structured output and tools")
		}
		_, _ = w.Write([]byte(completed))
	})
	gen, err := client.Generate(t.Context(), "trusted instructions", "untrusted input")
	if err != nil {
		t.Fatal(err)
	}
	if gen.Text != "first\nsecond" || gen.Model != "model-version" || gen.ResponseID != "r1" || len(gen.Usage) == 0 || len(gen.Trace) == 0 || gen.StartedAt.IsZero() {
		t.Errorf("bad result %+v", gen)
	}
}

func TestRejectsUnsuccessfulResponse(t *testing.T) {
	cases := []struct {
		name, body string
		status     int
	}{
		{"rate limit", `{"error":"test-api-secret"}`, 429},
		{"incomplete", `{"status":"incomplete","incomplete_details":{"reason":"max_output_tokens"},"output":[]}`, 200},
		{"refusal", `{"status":"completed","output":[{"type":"message","content":[{"type":"refusal","refusal":"no"}]}]}`, 200},
		{"error", `{"status":"completed","error":{"message":"test-api-secret"},"output":[]}`, 200},
		{"empty", `{"status":"completed","output":[]}`, 200},
		{"invalid", `broken`, 200},
		{"oversized", strings.Repeat("x", 4*1024*1024+1), 200},
	}
	for _, tt := range cases {
		t.Run(tt.name, func(t *testing.T) {
			c := testClient(t, func(w http.ResponseWriter, r *http.Request) {
				w.WriteHeader(tt.status)
				_, _ = w.Write([]byte(tt.body))
			})
			_, err := c.Generate(t.Context(), "", "")
			if err == nil || strings.Contains(err.Error(), "test-api-secret") {
				t.Fatalf("expected sanitized error, got %v", err)
			}
			if tt.status == 429 {
				upstream, ok := errors.AsType[*UpstreamError](err)
				if !ok || upstream.Status != 429 {
					t.Error("missing upstream status")
				}
			}
		})
	}
}

func TestCancellationAndRedirects(t *testing.T) {
	t.Run("cancel", func(t *testing.T) {
		release := make(chan struct{})
		defer close(release)
		client := testClient(t, func(w http.ResponseWriter, r *http.Request) {
			select {
			case <-r.Context().Done():
			case <-release:
			}
		})
		ctx, cancel := context.WithTimeout(t.Context(), 20*time.Millisecond)
		defer cancel()
		_, err := client.Generate(ctx, "", "")
		if !errors.Is(err, context.DeadlineExceeded) {
			t.Fatalf("got %v", err)
		}
	})
	t.Run("no credential redirect", func(t *testing.T) {
		target := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) { t.Error("redirect followed with credentials") }))
		defer target.Close()
		client := testClient(t, func(w http.ResponseWriter, r *http.Request) { http.Redirect(w, r, target.URL, 307) })
		_, err := client.Generate(t.Context(), "", "")
		if err == nil {
			t.Fatal("expected redirect error")
		}
	})
}
