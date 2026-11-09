package httpapi

import (
	"context"
	"errors"
	"fmt"
	"log/slog"
	"maps"
	"slices"
	"time"

	"github.com/Knigor/notFive_backend/services/digest/graph/model"
	"github.com/Knigor/notFive_backend/services/digest/internal/analysis"
	"github.com/Knigor/notFive_backend/services/digest/internal/export"
)

// PrepareDigest returns source-linked model decisions and prose. Symfony owns
// persistence and rendering of the four deliverable files.
func (s *Server) PrepareDigest(ctx context.Context, input model.PrepareDigestInput) (*model.PreparedDigest, error) {
	if s.cfg.AnalysisProvider == nil || s.cfg.ExportProvider == nil {
		return nil, errors.New("digest models are unavailable")
	}
	items := make([]analysis.Item, 0, len(input.Items))
	for _, item := range input.Items {
		if item == nil {
			return nil, errors.New("null material")
		}
		items = append(items, analysis.Item{
			ID: item.ID, Title: deref(item.Title), Text: item.Text, Source: deref(item.Source),
			URL: deref(item.URL), PublishedAt: item.PublishedAt, Missing: slices.Clone(item.Missing), Error: item.Error,
		})
	}
	dataset := analysis.Dataset{Dataset: "run", ControlTime: input.ControlTime, Timezone: "Europe/Moscow", Items: items}
	prepared, err := analysis.Prepare(dataset)
	if err != nil {
		return nil, err
	}
	select {
	case s.slots <- struct{}{}:
		defer func() { <-s.slots }()
	default:
		return nil, errors.New("all digest slots are busy")
	}
	ctx, cancel := context.WithTimeout(ctx, 2*s.cfg.Timeout)
	defer cancel()
	run := s.newRun("digest", input, "analysis + prose")
	run.Status = "running"
	run.Analysis = &AnalysisAudit{PromptVersion: analysis.PromptVersion, ModelInput: prepared.Input}
	analysisCtx, analysisCancel := context.WithTimeout(ctx, s.cfg.Timeout)
	analyzed, analysisGeneration, err := analysis.Analyze(analysisCtx, s.cfg.AnalysisProvider, prepared)
	analysisCancel()
	run.Analysis.Generation = analysisGeneration
	if err != nil {
		run.Status, run.Error, run.CompletedAt = "failed", err.Error(), time.Now().UTC()
		_ = s.save(run)
		return nil, err
	}
	run.Analysis.Result = &analyzed
	slog.Info("digest analysis completed", "run_id", run.ID, "groups", len(analyzed.Groups), "selected_groups", len(analyzed.RecommendedOrder), "duration_ms", analysisGeneration.DurationMS)
	preparedDraft, err := export.Prepare(export.Request{Analysis: analyzed, News: dataset})
	if err != nil {
		run.Status, run.Error, run.CompletedAt = "failed", err.Error(), time.Now().UTC()
		_ = s.save(run)
		return nil, err
	}
	validated := preparedDraft.Request.Analysis
	run.Export = &ExportAudit{PromptVersion: export.PromptVersion, ModelInput: preparedDraft.Input, EvidenceChecks: preparedDraft.EvidenceChecks, ValidatedAnalysis: &validated}
	draftCtx, draftCancel := context.WithTimeout(ctx, s.cfg.Timeout)
	draft, draftGeneration, err := export.Export(draftCtx, s.cfg.ExportProvider, preparedDraft)
	draftCancel()
	run.Export.Generation = draftGeneration
	if err != nil {
		run.Status, run.Error, run.CompletedAt = "failed", err.Error(), time.Now().UTC()
		_ = s.save(run)
		return nil, err
	}
	run.Export.Result = &draft
	run.Status, run.CompletedAt = "completed", time.Now().UTC()
	if err := s.save(run); err != nil {
		return nil, fmt.Errorf("save digest audit: %w", err)
	}
	slog.Info("digest completed", "run_id", run.ID, "events", len(draft.Events), "excluded_materials", len(validated.Excluded), "evidence_checks", len(preparedDraft.EvidenceChecks), "warnings", len(draft.Warnings), "duration_ms", time.Since(run.CreatedAt).Milliseconds())
	result := &model.PreparedDigest{
		RunID:  run.ID,
		Groups: []*model.DigestGroup{}, RecommendedOrder: slices.Clone(validated.RecommendedOrder),
		Excluded: []*model.DigestExclusion{}, Events: []*model.DigestEvent{},
		SelectionSummary: draft.SelectionSummary, Warnings: slices.Clone(draft.Warnings),
		AnalysisModel: analysisGeneration.Model, DraftModel: draftGeneration.Model,
		AnalysisPromptVersion: analysis.PromptVersion, DraftPromptVersion: export.PromptVersion,
		AnalysisDurationMs: int(analysisGeneration.DurationMS), DraftDurationMs: int(draftGeneration.DurationMS),
	}
	if len(analysisGeneration.Usage) > 0 {
		result.AnalysisUsageJSON = new(string(analysisGeneration.Usage))
	}
	if len(draftGeneration.Usage) > 0 {
		result.DraftUsageJSON = new(string(draftGeneration.Usage))
	}
	for _, id := range slices.Sorted(maps.Keys(validated.Groups)) {
		group := validated.Groups[id]
		converted := &model.DigestGroup{ID: id, Name: group.Name, Ids: slices.Clone(group.IDs), Facts: []*model.DigestFact{}, Contradictions: []*model.DigestContradiction{}, SelectionReason: group.SelectionReason}
		for _, fact := range group.Facts {
			f := &model.DigestFact{Statement: fact.Statement, SourceIds: slices.Clone(fact.SourceIDs), Evidence: []*model.DigestEvidence{}}
			for _, evidence := range fact.Evidence {
				f.Evidence = append(f.Evidence, &model.DigestEvidence{ArticleID: evidence.ArticleID, Quote: evidence.Quote})
			}
			converted.Facts = append(converted.Facts, f)
		}
		for _, contradiction := range group.Contradictions {
			converted.Contradictions = append(converted.Contradictions, &model.DigestContradiction{Description: contradiction.Description, SourceIds: slices.Clone(contradiction.SourceIDs)})
		}
		result.Groups = append(result.Groups, converted)
	}
	for _, id := range slices.Sorted(maps.Keys(validated.Excluded)) {
		result.Excluded = append(result.Excluded, &model.DigestExclusion{ArticleID: id, Reason: validated.Excluded[id]})
	}
	for _, event := range draft.Events {
		result.Events = append(result.Events, &model.DigestEvent{GroupID: event.GroupID, Title: event.Title, Summary: event.Summary, Consequences: event.Consequences, Post: event.Post, Reason: event.Reason})
	}
	return result, nil
}
