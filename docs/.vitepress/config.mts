import { defineConfig, type DefaultTheme } from 'vitepress'
import { slug } from 'github-slugger'

const englishSidebar: DefaultTheme.SidebarItem[] = [
  {
    text: 'Guide',
    items: [
      { text: 'Overview', link: '/en/overview' },
      { text: 'Getting started', link: '/en/getting-started' },
      { text: 'Routing', link: '/en/routing' },
      { text: 'Middleware & PSR-15', link: '/en/middleware' },
      { text: 'Request', link: '/en/request' },
      { text: 'Context & responses', link: '/en/context-response' },
      { text: 'Context extensions & DI', link: '/en/context-extensions' },
      { text: 'Errors', link: '/en/errors' },
    ],
  },
  {
    text: 'Reference',
    items: [
      { text: 'Public API', link: '/en/api' },
      { text: 'Built-in middleware', link: '/en/builtin-middleware' },
      { text: 'Advanced middleware', link: '/en/advanced-middleware' },
      { text: 'Compatibility', link: '/en/compatibility' },
      { text: 'Migration', link: '/en/migration' },
    ],
  },
  {
    text: 'Development & deployment',
    items: [
      { text: 'Testing', link: '/en/testing' },
      { text: 'Development', link: '/en/development' },
      { text: 'Deployment & streaming', link: '/en/deployment' },
    ],
  },
]

const russianSidebar: DefaultTheme.SidebarItem[] = [
  {
    text: 'Руководство',
    items: [
      { text: 'Обзор', link: '/ru/overview' },
      { text: 'Начало работы', link: '/ru/getting-started' },
      { text: 'Маршрутизация', link: '/ru/routing' },
      { text: 'Middleware и PSR-15', link: '/ru/middleware' },
      { text: 'Запрос', link: '/ru/request' },
      { text: 'Контекст и ответы', link: '/ru/context-response' },
      { text: 'Расширения контекста и DI', link: '/ru/context-extensions' },
      { text: 'Обработка ошибок', link: '/ru/errors' },
    ],
  },
  {
    text: 'Справочник',
    items: [
      { text: 'Публичный API', link: '/ru/api' },
      { text: 'Встроенные middleware', link: '/ru/builtin-middleware' },
      { text: 'Дополнительные middleware', link: '/ru/advanced-middleware' },
      { text: 'Совместимость', link: '/ru/compatibility' },
      { text: 'Миграция', link: '/ru/migration' },
    ],
  },
  {
    text: 'Разработка и запуск',
    items: [
      { text: 'Тестирование', link: '/ru/testing' },
      { text: 'Разработка', link: '/ru/development' },
      { text: 'Запуск и streaming', link: '/ru/deployment' },
    ],
  },
]

export default defineConfig({
  title: 'php-hono',
  description: 'An HTTP framework for PHP 8.5 with Hono-style routing and PSR-15 middleware support.',
  lang: 'en',
  base: '/php-hono/',
  srcExclude: ['README.md'],
  markdown: {
    // Keep existing fragment links identical on GitHub and on the site, including й and ё.
    anchor: { slugify: slug },
  },
  locales: {
    en: {
      label: 'English',
      lang: 'en',
      link: '/en/',
    },
    ru: {
      label: 'Русский',
      lang: 'ru',
      link: '/ru/',
      description: 'HTTP-фреймворк для PHP 8.5 с маршрутизацией в стиле Hono и поддержкой PSR-15 middleware.',
      themeConfig: {
        nav: [
          { text: 'Руководство', link: '/ru/getting-started' },
          { text: 'API', link: '/ru/api' },
        ],
        sidebar: russianSidebar,
        outline: { label: 'На этой странице' },
        docFooter: { prev: 'Предыдущая страница', next: 'Следующая страница' },
        editLink: {
          pattern: 'https://github.com/avil13/php-hono/edit/master/docs/:path',
          text: 'Редактировать на GitHub',
        },
        sidebarMenuLabel: 'Меню',
        returnToTopLabel: 'Наверх',
        darkModeSwitchLabel: 'Оформление',
        darkModeSwitchTitle: 'Тёмная тема',
        lightModeSwitchTitle: 'Светлая тема',
        langMenuLabel: 'Выбрать язык',
        skipToContentLabel: 'Перейти к содержимому',
      },
    },
  },
  themeConfig: {
    nav: [
      { text: 'Guide', link: '/en/getting-started' },
      { text: 'API', link: '/en/api' },
    ],
    sidebar: englishSidebar,
    outline: { level: [2, 3] },
    socialLinks: [{ icon: 'github', link: 'https://github.com/avil13/php-hono' }],
    editLink: { pattern: 'https://github.com/avil13/php-hono/edit/master/docs/:path' },
    search: {
      provider: 'local',
      options: {
        locales: {
          ru: {
            translations: {
              button: { buttonText: 'Поиск', buttonAriaLabel: 'Поиск по документации' },
              modal: {
                displayDetails: 'Показать подробности',
                resetButtonTitle: 'Сбросить поиск',
                backButtonTitle: 'Закрыть поиск',
                noResultsText: 'Ничего не найдено по запросу',
                footer: {
                  selectText: 'выбрать',
                  selectKeyAriaLabel: 'Enter',
                  navigateText: 'перейти',
                  navigateUpKeyAriaLabel: 'Стрелка вверх',
                  navigateDownKeyAriaLabel: 'Стрелка вниз',
                  closeText: 'закрыть',
                  closeKeyAriaLabel: 'Escape',
                },
              },
            },
          },
        },
      },
    },
  },
})
