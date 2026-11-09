package httpapi

import (
	"context"
	"embed"
	"encoding/json"
	"errors"
	"io"
	"maps"
	"net/http"
	"slices"
	"time"

	"github.com/Knigor/notFive_backend/services/digest/internal/analysis"
	"github.com/Knigor/notFive_backend/services/digest/internal/export"
	"github.com/Knigor/notFive_backend/services/digest/internal/yandex"
)

type PipelineResult struct {
	RunID    string          `json:"run_id"`
	Status   string          `json:"status"`
	News     NewsDataset     `json:"news"`
	Analysis analysis.Result `json:"analysis"`
	Draft    export.Result   `json:"draft"`
	Warnings []string        `json:"warnings"`
}

type PipelineAudit struct {
	Source        string            `json:"source"`
	Version       string            `json:"version"`
	Stage         string            `json:"stage"`
	Instructions  map[string]string `json:"instructions"`
	News          *NewsDataset      `json:"news,omitzero"`
	Result        *PipelineResult   `json:"result,omitzero"`
	FailureReason string            `json:"failure_reason,omitzero"`
}

//go:embed translations/pipeline_api.*.json
var pipelineMessageFiles embed.FS

var pipelineMessages = func() map[string]map[string]string {
	translations := make(map[string]map[string]string, 2)
	for _, locale := range []string{"ru", "en"} {
		raw, err := pipelineMessageFiles.ReadFile("translations/pipeline_api." + locale + ".json")
		if err != nil {
			panic(err)
		}
		var messages map[string]string
		if err := json.Unmarshal(raw, &messages); err != nil {
			panic(err)
		}
		translations[locale] = messages
	}
	return translations
}()

func pipelineMessage(r *http.Request, key string) string {
	messages := pipelineMessages[newsLocale(r.Header.Get("Accept-Language"))]
	if message := messages[key]; message != "" {
		return message
	}
	return messages["generation_failed"]
}

func pipelineLanguage(w http.ResponseWriter, r *http.Request) {
	w.Header().Set("Content-Language", newsLocale(r.Header.Get("Accept-Language")))
	w.Header().Add("Vary", "Accept-Language")
}

const pipelineSearchQuery = "Найди свежие новости кибербезопасности за заданные 24 часа, важные для российских организаций: новые уязвимости, инциденты, утечки и защитные меры. Не включай прогнозы, годовые отчёты и вечнозелёные обзоры. Укажи даты, источники, последствия и оговорки. Если подходящих публикаций нет, прямо сообщи об этом и ничего не выдумывай."

type pipelineWebRequest struct {
	ControlTime string   `json:"control_time"`
	Sites       []string `json:"sites"`
}

func (s *Server) createPipelineWeb(w http.ResponseWriter, r *http.Request) {
	pipelineLanguage(w, r)
	var input pipelineWebRequest
	if !decodePipeline(w, r, &input) {
		return
	}
	control, err := time.Parse(time.RFC3339, input.ControlTime)
	if err != nil {
		pipelineFail(w, r, http.StatusUnprocessableEntity, "invalid_input", "invalid_input", "", "")
		return
	}
	scoped, err := s.scopeSites(input.Sites)
	if err != nil {
		status, code := http.StatusUnprocessableEntity, "invalid_input"
		if errors.Is(err, ErrSitesUnavailable) {
			status, code = http.StatusServiceUnavailable, "pipeline_unavailable"
		}
		pipelineFail(w, r, status, code, err.Error(), "", "")
		return
	}
	scoped.runPipeline(w, r, input, "web", nil, func(ctx context.Context, run *Run) error {
		return scoped.collect(ctx, researchRequest{ControlTime: input.ControlTime, Query: pipelineSearchQuery}, control, run)
	})
}

func (s *Server) createPipelineNews(w http.ResponseWriter, r *http.Request) {
	pipelineLanguage(w, r)
	var input analysis.Dataset
	if !decodePipeline(w, r, &input) {
		return
	}
	prepared, err := analysis.Prepare(input)
	if err != nil {
		pipelineFail(w, r, http.StatusUnprocessableEntity, "invalid_input", err.Error(), "", "")
		return
	}
	// Preserve every material for the journal. Only period metadata is normalized;
	// analysis.Prepare filters the model input, not this source dataset.
	normalized := input
	normalized.ControlTime = prepared.Dataset.ControlTime
	normalized.PeriodStart = prepared.Dataset.PeriodStart
	normalized.Timezone = prepared.Dataset.Timezone
	dataset := pipelineNewsDataset(normalized)
	s.runPipeline(w, r, input, "news", &dataset, nil)
}

