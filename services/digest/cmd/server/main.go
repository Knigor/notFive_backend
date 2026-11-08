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
	"strconv"
	"strings"
	"syscall"
	"time"
	_ "time/tzdata"

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
	timeout, err := time.ParseDuration(env("REQUEST_TIMEOUT", "180s"))
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
	domains := strings.Split(env("YANDEX_SEARCH_DOMAINS", "securitylab.ru,xakep.ru,habr.com,ptsecurity.com,kaspersky.ru"), ",")
	for i := range domains {
		domains[i] = strings.TrimSpace(domains[i])
	}
	model := env("YANDEX_MODEL", "yandexgpt-5.1")
	baseURL := env("YANDEX_BASE_URL", yandex.DefaultBaseURL)
	client, err := yandex.New(yandex.Config{APIKey: os.Getenv("YANDEX_API_KEY"), FolderID: os.Getenv("YANDEX_FOLDER_ID"), Model: model, BaseURL: baseURL, Domains: domains, MaxOutputTokens: maxTokens, Timeout: timeout})
	if err != nil {
		return err
	}
	handler, err := httpapi.New(httpapi.Config{Token: os.Getenv("DIGEST_SERVICE_TOKEN"), DataDir: env("DATA_DIR", "./data"), Timeout: timeout, MaxConcurrent: concurrent, SearchDomains: domains, ProviderSettings: map[string]any{"model": "gpt://" + os.Getenv("YANDEX_FOLDER_ID") + "/" + model, "temperature": 0.1, "max_output_tokens": maxTokens, "allowed_domains": domains, "search_context_size": yandex.SearchContextSize, "region": "213", "timeout": timeout.String(), "store": false, "api_base_url": baseURL, "service_version": "6", "max_search_attempts": 2, "max_discovery_sources": 5}}, client)
	if err != nil {
		return err
	}
	ctx, stop := signal.NotifyContext(context.Background(), os.Interrupt, syscall.SIGTERM)
	defer stop()
	server := &http.Server{Addr: addr, Handler: handler, ReadHeaderTimeout: 5 * time.Second, ReadTimeout: 15 * time.Second, WriteTimeout: timeout + 15*time.Second, IdleTimeout: 60 * time.Second, MaxHeaderBytes: 16 * 1024, BaseContext: func(net.Listener) context.Context { return ctx }}
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
