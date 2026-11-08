// Package analysis groups supplied news articles without fetching external data.
package analysis

type Dataset struct {
	Dataset     string            `json:"dataset"`
	ControlTime string            `json:"control_time"`
	PeriodStart string            `json:"period_start"`
	Timezone    string            `json:"timezone"`
	Description string            `json:"description"`
	Fields      map[string]string `json:"fields"`
	Items       []Item            `json:"items"`
}

type Item struct {
	ID          string   `json:"id"`
	Title       string   `json:"title"`
	Text        *string  `json:"text"`
	Source      string   `json:"source"`
	URL         string   `json:"url"`
	PublishedAt *string  `json:"published_at"`
	Missing     []string `json:"missing"`
	Error       *string  `json:"error"`
}

type Result struct {
	Dataset          string            `json:"dataset"`
	ControlTime      string            `json:"control_time"`
	PeriodStart      string            `json:"period_start"`
	Timezone         string            `json:"timezone"`
	Groups           map[string]Group  `json:"groups"`
	RecommendedOrder []string          `json:"recommended_order"`
	Excluded         map[string]string `json:"excluded"`
	Warnings         []string          `json:"warnings"`
}

type Group struct {
	Name            string          `json:"name"`
	IDs             []string        `json:"ids"`
	Facts           []Fact          `json:"facts"`
	Contradictions  []Contradiction `json:"contradictions"`
	SelectionReason string          `json:"selection_reason"`
}

type Fact struct {
	Statement string     `json:"statement"`
	SourceIDs []string   `json:"source_ids"`
	Evidence  []Evidence `json:"evidence"`
}

type Evidence struct {
	ArticleID string `json:"article_id"`
	Quote     string `json:"quote"`
}

type Contradiction struct {
	Description string   `json:"description"`
	SourceIDs   []string `json:"source_ids"`
}

type Prepared struct {
	Dataset  Dataset
	Input    string
	Excluded map[string]string // Internal reason keys, localized by Analyze.
	Locale   string
}
