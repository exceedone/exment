# Security Policy / セキュリティポリシー

## Supported versions / サポートしている版

| Version | Supported |
| ------- | --------- |
| 7.0.x   | :white_check_mark: |
| 6.2.x   | :white_check_mark: security fixes only, until 2027-03-31 / 脆弱性の修正のみ、2027-03-31 まで |
| < 6.2   | :x: |

Please update to the latest release before reporting.  
報告の前に、できるだけ最新の版でお確かめください。

## Reporting a vulnerability / 脆弱性の報告

**Please do not report security vulnerabilities through public GitHub issues, discussions, or pull requests.**  
**脆弱性は、公開の issue・discussion・pull request には書かないでください。**

Report it privately in one of these ways: / 次のどちらかの方法で、非公開でご報告ください。

1. GitHub: open the **Security** tab of this repository and click **Report a vulnerability** (private vulnerability reporting).  
   このリポジトリの **Security** タブ → **Report a vulnerability** から報告する
2. Email: **info@exment.net**

Please include as much of the following as you can: / できるだけ次の内容を含めてください。

- Affected version (tag or commit) / 対象の版（タグ・コミット）
- Type of issue and its impact / 脆弱性の種類と影響
- Steps to reproduce (a local environment such as Docker is preferred) / 再現の手順（Docker などのローカル環境での確認を推奨）
- Affected source files or URLs, if known / 分かれば、該当するソースのファイル・URL
- A suggested fix, if you have one / 修正案があれば

Please test only on your own environment. Do not access systems or data of other users or of our services.  
確認はご自身の環境だけで行ってください。ほかの利用者や当社のサービスの環境・データにはアクセスしないでください。

## What to expect / 報告のあとの流れ

- We will acknowledge your report, usually within 5 business days. / 通常 5 営業日以内に、受け取ったことをご連絡します。
- We will investigate, keep you informed of our progress, and agree on a disclosure date with you. / 調査の状況をお知らせし、公開の時期をご相談します。
- Please do not disclose the issue publicly until a fixed release is available. / 修正版を公開するまでは、内容の公開を控えてください。
- After the fix is released, we publish a GitHub Security Advisory and credit the reporter if they wish. / 修正版の公開後、GitHub の Security Advisory を出し、ご希望があれば報告者のお名前を掲載します。

We do not currently offer a bug bounty program.  
現在、報奨金の制度は設けていません。
