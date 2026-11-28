# Exment への協力のしかた

Exment に協力していただき、ありがとうございます。English: [CONTRIBUTING.md](CONTRIBUTING.md)

## issue

- issue は日本語か英語で書いてください。できれば、版・再現の手順・エラーのログ（`storage/logs/laravel.log`）を書いてください。テンプレートがあります。
- **脆弱性は、公開の issue に書かないでください。** [SECURITY.md](SECURITY.md) を見てください。

## Pull Request

1. リポジトリをフォークして、開発の環境を作ります：[docs/ja/development.md](docs/ja/development.md)
2. ブランチを作ります。新しい機能は `feature/XXX`、不具合の直しは `fix/XXX` です。
3. Pull Request は、出したい版の **`develop/<版>`**（例：`develop/7.3.0`）に出します。`master` には出しません（`master` は出した版です）。ブランチとリリースは [docs/ja/releasing.md](docs/ja/releasing.md) にあります。
4. Pull Request のテンプレートに、何を・なぜ・どう確かめたかを書きます。

おねがい：

- 1 つの Pull Request には 1 つの話題だけ。同じ話題の小さな直しは、1 つの Pull Request にまとめてください。
- CI が動いているあいだは、なるべく push しないでください（push するたびに CI が初めから動きます）。
- ふるまいを変えたら、テストを足すか直してください：[document/ja/Test.md](document/ja/Test.md)
- push の前に php-cs-fixer でコードを整えてください（[docs/ja/development.md](docs/ja/development.md)）。
- 新しい言語のキーは、`ja` と `en` の両方に足してください（CI が確かめます）。
- 画面やメニューが変わる新しい機能は、はじめは「使わない」にしてください。更新しただけで、今のサイトが変わらないようにするためです。
- コミットや Pull Request に、「Generated with …」の行や、ツールの自動の共同作成者の行を入れないでください。

## CI

Pull Request のたびに、GitHub Actions が unit・feature・browser のテスト、PHPStan、php-cs-fixer、言語ファイルの確かめ、TypeScript の確かめを動かします。全部通り、メンテナーが見直したらマージします。ネットワークで落ちたジョブは、そのジョブだけ動かしなおします。

## 変更の記録

CHANGELOG のファイルはありません。版ごとの変更は、[GitHub のリリース](https://github.com/exceedone/exment/releases) と [マニュアルのリリースノート](https://exment.net/docs/ja/release_note.html) にあります。

## 行動規範

参加する人は、みな [行動規範（Code of Conduct）](CODE_OF_CONDUCT.md) を守ります。

## ライセンス

Pull Request を出すと、その内容は Exment と同じ [GPL-3.0](LICENSE) で公開されることに同意したことになります。
