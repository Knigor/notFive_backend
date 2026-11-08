package analysis

import (
	"bytes"
	_ "embed"
	"encoding/json"
)

const SchemaName = "news_analysis_v2"

//go:embed schema.json
var modelSchema []byte

func init() {
	if !json.Valid(modelSchema) {
		panic("invalid embedded news analysis schema")
	}
}

// ModelSchema describes the provider's array-based response. Analyze converts it
// to the public map-based result without changing the model's facts or quotes.
func ModelSchema() json.RawMessage {
	return json.RawMessage(bytes.Clone(modelSchema))
}
