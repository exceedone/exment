# Contributing to Exment

Thank you for helping Exment. Japanese: [CONTRIBUTING.ja.md](CONTRIBUTING.ja.md)

## Issues

- Write issues in English or Japanese, with the version, the steps to reproduce and the error log (`storage/logs/laravel.log`) if you can. The templates help.
- **Security vulnerabilities: do not open a public issue.** See [SECURITY.md](SECURITY.md).

## Pull requests

1. Fork the repository and set up a development environment: [docs/development.md](docs/development.md).
2. Make a branch: `feature/XXX` for a new feature, `fix/XXX` for a bug fix.
3. Send the pull request to the **`develop/<version>`** branch of the version it should ship in (for example `develop/7.3.0`). Not to `master`: `master` is what has been released. Branches and releases: [docs/releasing.md](docs/releasing.md).
4. Fill in the pull request template: what, why, and how you tested it.

Please:

- Keep one topic per pull request, and bundle small fixes of the same topic into one pull request.
- Push as little as possible while the CI runs (each push restarts it).
- Add or update tests when you change behaviour: [document/en/Test.md](document/en/Test.md).
- Format the code with php-cs-fixer before you push ([docs/development.md](docs/development.md#before-you-commit-php-cs-fixer)).
- Add every new language key to both `ja` and `en` (the CI checks it).
- A new feature that changes screens or menus starts turned off, so that updating does not change existing sites.
- Do not add lines such as "Generated with ..." or automatic co-author lines for tools to commits or pull requests.

## CI

GitHub Actions runs on every pull request: unit, feature and browser tests, PHPStan, php-cs-fixer, the language file check and the TypeScript check. A pull request is merged when they pass and a maintainer has reviewed it. When a job fails because of the network, only that job is run again.

## Release notes

There is no CHANGELOG file. The changes of each version are in the [GitHub releases](https://github.com/exceedone/exment/releases) and in the [release notes of the manual](https://exment.net/docs/release_note.html).

## Code of Conduct

Everyone taking part follows the [Code of Conduct](CODE_OF_CONDUCT.md).

## License

By sending a pull request you agree that your contribution is licensed under the [GPL-3.0](LICENSE), as Exment is.
