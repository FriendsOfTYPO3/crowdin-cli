# Crowdin CLI for TYPO3

Command line tool to inspect the translation status of the TYPO3 Crowdin projects and to review and approve
suggested translations. It builds upon [`friendsoftypo3/crowdin-base`](https://github.com/FriendsOfTYPO3/crowdin-base).

## Requirements

- PHP 8.5 with the extension `json`
- [Composer](https://getcomposer.org/)
- A Crowdin personal access token with access to the TYPO3 projects. To approve translations, the token owner needs
  the proofreader (or manager) role in these projects. You can generate such a token (scope "Projects") in Crowdin from
  [User Account > Settings > API](https://crowdin.com/settings#api-key)

## Installation

```bash
composer install
cp .env.example .env
```

Edit `.env`:

| Variable               | Description                                                                          |
|------------------------|--------------------------------------------------------------------------------------|
| `CROWDIN_ACCESS_TOKEN` | Your Crowdin access token (Crowdin: *Account Settings → API*).                       |
| `CONFIGURATION_FILE`   | Path of the project configuration file, relative to the project root. Keep the default `var/configuration.json`. |

Never commit `.env`, it contains your token.

Then fetch the list of projects once and store it locally:

```bash
php bin/crowdinSetup
```

Run it again whenever projects are added or languages change (or use `./crowdin-cli projects:list --refresh`).

## Usage

All commands are run with `./crowdin-cli <command>` (in DDEV: `ddev exec ./crowdin-cli <command>`). Use `./crowdin-cli list` to see them and
`./crowdin-cli <command> --help` for all options.

### List projects

```bash
./crowdin-cli projects:list
./crowdin-cli projects:list --refresh   # update the local configuration from Crowdin first
./crowdin-cli projects:list -v          # also show the languages of every project
```

The ID in the first column is what the other commands expect as `<projectId>`.

### Translation status of a project

```bash
./crowdin-cli project:status 368815
```

Shows, per language, the percentage of strings that are

- **Approved**,
- **Translated, not approved** (a translation exists but still waits for approval),
- **Translated (total)**, the sum of both.

### Review unapproved translations

```bash
./crowdin-cli translations:unapproved <projectId> <language>
./crowdin-cli translations:unapproved 368815 de
./crowdin-cli translations:unapproved 368815 de --user=jdoe --limit=500
```

For each unapproved translation the command shows the translation ID, who suggested it (name and username), the
date, the source text and the translation, so you can check that it looks fine. A summary of the number of pending
translations per user follows the list.

| Option                  | Description                                              |
|-------------------------|----------------------------------------------------------|
| `-u`, `--user=USERNAME` | Only show suggestions of this username (or numeric user ID). |
| `-l`, `--limit=N`       | Maximum number of translations displayed (default: 100). |

The language is the Crowdin language ID, as shown by `project:status` (for example `de`, `fr`, `es-ES`).
Source texts are fetched one by one, so large limits take a while.

### Approve translations

Approve specific translations, using the IDs shown by `translations:unapproved`:

```bash
./crowdin-cli translations:approve 368815 de 1949785 1941579
```

Approve everything a given user suggested in one language:

```bash
./crowdin-cli translations:approve 368815 de --user=jdoe
```

| Option         | Description                                                                 |
|----------------|-----------------------------------------------------------------------------|
| `-u`, `--user` | Approve all unapproved translations suggested by this username (or user ID). |
| `--dry-run`    | Only count what would be approved, nothing is changed.                      |
| `-y`, `--yes`  | Do not ask for confirmation.                                                |
| `-v`           | List every translation that is about to be approved.                        |

Only translations that are currently unapproved in the given language are touched; other IDs are skipped with a
warning. Combining IDs and `--user` approves only the given translations that were suggested by that user.

Recommended workflow for bulk approval:

```bash
./crowdin-cli translations:unapproved 368815 de --user=jdoe      # review
./crowdin-cli translations:approve 368815 de --user=jdoe --dry-run -v   # check what would be approved
./crowdin-cli translations:approve 368815 de --user=jdoe          # approve (asks for confirmation)
```

## Development

```bash
composer cs      # check coding style
composer csfix   # fix coding style
composer stan    # static analysis (PHPStan)
composer tests   # unit tests (PHPUnit)
```
