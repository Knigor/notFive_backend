import unittest

from .retrieval import retrieve_glossary


class GlossaryRetrievalTests(unittest.TestCase):
    def test_matches_russian_word_forms_and_english_phrases(self):
        result = retrieve_glossary(
            'Подбором паролей скомпрометировали учётные записи',
            'Сообщение о компрометации учетных записей, повышении привилегий и spear-phishing.',
        )
        by_name = {section['name']: section['matched_terms'] for section in result['sections']}
        self.assertIn('компрометация учётной записи', by_name['Уязвимости и компрометация'])
        self.assertIn('повышение привилегий', by_name['Уязвимости и компрометация'])
        self.assertIn('spear phishing', by_name['Атаки и вредоносное ПО'])

    def test_ignores_substrings_and_keeps_context_rule_without_matches(self):
        result = retrieve_glossary('Описание Ubisoft и social-сервиса', 'Игра вышла в раннем доступе.')
        self.assertEqual([], result['sections'])
        self.assertIn('учётная запись', result['context_rule'])

    def test_verb_variant_returns_canonical_term(self):
        result = retrieve_glossary(None, 'На уязвимой системе локальный пользователь может повысить привилегии.')
        self.assertEqual(['повышение привилегий'], result['sections'][0]['matched_terms'])

    def test_ambiguous_word_returns_dictionary_context_only(self):
        result = retrieve_glossary('Сертификаты на новый курс', 'Выпускникам выдали сертификат.')
        self.assertEqual(['Данные и доступ'], [section['name'] for section in result['sections']])
        self.assertIn('только в публикациях об информационной безопасности', result['context_rule'])

    def test_title_matches_rank_first_and_context_is_bounded(self):
        result = retrieve_glossary('LLM security', 'Фишинг, эксплойт, пароль, WAF, КИИ.')
        self.assertEqual(3, len(result['sections']))
        self.assertEqual('Безопасность ИИ', result['sections'][0]['name'])
