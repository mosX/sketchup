<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

## Крепёж через MCP

Сервер принимает запросы на `/api/mcp/woodworking`. Инструкции доступны при инициализации клиента, а схемы аргументов — в списке инструментов.

1. Вызовите `woodworking_get_project`, чтобы получить ID экземпляров деталей. Для просмотра и редактирования общего каталога нужен глобальный ключ. Используйте `woodworking_list_material_catalog`, `woodworking_create_catalog_fastener` или `woodworking_update_catalog_fastener`.
2. Вызовите `woodworking_place_fasteners` с координатами U/V на грани детали. Для ряда укажите `count`, `row_axis` и `spacing`. Чтобы проверить геометрию и получить предложения по второй детали, добавьте `suggest_targets: true` к предпросмотру. Для записи повторите вызов без этого параметра с `dry_run: false` и `expected_revision` из ответа. Передайте предложенные цели через `target_instance_id` для одной точки или через `row_target_instance_ids` для ряда.
3. Для копирования используйте `woodworking_copy_fastener_row`. `mirror_u` и `mirror_v` отражают ряд. Параметр `suggest_targets: true` работает и при предпросмотре копии. Список целей идёт в порядке возрастания исходных ID крепежа.
4. Для отверстий под шурупы вызовите `woodworking_prepare_fastener_drilling` сначала в режиме предпросмотра. Готовые шурупы и гвозди можно проверить инструментом `woodworking_analyze_fasteners`. Поле `approximate` указывает, что для части деталей расчёт выполнен по габаритам.

Для геометрического анализа и подбора второй детали PHP запускает Node.js из `PATH`. На сервере должны быть установлены зависимости проекта через `npm ci`. Если PHP не видит Node.js, задайте полный путь к исполняемому файлу в `WOODWORKING_NODE_BINARY`.

## Материалы через MCP

В общем каталоге `woodworking_list_material_catalog` показывает позиции и их ID. Через `woodworking_create_catalog_material` и `woodworking_update_catalog_material` можно добавлять и редактировать доски, листы и расходники. Для каталога нужен глобальный ключ, для записи — право `projects:write`. Цена доски или листа задаётся за штуку, цена расходника — за упаковку.

Для материала по умолчанию, назначений заготовкам и расходников проекта сначала вызовите `woodworking_get_project_materials`. Затем отправьте полный список через `woodworking_update_project_materials`, сохранив в нём нужные текущие позиции. Подтвердите результат вызовом с `dry_run: false` и `expected_revision` из предпросмотра. Количество расходника указывается в единице каталога.

## About Laravel

Laravel is a web application framework with expressive, elegant syntax. We believe development must be an enjoyable and creative experience to be truly fulfilling. Laravel takes the pain out of development by easing common tasks used in many web projects, such as:

- [Simple, fast routing engine](https://laravel.com/docs/routing).
- [Powerful dependency injection container](https://laravel.com/docs/container).
- Multiple back-ends for [session](https://laravel.com/docs/session) and [cache](https://laravel.com/docs/cache) storage.
- Expressive, intuitive [database ORM](https://laravel.com/docs/eloquent).
- Database agnostic [schema migrations](https://laravel.com/docs/migrations).
- [Robust background job processing](https://laravel.com/docs/queues).
- [Real-time event broadcasting](https://laravel.com/docs/broadcasting).

Laravel is accessible, powerful, and provides tools required for large, robust applications.

## Learning Laravel

Laravel has the most extensive and thorough [documentation](https://laravel.com/docs) and video tutorial library of all modern web application frameworks, making it a breeze to get started with the framework.

In addition, [Laracasts](https://laracasts.com) contains thousands of video tutorials on a range of topics including Laravel, modern PHP, unit testing, and JavaScript. Boost your skills by digging into our comprehensive video library.

You can also watch bite-sized lessons with real-world projects on [Laravel Learn](https://laravel.com/learn), where you will be guided through building a Laravel application from scratch while learning PHP fundamentals.

## Agentic Development

Laravel's predictable structure and conventions make it ideal for AI coding agents like Claude Code, Cursor, and GitHub Copilot. Install [Laravel Boost](https://laravel.com/docs/ai) to supercharge your AI workflow:

```bash
composer require laravel/boost --dev

php artisan boost:install
```

Boost provides your agent 15+ tools and skills that help agents build Laravel applications while following best practices.

## Contributing

Thank you for considering contributing to the Laravel framework! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

If you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell via [taylor@laravel.com](mailto:taylor@laravel.com). All security vulnerabilities will be promptly addressed.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
