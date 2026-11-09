package httpapi

import (
	"context"
	"encoding/json"
	"errors"
	"net/http"
	"net/http/httptest"
	"strings"
	"sync/atomic"
	"testing"
	"time"

	"github.com/Knigor/notFive_backend/services/digest/internal/collector"
	"github.com/Knigor/notFive_backend/services/digest/internal/news"
	"github.com/Knigor/notFive_backend/services/digest/internal/yandex"
)

const serviceToken = "test-private-token-at-least-32-characters"

type providerFunc func(context.Context, string, string) (collector.Generation, error)

type rejectSourceFetcher struct{}

func (rejectSourceFetcher) Fetch(context.Context, string) (news.Article, error) {
	return news.Article{}, news.ErrUnavailable
}

func (f providerFunc) Generate(ctx context.Context, ins, in string) (collector.Generation, error) {
	return f(ctx, ins, in)
}
func newServer(t *testing.T, p collector.Provider) (*Server, Config) {
	t.Helper()
	cfg := Config{Token: serviceToken, DataDir: t.TempDir(), Timeout: 15 * time.Second, MaxConcurrent: 1, SourceFetcher: rejectSourceFetcher{}}
	server, err := New(cfg, p)
	if err != nil {
		t.Fatal(err)
	}
	return server, cfg
}
func call(handler http.Handler, method, path, body, token string) *httptest.ResponseRecorder {
	request := httptest.NewRequest(method, path, strings.NewReader(body))
	if token != "" {
		request.Header.Set("Authorization", "Bearer "+token)
	}
	recorder := httptest.NewRecorder()
	handler.ServeHTTP(recorder, request)
	return recorder
}

func savedRunFromCollection(t *testing.T, handler http.Handler, response *httptest.ResponseRecorder) Run {
	t.Helper()
	id := response.Header().Get("X-Run-ID")
	location := response.Header().Get("Location")
	if response.Code != http.StatusCreated || !runIDPattern.MatchString(id) || location != "/v1/runs/"+id {
		t.Fatalf("missing saved run reference: status=%d headers=%v", response.Code, response.Header())
	}
	saved := call(handler, "GET", location, "", serviceToken)
	var run Run
	if saved.Code != http.StatusOK || json.Unmarshal(saved.Body.Bytes(), &run) != nil || run.ID != id {
		t.Fatalf("could not retrieve saved run: status=%d body=%s", saved.Code, saved.Body.String())
	}
	var document map[string]json.RawMessage
	if err := json.Unmarshal(saved.Body.Bytes(), &document); err != nil {
		t.Fatal(err)
	}
	for _, key := range []string{"result", "items", "news"} {
		if _, found := document[key]; found {
			t.Errorf("saved run contains unexpected generated or duplicated material: %s", key)
		}
	}
	return run
}

func TestAuthenticationAndValidation(t *testing.T) {
	var calls atomic.Int32
	server, _ := newServer(t, providerFunc(func(context.Context, string, string) (collector.Generation, error) {
		calls.Add(1)
		return collector.Generation{}, errors.New("must not be called")
	}))
	if call(server, "GET", "/healthz", "", "").Code != 200 {
		t.Fatal("healthcheck requires auth")
	}
	for _, path := range []string{"/v1/collect", "/v1/runs/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa"} {
		method := "POST"
		if strings.Contains(path, "/runs/") {
			method = "GET"
		}
		if res := call(server, method, path, `{}`, "wrong"); res.Code != 401 {
			t.Errorf("%s: %d", path, res.Code)
		}
	}
	cases := []struct {
		path, body string
		status     int
	}{
		{"/v1/collect", `{"control_time":"2026-11-07T12:00:00+03:00","query":"news","model":"attacker"}`, 400},
		{"/v1/collect", `{"control_time":"2026-11-07T12:00:00+03:00","query":"news"} {}`, 400},
		{"/v1/collect", `{"control_time":"2026-11-07T12:00:00","query":"news"}`, 422},
		{"/v1/collect", `{"control_time":"2026-11-07T12:00:00+03:00"}`, 422},
		{"/v1/collect", `{"control_time":"` + strings.Repeat("x", maxBody) + `"}`, 413},
		{"/v1/collect", `{"control_time":"2026-11-07T12:00:00+03:00","query":""}`, 422},
	}
	for _, tt := range cases {
		res := call(server, "POST", tt.path, tt.body, serviceToken)
		if res.Code != tt.status {
			t.Errorf("want %d got %d: %s", tt.status, res.Code, res.Body.String())
		}
	}
	if calls.Load() != 0 {
		t.Fatal("invalid/auth requests triggered provider")
	}
}

