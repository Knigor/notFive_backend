package httpapi

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"reflect"
	"strings"
	"sync/atomic"
	"testing"
	"time"

	"github.com/Knigor/notFive_backend/services/digest/internal/collector"
	"github.com/Knigor/notFive_backend/services/digest/internal/news"
)

func TestCollectionReturnsArticleTextsWithinWindowWithoutSynthesis(t *testing.T) {
	control := time.Date(2026, 11, 8, 13, 48, 16, 0, time.FixedZone("MSK", 3*60*60))
	articleURL := func(name string) string { return "https://www.securitylab.ru/news/" + name + ".php" }
	dates := map[string]time.Time{
		"lower": control.Add(-24 * time.Hour), "inside": control.Add(-24*time.Hour + time.Nanosecond),
		"upper": control, "future": control.Add(time.Nanosecond), "unknown": {}, "unavailable": {},
	}
	candidates := []searchCandidate{}
	for _, name := range []string{"lower", "inside", "upper", "future", "unknown", "unavailable"} {
		candidates = append(candidates, searchCandidate{URL: articleURL(name)})
	}
	raw, err := json.Marshal(map[string]any{"candidates": candidates})
	if err != nil {
		t.Fatal(err)
	}
	var searches atomic.Int32
	provider := providerFunc(func(_ context.Context, _, _ string) (collector.Generation, error) {
		searches.Add(1)
		return collector.Generation{Text: "```json\n" + string(raw) + "\n```", Trace: json.RawMessage(`{"output":[{"type":"web_search_call"}]}`)}, nil
	})
	_, cfg := newServer(t, provider)
	cfg.SearchDomains = []string{"securitylab.ru"}
	cfg.SourceFetcher = researchFetchFunc(func(_ context.Context, address string) (news.Article, error) {
		name := strings.TrimSuffix(strings.TrimPrefix(address, "https://www.securitylab.ru/news/"), ".php")
		if name == "unavailable" {
			return news.Article{}, news.ErrUnavailable
		}
		return news.Article{URL: address, Title: "Publication " + name, Text: "Source article text " + name, PublishedAt: dates[name], DateEvidence: "HTML datePublished", TextTruncated: name == "inside"}, nil
	})
	server, err := New(cfg, provider)
	if err != nil {
		t.Fatal(err)
	}
	response := call(server, "POST", "/v1/collect", `{"control_time":"2026-11-08T10:48:16Z","query":"кибербезопасность"}`, serviceToken)
	if response.Code != 201 {
		t.Fatalf("status %d: %s", response.Code, response.Body.String())
	}
	dataset := decodeNewsDataset(t, response)
	run := savedRunFromCollection(t, server, response)
	if run.Mode != "collection" || run.Status != "completed" || run.Collection == nil || searches.Load() != 2 {
		t.Fatalf("collection performed synthesis or lost its result: %+v; searches=%d", run, searches.Load())
	}
	collection := run.Collection
	if dataset.Dataset != "web" || dataset.Timezone != "Europe/Moscow" || dataset.ControlTime != "2026-11-08T13:48:16+03:00" || dataset.PeriodStart != "2026-11-07T13:48:16+03:00" || len(dataset.Items) != 6 {
		t.Fatalf("incorrect news.json metadata or selection: %+v", dataset)
	}
	if collection.ControlTime != "2026-11-08T13:48:16+03:00" || collection.WindowStart != "2026-11-07T13:48:16+03:00" || collection.Counts.Candidates != 6 || collection.Counts.Accepted != 2 || collection.Counts.Excluded != 4 {
		t.Fatalf("incorrect window or counts: %+v", collection)
	}
	if len(collection.Articles) != 2 || len(collection.ExcludedArticles) != 4 {
		t.Fatalf("wrong accepted/excluded sets: %+v", collection)
	}
	for i, name := range []string{"inside", "upper"} {
		article := collection.Articles[i]
		if article.URL != articleURL(name) || article.Text != "Source article text "+name || article.Title != "Publication "+name || article.Source != "www.securitylab.ru" || article.PublishedAt != dates[name].Format(time.RFC3339Nano) || article.ReadError != "" || article.ID == "" {
			t.Errorf("lost verified article data: %+v", article)
		}
		want := NewsItem{ID: article.ID, Title: article.Title, Text: new(article.Text), Source: article.Source, URL: article.URL, PublishedAt: new(article.PublishedAt), Missing: []string{}, Error: nil}
		if !reflect.DeepEqual(dataset.Items[i], want) {
			t.Errorf("news.json changed accepted material: got %+v, want %+v", dataset.Items[i], want)
		}
		if article.DateEvidence != "HTML datePublished" || article.TextTruncated != (name == "inside") {
			t.Errorf("audit lost date evidence or truncation marker: %+v", article)
		}
	}
	reasons := map[string]string{}
	for _, excluded := range collection.ExcludedArticles {
		reasons[excluded.RequestedURL] = excluded.Reason
		if excluded.Status != "excluded" || excluded.Reason == "" {
			t.Errorf("exclusion has no reason: %+v", excluded)
		}
	}
	for name, fragment := range map[string]string{"lower": "нижней границы", "future": "позднее контрольного времени", "unknown": "Дата публикации", "unavailable": "Не удалось получить"} {
		if !strings.Contains(reasons[articleURL(name)], fragment) {
			t.Errorf("missing %s exclusion reason: %q", name, reasons[articleURL(name)])
		}
	}
	if run.Research == nil || run.Research.Status != "completed" || !run.Research.SearchObserved || len(run.Research.SearchAttempts) != 2 || len(run.Research.SearchAttempts[0].Candidates) != 6 {
		t.Fatalf("search audit or Markdown JSON candidates lost: %+v", run.Research)
	}
	restarted, err := New(cfg, provider)
	if err != nil {
		t.Fatal(err)
	}
	saved := call(restarted, "GET", "/v1/runs/"+run.ID, "", serviceToken)
	var loaded Run
	if saved.Code != 200 || json.Unmarshal(saved.Body.Bytes(), &loaded) != nil || !reflect.DeepEqual(loaded.Collection, run.Collection) {
		t.Fatalf("collection was not persisted: %d %s", saved.Code, saved.Body.String())
	}
	for _, artifact := range []string{"research.md", "digest.md", "post.md", "email.html", "journal.csv"} {
		if got := call(restarted, "GET", "/v1/runs/"+run.ID+"/artifacts/"+artifact, "", serviceToken); got.Code != 404 {
			t.Errorf("collection exposed digest artifact %s: status %d", artifact, got.Code)
		}
	}
}

