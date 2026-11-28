<p align="center">
<a href="README.md">English</a> | <a href="README.ja.md">日本語</a> | <b>Tiếng Việt</b>
</p>

<p align="center">
<picture>
<source media="(prefers-color-scheme: dark)" srcset=".github/images/exment_logo_dark.svg">
<img src=".github/images/exment_logo.svg" alt="Exment" width="360">
</picture>
</p>

<h3 align="center">Web database mã nguồn mở, miễn phí, xây dựng hệ thống nghiệp vụ không cần lập trình (no-code)</h3>

<p align="center">
<a href="https://exment.net/vi/">Trang chính thức</a> ·
<a href="https://exment.net/vi/demo-env/">Demo</a> ·
<a href="https://exment.net/docs/#/">Hướng dẫn (tiếng Anh)</a> ·
<a href="https://exment.net/vi/whats-new/">What's new</a> ·
<a href="https://exment.net/vi/roadmap/">Lộ trình</a>
</p>

<p align="center">
<img src="https://exment.net/docs/img/view_kanban/kanban_board.png" alt="Kanban view của Exment" width="800">
</p>


## Exment là gì?
Exment là web database mã nguồn mở, miễn phí, giúp xây dựng hệ thống nghiệp vụ mà không cần lập trình. Chỉ bằng cài đặt trên màn hình, có thể gom về một chỗ các thông tin như khách hàng, dự án, đơn yêu cầu... trước đây quản lý rải rác bằng Excel hay nhiều file khác nhau.  
Có đủ các chức năng cần cho nghiệp vụ: workflow, Kanban, biểu đồ Gantt, SSO, xác thực 2 bước... Exment chạy trên máy chủ của công ty, trên 99% dịch vụ thuê máy chủ trong và ngoài Nhật Bản (XServer, Sakura Internet...) và trên cloud (AWS, Azure...), nên công ty tự giữ được dữ liệu. Dù số người dùng tăng bao nhiêu, chi phí cũng không tăng.

- **Miễn phí, chi phí không tăng theo số người dùng**: mã nguồn mở (GPL), không giới hạn số người dùng hay chức năng
- **Bắt đầu ngay với template nghiệp vụ** như Quản lý công việc, Quản lý khách hàng
- **Chạy trên máy chủ của công ty, tự giữ dữ liệu**: PHP (Laravel) với MySQL / MariaDB / SQL Server
- **Hơn 40.000 lượt cài đặt** bản mã nguồn mở (tổng trên Packagist, tính đến tháng 11/2026), hơn 200 lần nâng cấp phiên bản từ năm 2018

