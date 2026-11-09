package export

import (
	"context"
	"encoding/json"
	"slices"
	"strings"
	"testing"

	"github.com/Knigor/notFive_backend/services/digest/internal/analysis"
	"github.com/Knigor/notFive_backend/services/digest/internal/collector"
)

type providerFunc func(context.Context, string, string) (collector.Generation, error)

func (f providerFunc) Generate(ctx context.Context, instructions, input string) (collector.Generation, error) {
	return f(ctx, instructions, input)
}

func evidenceRequest() Request {
	source := "Веб-сервис получил 6 отчетов. Исправление не выпущено."
	fact := analysis.Fact{Statement: "Веб-сервис получил 6 отчетов", SourceIDs: []string{"A"}, Evidence: []analysis.Evidence{{ArticleID: "A", Quote: "Веб‑сервис получил 6 отчётов."}}}
	return Request{
		News:     analysis.Dataset{Dataset: "test", ControlTime: "2026-11-09T09:00:00+03:00", Items: []analysis.Item{{ID: "A", Title: "Источник", Text: new(source), URL: "https://example.org/article", PublishedAt: new("2026-11-09T08:00:00+03:00")}}},
		Analysis: analysis.Result{Dataset: "test", ControlTime: "2026-11-09T09:00:00+03:00", PeriodStart: "2026-11-08T09:00:00+03:00", Timezone: "Europe/Moscow", Groups: map[string]analysis.Group{"G1": {Name: "Событие", IDs: []string{"A"}, Facts: []analysis.Fact{fact}, Contradictions: []analysis.Contradiction{}, SelectionReason: "Проверка"}}, RecommendedOrder: []string{"G1"}, Excluded: map[string]string{}, Warnings: []string{}},
	}
}

func TestPrepareRetainsVerifiedFactsAndRestoresOriginalQuote(t *testing.T) {
	request := evidenceRequest()
	group := request.Analysis.Groups["G1"]
	bad := group.Facts[0]
	bad.Statement = "Веб-сервис получил 7 отчетов"
	bad.Evidence = []analysis.Evidence{{ArticleID: "A", Quote: "Веб-сервис получил 7 отчетов."}}
	group.Facts = append(group.Facts, bad)
	request.Analysis.Groups["G1"] = group
	before, _ := json.Marshal(request.Analysis)
	prepared, err := Prepare(request)
	if err != nil {
		t.Fatal(err)
	}
	kept := prepared.Request.Analysis.Groups["G1"]
	if len(kept.Facts) != 1 || kept.Facts[0].Evidence[0].Quote != "Веб-сервис получил 6 отчетов." || !slices.Equal(prepared.Request.Analysis.RecommendedOrder, []string{"G1"}) {
		t.Fatalf("verified fact or exact source quote lost: %+v", kept)
	}
	after, _ := json.Marshal(request.Analysis)
	if string(before) != string(after) {
		t.Fatal("source analysis audit was mutated")
	}
	if len(prepared.EvidenceChecks) != 2 || prepared.EvidenceChecks[0].ModelQuote != "Веб‑сервис получил 6 отчётов." || prepared.EvidenceChecks[0].Match != "typographic" || prepared.EvidenceChecks[1].Reason != "quote_not_found" {
		t.Fatalf("missing evidence trace: %+v", prepared.EvidenceChecks)
	}
	result, _, err := Export(t.Context(), providerFunc(func(_ context.Context, _, input string) (collector.Generation, error) {
		if strings.Contains(input, "7 отчетов") || !strings.Contains(input, "6 отчетов") {
			t.Fatal("unverified fact reached prose model")
		}
		return collector.Generation{Text: `{"selection_summary":"Одно событие", "events":[{"group_id":"G1","title":"Событие","summary":"Получено 6 отчетов.","consequences":"Исправление не выпущено.","post":"Получено 6 отчетов. Исправление не выпущено.","reason":"Есть сведения из источника."}]}`}, nil
	}), prepared)
	if err != nil || len(result.Events) != 1 || len(result.Warnings) == 0 {
		t.Fatalf("partial result failed: %+v %v", result, err)
	}
}

func TestPrepareExcludesUnsupportedGroupWithoutCallingProseModel(t *testing.T) {
	request := evidenceRequest()
	group := request.Analysis.Groups["G1"]
	group.Facts[0].Evidence[0].Quote = "Исправление выпущено."
	request.Analysis.Groups["G1"] = group
	prepared, err := Prepare(request)
	if err != nil {
		t.Fatal(err)
	}
	if len(prepared.Request.Analysis.RecommendedOrder) != 0 || len(prepared.Request.Analysis.Groups) != 0 || prepared.Request.Analysis.Excluded["A"] == "" {
		t.Fatalf("unsupported group still selected: %+v", prepared.Request.Analysis)
	}
	result, _, err := Export(t.Context(), providerFunc(func(context.Context, string, string) (collector.Generation, error) {
		t.Fatal("prose model called for a fully unsupported group")
		return collector.Generation{}, nil
	}), prepared)
	if err != nil || len(result.Events) != 0 || result.SelectionSummary == "" || len(result.Warnings) == 0 {
		t.Fatalf("empty result lacks explanation: %+v %v", result, err)
	}
}

