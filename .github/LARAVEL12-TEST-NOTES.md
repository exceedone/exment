# Laravel 12 CI test branch — notes

**Branch `feature/laravel-12-test-ga` is a throwaway branch. Do NOT merge it into
`master`.** It exists only to prove that GitHub Actions goes green for the
Laravel 12 work while `exceedone/exment-boilerplate` still has Laravel 10 on its
default branch.

`feature/laravel-12-test-ga` = `feature/laravel-12` + the temporary lines listed
below.

## What was added on top of `feature/laravel-12`

In `.github/workflows/unittest.yml`, `.github/workflows/phpstan.yml` and
`.github/workflows/check-lang.yml`, the `exment-boilerplate` checkout step got a
pinned ref:

```yaml
      - uses: actions/checkout@v4
        with:
          repository: ${{ env.EXMENT_BOILERPLATE_REPOSITORY}}
          # ===== TEMPORARY: remove from here down to "END TEMPORARY" before merging into master =====
          # ...
          ref: feature/laravel-12
          # ===== END TEMPORARY =====
          path: ./docker-exment/exment-boilerplate
```

Without it the workflows check out the **default branch (`main`)** of
exment-boilerplate, which still ships the Laravel 10
`composer.dev.json` / `composer.dev.lock` — that is what produced

```
In ExmentServiceProvider.php line 36:
  Class "ExmentAdminCore\Admin\AdminServiceProvider" not found
```

plus this file (`.github/LARAVEL12-TEST-NOTES.md`).

## Release order

1. Merge `exceedone/exment-boilerplate` `feature/laravel-12` → `main`.
2. On `exceedone/exment` `feature/laravel-12`, do **not** copy anything from
   `feature/laravel-12-test-ga`: no `ref:` blocks, no this file.
3. Merge `exceedone/exment` `feature/laravel-12` → `master`.

After step 1 the workflows resolve exment-boilerplate `main` and pick up the
Laravel 12 files on their own, so the pinned refs are no longer needed.

---

## Ghi chú (Tiếng Việt)

**Nhánh `feature/laravel-12-test-ga` chỉ dùng để test GitHub Actions. KHÔNG merge
nhánh này vào `master`.**

Nhánh này = `feature/laravel-12` + phần tạm thời:

- 3 file `.github/workflows/unittest.yml`, `phpstan.yml`, `check-lang.yml`: thêm
  khối `ref: feature/laravel-12` (nằm giữa 2 dòng đánh dấu `TEMPORARY` và
  `END TEMPORARY`) vào bước checkout `exment-boilerplate`.
- File này (`.github/LARAVEL12-TEST-NOTES.md`).

Lý do: repo `exment-boilerplate` chưa merge `feature/laravel-12` vào `main`, nên
nếu không ghim `ref`, workflow sẽ checkout nhánh `main` (vẫn là Laravel 10) và CI
báo lỗi `Class "ExmentAdminCore\Admin\AdminServiceProvider" not found`.

Thứ tự khi phát hành:

1. Merge `exment-boilerplate` nhánh `feature/laravel-12` vào `main`.
2. Trên `exment` nhánh `feature/laravel-12`, KHÔNG mang theo bất cứ thay đổi nào
   của `feature/laravel-12-test-ga` (không có khối `ref:`, không có file này).
3. Merge `exment` nhánh `feature/laravel-12` vào `master`.

Sau bước 1, workflow tự lấy `main` của exment-boilerplate (đã là Laravel 12) nên
không cần ghim `ref` nữa.
