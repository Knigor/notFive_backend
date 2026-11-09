package httpapi

import (
	"context"
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"slices"
	"strings"
	"sync/atomic"
	"testing"
	"time"

	"github.com/99designs/gqlgen/graphql/handler"
	"github.com/99designs/gqlgen/graphql/handler/transport"
	"github.com/Knigor/notFive_backend/services/digest/graph"
	"github.com/Knigor/notFive_backend/services/digest/graph/model"
	"github.com/Knigor/notFive_backend/services/digest/internal/analysis"
	"github.com/Knigor/notFive_backend/services/digest/internal/collector"
	"github.com/Knigor/notFive_backend/services/digest/internal/news"
)

func TestGraphQLCollectDatasetAndSites(t *testing.T) {
	searches := 0
	searchedDomains := [][]string{}
	server, _ := newServer(t, providerFunc(func(ctx context.Context, _, _ string) (collector.Generation, error) {
		searches++
		searchedDomains = append(searchedDomains, slices.Clone(collector.Domains(ctx, nil)))
		return collector.Generation{Text: `{"candidates":[]}`}, nil
	}))
	gql := handler.New(graph.NewExecutableSchema(graph.Config{Resolvers: &graph.Resolver{Collector: server}}))
	gql.AddTransport(transport.POST{})
	server.MountGraphQL(gql)
	query := `mutation($input: CollectInput!) { collect(input:$input) { runId mode sites articles { id text } researchJson } }`

	for _, test := range []struct {
		kind, variables, expectedMode string
	}{
		{"dataset", `{"kind":"DATASET","controlTime":"2026-11-08T12:00:00+03:00","items":[{"id":"A-1","text":"Source text","missing":[]}]}`, "DATASET"},
		{"sites", `{"kind":"SITES","controlTime":"2026-11-08T12:00:00+03:00","sites":["https://habr.com"]}`, "SITES"},
		{"default", `{"kind":"DEFAULT","controlTime":"2026-11-08T12:00:00+03:00"}`, "DEFAULT"},
	} {
		t.Run(test.kind, func(t *testing.T) {
			body, err := json.Marshal(map[string]any{"query": query, "variables": map[string]any{"input": json.RawMessage(test.variables)}})
			if err != nil {
				t.Fatal(err)
			}
			response := graphQLCall(server, string(body), serviceToken)
			var result struct {
				Data struct {
					Collect struct {
						RunID    string                      `json:"runId"`
						Mode     string                      `json:"mode"`
						Sites    []string                    `json:"sites"`
						Articles []struct{ ID, Text string } `json:"articles"`
					} `json:"collect"`
				} `json:"data"`
				Errors []any `json:"errors"`
			}
			if response.Code != 200 || json.Unmarshal(response.Body.Bytes(), &result) != nil || len(result.Errors) != 0 || result.Data.Collect.Mode != test.expectedMode || result.Data.Collect.RunID == "" {
				t.Fatalf("unexpected GraphQL response: %d %s", response.Code, response.Body.String())
			}
			if test.kind == "dataset" && (len(result.Data.Collect.Articles) != 1 || result.Data.Collect.Articles[0].Text != "Source text") {
				t.Fatalf("dataset lost normalized article: %s", response.Body.String())
			}
			if test.kind == "sites" && (len(result.Data.Collect.Sites) != 1 || result.Data.Collect.Sites[0] != "https://habr.com") {
				t.Fatalf("site selection changed: %s", response.Body.String())
			}
			if test.kind == "default" && len(result.Data.Collect.Sites) != 5 {
				t.Fatalf("default domains missing: %s", response.Body.String())
			}
		})
	}
	if searches != 2 {
		t.Fatalf("expected only site and default searches, got %d", searches)
	}
	if !slices.Equal(searchedDomains[0], []string{"habr.com"}) || len(searchedDomains[1]) != 5 {
		t.Fatalf("search used wrong domains: %v", searchedDomains)
	}
	unauthorized := graphQLCall(server, `{"query":"{ ping }"}`, "")
	if unauthorized.Code != 401 {
		t.Fatalf("GraphQL bypassed authentication: %d", unauthorized.Code)
	}
	invalid := graphQLCall(server, `{"query":"mutation { collect(input:{kind:SITES,controlTime:\"2026-11-08T12:00:00+03:00\",sites:[\"http://localhost\"]}) { runId } }"}`, serviceToken)
	if !strings.Contains(invalid.Body.String(), "sites must be HTTPS root URLs") {
		t.Fatalf("invalid site accepted: %s", invalid.Body.String())
	}
}