func TestCollectionWithoutEligibleArticlesCompletesWithoutSynthesis(t *testing.T) {
	for _, test := range []struct {
		name       string
		candidates string
		count      int
	}{
		{"empty", `[]`, 0},
		{"all_excluded", `[{"url":"https://www.securitylab.ru/news/600000.php"}]`, 1},
	} {
		t.Run(test.name, func(t *testing.T) {
			var searches atomic.Int32
			provider := providerFunc(func(_ context.Context, _, _ string) (collector.Generation, error) {
				searches.Add(1)
				return collector.Generation{Text: `{"candidates":` + test.candidates + `}`}, nil
			})
			server, _ := newServer(t, provider)
			response := call(server, "POST", "/v1/collect", `{"control_time":"2026-11-08T13:48:16+03:00","query":"ИБ"}`, serviceToken)
			dataset := decodeNewsDataset(t, response)
			run := savedRunFromCollection(t, server, response)
			if response.Code != 201 || run.Status != "completed" || run.Collection == nil || dataset.Items == nil || len(dataset.Items) != test.count {
				t.Fatalf("empty collection did not complete: %d %s", response.Code, response.Body.String())
			}
			if searches.Load() != int32(1+test.count) || run.Collection.Articles == nil || len(run.Collection.Articles) != 0 || run.Collection.Counts.Accepted != 0 || run.Collection.Counts.Candidates != test.count || run.Collection.Counts.Excluded != test.count || len(run.Collection.ExcludedArticles) != test.count {
				t.Fatalf("bad empty collection counts or synthesis: %+v", run.Collection)
			}
		})
	}
}

