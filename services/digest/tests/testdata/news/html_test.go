package news

import (
	"errors"
	"strings"
	"testing"
	"time"
)

func TestPublicationMetadataRequiresExplicitTimezoneAndIgnoresModifiedDate(t *testing.T) {
	tests := map[string]struct {
		metadata string
		valid    bool
	}{
		"published meta":                    {`<meta property="article:published_time" content="2026-11-07T12:00:00+03:00">`, true},
		"microdata meta":                    {`<meta itemprop="datePublished" content="2026-11-07T09:00:00Z">`, true},
		"time published":                    {`<time itemprop="datePublished" datetime="2026-11-07T09:00:00Z">вчера</time>`, true},
		"unlabelled time":                   {`<time datetime="2026-11-07T09:00:00Z">вчера</time>`, false},
		"date only":                         {`<meta property="article:published_time" content="2026-11-07">`, false},
		"timezone missing":                  {`<meta property="article:published_time" content="2026-11-07T12:00:00">`, false},
		"modified only":                     {`<meta property="article:modified_time" content="2026-11-07T09:00:00Z"><time itemprop="dateModified" datetime="2026-11-07T09:00:00Z"></time>`, false},
		"jsonld graph":                      {`<script type="application/ld+json">{"@graph":[{"@type":"Organization","datePublished":"2020-01-01T00:00:00Z"},{"@type":["Thing","NewsArticle"],"datePublished":"2026-11-07T12:00:00+03:00","dateModified":"2026-11-08T00:00:00Z"}]}</script>`, true},
		"untyped jsonld":                    {`<script type="application/ld+json">{"datePublished":"2026-11-07T09:00:00Z"}</script>`, false},
		"jsonld modified only":              {`<script type="application/ld+json">{"@type":"NewsArticle","dateModified":"2026-11-07T09:00:00Z"}</script>`, false},
		"equivalent offsets":                {`<meta property="article:published_time" content="2026-11-07T09:00:00Z"><script type="application/ld+json">{"@type":"Article","datePublished":"2026-11-07T12:00:00+03:00"}</script>`, true},
		"conflicting dates":                 {`<meta property="article:published_time" content="2026-11-07T09:00:00Z"><script type="application/ld+json">{"@type":"Article","datePublished":"2026-11-07T13:00:00+03:00"}</script>`, false},
		"page visible date is insufficient": {`<p>7 ноября 2026 12:00, по данным модели</p>`, false},
	}
	want, _ := time.Parse(time.RFC3339, "2026-11-07T09:00:00Z")
	for name, test := range tests {
		t.Run(name, func(t *testing.T) {
			article, err := parseHTML(strings.NewReader(`<html><head>` + test.metadata + `</head><body><main><h1>Заголовок</h1><p>Текст страницы.</p></main></body></html>`))
			if !test.valid {
				if !errors.Is(err, ErrUnknownDate) {
					t.Fatalf("unconfirmed date accepted: %+v %v", article, err)
				}
				return
			}
			if err != nil || !article.PublishedAt.Equal(want) || article.DateEvidence == "" || article.Title != "Заголовок" {
				t.Fatalf("valid metadata failed: %+v %v", article, err)
			}
		})
	}
}

func TestArticleBodyPreferredAndEmptyBodyUnavailable(t *testing.T) {
	metadata := `<meta property="article:published_time" content="2026-11-07T09:00:00Z">`
	article, err := parseHTML(strings.NewReader(metadata + `<main><p>Общий список.</p><article><h1>Заголовок</h1><div itemprop="articleBody"><p>Настоящий <em>текст</em>.</p><nav>Меню</nav><span hidden>Скрытый текст</span></div><p>Рекомендации снаружи.</p></article></main>`))
	if err != nil || article.Text != "Настоящий текст." || article.Title != "Заголовок" {
		t.Fatalf("wrong content selection: %+v %v", article, err)
	}
	_, err = parseHTML(strings.NewReader(metadata + `<body><nav>Только меню</nav><script>var x=1</script></body>`))
	if !errors.Is(err, ErrUnavailable) {
		t.Fatalf("empty content accepted: %v", err)
	}
}