| | |
|---|---|
| Giới thiệu | [Trang chính thức](https://exment.net/vi/) · [Chức năng chính](https://exment.net/vi/functions/) · [Cách sử dụng](https://exment.net/vi/modelcases/) · [Tài liệu (PDF)](https://exment.net/assets/exment-flyer-vi.pdf) |
| Dùng thử | [Danh sách demo](https://exment.net/vi/demo-env/) · [Cài đặt (tiếng Anh)](https://exment.net/docs/#/quickstart) |
| Giá | [Giá, hỗ trợ, giấy phép](https://exment.net/vi/plan/) · [So sánh với dịch vụ khác](https://exment.net/vi/compare/) |
| AI | [AI-OCR](https://exment.net/vi/ai-ocr/) · [Exment AI Chatbot](https://exment.net/vi/exment-ai-chatbot/) |
| Tin mới | [What's new](https://exment.net/vi/whats-new/) · [Lộ trình](https://exment.net/vi/roadmap/) |
| Hướng dẫn | [Hướng dẫn sử dụng (tiếng Anh)](https://exment.net/docs/#/) · [tiếng Nhật](https://exment.net/docs/#/ja/) |
| Liên hệ | [Liên hệ](https://exment.net/vi/inquiry/) |

[Trang demo](https://exment.net/vi/demo-env/): dùng thử Exment trên trình duyệt (cách đăng nhập có trên trang).

Phát triển bởi [Kajitori Co., Ltd.](https://exment.net/company/) (tập đoàn Exceed One)


## Điểm nổi bật (7.2 và 7.3)
- **Cơ sở dữ liệu web không cần lập trình**: tạo bảng, cột, biểu mẫu và chế độ xem (danh sách, tổng hợp, lịch, kanban, biểu đồ Gantt) ngay trên màn hình
- **Workflow**: thiết kế luồng phê duyệt bằng sơ đồ
- **Quản lý chức năng**: chức năng mới ban đầu ở trạng thái tắt; quản trị viên chọn chức năng sẽ dùng, nên cập nhật không làm thay đổi trang của bạn
- **API key và máy chủ MCP**: dùng Exment từ hệ thống khác và từ công cụ AI
- **Chia sẻ tệp**: thư mục và tệp có phân quyền, liên kết chia sẻ, phiên bản cũ
- **Plugin chính thức miễn phí đi kèm**: Quản lý dự án, Groupware và Support Desk, chỉ được cài khi quản trị viên bật

Chi tiết từng phiên bản: [What's new](https://exment.net/vi/whats-new/) · [Ghi chú phát hành](https://exment.net/docs/release_note.html)


## Chức năng
- Dashboard
- Đăng ký dữ liệu từ màn hình
- Tạo bảng tùy chỉnh (custom table), cột tùy chỉnh (custom column)
- Template nghiệp vụ (Quản lý công việc, Quản lý khách hàng...)
- Nhập/xuất template (dùng bảng, cột tùy chỉnh ở Exment khác)
- Nhập/xuất dữ liệu
- Tính toán trong form (tổng tiền, tiền thuế...)
- Quản lý quyền
- Quản lý tổ chức
- Cài đặt menu
- Tìm kiếm (tìm theo từ khóa tự do, tìm thông tin liên quan)
- Mẫu email
- View (danh sách, tổng hợp, lịch, Kanban, biểu đồ Gantt) và kiểu ô
- Công cụ danh sách dữ liệu (cột hiển thị, cố định cột, nhóm, sửa trực tiếp, thao tác hàng loạt)
- Workflow (luồng phê duyệt có màn hình thiết kế luồng)
- Form công khai (đăng ký dữ liệu không cần đăng nhập)
- Thông báo (email, trên màn hình, Slack, Microsoft Teams, LINE)
- Bảo mật đăng nhập (xác thực 2 bước, SSO bằng SAML / LDAP / OAuth)
- Xuất chứng từ (PDF / Excel), sao lưu và khôi phục
- Plugin
- API

Và còn rất nhiều chức năng khác.

## Môi trường hoạt động
### Máy chủ
- PHP 8.2.0 trở lên
- MySQL 8.0 trở lên, hoặc MariaDB 10.11 trở lên
- Laravel 12.X

### Trình duyệt hỗ trợ
- Google Chrome
- Microsoft Edge

## Màn hình

### Danh sách dữ liệu
![Danh sách dữ liệu](https://exment.net/wp-content/uploads/2020/03/list_of_data.gif)  
  
### Sửa dữ liệu
![Sửa dữ liệu](https://exment.net/wp-content/uploads/2020/03/list_edit.gif)  
  
### Tìm kiếm
![Tìm kiếm](https://exment.net/wp-content/uploads/2020/03/search.gif)

### Dashboard
![Dashboard](https://exment.net/wp-content/uploads/2020/03/dashboard.gif)

### Lịch
![Lịch](https://exment.net/wp-content/uploads/2019/05/capture_7_calendarview.png)


## Bắt đầu nhanh (QuickStart)
> Cần chuẩn bị môi trường LAMP và cài composer. Chi tiết xem [hướng dẫn cài đặt (tiếng Anh)](https://exment.net/docs/#/quickstart).

- Tạo project Laravel bằng composer ("exment" là tên project).

~~~
composer create-project "laravel/laravel=12.*" exment
cd exment
~~~

- Thêm exceedone/exment bằng composer.

~~~
composer require exceedone/exment
php artisan vendor:publish --provider="Exceedone\Exment\ExmentServiceProvider"
~~~

- Sửa file .env.

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
APP_TIMEZONE=Asia/Ho_Chi_Minh
APP_LOCALE=en
~~~

> Giao diện Exment hiện có tiếng Anh (en) và tiếng Nhật (ja).

- (Khuyến nghị) Thêm trang lỗi. Laravel 12 không có `app/Exceptions/Handler.php`; xử lý ngoại lệ được cài đặt trong `bootstrap/app.php`. Sửa khối `withExceptions` như sau.

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


- Mở trang Exment.  
URL là http://127.0.0.1:8000/admin

~~~
php artisan serve
~~~

# Bảo mật
Nếu bạn tìm thấy lỗ hổng bảo mật, đừng mở issue công khai. Hãy báo riêng theo cách trong [SECURITY.md](SECURITY.md).

# Dành cho nhà phát triển
Chúng tôi luôn hoan nghênh Pull Request.

- [CONTRIBUTING.md](CONTRIBUTING.md): cách gửi Pull Request (nhánh nào, test, cách viết code)
- [docs/development.md](docs/development.md): cài đặt môi trường phát triển
- [docs/releasing.md](docs/releasing.md): nhánh, số phiên bản và cách phát hành
- [document/en/Test.md](document/en/Test.md): chạy test
- [Hướng dẫn sử dụng](https://exment.net/docs/vi/) · [Ghi chú phát hành](https://exment.net/docs/release_note.html) · [Thời gian hỗ trợ](https://exment.net/docs/support_policy.html)

Khi cài Exment, hãy dùng phiên bản đã phát hành (ví dụ `composer require exceedone/exment`), không dùng nhánh `-dev`.

# Cộng đồng
Hiện chưa có diễn đàn; hãy dùng issue trên GitHub. Vui lòng viết issue bằng tiếng Anh hoặc tiếng Nhật.  
Mọi người tham gia đều tuân theo [Quy tắc ứng xử (Code of Conduct)](CODE_OF_CONDUCT.md).


# Các repository liên quan

- **[exment-admin-core](https://github.com/exceedone/exment-admin-core)**  
Framework nền của Exment (namespace `ExmentAdminCore\Admin`). Fork từ [open-admin](https://github.com/open-admin-org/open-admin) / [open-admin-core](https://github.com/dedermus/open-admin-core), vốn phát triển từ [z-song/laravel-admin](https://github.com/z-song/laravel-admin).

- **[Hướng dẫn](https://github.com/exceedone/exment-manual)**  
Trang hướng dẫn sử dụng Exment.

- **[Update Batch](https://github.com/exment-git/batch-update)**  
Batch nâng cấp.

- **[Auto Composer](https://github.com/exment-git/auto-composer)**  
Tự động chạy composer.

- **[Docker](https://github.com/exment-git/docker-exment)**  
Docker cho Exment.

- **[Plugin Sample](https://github.com/exment-git/plugin-sample)**  
Mẫu plugin của Exment.

- **[Spout](https://github.com/exment-git/spout)**  
Thư viện nhập/xuất Excel.  
Fork từ [box/spout](https://github.com/box/spout). Chúng tôi fork vì box/spout đã được lưu trữ (archived).