func TestGraphQLPrepareDigestReturnsStructuredFields(t *testing.T) {
	server, calls := pipelineFixture(t, false, "")
	gql := handler.New(graph.NewExecutableSchema(graph.Config{Resolvers: &graph.Resolver{Collector: server}}))
	gql.AddTransport(transport.POST{})
	server.MountGraphQL(gql)
	query := `mutation($input: PrepareDigestInput!) { prepareDigest(input:$input) { runId groups { id ids facts { evidence { articleId quote } } } recommendedOrder excluded { articleId reason } events { groupId title post } analysisPromptVersion draftPromptVersion } }`
	input := map[string]any{"controlTime": "2026-11-08T10:00:00Z", "items": []map[string]any{{"id": "A-1", "title": "Test", "text": pipelineTestText, "source": "Test source", "url": pipelineTestURL, "publishedAt": "2026-11-08T10:00:00Z", "missing": []string{}}}}
	body, err := json.Marshal(map[string]any{"query": query, "variables": map[string]any{"input": input}})
	if err != nil {
		t.Fatal(err)
	}
	response := graphQLCall(server, string(body), serviceToken)
	var result struct {
		Data struct {
			PrepareDigest struct {
				RunID  string `json:"runId"`
				Groups []struct {
					ID  string   `json:"id"`
					IDs []string `json:"ids"`
				} `json:"groups"`
				RecommendedOrder []string `json:"recommendedOrder"`
				Events           []struct {
					GroupID string `json:"groupId"`
					Post    string `json:"post"`
				} `json:"events"`
			} `json:"prepareDigest"`
		} `json:"data"`
		Errors []any `json:"errors"`
	}
	if response.Code != http.StatusOK || json.Unmarshal(response.Body.Bytes(), &result) != nil || len(result.Errors) != 0 {
		t.Fatalf("prepareDigest failed: %d %s", response.Code, response.Body.String())
	}
	if !slices.Equal(*calls, []string{"analysis", "export"}) || !slices.Equal(result.Data.PrepareDigest.RecommendedOrder, []string{"G1"}) || len(result.Data.PrepareDigest.Groups) != 1 || !slices.Equal(result.Data.PrepareDigest.Groups[0].IDs, []string{"A-1"}) || len(result.Data.PrepareDigest.Events) != 1 || result.Data.PrepareDigest.Events[0].Post == "" {
		t.Fatalf("structured draft lost source links or prose: %s", response.Body.String())
	}
	if result.Data.PrepareDigest.RunID == "" {
		t.Fatal("prepareDigest omitted its audit ID")
	}
	if _, err := os.Stat(filepath.Join(server.cfg.DataDir, "runs", result.Data.PrepareDigest.RunID+".json")); err != nil {
		t.Fatalf("audit ID does not resolve to saved run: %v", err)
	}
	if strings.Contains(response.Body.String(), `"files"`) {
		t.Fatal("GraphQL returned ready files")
	}
}

