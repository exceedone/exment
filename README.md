<p align="center">
<b>English</b> | <a href="README.ja.md">日本語</a> | <a href="README.vi.md">Tiếng Việt</a>
</p>

<p align="center">
<picture>
<source media="(prefers-color-scheme: dark)" srcset=".github/images/exment_logo_dark.svg">
<img src=".github/images/exment_logo.svg" alt="Exment" width="360">
</picture>
</p>

<h3 align="center">Free, open-source, no-code web database</h3>

<p align="center">
<a href="https://exment.net/en/">Official site</a> ·
<a href="https://exment.net/en/demo-env/">Demo</a> ·
<a href="https://exment.net/docs/#/">Manual</a> ·
<a href="https://exment.net/en/whats-new/">What's new</a> ·
<a href="https://exment.net/en/roadmap/">Roadmap</a>
</p>

<p align="center">
<img src="https://exment.net/docs/img/view_kanban/kanban_board.png" alt="Exment kanban view" width="800">
</p>


## What is Exment?
Exment is a free, open-source web database that lets you build business applications without programming. Bring customers, deals, requests and other information scattered across Excel sheets and files into one place, just by configuring screens.  
It comes with everything business teams need: workflow, Kanban, Gantt charts, SSO, two-factor authentication and more. Exment runs on your own server, on 99% of shared hosting providers in Japan and abroad, or in the cloud (AWS, Azure, etc.), so your data stays with you. And no matter how many users you add, the price never goes up.

- **Free, with no per-user fees**: open source (GPL), with no limits on users or features
- **Get started fast with business templates** such as Task Management and Customer Management
- **Runs on your own server, so you own your data**: PHP (Laravel) with MySQL / MariaDB / SQL Server
- **40,000+ installs** of the open-source edition (Packagist, as of November 2026) and 200+ releases since 2018

