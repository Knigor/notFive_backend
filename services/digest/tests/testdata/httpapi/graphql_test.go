package httpapi

import (
	"context"
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"slices"
	"strings"
	"testing"

	"github.com/99designs/gqlgen/graphql/handler"
	"github.com/99designs/gqlgen/graphql/handler/transport"
	"github.com/Knigor/notFive_backend/services/digest/graph"
	"github.com/Knigor/notFive_backend/services/digest/internal/collector"
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
