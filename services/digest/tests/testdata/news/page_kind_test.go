package news

import "testing"

func TestIsListingURLRejectsHomeAndKnownSections(t *testing.T) {
	for _, raw := range []string{
		"https://xakep.ru/",
		"https://www.securitylab.ru",
		"https://unknown.example/",
		"https://habr.com.attacker.example/ru/news/",
		"https://unconfigured.example/news/",
		"https://www.securitylab.ru/news/",
		"https://WWW.SECURITYLAB.RU/news/?page=2#headlines",
		"https://www.securitylab.ru/%6Eews/",
		"https://xakep.ru/tag/news/",
		"https://xakep.ru/category/news/",
		"https://habr.com/ru/",
		"https://habr.com/ru/news/",
		"https://habr.com/ru/news/top/daily/",
		"https://habr.com/ru/hubs/infosecurity/",
		"https://habr.com/ru/hubs/infosecurity/news/",
		"https://habr.com/ru/hubs/infosecurity/articles/",
		"https://habr.com/ru/companies/pt/articles/",
		"https://ptsecurity.com/research/analytics/",
		"https://ptsecurity.com/about/news/",
		"https://www.ptsecurity.com/ru-ru/research/analytics/",
		"https://global.ptsecurity.com/en/research/analytics/",
		"https://www.kaspersky.ru/about/press-releases",
		"https://www.kaspersky.ru/about/press-releases?year=2026",
	} {
		t.Run(raw, func(t *testing.T) {
			if !IsListingURL(raw) {
				t.Fatal("known listing accepted as a potential article")
			}
		})
	}
}

func TestIsListingURLPreservesArticlesAndUnknownPaths(t *testing.T) {
	for _, raw := range []string{
		"https://www.securitylab.ru/news/579794.php",
		"https://habr.com/ru/news/1105010/",
		"https://HABR.COM/ru/news/1105010/?utm_source=search#comments",
		"https://habr.com/ru/articles/986800/",
		"https://habr.com/ru/companies/pt/articles/1008318/",
		"https://ptsecurity.com/research/analytics/russia-cyberthreat-landscape-2026/",
		"https://ptsecurity.com/about/news/new-incident/",
		"https://www.kaspersky.ru/about/press-releases/new-research",
		"https://xakep.ru/2026/11/08/news-from-company/",
		"https://xakep.ru/tag/news/article-suffix/",
		"https://securitylab.ru/unknown/article/",
		"https://unconfigured.example/index.php?article=12",
		"https://nothabr.com/ru/news/top/daily/",
		"https://habr.com/ru/hubs/infosecurity/news/unknown-path/",
		"https://habr.com/%zz",
		"/ru/news/",
		"",
	} {
		t.Run(raw, func(t *testing.T) {
			if IsListingURL(raw) {
				t.Fatal("article or unknown path blocked by listing heuristic")
			}
		})
	}
}
