package httpapi

import "strings"

// researchJSONPayload accepts a JSON document with an optional whole Markdown
// code fence. It does not extract arbitrary objects from surrounding prose or
// concatenate multiple blocks; malformed responses still use the audit fallback.
func researchJSONPayload(text string) string {
	trim := func(value string) string {
		return strings.TrimSpace(strings.TrimPrefix(strings.TrimSpace(value), "\uFEFF"))
	}
	text = trim(text)
	lines := strings.Split(text, "\n")
	if len(lines) < 3 || strings.TrimSpace(lines[len(lines)-1]) != "```" {
		return text
	}
	info, fenced := strings.CutPrefix(strings.TrimSpace(lines[0]), "```")
	info = strings.TrimSpace(info)
	if !fenced || (info != "" && !strings.EqualFold(info, "json")) {
		return text
	}
	return trim(strings.Join(lines[1:len(lines)-1], "\n"))
}
