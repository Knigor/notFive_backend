package main

import (
	"context"
	"errors"
	"fmt"
	"log/slog"
	"net"
	"net/http"
	"os"
	"os/signal"
	"slices"
	"strconv"
	"strings"
	"syscall"
	"time"
	_ "time/tzdata"

	gqlhandler "github.com/99designs/gqlgen/graphql/handler"
	"github.com/99designs/gqlgen/graphql/handler/transport"
	"github.com/Knigor/notFive_backend/services/digest/graph"
	"github.com/Knigor/notFive_backend/services/digest/internal/analysis"
	"github.com/Knigor/notFive_backend/services/digest/internal/collector"
	"github.com/Knigor/notFive_backend/services/digest/internal/export"
	"github.com/Knigor/notFive_backend/services/digest/internal/httpapi"
	"github.com/Knigor/notFive_backend/services/digest/internal/yandex"
)

func main() {
	if err := run(); err != nil {
		slog.Error("service stopped", "error", err)
		os.Exit(1)
	}
}

func env(name, fallback string) string {
	if value := os.Getenv(name); value != "" {
		return value
	}
	return fallback
}
func intEnv(name, fallback string) (int, error) {
	value, err := strconv.Atoi(env(name, fallback))
	if err != nil {
		return 0, fmt.Errorf("%s must be an integer", name)
	}
	return value, nil
}

func run() error {
	addr := env("LISTEN_ADDR", ":8081")
	if len(os.Args) > 1 && os.Args[1] == "healthcheck" {
		return healthcheck(addr)
	}
	timeout, err := time.ParseDuration(env("REQUEST_TIMEOUT", "300s"))
	if err != nil {
		return errors.New("invalid REQUEST_TIMEOUT")
	}
	concurrent, err := intEnv("MAX_CONCURRENT", "2")
	if err != nil {
		return err
	}
	maxTokens, err := intEnv("MAX_OUTPUT_TOKENS", "8000")
	if err != nil {
		return err
	}
	analysisMaxTokens, err := intEnv("YANDEX_ANALYSIS_MAX_OUTPUT_TOKENS", "12000")
	if err != nil {
		return err
	}
	exportMaxTokens, err := intEnv("YANDEX_EXPORT_MAX_OUTPUT_TOKENS", "8000")
	if err != nil {
		return err
	}
	domains := strings.Split(env("YANDEX_SEARCH_DOMAINS", "securitylab.ru,xakep.ru,habr.com,ptsecurity.com,kaspersky.ru"), ",")
	for i := range domains {
		domains[i] = strings.TrimSpace(domains[i])
	}
	model := env("YANDEX_MODEL", "yandexgpt-5.1")
	analysisModel := env("YANDEX_ANALYSIS_MODEL", "aliceai-llm-flash")
	exportModel := env("YANDEX_EXPORT_MODEL", analysisModel)
	baseURL := env("YANDEX_BASE_URL", yandex.DefaultBaseURL)
	apiKey, folderID := os.Getenv("YANDEX_API_KEY"), os.Getenv("YANDEX_FOLDER_ID")
	client, err := yandex.New(yandex.Config{APIKey: apiKey, FolderID: folderID, Model: model, BaseURL: baseURL, Domains: domains, MaxOutputTokens: maxTokens, Timeout: timeout})
	if err != nil {
		return err
	}
	analysisClient, err := yandex.NewAnalysis(yandex.Config{APIKey: apiKey, FolderID: folderID, Model: analysisModel, BaseURL: baseURL, MaxOutputTokens: analysisMaxTokens, Timeout: timeout})
	if err != nil {
		return err
	}
	exportClient, err := yandex.NewExport(yandex.Config{APIKey: apiKey, FolderID: folderID, Model: exportModel, BaseURL: baseURL, MaxOutputTokens: exportMaxTokens, Timeout: timeout})
	if err != nil {
		return err
	}
	handler, err := httpapi.New(httpapi.Config{
		Token: os.Getenv("DIGEST_SERVICE_TOKEN"), DataDir: env("DATA_DIR", "./data"),
		Timeout: timeout, MaxConcurrent: concurrent, SearchDomains: domains,
		SearchProviderFactory: func(selectedDomains []string) (collector.Provider, error) {
			return yandex.New(yandex.Config{APIKey: apiKey, FolderID: folderID, Model: model, BaseURL: baseURL, Domains: slices.Clone(selectedDomains), MaxOutputTokens: maxTokens, Timeout: timeout})
		},
		ProviderSettings: map[string]any{
			"model": "gpt://" + folderID + "/" + model, "temperature": 0.1, "max_output_tokens": maxTokens,
			"allowed_domains": domains, "search_context_size": yandex.SearchContextSize, "region": "213",
			"timeout": timeout.String(), "store": false, "api_base_url": baseURL, "service_version": "12",
			"max_search_attempts": 2, "max_discovery_sources": 5,
		},
		AnalysisProvider: analysisClient,
		AnalysisSettings: map[string]any{
			"model": "gpt://" + folderID + "/" + analysisModel, "temperature": 0.1, "max_output_tokens": analysisMaxTokens,
			"response_format": "json_schema", "schema_name": analysis.SchemaName, "strict": true,
			"tools_enabled": false, "timeout": timeout.String(),
			"store": false, "api_base_url": baseURL, "service_version": "12",
		},
		ExportProvider: exportClient,
		ExportSettings: map[string]any{
			"model": "gpt://" + folderID + "/" + exportModel, "temperature": 0.1, "max_output_tokens": exportMaxTokens,
			"response_format": "json_schema", "schema_name": export.SchemaName, "strict": true,
			"tools_enabled": false, "timeout": timeout.String(),
			"store": false, "api_base_url": baseURL, "service_version": "12",
		},
	}, client)
	if err != nil {
		return err
	}
	graphql := gqlhandler.New(graph.NewExecutableSchema(graph.Config{Resolvers: &graph.Resolver{Collector: handler}}))
	graphql.AddTransport(transport.POST{})
	handler.MountGraphQL(graphql)
	ctx, stop := signal.NotifyContext(context.Background(), os.Interrupt, syscall.SIGTERM)
	defer stop()
	// A pipeline request runs three stages; each stage retains its own timeout.
	server := &http.Server{Addr: addr, Handler: handler, ReadHeaderTimeout: 5 * time.Second, ReadTimeout: 60 * time.Second, WriteTimeout: 3*timeout + 15*time.Second, IdleTimeout: 60 * time.Second, MaxHeaderBytes: 16 * 1024, BaseContext: func(net.Listener) context.Context { return ctx }}
	finished := make(chan error, 1)
	go func() { finished <- server.ListenAndServe() }()
	slog.Info("article collector listening", "address", addr)
	select {
	case err := <-finished:
		if errors.Is(err, http.ErrServerClosed) {
			return nil
		}
		return err
	case <-ctx.Done():
		shutdown, cancel := context.WithTimeout(context.Background(), 10*time.Second)
		defer cancel()
		return server.Shutdown(shutdown)
	}
}

func healthcheck(addr string) error {
	_, port, err := net.SplitHostPort(addr)
	if err != nil {
		return errors.New("invalid LISTEN_ADDR")
	}
	client := &http.Client{Timeout: 3 * time.Second}
	resp, err := client.Get("http://127.0.0.1:" + port + "/healthz")
	if err != nil {
		return errors.New("healthcheck failed")
	}
	defer resp.Body.Close()
	if resp.StatusCode != http.StatusOK {
		return errors.New("healthcheck failed")
	}
	return nil
}