// runPipeline shares analysis/export orchestration between website collection
// and a supplied dataset. A nil collection step never searches or fetches news;
// eligible articles can still require model API calls for analysis and export.
func (s *Server) runPipeline(w http.ResponseWriter, r *http.Request, input any, source string, dataset *NewsDataset, collect func(context.Context, *Run) error) {
	if s.cfg.AnalysisProvider == nil || s.cfg.ExportProvider == nil {
		pipelineFail(w, r, http.StatusServiceUnavailable, "pipeline_unavailable", "unavailable", "", "")
		return
	}
	select {
	case s.slots <- struct{}{}:
		defer func() { <-s.slots }()
	default:
		w.Header().Set("Retry-After", "5")
		pipelineFail(w, r, http.StatusTooManyRequests, "busy", "busy", "", "")
		return
	}
	stages := 2
	stage := "analysis"
	instructions := map[string]string{"analysis": analysis.Instructions, "export": export.Instructions}
	settings := map[string]any{"analysis": maps.Clone(s.cfg.AnalysisSettings), "export": maps.Clone(s.cfg.ExportSettings)}
	if collect != nil {
		stages, stage = 3, "collection"
		instructions["collection"] = researchInstructions
		settings["collection"] = maps.Clone(s.cfg.ProviderSettings)
	}
	totalTimeout := time.Duration(stages) * s.cfg.Timeout
	ctx, cancel := context.WithTimeout(r.Context(), totalTimeout)
	defer cancel()
	locale := newsLocale(r.Header.Get("Accept-Language"))
	run := s.newRun("pipeline", input, "pipeline-v2: "+source)
	run.Status = "running"
	settings["stage_timeout"], settings["pipeline_timeout"] = s.cfg.Timeout.String(), totalTimeout.String()
	run.Settings = settings
	run.Pipeline = &PipelineAudit{Version: "pipeline-v2", Source: source, Stage: stage, Instructions: instructions, News: dataset}
	if !s.pipelineCheckpoint(w, r, &run) {
		return
	}
	if collect != nil {
		err := s.pipelineStage(ctx, func(stageCtx context.Context) error {
			return collect(stageCtx, &run)
		})
		if err != nil {
			s.completePipeline(w, r, &run, err)
			return
		}
		dataset = new(newsDataset(run.Collection, locale))
		run.Pipeline.News = dataset
		run.Pipeline.Stage = "analysis"
		if !s.pipelineCheckpoint(w, r, &run) {
			return
		}
	}
	analysisInput := pipelineAnalysisDataset(*dataset)
	err := s.pipelineStage(ctx, func(stageCtx context.Context) error {
		run.Analysis = &AnalysisAudit{PromptVersion: analysis.PromptVersion}
		prepared, err := analysis.Prepare(analysisInput)
		if err != nil {
			return err
		}
		prepared.Locale = locale
		run.Analysis.ModelInput = prepared.Input
		result, generation, err := analysis.Analyze(stageCtx, s.cfg.AnalysisProvider, prepared)
		run.Analysis.Generation = generation
		if err == nil {
			run.Analysis.Result = &result
		}
		return err
	})
	if err != nil {
		s.completePipeline(w, r, &run, err)
		return
	}
	validatedAnalysis := *run.Analysis.Result
	run.Pipeline.Stage = "export"
	if !s.pipelineCheckpoint(w, r, &run) {
		return
	}
	err = s.pipelineStage(ctx, func(stageCtx context.Context) error {
		run.Export = &ExportAudit{PromptVersion: export.PromptVersion}
		prepared, err := export.Prepare(export.Request{Analysis: *run.Analysis.Result, News: analysisInput})
		if err != nil {
			return err
		}
		prepared.Locale = locale
		run.Export.ModelInput = prepared.Input
		validatedAnalysis = prepared.Request.Analysis
		run.Export.ValidatedAnalysis = &validatedAnalysis
		run.Export.EvidenceChecks = prepared.EvidenceChecks
		result, generation, err := export.Export(stageCtx, s.cfg.ExportProvider, prepared)
		run.Export.Generation = generation
		if err == nil {
			run.Export.Result = &result
		}
		return err
	})
	if err == nil {
		warnings := []string{}
		stageWarnings := [][]string{run.Analysis.Result.Warnings, run.Export.Result.Warnings}
		if run.Collection != nil {
			stageWarnings = append([][]string{run.Collection.Warnings}, stageWarnings...)
		}
		for _, messages := range stageWarnings {
			for _, message := range messages {
				if !slices.Contains(warnings, message) {
					warnings = append(warnings, message)
				}
			}
		}
		run.Pipeline.Result = &PipelineResult{RunID: run.ID, Status: "completed", News: *dataset, Analysis: validatedAnalysis, Draft: *run.Export.Result, Warnings: warnings}
	}
	s.completePipeline(w, r, &run, err)
}

func pipelineNewsDataset(input analysis.Dataset) NewsDataset {
	result := NewsDataset{Dataset: input.Dataset, ControlTime: input.ControlTime, PeriodStart: input.PeriodStart, Timezone: input.Timezone, Description: input.Description, Fields: maps.Clone(input.Fields), Items: make([]NewsItem, 0, len(input.Items))}
	for _, item := range input.Items {
		result.Items = append(result.Items, NewsItem{ID: item.ID, Title: item.Title, Text: item.Text, Source: item.Source, URL: item.URL, PublishedAt: item.PublishedAt, Missing: slices.Clone(item.Missing), Error: item.Error})
	}
	return result
}

