package httpapi

import (
	"context"
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"reflect"
	"slices"
	"strings"
	"sync"
	"testing"
	"time"

	"github.com/Knigor/notFive_backend/services/digest/internal/analysis"
	"github.com/Knigor/notFive_backend/services/digest/internal/collector"
	"github.com/Knigor/notFive_backend/services/digest/internal/news"
	"github.com/Knigor/notFive_backend/services/digest/internal/yandex"
)

const pipelineTestRequest = `{"control_time":"2026-11-08T10:00:00Z","sites":["https://www.securitylab.ru"]}`
const pipelineTestURL = "https://www.securitylab.ru/news/600001.php"
const pipelineTestText = "Тестовый источник сообщает об исправлении уязвимости."

// The fake providers exercise HTTP orchestration without network or model calls.
func pipelineFixture(t *testing.T, empty bool, failureStage string) (*Server, *[]string) {
	t.Helper()
	calls := []string{}
	checkStage := func(ctx context.Context, stage string) error {
		calls = append(calls, stage)
		deadline, ok := ctx.Deadline()
		if !ok || time.Until(deadline) > 15*time.Second {
			t.Errorf("%s has no per-stage timeout", stage)
		}
		if stage == failureStage {
			return &yandex.UpstreamError{Status: http.StatusServiceUnavailable}
		}
		return nil
	}
	search := providerFunc(func(ctx context.Context, _, input string) (collector.Generation, error) {
		if !strings.Contains(input, "2026-11-08T13:00:00+03:00") {
			t.Error("search lost normalized control time")
		}
		var plan struct {
			Topic string `json:"untrusted_topic"`
		}
		_, payload, _ := strings.Cut(input, "\n")
		if json.Unmarshal([]byte(payload), &plan) != nil || plan.Topic != pipelineSearchQuery {
			t.Error("search did not receive the server-owned query")
		}
		generation := collector.Generation{ResponseID: "collection-response", Trace: json.RawMessage(`{"output":[{"type":"web_search_call"}]}`)}
		if err := checkStage(ctx, "collection"); err != nil {
			return generation, err
		}
		generation.Text = `{"candidates":[]}`
		if !empty {
			generation.Text = `{"candidates":[{"url":"` + pipelineTestURL + `"}]}`
		}
		return generation, nil
	})
	_, cfg := newServer(t, search)
	cfg.SearchDomains = []string{"securitylab.ru"}
	cfg.SourceFetcher = researchFetchFunc(func(_ context.Context, address string) (news.Article, error) {
		return news.Article{URL: address, Title: "Тестовая публикация", Text: pipelineTestText,
			PublishedAt: time.Date(2026, 11, 8, 12, 0, 0, 0, time.FixedZone("MSK", 3*60*60)), DateEvidence: "test datePublished"}, nil
	})
	cfg.SearchProviderFactory = func([]string) (collector.Provider, error) { return search, nil }
	cfg.SourceFetcherFactory = func([]string) SourceFetcher { return cfg.SourceFetcher }
	var sourceID string
	cfg.AnalysisProvider = providerFunc(func(ctx context.Context, _, input string) (collector.Generation, error) {
		generation := collector.Generation{ResponseID: "analysis-response"}
		if err := checkStage(ctx, "analysis"); err != nil {
			return generation, err
		}
		var body struct {
			ControlTime string          `json:"control_time"`
			Items       []analysis.Item `json:"untrusted_items"`
		}
		if json.Unmarshal([]byte(input), &body) != nil || len(body.Items) != 1 || body.ControlTime != "2026-11-08T13:00:00+03:00" || body.Items[0].URL != pipelineTestURL || body.Items[0].Text == nil || *body.Items[0].Text != pipelineTestText {
			t.Fatal("analysis did not receive the collected article and same period")
		}
		sourceID = body.Items[0].ID
		generation.Text = `{"groups":[{"id":"G1","name":"Исправление уязвимости","ids":["` + sourceID + `"],"facts":[{"statement":"Исправлена уязвимость","source_ids":["` + sourceID + `"],"evidence":[{"article_id":"` + sourceID + `","quote":"` + pipelineTestText + `"}]}],"contradictions":[],"selection_reason":"Есть исправление"}],"recommended_order":["G1"],"excluded":[]}`
		return generation, nil
	})
	cfg.ExportProvider = providerFunc(func(ctx context.Context, _, input string) (collector.Generation, error) {
		generation := collector.Generation{ResponseID: "export-response"}
		if err := checkStage(ctx, "export"); err != nil {
			return generation, err
		}
		var body struct {
			ControlTime string                    `json:"control_time"`
			Groups      map[string]analysis.Group `json:"untrusted_analysis"`
			Articles    []analysis.Item           `json:"untrusted_articles"`
		}
		if json.Unmarshal([]byte(input), &body) != nil || body.ControlTime != "2026-11-08T13:00:00+03:00" || !reflect.DeepEqual(body.Groups["G1"].IDs, []string{sourceID}) || len(body.Articles) != 1 || body.Articles[0].URL != pipelineTestURL {
			t.Fatal("export did not receive the matching analysis and source")
		}
		generation.Text = `{"selection_summary":"В анализе выбрано одно событие.","events":[{"group_id":"G1","title":"Исправление уязвимости","summary":"Источник сообщает об исправлении. Подробности ограничены.","consequences":"Доступно исправление.","post":"Источник сообщает об исправлении уязвимости.","reason":"Есть исправление."}]}`
		return generation, nil
	})
	server, err := New(cfg, search)
	if err != nil {
		t.Fatal(err)
	}
	return server, &calls
}

