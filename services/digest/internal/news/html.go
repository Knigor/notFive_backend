package news

import (
	"encoding/json"
	"fmt"
	"io"
	"maps"
	"slices"
	"strings"
	"time"
	"unicode"
	"unicode/utf8"

	"golang.org/x/net/html"
)

type dateCandidate struct{ value, evidence string }

func parseHTML(reader io.Reader) (Article, error) {
	// HTML5 tree construction handles optional/misnested tags in real publisher
	// pages. The caller has already limited the downloaded document to 2 MiB.
	document, err := html.Parse(reader)
	if err != nil {
		return Article{}, ErrUnavailable
	}
	var dates []dateCandidate
	var ogTitle, pageTitle string
	var articleBodies, articles, mains, bodies []*html.Node
	for node := range document.Descendants() {
		if node.Type != html.ElementNode {
			continue
		}
		switch node.Data {
		case "meta":
			property := strings.ToLower(attribute(node, "property"))
			if property == "" {
				property = strings.ToLower(attribute(node, "name"))
			}
			content := strings.TrimSpace(attribute(node, "content"))
			if property == "article:published_time" || property == "datepublished" || hasWord(attribute(node, "itemprop"), "datePublished") {
				field := property
				if field == "" {
					field = "itemprop=datePublished"
				}
				dates = append(dates, dateCandidate{content, "meta " + field + "=" + content})
			}
			if property == "og:title" && ogTitle == "" {
				ogTitle = content
			}
		case "script":
			if strings.EqualFold(strings.TrimSpace(attribute(node, "type")), "application/ld+json") {
				var data any
				if json.Unmarshal([]byte(rawText(node)), &data) == nil {
					collectJSONDates(data, 0, &dates)
				}
			}
		case "time":
			if hasWord(attribute(node, "itemprop"), "datePublished") || hasWord(attribute(node, "property"), "datePublished") {
				value := strings.TrimSpace(attribute(node, "datetime"))
				dates = append(dates, dateCandidate{value, "time datePublished datetime=" + value})
			}
		case "article":
			articles = append(articles, node)
		case "main":
			mains = append(mains, node)
		case "body":
			bodies = append(bodies, node)
		case "title":
			if pageTitle == "" {
				pageTitle = rawText(node)
			}
		}
		if hasWord(attribute(node, "itemprop"), "articleBody") {
			articleBodies = append(articleBodies, node)
		}
	}
	published, evidence, err := publicationDate(dates)
	if err != nil {
		return Article{}, err
	}
	var selected *html.Node
	var text string
	for _, candidates := range [][]*html.Node{articleBodies, articles, mains, bodies} {
		for _, candidate := range candidates {
			candidateText := readableText(candidate)
			if len(candidateText) > len(text) {
				selected, text = candidate, candidateText
			}
		}
		if text != "" {
			break
		}
	}
	if text == "" {
		return Article{}, ErrUnavailable
	}
	title := ogTitle
	if title == "" && selected != nil {
		title = firstHeading(selected)
		for ancestor := range selected.Ancestors() {
			if title != "" {
				break
			}
			if ancestor.Data == "article" || ancestor.Data == "main" || ancestor.Data == "body" {
				title = firstHeading(ancestor)
			}
		}
	}
	if title == "" {
		title = pageTitle
	}
	text, truncated := truncateRunes(text, maxTextRunes)
	title, titleTruncated := truncateRunes(strings.Join(strings.Fields(title), " "), 500)
	return Article{Title: title, Text: text, PublishedAt: published, DateEvidence: evidence, TextTruncated: truncated || titleTruncated}, nil
}

func publicationDate(candidates []dateCandidate) (time.Time, string, error) {
	if len(candidates) > 256 {
		return time.Time{}, "", fmt.Errorf("%w: too many publication metadata entries", ErrUnknownDate)
	}
	var published time.Time
	var evidence []string
	for _, candidate := range candidates {
		value, err := parseExplicitDate(candidate.value)
		if err != nil {
			continue
		}
		if !published.IsZero() && !published.Equal(value) {
			return time.Time{}, "", fmt.Errorf("%w: publication metadata conflicts", ErrUnknownDate)
		}
		published = value
		if !slices.Contains(evidence, candidate.evidence) {
			evidence = append(evidence, candidate.evidence)
		}
	}
	if published.IsZero() {
		return time.Time{}, "", ErrUnknownDate
	}
	return published, strings.Join(evidence, "; "), nil
}

