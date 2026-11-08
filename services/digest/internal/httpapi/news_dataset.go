package httpapi

import (
	"embed"
	"encoding/json"
	"maps"

	"golang.org/x/text/language"
)

// NewsDataset follows the organizers' news.json interchange format. Source
// content is copied from the collection; only descriptive metadata is localized.
type NewsDataset struct {
	Dataset     string            `json:"dataset"`
	ControlTime string            `json:"control_time"`
	PeriodStart string            `json:"period_start"`
	Timezone    string            `json:"timezone"`
	Description string            `json:"description"`
	Fields      map[string]string `json:"fields"`
	Items       []NewsItem        `json:"items"`
}

type NewsItem struct {
	ID          string   `json:"id"`
	Title       string   `json:"title"`
	Text        *string  `json:"text"`
	Source      string   `json:"source"`
	URL         string   `json:"url"`
	PublishedAt *string  `json:"published_at"`
	Missing     []string `json:"missing"`
	Error       *string  `json:"error"`
}

type newsMetadata struct {
	Description string            `json:"description"`
	Fields      map[string]string `json:"fields"`
}

//go:embed translations/news.*.json
var newsTranslationFiles embed.FS

var newsTranslations = func() map[string]newsMetadata {
	translations := make(map[string]newsMetadata, 2)
	for _, locale := range []string{"ru", "en"} {
		raw, err := newsTranslationFiles.ReadFile("translations/news." + locale + ".json")
		if err != nil {
			panic(err)
		}
		var metadata newsMetadata
		if err := json.Unmarshal(raw, &metadata); err != nil {
			panic(err)
		}
		translations[locale] = metadata
	}
	return translations
}()

func newsLocale(acceptLanguage string) string {
	tags, _, err := language.ParseAcceptLanguage(acceptLanguage)
	if err == nil {
		for _, tag := range tags {
			base, _, _ := tag.Raw()
			if locale := base.String(); locale == "ru" || locale == "en" {
				return locale
			}
		}
	}
	return "ru"
}

func newsDataset(collection *Collection, locale string) NewsDataset {
	metadata := newsTranslations[locale]
	dataset := NewsDataset{
		Dataset: "web", ControlTime: collection.ControlTime, PeriodStart: collection.WindowStart,
		Timezone: "Europe/Moscow", Description: metadata.Description,
		Fields: maps.Clone(metadata.Fields), Items: make([]NewsItem, 0, len(collection.Articles)),
	}
	for _, article := range collection.Articles {
		dataset.Items = append(dataset.Items, NewsItem{
			ID: article.ID, Title: article.Title, Text: new(article.Text), Source: article.Source,
			URL: article.URL, PublishedAt: new(article.PublishedAt), Missing: []string{}, Error: nil,
		})
	}
	return dataset
}
