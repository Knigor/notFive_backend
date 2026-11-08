// Package httpapi exposes the private article collection API.
package httpapi

import (
	"context"
	"crypto/rand"
	"crypto/sha256"
	"crypto/subtle"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net/http"
	"os"
	"path/filepath"
	"regexp"
	"slices"
	"strings"
	"time"

	"github.com/Knigor/notFive_backend/services/digest/internal/collector"
	"github.com/Knigor/notFive_backend/services/digest/internal/news"
	"github.com/Knigor/notFive_backend/services/digest/internal/yandex"
)

const maxBody = 1 << 20

var runIDPattern = regexp.MustCompile(`^[a-f0-9]{32}$`)

type Config struct {
	Token            string
	DataDir          string
	Timeout          time.Duration
	MaxConcurrent    int
	ProviderSettings map[string]any
	SearchDomains    []string
	SourceFetcher    SourceFetcher
}

type Server struct {
	cfg            Config
	provider       collector.Provider
	fetcher        SourceFetcher
	dynamicFetcher bool
	slots          chan struct{}
	mux            *http.ServeMux
}

type Run struct {
	UpstreamStatus int             `json:"upstream_status,omitzero"`
	ID             string          `json:"id"`
	Mode           string          `json:"mode"`
	Status         string          `json:"status"`
	CreatedAt      time.Time       `json:"created_at"`
	CompletedAt    time.Time       `json:"completed_at"`
	Input          json.RawMessage `json:"input"`
	InputSHA256    string          `json:"input_sha256"`
	Instructions   string          `json:"instructions"`
	Settings       map[string]any  `json:"settings"`
	Cost           *float64        `json:"cost"` // Unknown: a tariff/estimate is not actual billed cost.
	Error          string          `json:"error,omitzero"`
	Research       *Research       `json:"research,omitzero"`
	Collection     *Collection     `json:"collection,omitzero"`
}

func New(cfg Config, provider collector.Provider) (*Server, error) {
	if len(cfg.Token) < 32 {
		return nil, errors.New("DIGEST_SERVICE_TOKEN must have at least 32 characters")
	}
	if cfg.DataDir == "" {
		return nil, errors.New("DATA_DIR is required")
	}
	if cfg.Timeout < 15*time.Second || cfg.Timeout > 10*time.Minute {
		return nil, errors.New("REQUEST_TIMEOUT must be 15s..10m")
	}
	if cfg.MaxConcurrent < 1 || cfg.MaxConcurrent > 8 {
		return nil, errors.New("MAX_CONCURRENT must be 1..8")
	}
	if provider == nil {
		return nil, errors.New("provider is required")
	}
	if len(cfg.SearchDomains) == 0 {
		cfg.SearchDomains = []string{"securitylab.ru", "xakep.ru", "habr.com", "ptsecurity.com", "kaspersky.ru"}
	}
	cfg.SearchDomains = slices.Clone(cfg.SearchDomains)
	dynamicFetcher := cfg.SourceFetcher == nil
	if dynamicFetcher {
		cfg.SourceFetcher = news.New(cfg.SearchDomains)
	}
	if err := os.MkdirAll(filepath.Join(cfg.DataDir, "runs"), 0700); err != nil {
		return nil, fmt.Errorf("create run storage: %w", err)
	}
	s := &Server{cfg: cfg, provider: provider, fetcher: cfg.SourceFetcher, dynamicFetcher: dynamicFetcher, slots: make(chan struct{}, cfg.MaxConcurrent), mux: http.NewServeMux()}
	s.mux.HandleFunc("GET /healthz", func(w http.ResponseWriter, r *http.Request) {
		respond(w, http.StatusOK, map[string]string{"status": "ok"})
	})
	s.mux.Handle("POST /v1/collect", s.auth(http.HandlerFunc(s.createCollection)))
	s.mux.Handle("GET /v1/runs/{id}", s.auth(http.HandlerFunc(s.getRun)))
	return s, nil
}

func (s *Server) ServeHTTP(w http.ResponseWriter, r *http.Request) {
	w.Header().Set("X-Content-Type-Options", "nosniff")
	w.Header().Set("Cache-Control", "no-store")
	s.mux.ServeHTTP(w, r)
}

func (s *Server) MountGraphQL(handler http.Handler) {
	s.mux.Handle("POST /graphql", s.auth(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		r.Body = http.MaxBytesReader(w, r.Body, 52*1024*1024)
		handler.ServeHTTP(w, r)
	})))
}

func (s *Server) auth(next http.Handler) http.Handler {
	expected := sha256.Sum256([]byte(s.cfg.Token))
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		token, ok := strings.CutPrefix(r.Header.Get("Authorization"), "Bearer ")
		actual := sha256.Sum256([]byte(token))
		if !ok || subtle.ConstantTimeCompare(actual[:], expected[:]) != 1 {
			w.Header().Set("WWW-Authenticate", "Bearer")
			fail(w, http.StatusUnauthorized, "unauthorized", "A service bearer token is required", "")
			return
		}
		next.ServeHTTP(w, r)
	})
}

