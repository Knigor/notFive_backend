package export

import (
	"embed"
	"encoding/json"
	"fmt"
)

//go:embed translations/*.json
var translationFiles embed.FS

var translations = func() map[string]map[string]string {
	all := map[string]map[string]string{}
	for _, locale := range []string{"ru", "en"} {
		raw, err := translationFiles.ReadFile("translations/" + locale + ".json")
		if err != nil {
			panic(err)
		}
		var values map[string]string
		if err := json.Unmarshal(raw, &values); err != nil {
			panic(err)
		}
		all[locale] = values
	}
	return all
}()

func message(locale, key string, args ...any) string {
	if locale != "en" {
		locale = "ru"
	}
	return fmt.Sprintf(translations[locale][key], args...)
}
