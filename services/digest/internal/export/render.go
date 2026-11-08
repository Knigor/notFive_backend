package export

import (
	"encoding/csv"
	"fmt"
	"html"
	"maps"
	"regexp"
	"slices"
	"strings"
	"time"
	"unicode/utf8"

	"github.com/Knigor/notFive_backend/services/digest/internal/analysis"
)

var urlPattern = regexp.MustCompile(`https?://[^\s<>]+`)
var headingPattern = regexp.MustCompile(`(?m)^\s*#+\s*`)

func render(prepared Prepared, texts map[string]eventText, selectionSummary string) map[string]string {
	a := prepared.Request.Analysis
	period := displayTime(a.PeriodStart) + " – " + displayTime(a.ControlTime) + " МСК"
	items := make(map[string]analysis.Item, len(prepared.Request.News.Items))
	for _, item := range prepared.Request.News.Items {
		items[item.ID] = item
	}
	var digest, post strings.Builder
	fmt.Fprintf(&digest, "# Дайджест по кибербезопасности: %s\n\nКонтрольное время: %s МСК.\n\n", period, displayTime(a.ControlTime))
	fmt.Fprintf(&post, "Кибербезопасность за сутки: %s\n\n", period)
	count := len(a.RecommendedOrder)
	intro := fmt.Sprintf("В анализе выбрано событий: %d из максимум пяти.", count)
	if count == 0 {
		intro = "В переданном анализе нет выбранных событий. Дайджест пуст; причины исключения материалов приведены в журнале."
	} else if summary := plain(selectionSummary); summary != "" {
		intro += " " + summary
	} else if count < 5 {
		intro += " Экспорт использует готовый отбор и не добавляет события сверх него."
	}
	if len(prepared.issues) > 0 {
		intro += fmt.Sprintf(" Черновик: %d позиций требуют проверки источников.", len(prepared.issues))
	}
	digest.WriteString(intro + "\n\n")
	post.WriteString(intro + "\n\n")
	for index, id := range a.RecommendedOrder {
		group, text := a.Groups[id], texts[id]
		fmt.Fprintf(&digest, "## %d. %s\nСуть: %s\nПоследствия: %s\nИсточники: %s\nОбоснование: %s\n\n", index+1,
			plain(text.Title), plain(text.Summary), plain(text.Consequences), sources(group, items), plain(text.Reason))
		fmt.Fprintf(&post, "%d. %s\n%s\n", index+1, plain(text.Title), plain(text.Post))
		for _, articleID := range unique(group.IDs) {
			if item, exists := items[articleID]; exists && validURL(item.URL) {
				post.WriteString(item.URL + "\n")
			}
		}
		post.WriteByte('\n')
	}
	digestText := strings.TrimSpace(digest.String()) + "\n"
	return map[string]string{
		"digest.md":   digestText,
		"post.md":     strings.TrimSpace(post.String()) + "\n",
		"email.md":    fmt.Sprintf("Тема: Дайджест по кибербезопасности за %s\nПериод: %s\n\n%s", displayTime(a.ControlTime), period, digestText),
		"journal.csv": journal(prepared, texts),
	}
}

func sources(group analysis.Group, items map[string]analysis.Item) string {
	parts := []string{}
	for _, id := range unique(group.IDs) {
		item, exists := items[id]
		if !exists {
			parts = append(parts, "Источник отсутствует в news.json, ID "+plain(id))
			continue
		}
		date := "дата не подтверждена"
		if item.PublishedAt != nil {
			if _, err := time.Parse(time.RFC3339Nano, *item.PublishedAt); err == nil {
				date = displayTime(*item.PublishedAt) + " МСК"
			}
		}
		publisher := plain(item.Source)
		if publisher == "" {
			publisher = "издатель не указан"
		}
		link := "URL отсутствует или некорректен"
		if validURL(item.URL) {
			link = item.URL
		}
		parts = append(parts, fmt.Sprintf("%s, %s, %s, ID %s", publisher, date, link, plain(id)))
	}
	if len(parts) == 0 {
		return "Источники не указаны."
	}
	return strings.Join(parts, "; ")
}

