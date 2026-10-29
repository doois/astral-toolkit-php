<?php

declare(strict_types=1);

namespace App;

/**
 * The site's own interface strings, per locale. Content comes from the CMS; these
 * are the few words the templates need (navigation, pagination, labels). Add a
 * locale here when the project gains one; unknown locales fall back to "en".
 */
final class I18n
{
    private const EN = [
        'pageSegment' => 'page',
        'skipToContent' => 'Skip to content',
        'menu' => 'Menu',
        'language' => 'Language',
        'home' => 'Home',
        'blog' => 'Blog',
        'allPosts' => 'All posts',
        'categories' => 'Categories',
        'allCategories' => 'All',
        'readMore' => 'Read more',
        'by' => 'By',
        'publishedOn' => 'Published on',
        'updatedOn' => 'Updated on',
        'previous' => 'Previous',
        'next' => 'Next',
        'page' => 'Page %1$d of %2$d',
        'notFoundTitle' => 'Page not found',
        'notFoundText' => 'The address may have changed or the page may have been removed.',
        'backHome' => 'Back to the home page',
        'draftPreview' => 'Draft preview: this is not the published version.',
        'noPosts' => 'No posts yet.',
        'loading' => 'Loading',
        'relatedByCategory' => 'More in',
    ];

    private const PT_BR = [
        'pageSegment' => 'pagina',
        'skipToContent' => 'Ir para o conteúdo',
        'menu' => 'Menu',
        'language' => 'Idioma',
        'home' => 'Início',
        'blog' => 'Blog',
        'allPosts' => 'Todos os posts',
        'categories' => 'Categorias',
        'allCategories' => 'Todas',
        'readMore' => 'Ler mais',
        'by' => 'Por',
        'publishedOn' => 'Publicado em',
        'updatedOn' => 'Atualizado em',
        'previous' => 'Anterior',
        'next' => 'Próxima',
        'page' => 'Página %1$d de %2$d',
        'notFoundTitle' => 'Página não encontrada',
        'notFoundText' => 'O endereço pode ter mudado ou a página pode ter sido removida.',
        'backHome' => 'Voltar para o início',
        'draftPreview' => 'Pré-visualização de rascunho: esta não é a versão publicada.',
        'noPosts' => 'Ainda não há posts.',
        'loading' => 'Carregando',
        'relatedByCategory' => 'Mais em',
    ];

    private const STRINGS = ['en' => self::EN, 'pt-BR' => self::PT_BR, 'pt' => self::PT_BR];

    /** The names of the locales for the language switcher. */
    public const LOCALE_NAMES = ['pt-BR' => 'Português', 'pt' => 'Português', 'en' => 'English', 'es' => 'Español', 'nl' => 'Nederlands', 'fr' => 'Français', 'de' => 'Deutsch', 'it' => 'Italiano'];

    /** Month names and the long date pattern ({d}, {m}, {y}) when the intl extension is not there. */
    private const DATES = [
        'pt' => ['{d} de {m} de {y}', ['janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro']],
        'en' => ['{m} {d}, {y}', ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December']],
        'es' => ['{d} de {m} de {y}', ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre']],
        'nl' => ['{d} {m} {y}', ['januari', 'februari', 'maart', 'april', 'mei', 'juni', 'juli', 'augustus', 'september', 'oktober', 'november', 'december']],
        'fr' => ['{d} {m} {y}', ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre']],
        'de' => ['{d}. {m} {y}', ['Januar', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember']],
        'it' => ['{d} {m} {y}', ['gennaio', 'febbraio', 'marzo', 'aprile', 'maggio', 'giugno', 'luglio', 'agosto', 'settembre', 'ottobre', 'novembre', 'dicembre']],
    ];

    /** @return array<string, string> */
    public static function strings(string $locale): array
    {
        return self::STRINGS[$locale] ?? self::STRINGS[explode('-', $locale)[0]] ?? self::EN;
    }

    public static function t(string $locale, string $key): string
    {
        return self::strings($locale)[$key] ?? $key;
    }

    /** "Página 2 de 3". */
    public static function page(string $locale, int $current, int $total): string
    {
        return sprintf(self::strings($locale)['page'], $current, $total);
    }

    public static function localeName(string $locale): string
    {
        return self::LOCALE_NAMES[$locale] ?? $locale;
    }

    /** A date in the locale's long format ("14 de outubro de 2026"), read in UTC. */
    public static function formatDate(?string $value, string $locale): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        try {
            $date = new \DateTimeImmutable(strlen($value) === 10 ? "{$value}T12:00:00Z" : $value);
        } catch (\Exception) {
            return '';
        }
        $date = $date->setTimezone(new \DateTimeZone('UTC'));
        if (class_exists(\IntlDateFormatter::class)) {
            $formatter = new \IntlDateFormatter($locale, \IntlDateFormatter::LONG, \IntlDateFormatter::NONE, 'UTC');
            $out = $formatter->format($date);
            if (is_string($out)) {
                return $out;
            }
        }
        [$pattern, $months] = self::DATES[explode('-', $locale)[0]] ?? self::DATES['en'];
        return strtr($pattern, ['{d}' => $date->format('j'), '{m}' => $months[(int) $date->format('n') - 1], '{y}' => $date->format('Y')]);
    }
}
