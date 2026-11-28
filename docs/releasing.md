# Releasing

This page explains the branches, the version numbers and how a release of Exment is made.
How to send a pull request: [CONTRIBUTING.md](../CONTRIBUTING.md). Japanese: [ja/releasing.md](ja/releasing.md).

## Branches

There are only two kinds of branches.

| Branch | What it is |
|---|---|
| `develop/<version>` (for example `develop/7.3.0`) | Collects what ships in that version. **Every pull request goes to the `develop/<version>` of the version it should ship in.** |
| `master` | What has been released. Nothing is pushed to it directly; it changes only through the release pull request. |

- Work branches are named `feature/XXX` (new features) or `fix/XXX` (bug fixes) and go to a `develop/<version>` by pull request.
- A `develop/<version>` for the next patch version (for example `develop/7.2.9`) is made only when a release needs it. When no such branch exists, a small fix goes to the next minor version only (for example `develop/7.3.0`).
- When two `develop/<version>` branches exist, a fix merged into the older one is also brought into the newer one by the maintainers.
- After a version is released and merged into `master`, its `develop/<version>` may be deleted. It can be made again from the tag.
- Old version lines get security fixes only (see [SECURITY.md](../SECURITY.md)). The maintainers make a branch from the tag when a fix is needed.

When you are not sure which version a change belongs to, ask in the issue or the pull request.

## Version numbers and tags

| Kind | Tag | GitHub release |
|---|---|---|
| Release | `vX.Y.Z` (for example `v7.2.8`) | normal release |
| Release candidate | `vX.Y.Z-rcN` (for example `v7.3.0-rc1`) | **pre-release** |

- Composer and Packagist read the tags. A release candidate is not installed by `composer update` with the default stability.
- The installation zip on exment.net is made by a scheduled job on the exment.net server. It skips pre-releases, so a release candidate gets no zip.

### Pre-releases are not installed automatically

A release candidate (`vX.Y.Z-rcN`) is for testing only. Nothing picks it up by itself:

- `composer update` installs stable versions only (Composer's default `minimum-stability` is `stable`).
- The download zip on exment.net is built from stable releases only.
- The "new version" notice in Exment and the update scripts look at stable versions only.

To try a release candidate on a test site, require the exact version, for example
`composer require "exceedone/exment:7.3.0-rc1" "exceedone/exment-admin-core:^2.1.1" -W`, then `php artisan exment:update --force`. Back up first.

## Who does what

| Step | Who |
|---|---|
| Pull request to `develop/<version>` | anyone |
| Merge into `develop/<version>` | a maintainer, after the CI passes and the change is reviewed and tried |
| Release pull request `develop/<version>` → `master` | prepared by a maintainer. `master` requires an approving review, so the repository owner approves and merges it |
| Tag, GitHub release, exment.net, manual | a maintainer, after the release pull request is merged |

## Testing before a release

- Before a minor version (for example 7.3.0), a release candidate is tagged and tested on a test environment. While it is being tested, only the fixes agreed for that version go into its `develop/<version>`.
- A patch version (for example 7.2.8) is released when the CI passes and the maintainers have tried it. A release candidate is used when the change is large.
- The official plugins in [exment-plugins](https://github.com/exceedone/exment-plugins) are released on their own schedule and do not wait for this.

## Release checklist

1. **Final test**: the CI passes on the latest commit of `develop/<version>`, and the main screens work on a test environment.
2. **Release pull request**: `develop/<version>` → `master`. The repository owner approves and merges it.
3. **Tag and GitHub release**: tag `vX.Y.Z` on the merged commit and publish the GitHub release with notes in English and Japanese. For a release candidate, tag `vX.Y.Z-rcN` and publish it as a pre-release.
4. **exment.net download**: the scheduled job makes the zip (for the lowest supported PHP, 8.2) and `exment.json` (version, size, sha256). Check that the zip and the download page show the new version.
5. **What's new and the news feed**: add the version to What's new on exment.net. The news list on the Exment dashboard reads `https://exment.net/api/posts.json`, so check that it shows the new version too.
6. **Manual**: merge the release notes into [exment-manual](https://github.com/exceedone/exment-manual) and publish the manual. Merging alone does not publish it.
7. **Demo**: update the demo site to the new version.
8. **Issues**: reply to the GitHub issues fixed in the release, and close them.

## 7.2.x and 7.3

| Line | Branch | exment-admin-core | CI uses |
|---|---|---|---|
| 7.2.x | `develop/7.2.<n>` | 1.x (`^1.0.6`) | admin-core `1.x`, boilerplate `7.2` |
| 7.3 | `develop/7.3.0` | 2.x (`^2.1.1`) | admin-core `2.x`, boilerplate `main` |

Updating a site from 7.2 to 7.3 is **not a plain `composer update`**, because exment-admin-core moves to 2.x. Follow the update page of the manual: [Update](https://exment.net/docs/update.html) ([Japanese](https://exment.net/docs/ja/update.html)).

## Security fixes

A vulnerability in a released version is not fixed in a public pull request. It is fixed in the private fork of a GitHub Security Advisory and published together with the release. See [SECURITY.md](../SECURITY.md).
