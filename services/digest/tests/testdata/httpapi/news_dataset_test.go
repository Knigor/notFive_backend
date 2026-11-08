package httpapi

import (
	"context"
	"encoding/json"
	"net/http/httptest"
	"strings"
	"sync/atomic"
	"testing"
	"time"

	"github.com/Knigor/notFive_backend/services/digest/internal/collector"
	"github.com/Knigor/notFive_backend/services/digest/internal/news"
)

func requireJSONKeys(t *testing.T, raw []byte, keys ...string) map[string]json.RawMessage {
	t.Helper()
	var object map[string]json.RawMessage
	if err := json.Unmarshal(raw, &object); err != nil {
		t.Fatal(err)
	}
	if len(object) != len(keys) {
		t.Fatalf("wrong JSON keys: got %v, want %v", object, keys)
	}
	for _, key := range keys {
		if _, ok := object[key]; !ok {
			t.Fatalf("missing required key: %s", key)
		}
	}
	return object
}

func decodeNewsDataset(t *testing.T, response *httptest.ResponseRecorder) NewsDataset {
	t.Helper()
	if response.Code != 201 {
		t.Fatalf("collection failed: status=%d body=%s", response.Code, response.Body.String())
	}
	root := requireJSONKeys(t, response.Body.Bytes(), "dataset", "control_time", "period_start", "timezone", "description", "fields", "items")
	fields := requireJSONKeys(t, root["fields"], "id", "title", "text", "source", "url", "published_at", "missing", "error")
	for key, raw := range fields {
		var value string
		if json.Unmarshal(raw, &value) != nil || value == "" {
			t.Errorf("empty or non-string field description: %s", key)
		}
	}
	var items []json.RawMessage
	if err := json.Unmarshal(root["items"], &items); err != nil || items == nil {
		t.Fatalf("items must be an array, including an empty one: %s", root["items"])
	}
	for _, item := range items {
		fields := requireJSONKeys(t, item, "id", "title", "text", "source", "url", "published_at", "missing", "error")
		if string(fields["error"]) != "null" || string(fields["missing"]) != "[]" {
			t.Errorf("accepted item must contain explicit error:null and missing:[]: %s", item)
		}
	}
	var dataset NewsDataset
	if err := json.Unmarshal(response.Body.Bytes(), &dataset); err != nil {
		t.Fatal(err)
	}
	return dataset
}

func TestNewsDatasetLocalizesOnlyMetadata(t *testing.T) {
	for _, test := range []struct{ name, accept, locale string }{
		{"default", "", "ru"},
		{"Russian region", "ru-RU", "ru"},
		{"English region", "en-US", "en"},
		{"weighted Russian", "en;q=0.2, ru;q=0.9", "ru"},
		{"weighted English", "ru;q=0.2, en-GB;q=0.9", "en"},
		{"skip unsupported", "fr-FR, en;q=0.8", "en"},
		{"unsupported fallback", "es-ES", "ru"},
		{"wildcard fallback", "*", "ru"},
		{"invalid fallback", "en;q=broken", "ru"},
		{"zero quality excluded", "en;q=0, ru;q=1", "ru"},
	} {
		t.Run(test.name, func(t *testing.T) {
			var searches atomic.Int32
			provider := providerFunc(func(context.Context, string, string) (collector.Generation, error) {
				searches.Add(1)
				return collector.Generation{Text: `{"candidates":[{"url":"https://www.securitylab.ru/news/600000.php"}]}`}, nil
			})
			_, cfg := newServer(t, provider)
			cfg.SourceFetcher = researchFetchFunc(func(_ context.Context, address string) (news.Article, error) {
				return news.Article{URL: address, Title: "Исходный заголовок", Text: "Исходный текст: 12 серверов, по предварительным данным.", PublishedAt: time.Date(2026, 11, 8, 10, 0, 0, 0, time.UTC), DateEvidence: "HTML datePublished"}, nil
			})
			server, err := New(cfg, provider)
			if err != nil {
				t.Fatal(err)
			}
			request := httptest.NewRequest("POST", "/v1/collect", strings.NewReader(`{"control_time":"2026-11-08T10:48:16Z","query":"ИБ"}`))
			request.Header.Set("Authorization", "Bearer "+serviceToken)
			request.Header.Set("Accept-Language", test.accept)
			response := httptest.NewRecorder()
			server.ServeHTTP(response, request)
			dataset := decodeNewsDataset(t, response)
			if response.Header().Get("Content-Language") != test.locale || response.Header().Get("Vary") != "Accept-Language" {
				t.Fatalf("wrong locale headers: %v", response.Header())
			}
			wantTitle := "заголовок"
			wantDescription := "Исходные публикации"
			if test.locale == "en" {
				wantTitle = "title"
				wantDescription = "Source publications"
			}
			if dataset.Fields["title"] != wantTitle || !strings.HasPrefix(dataset.Description, wantDescription) {
				t.Fatalf("wrong metadata language: %s, %v", dataset.Description, dataset.Fields)
			}
			if len(dataset.Items) != 1 || dataset.Items[0].Text == nil || dataset.Items[0].Title != "Исходный заголовок" || *dataset.Items[0].Text != "Исходный текст: 12 серверов, по предварительным данным." {
				t.Fatalf("localization changed source material: %+v", dataset.Items)
			}
			run := savedRunFromCollection(t, server, response)
			if searches.Load() != 1 || run.Collection.Articles[0].Text != *dataset.Items[0].Text || run.Collection.Articles[0].ID != dataset.Items[0].ID {
				t.Fatal("metadata formatting or audit retrieval changed material or called the model")
			}
		})
	}
}