func TestGraphQLPrepareDigestPreservesBudgetExclusionsAndAudit(t *testing.T) {
	for _, allOverflow := range []bool{false, true} {
		t.Run(map[bool]string{false: "later article fits", true: "all articles overflow"}[allOverflow], func(t *testing.T) {
			server, calls := pipelineFixture(t, false, "")
			gql := handler.New(graph.NewExecutableSchema(graph.Config{Resolvers: &graph.Resolver{Collector: server}}))
			gql.AddTransport(transport.POST{})
			server.MountGraphQL(gql)
			items := []*model.MaterialInput{{
				ID: "oversized", Title: new("Большая публикация"), Text: new(strings.Repeat("я", 230001)),
				Source: new("Test source"), URL: new("https://securitylab.ru/news/large.php"),
				PublishedAt: new("2026-11-08T10:00:00Z"), Missing: []string{},
			}}
			if !allOverflow {
				items = append(items, &model.MaterialInput{
					ID: "A-1", Title: new("Test"), Text: new(pipelineTestText), Source: new("Test source"),
					URL: new(pipelineTestURL), PublishedAt: new("2026-11-08T10:00:00Z"), Missing: []string{},
				})
			}
			body, err := json.Marshal(map[string]any{
				"query":     `mutation($input: PrepareDigestInput!) { prepareDigest(input:$input) { runId groups { id ids } recommendedOrder excluded { articleId reason } events { groupId } warnings } }`,
				"variables": map[string]any{"input": model.PrepareDigestInput{ControlTime: "2026-11-08T10:00:00Z", Items: items}},
			})
			if err != nil {
				t.Fatal(err)
			}
			response := graphQLCall(server, string(body), serviceToken)
			var result struct {
				Data   struct{ PrepareDigest model.PreparedDigest } `json:"data"`
				Errors []any                                        `json:"errors"`
			}
			if response.Code != http.StatusOK || json.Unmarshal(response.Body.Bytes(), &result) != nil || len(result.Errors) != 0 {
				t.Fatalf("budget overflow failed the whole GraphQL request: %d %s", response.Code, response.Body.String())
			}
			prepared := result.Data.PrepareDigest
			if len(prepared.Excluded) != 1 || prepared.Excluded[0].ArticleID != "oversized" || !strings.Contains(prepared.Excluded[0].Reason, "230000") || !strings.Contains(prepared.Excluded[0].Reason, "бюджет") {
				t.Fatalf("GraphQL lost the budget exclusion: %+v", prepared.Excluded)
			}
			if !strings.Contains(strings.Join(prepared.Warnings, "\n"), "oversized") {
				t.Fatalf("GraphQL lost the budget warning: %v", prepared.Warnings)
			}
			if allOverflow {
				if len(*calls) != 0 || len(prepared.Events) != 0 || len(prepared.Groups) != 0 || len(prepared.RecommendedOrder) != 0 {
					t.Fatalf("all-overflow request called a model or invented an event: calls=%v, events=%v", *calls, prepared.Events)
				}
			} else if !slices.Equal(*calls, []string{"analysis", "export"}) || len(prepared.Events) != 1 || len(prepared.Groups) != 1 || !slices.Equal(prepared.Groups[0].Ids, []string{"A-1"}) {
				t.Fatalf("later fitting article failed to reach the digest: calls=%v, groups=%v", *calls, prepared.Groups)
			}
			raw, err := os.ReadFile(filepath.Join(server.cfg.DataDir, "runs", prepared.RunID+".json"))
			if err != nil {
				t.Fatal(err)
			}
			var audit Run
			if json.Unmarshal(raw, &audit) != nil || audit.Status != "completed" || audit.Analysis == nil || audit.Analysis.Result == nil || audit.Export == nil || audit.Export.ValidatedAnalysis == nil {
				t.Fatal("completed budget-limited request lacks analysis/export audit")
			}
			if !strings.Contains(audit.Analysis.Result.Excluded["oversized"], "230000") || audit.Export.ValidatedAnalysis.Excluded["oversized"] != prepared.Excluded[0].Reason {
				t.Fatal("analysis, export and GraphQL audit disagree about the excluded material")
			}
		})
	}
}