| | |
|---|---|
| Learn | [Official site](https://exment.net/en/) · [Features](https://exment.net/en/functions/) · [Use cases](https://exment.net/en/modelcases/) · [Flyer (PDF)](https://exment.net/assets/exment-flyer-en.pdf) |
| Try | [Demo sites](https://exment.net/en/demo-env/) · [Installation](https://exment.net/docs/#/quickstart) |
| Pricing | [Pricing, support and license](https://exment.net/en/plan/) · [Compare](https://exment.net/en/compare/) |
| AI | [AI-OCR](https://exment.net/en/ai-ocr/) · [Exment AI Chatbot](https://exment.net/en/exment-ai-chatbot/) |
| News | [What's new](https://exment.net/en/whats-new/) · [Roadmap](https://exment.net/en/roadmap/) |
| Docs | [Manual](https://exment.net/docs/#/) |
| Contact | [Contact us](https://exment.net/en/inquiry/) |

<a href="https://demo.exment.net/admin" target="_blank">Demo site</a>  
Then please enter  
Usercode：admin  
Password：adminadmin

Developed by [Kajitori Co., Ltd.](https://exment.net/company/) (Exceed One group)


## Highlights (7.2 and 7.3)
- **No-code web database**: make tables, columns, forms and views (list, summary, calendar, kanban, Gantt chart) on the screen
- **Workflow**: approval flows drawn in a visual designer
- **Feature management**: new features start turned off; administrators choose what to use, so an update does not change your site
- **API keys and MCP server**: use Exment from other systems and from AI tools
- **File sharing**: folders and files with permissions, shared links and versions
- **Free official plugins, bundled**: Project Management, Groupware and Support Desk, installed only when an administrator turns them on

Details of each version: [What's new](https://exment.net/en/whats-new/) · [Release notes](https://exment.net/docs/release_note.html)


## Functions
- Dashboard
- Data registration from the screen
- Custom table/Custom column creation
- Business templates (Task Management, Customer Management, ...)
- Import/export templates (Custom tables/Columns can be used with other Exments)
- Import/export data
- Value calculation function in form (total amount, calculation of tax amount)
- Authorization management
- Organization management
- Menu configuration management
- Search (free word search, search words related to information)
- Mail Template
- Views (list, summary, calendar, kanban, Gantt chart) and cell styles
- Data grid tools (column visibility, freeze, grouping, inline edit, bulk actions)
- Workflow (approval flow with a visual designer)
- Public form (data entry without login)
- Notifications (mail, in-app, Slack, Microsoft Teams, LINE)
- Login security (2-factor authentication, SSO with SAML / LDAP / OAuth)
- Document output (PDF / Excel), backup and restore
- Plugins
- API

And more and more and more functions....

## Operating environment
### Server
- PHP 8.2.0 or upper
- MySQL 8.0 or upper, or MariaDB 10.11 or upper
- Laravel 12.X

### Support Browser
- Google Chrome
- Microsoft Edge

## Screen Shot

### List of Data
![List of Data](https://exment.net/wp-content/uploads/2020/03/list_of_data.gif)  
  
### Data Edit
![Data Edit](https://exment.net/wp-content/uploads/2020/03/list_edit.gif)  
  
### Search
![Search](https://exment.net/wp-content/uploads/2020/03/search.gif)

### Dashboard
![Dashboard](https://exment.net/wp-content/uploads/2020/03/dashboard.gif)

### Calendar
![Calendar](https://exment.net/wp-content/uploads/2019/05/capture_7_calendarview.png)


## QuickStart
> You need set up LAMP and install composer.

- Create Laravel project using composer. ("exment" is project name.)

~~~
composer create-project "laravel/laravel=12.*" exment
cd exment
~~~

- Require exceedone/exment using composer.

~~~
composer require exceedone/exment
php artisan vendor:publish --provider="Exceedone\Exment\ExmentServiceProvider"
~~~

- Edit .env file.

~~~
### Database setting
# Change database setting
# If your database is MySQL, as below
DB_CONNECTION=mysql
# If your database is MariaDB, as below.
DB_CONNECTION=mariadb

DB_HOST=127.0.0.1 #MySQL host name
DB_PORT=3306 #MySQL port no.
DB_DATABASE=homestead #MySQL database name for Exment.
DB_USERNAME=homestead #MySQL user name for Exment.
DB_PASSWORD=secret #MySQL password name for Exment.

### timezone and locale
APP_TIMEZONE=America/Santiago
APP_LOCALE=en
~~~

- (Recommend) Add error page. Laravel 12 has no `app/Exceptions/Handler.php`; exception handling is configured in `bootstrap/app.php`. Modify the `withExceptions` block.

~~~ php
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Http\Request;

    ->withExceptions(function (Exceptions $exceptions) {
        // Add
        $exceptions->render(function (\Throwable $e, Request $request) {
            // Returning null falls back to Laravel's default rendering.
            return \Exment::error($request, $e, function ($request, $e) {
                return null;
            });
        });
    })
~~~


- Access exment page.  
URL is http://127.0.0.1:8000/admin

~~~
php artisan serve
~~~

# Security
If you find a security vulnerability, please do not open a public issue. Report it privately as described in [SECURITY.md](SECURITY.md).

# For developers
Pull requests are welcome. Implementing a feature is even better than requesting it.

- [CONTRIBUTING.md](CONTRIBUTING.md): how to send a pull request (which branch, tests, coding style)
- [docs/development.md](docs/development.md): set up a development environment
- [docs/releasing.md](docs/releasing.md): branches, versions and how a release is made
- [document/en/Test.md](document/en/Test.md): run the tests
- [Manual](https://exment.net/docs/) · [Release notes](https://exment.net/docs/release_note.html) · [Supported versions](https://exment.net/docs/support_policy.html)

To install Exment, use a released version (for example `composer require exceedone/exment`), not a `-dev` branch.

# Community
Exment does not have a community forum yet; please use GitHub issues. Write issues in English or Japanese.  
Everyone taking part follows the [Code of Conduct](CODE_OF_CONDUCT.md).


# Other repositories

- **[exment-admin-core](https://github.com/exceedone/exment-admin-core)**  
Based Exment's framework (namespace `ExmentAdminCore\Admin`). Forked from [open-admin](https://github.com/open-admin-org/open-admin) / [open-admin-core](https://github.com/dedermus/open-admin-core), which in turn derive from [z-song/laravel-admin](https://github.com/z-song/laravel-admin).

- **[Manual](https://github.com/exceedone/exment-manual)**  
For exment manual page.

- **[Update Batch](https://github.com/exment-git/batch-update)**  
Update batch.

- **[Auto Composer](https://github.com/exment-git/auto-composer)**  
Call auto composer.

- **[Docker](https://github.com/exment-git/docker-exment)**  
Exment dockers.

- **[Plugin Sample](https://github.com/exment-git/plugin-sample)**  
Exment plugin's samples.

- **[Spout](https://github.com/exment-git/spout)**  
Excel import and export library.  
Forked from [box/spout](https://github.com/box/spout). We forked because box/spout is archived.