func TestPipelineProducesStructuredDraftAndHandlesEmptyCollection(t *testing.T) {
	t.Run("reject client query", func(t *testing.T) {
		server, calls := pipelineFixture(t, true, "")
		body := strings.TrimSuffix(pipelineTestRequest, "}") + `,"query":"override"}`
		response := call(server, "POST", "/v1/pipeline/web", body, serviceToken)
		if response.Code != http.StatusBadRequest || len(*calls) != 0 {
			t.Fatal("client query should be rejected before calling providers")
		}
	})
	for _, empty := range []bool{false, true} {
		t.Run(map[bool]string{false: "article", true: "empty"}[empty], func(t *testing.T) {
			server, calls := pipelineFixture(t, empty, "")
			response := call(server, "POST", "/v1/pipeline/web", pipelineTestRequest, serviceToken)
			var result struct {
				RunID    string          `json:"run_id"`
				Status   string          `json:"status"`
				News     NewsDataset     `json:"news"`
				Analysis analysis.Result `json:"analysis"`
				Draft    struct {
					Events []struct {
						GroupID string `json:"group_id"`
					} `json:"events"`
					SelectionSummary string `json:"selection_summary"`
				} `json:"draft"`
				Warnings []string `json:"warnings"`
			}
			if response.Code != http.StatusCreated || json.Unmarshal(response.Body.Bytes(), &result) != nil {
				t.Fatalf("pipeline failed: %d %s", response.Code, response.Body.String())
			}
			if result.Status != "completed" || result.RunID != response.Header().Get("X-Run-ID") || result.News.ControlTime != "2026-11-08T13:00:00+03:00" || result.Analysis.ControlTime != result.News.ControlTime || result.Warnings == nil {
				t.Fatal("pipeline lost status, period, run ID or warnings array")
			}
			if strings.Contains(response.Body.String(), `"files"`) {
				t.Fatal("Go still returns rendered files")
			}
			if !empty && (len(result.Draft.Events) != 1 || result.Draft.Events[0].GroupID != "G1") {
				t.Fatal("selected event prose missing")
			}
			wantCalls := []string{"collection", "analysis", "export"}
			wantArticles := 1
			if empty {
				wantCalls, wantArticles = []string{"collection"}, 0
			}
			if !reflect.DeepEqual(*calls, wantCalls) || len(result.News.Items) != wantArticles {
				t.Fatalf("wrong stage calls %v or article count %d", *calls, len(result.News.Items))
			}
			if !empty && !reflect.DeepEqual(result.Analysis.Groups["G1"].IDs, []string{result.News.Items[0].ID}) {
				t.Fatal("structured analysis lost source ID")
			}
			saved := call(server, "GET", response.Header().Get("Location"), "", serviceToken)
			var run Run
			if saved.Code != http.StatusOK || json.Unmarshal(saved.Body.Bytes(), &run) != nil || run.Mode != "pipeline" || run.Status != "completed" || run.Research == nil || run.Collection == nil || run.Analysis == nil || run.Export == nil || run.Analysis.Result == nil || run.Export.Result == nil || run.Export.Result.SelectionSummary != result.Draft.SelectionSummary {
				t.Fatal("complete pipeline audit not preserved")
			}
		})
	}
}