func TestCollectionRequiresAuthenticationAndValidInput(t *testing.T) {
	var providerCalls atomic.Int32
	server, _ := newServer(t, providerFunc(func(context.Context, string, string) (collector.Generation, error) {
		providerCalls.Add(1)
		return collector.Generation{}, errors.New("invalid request reached provider")
	}))
	for _, token := range []string{"", "wrong"} {
		if response := call(server, "POST", "/v1/collect", `{}`, token); response.Code != 401 {
			t.Errorf("token %q: expected 401, got %d", token, response.Code)
		}
	}
	for _, body := range []string{
		`{}`,
		`{"control_time":"2026-11-08T13:48:16","query":"ИБ"}`,
		`{"control_time":"2026-11-08T13:48:16+03:00","query":" "}`,
		`{"control_time":"2026-11-08T13:48:16+03:00","query":"` + strings.Repeat("я", 2001) + `"}`,
	} {
		if response := call(server, "POST", "/v1/collect", body, serviceToken); response.Code != 422 {
			t.Errorf("expected 422, got %d: %s", response.Code, response.Body.String())
		}
	}
	if providerCalls.Load() != 0 {
		t.Fatal("invalid or unauthenticated input triggered provider")
	}
}

func TestCollectionDoesNotApplySynthesisInputBudget(t *testing.T) {
	candidates := []searchCandidate{}
	for i := range 40 {
		candidates = append(candidates, searchCandidate{URL: fmt.Sprintf("https://www.securitylab.ru/news/%d.php", 600000+i)})
	}
	raw, err := json.Marshal(map[string]any{"candidates": candidates})
	if err != nil {
		t.Fatal(err)
	}
	var searches atomic.Int32
	provider := providerFunc(func(_ context.Context, _, _ string) (collector.Generation, error) {
		searches.Add(1)
		return collector.Generation{Text: string(raw)}, nil
	})
	_, cfg := newServer(t, provider)
	control := time.Date(2026, 11, 8, 13, 48, 16, 0, time.FixedZone("MSK", 3*60*60))
	text := strings.Repeat("я", 12000)
	cfg.SearchDomains = []string{"securitylab.ru"}
	var active atomic.Int32
	cfg.SourceFetcher = researchFetchFunc(func(_ context.Context, address string) (news.Article, error) {
		current := active.Add(1)
		defer active.Add(-1)
		if current > 4 {
			t.Errorf("article fetch concurrency exceeded four workers: %d", current)
		}
		time.Sleep(time.Millisecond)
		return news.Article{URL: address, Title: "Publication", Text: text, PublishedAt: control, DateEvidence: "HTML datePublished"}, nil
	})
	server, err := New(cfg, provider)
	if err != nil {
		t.Fatal(err)
	}
	response := call(server, "POST", "/v1/collect", `{"control_time":"2026-11-08T13:48:16+03:00","query":"ИБ"}`, serviceToken)
	dataset := decodeNewsDataset(t, response)
	run := savedRunFromCollection(t, server, response)
	if response.Code != 201 || run.Collection == nil || len(dataset.Items) != 40 {
		t.Fatalf("large collection failed: status %d", response.Code)
	}
	if searches.Load() != 1 || len(run.Collection.Articles) != 40 || run.Collection.Counts.Accepted != 40 || run.Collection.Counts.Excluded != 0 || len(run.Collection.ExcludedArticles) != 0 {
		t.Fatalf("collection wrongly applied model input budget: counts=%+v articles=%d", run.Collection.Counts, len(run.Collection.Articles))
	}
	for i, article := range run.Collection.Articles {
		if article.Text != text || dataset.Items[i].Text == nil || *dataset.Items[i].Text != text {
			t.Errorf("collection truncated source %s", article.URL)
		}
	}
}