func journal(prepared Prepared, texts map[string]eventText) string {
	var output strings.Builder
	output.WriteRune('\ufeff')
	w := csv.NewWriter(&output)
	w.Comma = ';'
	_ = w.Write([]string{"id", "status", "group", "reason", "error"})
	a := prepared.Request.Analysis
	groupIDs := slices.Sorted(maps.Keys(a.Groups))
	for _, item := range prepared.Request.News.Items {
		status := "исключён"
		groups, reasons, failures := []string{}, []string{}, []string{}
		selected := false
		for _, groupID := range groupIDs {
			group := a.Groups[groupID]
			if !slices.Contains(group.IDs, item.ID) {
				continue
			}
			groups = append(groups, groupID+" "+group.Name)
			if slices.Contains(a.RecommendedOrder, groupID) {
				selected = true
				if status == "исключён" {
					status = "объединён"
				}
				if len(group.IDs) > 0 && group.IDs[0] == item.ID {
					status = "включён"
				}
				reasons = append(reasons, groupID+": "+texts[groupID].Reason)
				if len(group.IDs) > 1 {
					reasons = append(reasons, "Материалы этой группы объединены; первый ID является основной строкой журнала, это не оценка независимости источников.")
				}
				if issues := prepared.issues[groupID]; len(issues) > 0 {
					failures = append(failures, groupID+": "+strings.Join(issues, ", "))
				}
			}
		}
		if reason := a.Excluded[item.ID]; reason != "" {
			reasons = append(reasons, "Решение анализа: "+reason)
			if selected {
				failures = append(failures, "analysis_inclusion_exclusion_conflict")
			}
		} else if !selected {
			if len(groups) > 0 {
				reasons = append(reasons, "Группа не входит в recommended_order переданного анализа; отдельная причина отказа не указана.")
			} else {
				reasons = append(reasons, "Анализ не объясняет судьбу материала; требуется повторная проверка.")
				failures = append(failures, "analysis_omitted_item")
			}
		}
		if reason := prepared.excluded[item.ID]; reason != "" {
			failures = append(failures, reason)
		}
		if item.Error != nil && *item.Error != "" {
			failures = append(failures, *item.Error)
		}
		if len(item.Missing) > 0 {
			failures = append(failures, "missing: "+strings.Join(item.Missing, ", "))
		}
		_ = w.Write([]string{item.ID, status, strings.Join(groups, "; "), dash(strings.Join(reasons, " ")), dash(strings.Join(failures, "; "))})
	}
	w.Flush() // strings.Builder cannot return a write error.
	return output.String()
}

func unique(values []string) []string {
	result := []string{}
	for _, value := range values {
		if !slices.Contains(result, value) {
			result = append(result, value)
		}
	}
	return result
}

func displayTime(value string) string {
	t, err := time.Parse(time.RFC3339Nano, value)
	if err != nil {
		return "дата не подтверждена"
	}
	return t.In(time.FixedZone("Europe/Moscow", 3*60*60)).Format("02.01.2006 15:04:05")
}

func dash(value string) string {
	return strings.NewReplacer("—", "-", "―", "-").Replace(value)
}

func plain(value string) string {
	value = urlPattern.ReplaceAllString(value, "[URL не используется в тексте]")
	return html.EscapeString(dash(strings.Join(strings.Fields(value), " ")))
}

func visibleLength(value string) int {
	value = urlPattern.ReplaceAllString(value, "")
	value = headingPattern.ReplaceAllString(value, "")
	value = strings.NewReplacer("**", "", "__", "", "\r", "", "\n", "").Replace(value)
	return utf8.RuneCountInString(value)
}