func (s *Server) pipelineStage(ctx context.Context, execute func(context.Context) error) error {
	if err := ctx.Err(); err != nil {
		return err
	}
	stageCtx, cancel := context.WithTimeout(ctx, s.cfg.Timeout)
	defer cancel()
	if err := execute(stageCtx); err != nil {
		return err
	}
	return stageCtx.Err()
}

func pipelineAnalysisDataset(news NewsDataset) analysis.Dataset {
	result := analysis.Dataset{Dataset: news.Dataset, ControlTime: news.ControlTime, PeriodStart: news.PeriodStart, Timezone: news.Timezone, Description: news.Description, Fields: maps.Clone(news.Fields), Items: make([]analysis.Item, 0, len(news.Items))}
	for _, item := range news.Items {
		result.Items = append(result.Items, analysis.Item{ID: item.ID, Title: item.Title, Text: item.Text, Source: item.Source, URL: item.URL, PublishedAt: item.PublishedAt, Missing: slices.Clone(item.Missing), Error: item.Error})
	}
	return result
}

func (s *Server) pipelineCheckpoint(w http.ResponseWriter, r *http.Request, run *Run) bool {
	if err := s.save(*run); err != nil {
		pipelineFail(w, r, http.StatusInternalServerError, "storage_error", "storage_error", run.ID, run.Pipeline.Stage)
		return false
	}
	return true
}

func (s *Server) completePipeline(w http.ResponseWriter, r *http.Request, run *Run, err error) {
	run.CompletedAt = time.Now().UTC()
	status, reason := http.StatusCreated, ""
	if err != nil {
		status, reason = pipelineFailure(err)
		run.Status = "failed"
		run.Pipeline.FailureReason = reason
		run.Error = pipelineMessage(r, reason)
		if upstream, ok := errors.AsType[*yandex.UpstreamError](err); ok {
			run.UpstreamStatus = upstream.Status
		}
		if run.Pipeline.Stage == "analysis" && run.Analysis != nil {
			run.Analysis.FailureReason = reason
		}
		if run.Pipeline.Stage == "export" && run.Export != nil {
			run.Export.FailureReason = reason
		}
	} else {
		run.Status = "completed"
		run.Pipeline.Stage = "completed"
	}
	if !s.pipelineCheckpoint(w, r, run) {
		return
	}
	w.Header().Set("X-Run-ID", run.ID)
	w.Header().Set("Location", "/v1/runs/"+run.ID)
	if err != nil {
		pipelineFail(w, r, status, "pipeline_failed", reason, run.ID, run.Pipeline.Stage)
		return
	}
	// Generated documents remain Russian content even with English error metadata.
	w.Header().Set("Content-Language", "ru")
	respond(w, status, run.Pipeline.Result)
}

func pipelineFailure(err error) (int, string) {
	if errors.Is(err, context.DeadlineExceeded) {
		return http.StatusGatewayTimeout, "timeout"
	}
	if errors.Is(err, context.Canceled) {
		return http.StatusRequestTimeout, "cancelled"
	}
	if errors.Is(err, analysis.ErrInvalidModelOutput) || errors.Is(err, export.ErrInvalidModelOutput) {
		return http.StatusBadGateway, "invalid_model_output"
	}
	if response, ok := errors.AsType[*yandex.ResponseError](err); ok {
		switch response.Reason {
		case "content_filter", "max_output_tokens", "refusal":
			return http.StatusBadGateway, response.Reason
		}
	}
	return http.StatusBadGateway, "generation_failed"
}

func pipelineFail(w http.ResponseWriter, r *http.Request, status int, code, key, id, stage string) {
	respond(w, status, struct {
		Error   string `json:"error"`
		Message string `json:"message"`
		RunID   string `json:"run_id,omitzero"`
		Stage   string `json:"stage,omitzero"`
	}{code, pipelineMessage(r, key), id, stage})
}

func decodePipeline(w http.ResponseWriter, r *http.Request, input any) bool {
	r.Body = http.MaxBytesReader(w, r.Body, maxBody)
	decoder := json.NewDecoder(r.Body)
	decoder.DisallowUnknownFields()
	err := decoder.Decode(input)
	if err == nil {
		var extra any
		if next := decoder.Decode(&extra); next != io.EOF {
			err = errors.New("expected one JSON object")
			if next != nil {
				err = next
			}
		}
	}
	if err == nil {
		return true
	}
	status := http.StatusBadRequest
	if _, ok := errors.AsType[*http.MaxBytesError](err); ok {
		status = http.StatusRequestEntityTooLarge
	}
	pipelineFail(w, r, status, "invalid_json", "invalid_json", "", "")
	return false
}