func TestPipelineStopsAndPreservesPartialAuditOnStageFailure(t *testing.T) {
	stages := []string{"collection", "analysis", "export"}
	for index, stage := range stages {
		t.Run(stage, func(t *testing.T) {
			server, calls := pipelineFixture(t, false, stage)
			response := call(server, "POST", "/v1/pipeline/web", pipelineTestRequest, serviceToken)
			var failure struct {
				RunID string `json:"run_id"`
				Stage string `json:"stage"`
			}
			if response.Code != http.StatusBadGateway || json.Unmarshal(response.Body.Bytes(), &failure) != nil || failure.Stage != stage || !runIDPattern.MatchString(failure.RunID) {
				t.Fatalf("missing stage failure details: %d %s", response.Code, response.Body.String())
			}
			if !reflect.DeepEqual(*calls, stages[:index+1]) {
				t.Fatalf("pipeline retried or continued after failure: %v", *calls)
			}
			saved := call(server, "GET", "/v1/runs/"+failure.RunID, "", serviceToken)
			var run Run
			if saved.Code != http.StatusOK || json.Unmarshal(saved.Body.Bytes(), &run) != nil || run.Status != "failed" || run.UpstreamStatus != http.StatusServiceUnavailable || run.Research == nil {
				t.Fatal("failed run audit missing")
			}
			if (run.Collection != nil) != (index > 0) || (run.Analysis != nil && run.Analysis.Result != nil) != (index > 1) || (run.Export != nil && run.Export.Result != nil) {
				t.Fatal("partial completed results were lost or unfinished stage reported success")
			}
			if index >= 1 && run.Analysis == nil || index == 2 && run.Export == nil {
				t.Fatal("failed stage audit missing")
			}
			if index == 0 && run.Research.Generation.ResponseID != "collection-response" || index == 1 && run.Analysis.Generation.ResponseID != "analysis-response" || index == 2 && run.Export.Generation.ResponseID != "export-response" {
				t.Fatal("failed provider response was not saved")
			}
		})
	}
}

func TestPipelineSharesSlotsAndStopsOnCancellation(t *testing.T) {
	server, calls := pipelineFixture(t, false, "")
	entered := make(chan struct{})
	server.cfg.AnalysisProvider = providerFunc(func(ctx context.Context, _, _ string) (collector.Generation, error) {
		*calls = append(*calls, "analysis")
		close(entered)
		<-ctx.Done()
		return collector.Generation{}, ctx.Err()
	})
	ctx, cancel := context.WithCancel(t.Context())
	defer cancel()
	request := httptest.NewRequest("POST", "/v1/pipeline/web", strings.NewReader(pipelineTestRequest)).WithContext(ctx)
	request.Header.Set("Authorization", "Bearer "+serviceToken)
	response := httptest.NewRecorder()
	done := make(chan struct{})
	go func() {
		defer close(done)
		server.ServeHTTP(response, request)
	}()
	select {
	case <-entered:
	case <-time.After(5 * time.Second):
		t.Fatal("pipeline never reached analysis")
	}
	for _, path := range []string{"/v1/pipeline/web", "/v1/collect"} {
		body := pipelineTestRequest
		if path == "/v1/collect" {
			body = `{"control_time":"2026-11-08T10:00:00Z","query":"news"}`
		}
		if busy := call(server, "POST", path, body, serviceToken); busy.Code != http.StatusTooManyRequests {
			t.Errorf("%s did not share the concurrency slot: %d", path, busy.Code)
		}
	}
	cancel()
	<-done
	if response.Code != http.StatusRequestTimeout || slices.Contains(*calls, "export") || len(server.slots) != 0 {
		t.Fatalf("cancellation did not stop and release pipeline: status=%d calls=%v", response.Code, *calls)
	}
}