func decode(w http.ResponseWriter, r *http.Request, dst any) bool {
	r.Body = http.MaxBytesReader(w, r.Body, maxBody)
	decoder := json.NewDecoder(r.Body)
	decoder.DisallowUnknownFields()
	err := decoder.Decode(dst)
	if err == nil {
		var extra any
		if e := decoder.Decode(&extra); e != io.EOF {
			err = errors.New("expected one JSON object")
			if e != nil {
				err = e
			}
		}
	}
	if err != nil {
		status := http.StatusBadRequest
		if _, ok := errors.AsType[*http.MaxBytesError](err); ok {
			status = http.StatusRequestEntityTooLarge
		}
		fail(w, status, "invalid_json", "Expected a single JSON object with documented fields; limit 1 MiB", "")
		return false
	}
	return true
}

func (s *Server) acquire(w http.ResponseWriter) bool {
	select {
	case s.slots <- struct{}{}:
		return true
	default:
		w.Header().Set("Retry-After", "5")
		fail(w, http.StatusTooManyRequests, "busy", "All collection slots are busy", "")
		return false
	}
}

func (s *Server) newRun(mode string, input any, instructions string) Run {
	raw, _ := json.Marshal(input) // Only concrete request types with JSON-safe fields.
	hash := sha256.Sum256(raw)
	id := make([]byte, 16)
	_, _ = rand.Read(id)
	return Run{ID: hex.EncodeToString(id), Mode: mode, Status: "completed", CreatedAt: time.Now().UTC(), Input: raw, InputSHA256: hex.EncodeToString(hash[:]), Instructions: instructions, Settings: s.cfg.ProviderSettings}
}

func (s *Server) complete(w http.ResponseWriter, run *Run, err error) {
	run.CompletedAt = time.Now().UTC()
	status := http.StatusCreated
	if err != nil {
		run.Status = "failed"
		if upstream, ok := errors.AsType[*yandex.UpstreamError](err); ok {
			run.UpstreamStatus = upstream.Status
		}
		status = http.StatusBadGateway
		run.Error = "Collection failed; inspect stored audit and provider configuration"
		if errors.Is(err, context.DeadlineExceeded) {
			status = http.StatusGatewayTimeout
			run.Error = "Collection exceeded REQUEST_TIMEOUT"
		}
		if errors.Is(err, context.Canceled) {
			status = http.StatusRequestTimeout
			run.Error = "Collection was cancelled"
		}
	}
	if saveErr := s.save(*run); saveErr != nil {
		fail(w, http.StatusInternalServerError, "storage_error", "Could not persist the run; no result is reported as saved", "")
		return
	}
	if err != nil {
		fail(w, status, "generation_failed", run.Error, run.ID)
		return
	}
	w.Header().Set("Location", "/v1/runs/"+run.ID)
	respond(w, status, run)
}

func (s *Server) save(run Run) error {
	raw, err := json.Marshal(run)
	if err != nil {
		return err
	}
	dir := filepath.Join(s.cfg.DataDir, "runs")
	f, err := os.CreateTemp(dir, ".run-*.tmp")
	if err != nil {
		return err
	}
	name := f.Name()
	defer os.Remove(name)
	if _, err = f.Write(raw); err != nil {
		f.Close()
		return err
	}
	if err = f.Sync(); err != nil {
		f.Close()
		return err
	}
	if err = f.Close(); err != nil {
		return err
	}
	return os.Rename(name, filepath.Join(dir, run.ID+".json"))
}

func (s *Server) load(w http.ResponseWriter, r *http.Request) (Run, bool) {
	id := r.PathValue("id")
	if !runIDPattern.MatchString(id) {
		fail(w, 404, "not_found", "Run not found", "")
		return Run{}, false
	}
	raw, err := os.ReadFile(filepath.Join(s.cfg.DataDir, "runs", id+".json"))
	if errors.Is(err, os.ErrNotExist) {
		fail(w, 404, "not_found", "Run not found", "")
		return Run{}, false
	}
	var run Run
	if err != nil || json.Unmarshal(raw, &run) != nil {
		fail(w, 500, "storage_error", "Could not read the run", "")
		return Run{}, false
	}
	return run, true
}

func (s *Server) getRun(w http.ResponseWriter, r *http.Request) {
	if run, ok := s.load(w, r); ok {
		respond(w, 200, run)
	}
}

func fail(w http.ResponseWriter, status int, code, message, id string) {
	respond(w, status, struct {
		Error   string `json:"error"`
		Message string `json:"message"`
		RunID   string `json:"run_id,omitzero"`
	}{code, message, id})
}
func respond(w http.ResponseWriter, status int, body any) {
	w.Header().Set("Content-Type", "application/json; charset=utf-8")
	w.WriteHeader(status)
	_ = json.NewEncoder(w).Encode(body)
}
