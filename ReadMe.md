<p align="center">
<b>English</b> | <a href="ReadMe.ja.md">日本語</a> | <a href="ReadMe.vi.md">Tiếng Việt</a>
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


## What's new in v7.0.0
Released on October 28, 2026. See how work is progressing at a glance. ([What's new](https://exment.net/en/whats-new/))

### Kanban view
Show records as cards in columns by status or another field, and drag them to move. Works together with workflow. ([Manual](https://exment.net/docs/#/view_kanban)) The screenshot at the top of this page is the kanban view.

### Gantt chart view
Show each record as a bar on a timeline from start date to due date. Supports colors, progress, milestones and parent-child indentation. ([Manual](https://exment.net/docs/#/view_gantt))

### Visual workflow designer
Place statuses and actions on a diagram and design your workflow while seeing the whole flow. ([Manual](https://exment.net/docs/#/workflow_design))  
![Workflow designer](https://exment.net/docs/img/workflow_design/wf_designer.png)

### Cell styles
Set up presets that change cell colors and text in the data grid and Kanban based on their values. ([Manual](https://exment.net/docs/#/cell_style_preset))  
![Cell styles](https://exment.net/docs/img/cell_style_preset/preset_grid_result.png)

### Data grid tools
Show or hide columns, freeze columns, group rows, edit inline, quick preview, bulk actions and more. ([Manual](https://exment.net/docs/#/data_grid_tools))  
![Data grid tools](https://exment.net/docs/img/data_grid_tools/grid_overview.png)

### Laravel 12 and PHP 8.2+
The framework is updated to Laravel 12 and the admin UI libraries are refreshed. Please check the notes before updating. ([Update](https://exment.net/docs/#/update))


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

# Community
Exment does not currently have a community forum. I am taking advantage of issues. However, we may use some other platform in the future. If you have detailed knowledge, I would be grateful if you could give me a recommendation.

## Pull Request
Pull Requests are always welcome. Currently, I'd be happy if you actually implemented the function rather than requesting a new function.

## Branches
| Branch | Packagist | What it is |
|---|---|---|
| `master` | `dev-master` | **The next version. Send every pull request here**, new features and fixes alike |
| `7.1` | `7.1.x-dev` | Maintenance of 7.1 (7.1.1, 7.1.2 ...) |
| `7.0` | `7.0.x-dev` | Maintenance of 7.0 (7.0.4, 7.0.5 ...) |
| `6.2` | `6.2.x-dev` | Maintenance of 6.2 (6.2.16, 6.2.17 ...) |

A fix that a released version also needs goes to `master` first. Add the label `backport-7.1` / `backport-7.0` / `backport-6.2` to the pull request, and the maintainers pick it into that branch.

To install Exment, use a released version (for example `composer require exceedone/exment`), not a `-dev` one. Details: [Develop.md](document/en/Develop.md)


# Other pages
Setup for Develop [En](document/en/Develop.md) / [日本語](document/ja/Develop.md)  
Setup for Test [En](document/en/Test.md) / [日本語](document/ja/Test.md)  


# issues
Please write issues using English or Japanese.  
Security vulnerabilities: see [SECURITY.md](SECURITY.md).


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

