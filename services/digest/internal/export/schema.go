package export

import (
	"bytes"
	_ "embed"
	"encoding/json"
)

const SchemaName = "news_export_v1"

//go:embed schema.json
var modelSchema []byte

func ModelSchema() json.RawMessage { return bytes.Clone(modelSchema) }