func TestPrepareDoesNotDiscardAnotherVerifiedGroupForSharedArticle(t *testing.T) {
	request := evidenceRequest()
	request.Analysis.Groups["G2"] = analysis.Group{Name: "Неподтверждённое", IDs: []string{"A"}, Facts: []analysis.Fact{}, Contradictions: []analysis.Contradiction{}}
	request.Analysis.RecommendedOrder = []string{"G2", "G1"}
	prepared, err := Prepare(request)
	if err != nil {
		t.Fatal(err)
	}
	if prepared.Request.Analysis.Excluded["A"] != "" || !slices.Equal(prepared.Request.Analysis.RecommendedOrder, []string{"G1"}) {
		t.Fatalf("shared material lost: %+v", prepared.Request.Analysis)
	}
}

func TestPrepareNeverSilentlyDropsAnUnverifiableContradiction(t *testing.T) {
	request := evidenceRequest()
	group := request.Analysis.Groups["G1"]
	group.Contradictions = []analysis.Contradiction{{Description: "Другой источник отрицает сообщение", SourceIDs: []string{"missing"}}}
	request.Analysis.Groups["G1"] = group
	prepared, err := Prepare(request)
	if err != nil {
		t.Fatal(err)
	}
	if len(prepared.Request.Analysis.RecommendedOrder) != 0 || !strings.Contains(prepared.Request.Analysis.Excluded["A"], "противоречия") {
		t.Fatalf("contradiction was hidden: %+v", prepared.Request.Analysis)
	}
}

func TestPrepareRequiresEvidenceFromEachDeclaredSource(t *testing.T) {
	request := evidenceRequest()
	item := request.News.Items[0]
	item.ID = "B"
	request.News.Items = append(request.News.Items, item)
	group := request.Analysis.Groups["G1"]
	group.IDs = append(group.IDs, "B")
	group.Facts[0].SourceIDs = append(group.Facts[0].SourceIDs, "B")
	request.Analysis.Groups["G1"] = group
	prepared, err := Prepare(request)
	if err != nil {
		t.Fatal(err)
	}
	if len(prepared.Request.Analysis.RecommendedOrder) != 0 {
		t.Fatal("unreferenced source counted as evidence")
	}
}

func TestPrepareRejectsOutsideWindowEvenWhenAnalysisClaimsValidEvidence(t *testing.T) {
	request := evidenceRequest()
	request.News.Items[0].PublishedAt = new("2026-11-08T09:00:00+03:00")
	prepared, err := Prepare(request)
	if err != nil {
		t.Fatal(err)
	}
	if len(prepared.Request.Analysis.RecommendedOrder) != 0 || !strings.Contains(prepared.Request.Analysis.Excluded["A"], "вне периода") || strings.Contains(prepared.Input, "Веб-сервис") {
		t.Fatalf("out-of-window material reached export: %s %+v", prepared.Input, prepared.Request.Analysis)
	}
}

func TestPrepareRetainsBudgetExclusionWithoutSendingOversizedSourceToProse(t *testing.T) {
	request := evidenceRequest()
	oversized := analysis.Item{
		ID: "oversized", Title: "Большой источник", Text: new(strings.Repeat("я", 144001)),
		URL: "https://example.org/oversized", PublishedAt: new("2026-11-09T08:00:00+03:00"),
	}
	request.News.Items = append([]analysis.Item{oversized}, request.News.Items...)
	request.Analysis.Excluded["oversized"] = "Бюджет анализа превышен."
	prepared, err := Prepare(request)
	if err != nil {
		t.Fatal(err)
	}
	reason := prepared.Request.Analysis.Excluded["oversized"]
	if !strings.Contains(reason, "144000") || !strings.Contains(reason, "бюджет") || strings.Contains(reason, "source_analysis_budget_exceeded") {
		t.Fatalf("export lost the human-readable budget reason: %q", reason)
	}
	if !slices.Equal(prepared.Request.Analysis.RecommendedOrder, []string{"G1"}) || strings.Contains(prepared.Input, "oversized") || !strings.Contains(prepared.Input, "Веб-сервис получил 6 отчетов.") {
		t.Fatal("budget exclusion removed the later verified event or reached the prose model")
	}
	if len(request.News.Items) != 2 || *request.News.Items[0].Text != *oversized.Text {
		t.Fatal("original news was truncated or lost a source needed by the journal")
	}
}
