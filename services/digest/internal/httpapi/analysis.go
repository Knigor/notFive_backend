package httpapi

import (
	"context"
	"embed"
	"encoding/json"
	"errors"
	"maps"
	"net/http"

	"github.com/Knigor/notFive_backend/services/digest/internal/analysis"
	"github.com/Knigor/notFive_backend/services/digest/internal/collector"
	"github.com/Knigor/notFive_backend/services/digest/internal/yandex"
)

type AnalysisAudit struct {
	PromptVersion string               `json:"prompt_version"`
	ModelInput    string               `json:"model_input"`
	Generation    collector.Generation `json:"generation"`
	Result        *analysis.Result     `json:"result,omitzero"`
	FailureReason string               `json:"failure_reason,omitzero"`
}

//go:embed translations/analysis_api.*.json
var analysisMessageFiles embed.FS

var analysisMessages = func() map[string]map[string]string {
	translations := make(map[string]map[string]string, 2)
	for _, locale := range []string{"ru", "en"} {
		raw, err := analysisMessageFiles.ReadFile("translations/analysis_api." + locale + ".json")
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

func analysisMessage(r *http.Request, key string) string {
	messages := analysisMessages[newsLocale(r.Header.Get("Accept-Language"))]
	if message := messages[key]; message != "" {
		return message
	}
	return messages["invalid_input"]
}

func (s *Server) createAnalysis(w http.ResponseWriter, r *http.Request) {
	w.Header().Set("Content-Language", newsLocale(r.Header.Get("Accept-Language")))
	w.Header().Add("Vary", "Accept-Language")
	if s.cfg.AnalysisProvider == nil {
		fail(w, http.StatusServiceUnavailable, "analysis_unavailable", analysisMessage(r, "unavailable"), "")
		return
	}
	var input analysis.Dataset
	if !decode(w, r, &input) {
		return
	}
	prepared, err := analysis.Prepare(input)
	if err != nil {
		fail(w, http.StatusUnprocessableEntity, "invalid_input", analysisMessage(r, err.Error()), "")
		return
	}
	prepared.Locale = newsLocale(r.Header.Get("Accept-Language"))
	if !s.acquire(w) {
		return
	}
	defer func() { <-s.slots }()
	ctx, cancel := context.WithTimeout(r.Context(), s.cfg.Timeout)
	defer cancel()
	run := s.newRun("analysis", input, analysis.Instructions)
	run.Settings = maps.Clone(s.cfg.AnalysisSettings)
	result, generation, err := analysis.Analyze(ctx, s.cfg.AnalysisProvider, prepared)
	run.Analysis = &AnalysisAudit{PromptVersion: analysis.PromptVersion, ModelInput: prepared.Input, Generation: generation}
	if err == nil {
		run.Analysis.Result = &result
	} else if response, ok := errors.AsType[*yandex.ResponseError](err); ok {
		run.Analysis.FailureReason = response.Reason
	} else if errors.Is(err, analysis.ErrInvalidModelOutput) {
		run.Analysis.FailureReason = "invalid_model_output"
	}
	s.complete(w, r, &run, err)
}