func parseExplicitDate(value string) (time.Time, error) {
	for _, layout := range []string{time.RFC3339Nano, "2006-01-02T15:04:05-0700", "2006-01-02 15:04:05-07:00"} {
		if parsed, err := time.Parse(layout, value); err == nil {
			return parsed, nil
		}
	}
	return time.Time{}, ErrUnknownDate
}

func collectJSONDates(value any, depth int, candidates *[]dateCandidate) {
	if depth > 32 || len(*candidates) > 256 {
		return
	}
	switch item := value.(type) {
	case map[string]any:
		if articleType(item["@type"]) {
			if date, ok := item["datePublished"].(string); ok {
				*candidates = append(*candidates, dateCandidate{date, "JSON-LD Article.datePublished=" + date})
			}
		}
		for _, key := range slices.Sorted(maps.Keys(item)) {
			collectJSONDates(item[key], depth+1, candidates)
		}
	case []any:
		for _, child := range item {
			collectJSONDates(child, depth+1, candidates)
		}
	}
}

func firstHeading(root *html.Node) string {
	for node := range root.Descendants() {
		if node.Type == html.ElementNode && node.Data == "h1" {
			if title := readableText(node); title != "" {
				return title
			}
		}
	}
	return ""
}

func articleType(value any) bool {
	switch types := value.(type) {
	case string:
		return types == "Article" || types == "NewsArticle" || types == "https://schema.org/Article" || types == "https://schema.org/NewsArticle" || types == "http://schema.org/Article" || types == "http://schema.org/NewsArticle"
	case []any:
		return slices.ContainsFunc(types, articleType)
	}
	return false
}

func attribute(node *html.Node, name string) string {
	for _, attr := range node.Attr {
		if attr.Key == name {
			return attr.Val
		}
	}
	return ""
}

func hasWord(value, word string) bool {
	for part := range strings.FieldsSeq(value) {
		if strings.EqualFold(part, word) {
			return true
		}
	}
	return false
}

func rawText(node *html.Node) string {
	var text strings.Builder
	for child := range node.Descendants() {
		if child.Type == html.TextNode {
			text.WriteString(child.Data)
		}
	}
	return text.String()
}

func readableText(node *html.Node) string {
	var text strings.Builder
	var visit func(*html.Node)
	visit = func(current *html.Node) {
		if current.Type == html.ElementNode && ignoredElement(current) {
			return
		}
		if current.Type == html.TextNode {
			text.WriteString(current.Data)
		}
		block := current.Type == html.ElementNode && slices.Contains([]string{"p", "div", "section", "article", "main", "li", "ul", "ol", "br", "h1", "h2", "h3", "h4", "blockquote", "tr"}, current.Data)
		if block {
			text.WriteByte(' ')
		}
		for child := current.FirstChild; child != nil; child = child.NextSibling {
			visit(child)
		}
		if block {
			text.WriteByte(' ')
		}
	}
	visit(node)
	return strings.Join(strings.Fields(text.String()), " ")
}

func ignoredElement(node *html.Node) bool {
	if slices.Contains([]string{"nav", "script", "style", "header", "footer", "aside", "form", "button", "noscript", "template", "svg", "canvas", "iframe", "head"}, node.Data) {
		return true
	}
	if attribute(node, "aria-hidden") == "true" {
		return true
	}
	for _, attr := range node.Attr {
		if attr.Key == "hidden" {
			return true
		}
	}
	for token := range strings.FieldsSeq(strings.ToLower(attribute(node, "class") + " " + attribute(node, "id"))) {
		if slices.Contains([]string{"ad", "ads", "advert", "advertisement", "banner", "menu", "sidebar", "share", "social"}, token) || strings.HasPrefix(token, "ad-") || strings.Contains(token, "cookie") || strings.Contains(token, "comment") || strings.Contains(token, "related") || strings.Contains(token, "recommend") {
			return true
		}
	}
	return false
}

func truncateRunes(text string, limit int) (string, bool) {
	if utf8.RuneCountInString(text) <= limit {
		return text, false
	}
	runes := []rune(text)
	end := limit
	for end > limit*4/5 && !unicode.IsSpace(runes[end-1]) {
		end--
	}
	if end <= limit*4/5 {
		end = limit
	}
	return strings.TrimSpace(string(runes[:end])), true
}