func TestResearchRecordsOnlyActualSearchTrace(t *testing.T) {
	provider := providerFunc(func(ctx context.Context, ins, input string) (collector.Generation, error) {
		if !strings.Contains(ins, "web_search") || !strings.Contains(input, "window_start_exclusive") {
			t.Error("wrong research request")
		}
		return collector.Generation{Text: "Краткий обзор", Trace: json.RawMessage(`{"output":[{"type":"web_search_call","action":{"type":"search","queries":["actual query"]}},{"type":"message","content":[{"type":"output_text","annotations":[{"type":"url_citation","url":"https://source.example/news","title":"ignored"},{"type":"url_citation","url":"https://source.example/news"},{"type":"url_citation","url":"javascript:alert(1)"},{"type":"url_citation","url":"source.example"}]}]}]}`)}, nil
	})
	server, _ := newServer(t, provider)
	response := call(server, "POST", "/v1/collect", `{"control_time":"2026-11-07T09:00:00Z","query":"свежие уязвимости"}`, serviceToken)
	if response.Code != 201 {
		t.Fatal(response.Body.String())
	}
	run := savedRunFromCollection(t, server, response)
	if run.Mode != "collection" || run.Research == nil || run.Research.Status != "completed" || !run.Research.SearchObserved || len(run.Research.SearchCalls) != 2 || len(run.Research.CitationURLs) != 1 || len(run.Research.CitationRefs) != 3 || run.Research.ControlTime != "2026-11-07T12:00:00+03:00" {
		t.Fatalf("bad research %+v", run.Research)
	}
	if run.Collection == nil || len(run.Collection.Articles) != 0 || len(run.Collection.ExcludedArticles) != 1 {
		t.Fatal("raw model overview leaked or excluded candidate lost")
	}
	if !runIDPattern.MatchString(run.ID) || len(run.InputSHA256) != 64 || run.Cost != nil {
		t.Fatalf("invalid saved run metadata: %+v", run)
	}
	empty := Research{Generation: collector.Generation{Trace: json.RawMessage(`{"output":[]}`)}}
	extractSearchTrace(&empty)
	if empty.SearchObserved || len(empty.SearchCalls) != 0 || len(empty.CitationURLs) != 0 {
		t.Error("invented search evidence")
	}
}

func TestProviderFailureIsPersisted(t *testing.T) {
	server, _ := newServer(t, providerFunc(func(context.Context, string, string) (collector.Generation, error) {
		return collector.Generation{ResponseID: "failed-response"}, &yandex.UpstreamError{Status: 401}
	}))
	response := call(server, "POST", "/v1/collect", `{"control_time":"2026-11-07T12:00:00+03:00","query":"ИБ"}`, serviceToken)
	if response.Code != 502 {
		t.Fatal(response.Body.String())
	}
	var failure struct {
		RunID string `json:"run_id"`
	}
	_ = json.Unmarshal(response.Body.Bytes(), &failure)
	response = call(server, "GET", "/v1/runs/"+failure.RunID, "", serviceToken)
	var run Run
	_ = json.Unmarshal(response.Body.Bytes(), &run)
	if run.Status != "failed" || run.UpstreamStatus != 401 || run.Research.Generation.ResponseID != "failed-response" {
		t.Fatal("failed run lost")
	}
	if call(server, "GET", "/v1/runs/"+failure.RunID+"/artifacts/research.md", "", serviceToken).Code != 404 {
		t.Fatal("failed run exposes an artifact")
	}
}

func TestConcurrencyAndCancellation(t *testing.T) {
	entered := make(chan struct{})
	server, _ := newServer(t, providerFunc(func(ctx context.Context, _, _ string) (collector.Generation, error) {
		close(entered)
		<-ctx.Done()
		return collector.Generation{}, ctx.Err()
	}))
	ctx, cancel := context.WithCancel(t.Context())
	defer cancel()
	request := httptest.NewRequest("POST", "/v1/collect", strings.NewReader(`{"control_time":"2026-11-07T12:00:00+03:00","query":"ИБ"}`)).WithContext(ctx)
	request.Header.Set("Authorization", "Bearer "+serviceToken)
	response := httptest.NewRecorder()
	done := make(chan struct{})
	go func() {
		defer close(done)
		server.ServeHTTP(response, request)
	}()
	<-entered
	busy := call(server, "POST", "/v1/collect", `{"control_time":"2026-11-07T12:00:00+03:00","query":"ИБ"}`, serviceToken)
	if busy.Code != 429 || busy.Header().Get("Retry-After") != "5" {
		t.Errorf("expected concurrency limit and retry hint, got %d %v", busy.Code, busy.Header())
	}
	cancel()
	<-done
	if response.Code != 408 {
		t.Errorf("expected cancelled collection status408, got %d", response.Code)
	}

}

func TestRemovedGenerationRoutesReturnNotFound(t *testing.T) {
	var calls atomic.Int32
	server, _ := newServer(t, providerFunc(func(context.Context, string, string) (collector.Generation, error) {
		calls.Add(1)
		return collector.Generation{}, errors.New("removed endpoint called provider")
	}))
	for _, path := range []string{"/v1/digests", "/v1/research", "/v1/runs/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa/artifacts/research.md"} {
		method := "POST"
		if strings.Contains(path, "/runs/") {
			method = "GET"
		}
		if response := call(server, method, path, `{}`, serviceToken); response.Code != 404 {
			t.Errorf("removed route %s returned %d", path, response.Code)
		}
	}
	if calls.Load() != 0 {
		t.Fatal("removed endpoint called provider")
	}
}