func TestGraphQLCollectPreservesDifferentSourcesOfOneEvent(t *testing.T) {
	addresses := []string{pipelineTestURL, "https://3dnews.ru/news/one.html"}
	ids := []string{candidateID(addresses[0]), candidateID(addresses[1])}
	search := providerFunc(func(context.Context, string, string) (collector.Generation, error) {
		// The same URL twice is one material, but another publisher remains a
		// separate source even with identical text and headline.
		raw, _ := json.Marshal(map[string]any{"candidates": []searchCandidate{{URL: addresses[0]}, {URL: addresses[0]}, {URL: addresses[1]}}})
		return collector.Generation{Text: string(raw)}, nil
	})
	_, cfg := newServer(t, search)
	cfg.SearchDomains = []string{"securitylab.ru", "3dnews.ru"}
	var fetched atomic.Int32
	cfg.SourceFetcher = researchFetchFunc(func(_ context.Context, address string) (news.Article, error) {
		fetched.Add(1)
		return news.Article{URL: address, Title: "Исправление уязвимости", Text: pipelineTestText, PublishedAt: time.Date(2026, 11, 8, 9, 0, 0, 0, time.UTC)}, nil
	})
	cfg.AnalysisProvider = providerFunc(func(_ context.Context, _, input string) (collector.Generation, error) {
		var body struct {
			Items []analysis.Item `json:"untrusted_items"`
		}
		if json.Unmarshal([]byte(input), &body) != nil || len(body.Items) != 2 {
			t.Fatal("analysis lost a distinct publication before model selection")
		}
		for i, item := range body.Items {
			if item.ID != ids[i] || item.URL != addresses[i] || item.Text == nil || *item.Text != pipelineTestText {
				t.Fatalf("analysis changed source %d: %+v", i, item)
			}
		}
		raw, _ := json.Marshal(map[string]any{
			"groups": []map[string]any{{"id": "G1", "name": "Исправление уязвимости", "ids": ids,
				"facts":          []map[string]any{{"statement": "Исправлена уязвимость", "source_ids": ids, "evidence": []map[string]string{{"article_id": ids[0], "quote": pipelineTestText}, {"article_id": ids[1], "quote": pipelineTestText}}}},
				"contradictions": []any{}, "selection_reason": "Два источника об одном исправлении"}},
			"recommended_order": []string{"G1"}, "excluded": []any{},
		})
		return collector.Generation{Text: string(raw)}, nil
	})
	cfg.ExportProvider = providerFunc(func(context.Context, string, string) (collector.Generation, error) {
		return collector.Generation{Text: `{"selection_summary":"Одно событие из двух публикаций.","events":[{"group_id":"G1","title":"Исправление уязвимости","summary":"Два источника сообщили об исправлении.","consequences":"Доступно исправление.","post":"Исправлена уязвимость.","reason":"Есть исправление."}]}`}, nil
	})
	server, err := New(cfg, search)
	if err != nil {
		t.Fatal(err)
	}
	gql := handler.New(graph.NewExecutableSchema(graph.Config{Resolvers: &graph.Resolver{Collector: server}}))
	gql.AddTransport(transport.POST{})
	server.MountGraphQL(gql)
	response := graphQLCall(server, `{"query":"mutation { collect(input:{kind:SITES,controlTime:\"2026-11-08T10:00:00Z\",sites:[\"https://securitylab.ru\",\"https://3dnews.ru\"]}) { runId articles { id title text source url publishedAt missing } excluded { id } researchJson } }"}`, serviceToken)
	var collected struct {
		Data   struct{ Collect model.CollectResult } `json:"data"`
		Errors []any                                 `json:"errors"`
	}
	if response.Code != http.StatusOK || json.Unmarshal(response.Body.Bytes(), &collected) != nil || len(collected.Errors) != 0 || len(collected.Data.Collect.Articles) != 2 || len(collected.Data.Collect.Excluded) != 0 || fetched.Load() != 2 {
		t.Fatalf("collection discarded a source or duplicated a URL: %d %s, fetches=%d", response.Code, response.Body.String(), fetched.Load())
	}
	var research Research
	if collected.Data.Collect.ResearchJSON == nil || json.Unmarshal([]byte(*collected.Data.Collect.ResearchJSON), &research) != nil || len(research.CandidateAudit) != 2 || len(research.Sources) != 2 {
		t.Fatal("collection audit does not contain one decision per unique material")
	}
	items := make([]*model.MaterialInput, 0, 2)
	for _, article := range collected.Data.Collect.Articles {
		items = append(items, &model.MaterialInput{ID: article.ID, Title: article.Title, Text: article.Text, Source: article.Source, URL: article.URL, PublishedAt: article.PublishedAt, Missing: article.Missing})
	}
	body, _ := json.Marshal(map[string]any{
		"query":     `mutation($input: PrepareDigestInput!) { prepareDigest(input:$input) { groups { id ids facts { sourceIds evidence { articleId quote } } } recommendedOrder excluded { articleId } events { groupId } } }`,
		"variables": map[string]any{"input": model.PrepareDigestInput{ControlTime: "2026-11-08T10:00:00Z", Items: items}},
	})
	response = graphQLCall(server, string(body), serviceToken)
	var prepared struct {
		Data   struct{ PrepareDigest model.PreparedDigest } `json:"data"`
		Errors []any                                        `json:"errors"`
	}
	if response.Code != http.StatusOK || json.Unmarshal(response.Body.Bytes(), &prepared) != nil || len(prepared.Errors) != 0 {
		t.Fatalf("prepareDigest failed: %d %s", response.Code, response.Body.String())
	}
	result := prepared.Data.PrepareDigest
	if len(result.Groups) != 1 || !slices.Equal(result.Groups[0].Ids, ids) || !slices.Equal(result.RecommendedOrder, []string{"G1"}) || len(result.Excluded) != 0 || len(result.Events) != 1 || result.Events[0].GroupID != "G1" {
		t.Fatalf("one model event lost its two source IDs: %s", response.Body.String())
	}
}

func graphQLCall(server *Server, body, token string) *httptest.ResponseRecorder {
	request := httptest.NewRequest(http.MethodPost, "/graphql", strings.NewReader(body))
	request.Header.Set("Content-Type", "application/json")
	if token != "" {
		request.Header.Set("Authorization", "Bearer "+token)
	}
	response := httptest.NewRecorder()
	server.ServeHTTP(response, request)
	return response
}
