package httpapi

import (
	"context"
	"embed"
	"encoding/json"
	"errors"
	"io"
	"maps"
	"net/http"

	"github.com/Knigor/notFive_backend/services/digest/internal/analysis"
	"github.com/Knigor/notFive_backend/services/digest/internal/collector"
	"github.com/Knigor/notFive_backend/services/digest/internal/export"
	"github.com/Knigor/notFive_backend/services/digest/internal/yandex"
)

type ExportAudit struct {
	EvidenceChecks    []analysis.EvidenceCheck `json:"evidence_checks,omitzero"`
	ValidatedAnalysis *analysis.Result         `json:"validated_analysis,omitzero"`
	PromptVersion     string                   `json:"prompt_version"`
	ModelInput        string                   `json:"model_input"`
	Generation        collector.Generation     `json:"generation"`
	Result            *export.Result           `json:"result,omitzero"`
	FailureReason     string                   `json:"failure_reason,omitzero"`
}

//go:embed translations/export_api.*.json
var exportMessageFiles embed.FS

var exportMessages = func() map[string]map[string]string {
	translations := make(map[string]map[string]string, 2)
	for _, locale := range []string{"ru", "en"} {
		raw, err := exportMessageFiles.ReadFile("translations/export_api." + locale + ".json")
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

func exportMessage(r *http.Request, key string) string {
	messages := exportMessages[newsLocale(r.Header.Get("Accept-Language"))]
	if message := messages[key]; message != "" {
		return message
	}
	return messages["invalid_input"]
}

func (s *Server) createExport(w http.ResponseWriter, r *http.Request) {
	w.Header().Set("Content-Language", newsLocale(r.Header.Get("Accept-Language")))
	w.Header().Add("Vary", "Accept-Language")
	if s.cfg.ExportProvider == nil {
		fail(w, http.StatusServiceUnavailable, "export_unavailable", exportMessage(r, "unavailable"), "")
		return
	}
	var input export.Request
	if !decodeExport(w, r, &input) {
		return
	}
	prepared, err := export.Prepare(input)
	if err != nil {
		fail(w, http.StatusUnprocessableEntity, "invalid_input", exportMessage(r, err.Error()), "")
		return
	}
	prepared.Locale = newsLocale(r.Header.Get("Accept-Language"))
	select {
	case s.slots <- struct{}{}:
		defer func() { <-s.slots }()
	default:
		w.Header().Set("Retry-After", "5")
		fail(w, http.StatusTooManyRequests, "busy", exportMessage(r, "busy"), "")
		return
	}
	ctx, cancel := context.WithTimeout(r.Context(), s.cfg.Timeout)
	defer cancel()
	run := s.newRun("export", input, export.Instructions)
	run.Settings = maps.Clone(s.cfg.ExportSettings)
	result, generation, err := export.Export(ctx, s.cfg.ExportProvider, prepared)
	run.Export = &ExportAudit{PromptVersion: export.PromptVersion, ModelInput: prepared.Input, Generation: generation, EvidenceChecks: prepared.EvidenceChecks, ValidatedAnalysis: &prepared.Request.Analysis}
	if err == nil {
		run.Export.Result = &result
	} else if response, ok := errors.AsType[*yandex.ResponseError](err); ok {
		run.Export.FailureReason = response.Reason
	} else if errors.Is(err, export.ErrInvalidModelOutput) {
		run.Export.FailureReason = "invalid_model_output"
	}
	s.complete(w, r, &run, err)
}

func decodeExport(w http.ResponseWriter, r *http.Request, dst *export.Request) bool {
	r.Body = http.MaxBytesReader(w, r.Body, maxBody)
	decoder := json.NewDecoder(r.Body)
	decoder.DisallowUnknownFields()
	err := decoder.Decode(dst)
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
	fail(w, status, "invalid_json", exportMessage(r, "invalid_json"), "")
	return false
}