func TestPipelineNewsFiltersDatesWithoutSearchAndKeepsEveryJournalRow(t *testing.T) {
	server, calls := pipelineFixture(t, false, "")
	server.cfg.SearchProviderFactory = nil // A file does not need website clients.
	server.provider = providerFunc(func(context.Context, string, string) (collector.Generation, error) {
		t.Fatal("file pipeline must not search")
		return collector.Generation{}, nil
	})
	server.fetcher = researchFetchFunc(func(context.Context, string) (news.Article, error) {
		t.Fatal("file pipeline must not fetch any URL")
		return news.Article{}, nil
	})
	base := analysis.Item{ID: "input-upper", Title: "Тестовая публикация", Text: new(pipelineTestText), URL: pipelineTestURL, Source: "Test source", PublishedAt: new("2026-11-08T10:00:00Z"), Missing: []string{}}
	lower, future, unknown, missingText, readError := base, base, base, base, base
	lower.ID, lower.PublishedAt = "input-lower", new("2026-11-07T10:00:00Z")
	future.ID, future.PublishedAt = "input-future", new("2026-11-08T10:00:00.000000001Z")
	unknown.ID, unknown.PublishedAt = "input-unknown", nil
	missingText.ID, missingText.Text = "input-no-text", nil
	readError.ID, readError.Error = "input-error", new("source unavailable")
	input := analysis.Dataset{Dataset: "train", ControlTime: "2026-11-08T10:00:00Z", Items: []analysis.Item{base, lower, future, unknown, missingText, readError}}
	body, _ := json.Marshal(input)
	response := call(server, "POST", "/v1/pipeline/news", string(body), serviceToken)
	var result PipelineResult
	if response.Code != http.StatusCreated || json.Unmarshal(response.Body.Bytes(), &result) != nil {
		t.Fatalf("news pipeline failed: %d %s", response.Code, response.Body.String())
	}
	if !reflect.DeepEqual(*calls, []string{"analysis", "export"}) || len(result.News.Items) != len(input.Items) || result.News.Dataset != "train" {
		t.Fatalf("file data or stages lost: calls=%v items=%d", *calls, len(result.News.Items))
	}
	if result.News.ControlTime != "2026-11-08T13:00:00+03:00" || result.News.PeriodStart != "2026-11-07T13:00:00+03:00" || result.News.Timezone != "Europe/Moscow" {
		t.Fatal("file period was not normalized to Moscow")
	}
	if len(result.Analysis.Excluded) != 5 || !reflect.DeepEqual(result.Analysis.Groups["G1"].IDs, []string{base.ID}) {
		t.Fatal("out-of-window or unreadable article reached analysis")
	}
	if len(result.Draft.Events) != 1 || result.Draft.Events[0].GroupID != "G1" {
		t.Fatal("draft event missing")
	}
	var run Run
	saved := call(server, "GET", response.Header().Get("Location"), "", serviceToken)
	if json.Unmarshal(saved.Body.Bytes(), &run) != nil || run.Pipeline.Source != "news" || run.Research != nil || run.Collection != nil || run.Settings["pipeline_timeout"] != "30s" || len(run.Pipeline.Instructions) != 2 {
		t.Fatal("file audit contains a search stage or incorrect timeout")
	}
	if old := call(server, "POST", "/v1/pipeline", pipelineTestRequest, serviceToken); old.Code != http.StatusNotFound {
		t.Fatal("old combined endpoint remains registered")
	}
}

func TestPipelineWebKeepsConcurrentSiteScopesIsolated(t *testing.T) {
	server, _ := pipelineFixture(t, true, "")
	server.cfg.MaxConcurrent = 2
	server.slots = make(chan struct{}, 2)
	ready := make(chan string, 2)
	release := make(chan struct{})
	server.cfg.SearchProviderFactory = func(domains []string) (collector.Provider, error) {
		domain := domains[0]
		return providerFunc(func(ctx context.Context, _, input string) (collector.Generation, error) {
			var plan struct {
				Domains []string `json:"allowed_domains"`
			}
			_, payload, _ := strings.Cut(input, "\n")
			if json.Unmarshal([]byte(payload), &plan) != nil || !reflect.DeepEqual(plan.Domains, []string{domain}) {
				t.Errorf("request for %s received different domain plan: %s", domain, input)
			}
			ready <- domain
			select {
			case <-release:
			case <-ctx.Done():
				return collector.Generation{}, ctx.Err()
			}
			return collector.Generation{Text: `{"candidates":[]}`, Trace: json.RawMessage(`{"output":[{"type":"web_search_call"}]}`)}, nil
		}), nil
	}
	server.cfg.SourceFetcherFactory = func([]string) SourceFetcher { return rejectSourceFetcher{} }
	responses := make([]*httptest.ResponseRecorder, 2)
	domains := []string{"securitylab.ru", "xakep.ru"}
	var workers sync.WaitGroup
	for index, domain := range domains {
		workers.Go(func() {
			body := `{"control_time":"2026-11-08T10:00:00Z","sites":["` + domain + `"]}`
			responses[index] = call(server, "POST", "/v1/pipeline/web", body, serviceToken)
		})
	}
	for range 2 {
		select {
		case <-ready:
		case <-time.After(5 * time.Second):
			close(release)
			workers.Wait()
			t.Fatal("concurrent requests did not both reach isolated providers")
		}
	}
	close(release)
	workers.Wait()
	for index, response := range responses {
		if response.Code != http.StatusCreated {
			t.Fatalf("concurrent request failed: %d %s", response.Code, response.Body.String())
		}
		var run Run
		saved := call(server, "GET", response.Header().Get("Location"), "", serviceToken)
		if json.Unmarshal(saved.Body.Bytes(), &run) != nil || !reflect.DeepEqual(run.Research.AllowedDomains, []string{domains[index]}) || run.Pipeline.Source != "web" {
			t.Fatal("concurrent site scope leaked into another audit")
		}
	}
	if !reflect.DeepEqual(server.cfg.SearchDomains, []string{"securitylab.ru"}) || server.discoverySources != nil {
		t.Fatal("request site scopes mutated shared server configuration")
	}
}
