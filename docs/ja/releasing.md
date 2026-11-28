# リリース

このページは、ブランチ・版の番号・Exment のリリースのしかたを書きます。
Pull Request の出し方は [CONTRIBUTING.ja.md](../../CONTRIBUTING.ja.md) を見てください。English: [../releasing.md](../releasing.md)

## ブランチ

ブランチは 2 種類だけです。

| ブランチ | 役目 |
|---|---|
| `develop/<版>`（例：`develop/7.3.0`） | その版で出すものを集めるところ。**Pull Request は全部、出したい版の `develop/<版>` に出します。** |
| `master` | 出した版。直接は push しません。リリースの Pull Request でだけ変わります。 |

- 作業のブランチの名前は、新しい機能は `feature/XXX`、不具合の直しは `fix/XXX` にして、Pull Request で `develop/<版>` に出します。
- つぎのパッチの版の `develop/<版>`（例：`develop/7.2.9`）は、そのリリースが要るときだけ作ります。そのブランチがないときは、小さな直しはつぎのマイナーの版（例：`develop/7.3.0`）だけに出します。
- `develop/<版>` が 2 つあるときは、古いほうに入った直しを、メンテナーが新しいほうにも入れます。
- 版を出して `master` に入れたら、その `develop/<版>` は消してかまいません。タグから作りなおせます。
- 古い系列は、脆弱性の直しだけです（[SECURITY.md](../../SECURITY.md)）。直しが要るときに、メンテナーがタグからブランチを作ります。

どの版に入れるかわからないときは、issue か Pull Request で聞いてください。

## 版の番号とタグ

| 種類 | タグ | GitHub のリリース |
|---|---|---|
| 正式版 | `vX.Y.Z`（例：`v7.2.8`） | ふつうのリリース |
| 試しの版（リリース候補） | `vX.Y.Z-rcN`（例：`v7.3.0-rc1`） | **プレリリース** |

- Composer と Packagist はタグを読みます。試しの版は、ふつうの設定の `composer update` では入りません。
- exment.net のインストール用の zip は、exment.net のサーバーで決まった時間に動く処理が作ります。プレリリースは飛ばすので、試しの版の zip はできません。

## だれが何をするか

| すること | だれ |
|---|---|
| `develop/<版>` への Pull Request | だれでも |
| `develop/<版>` へのマージ | メンテナー。CI が通り、見直しと動きの確かめがすんでから |
| リリースの Pull Request（`develop/<版>` → `master`） | メンテナーが用意する。`master` は承認のレビューが要るので、リポジトリの持ち主が承認してマージする |
| タグ・GitHub のリリース・exment.net・マニュアル | メンテナー。リリースの Pull Request がマージされてから |

## リリースの前の確かめ

- マイナーの版（例：7.3.0）は、先に試しの版のタグを付けて、試しの環境で確かめます。確かめているあいだ、その `develop/<版>` には、その版で直すと決めたものだけを入れます。
- パッチの版（例：7.2.8）は、CI が通り、メンテナーが動きを確かめたら出します。変更が大きいときは、試しの版を使います。
- [exment-plugins](https://github.com/exceedone/exment-plugins) の公式プラグインは、本体とは別に出すので、これを待ちません。

## リリースのチェックリスト

1. **最後のテスト**：`develop/<版>` の最新のコミットで CI が通り、試しの環境で主な画面が動く。
2. **リリースの Pull Request**：`develop/<版>` → `master`。リポジトリの持ち主が承認してマージする。
3. **タグと GitHub のリリース**：マージしたコミットに `vX.Y.Z` のタグを付け、英語と日本語のリリースノートで GitHub のリリースを公開する。試しの版は `vX.Y.Z-rcN` を付けて、プレリリースで公開する。
4. **exment.net のダウンロード**：決まった時間に動く処理が、zip（いちばん低い PHP の 8.2 用）と `exment.json`（版・大きさ・sha256）を作る。zip とダウンロードのページが新しい版になったか確かめる。
5. **What's new とお知らせ**：exment.net の What's new に版を足す。Exment のダッシュボードのお知らせは `https://exment.net/api/posts.json` を読むので、そこにも新しい版が出ているか確かめる。
6. **マニュアル**：[exment-manual](https://github.com/exceedone/exment-manual) にリリースノートをマージして、マニュアルを公開する。マージしただけでは公開されない。
7. **デモ**：デモのサイトを新しい版にする。
8. **issue**：この版で直した GitHub の issue に返事をして、閉じる。

## 7.2.x と 7.3

| 系列 | ブランチ | exment-admin-core | CI が使うもの |
|---|---|---|---|
| 7.2.x | `develop/7.2.<n>` | 1.x（`^1.0.6`） | admin-core の `1.x`、boilerplate の `7.2` |
| 7.3 | `develop/7.3.0` | 2.x（`^2.1.1`） | admin-core の `2.x`、boilerplate の `main` |

7.2 から 7.3 に上げるのは、**`composer update` だけではできません**（exment-admin-core が 2.x になるため）。マニュアルの [アップデート](https://exment.net/docs/ja/update.html) の手順で上げてください。

## 脆弱性の直し

出した版の脆弱性は、公開の Pull Request で直しません。GitHub の Security Advisory の非公開のフォークで直して、リリースと同じときに公開します。[SECURITY.md](../../SECURITY.md) を見てください。
