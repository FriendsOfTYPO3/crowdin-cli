# Crowdin CLI for TYPO3

Command line tool to work with the TYPO3 Crowdin projects. It can

- list the projects and show their translation status per language,
- review the suggested translations (also guided, step by step), approve them, and revoke approvals made by mistake,
- clean up suggestions which merely repeat an approved translation or another suggestion,
- download the translation files of a project in a language, and upload them back as suggestions,
- report who proofreads and who contributes to a project.

It builds upon [`friendsoftypo3/crowdin-base`](https://github.com/FriendsOfTYPO3/crowdin-base).

## Contents

- [Requirements](#requirements)
- [Installation](#installation)
- [Usage](#usage)
  - [JSON output for scripts](#json-output-for-scripts)
  - [Guided review (interactive)](#guided-review-interactive)
  - [List projects](#list-projects)
  - [Translation status of a project](#translation-status-of-a-project)
  - [Review unapproved translations](#review-unapproved-translations)
  - [Approve translations](#approve-translations)
  - [Revoke approvals](#revoke-approvals)
  - [Clean up duplicate suggestions](#clean-up-duplicate-suggestions)
- [Translation files](#translation-files)
  - [Download translation files](#download-translation-files)
  - [Upload translation files](#upload-translation-files)
- [Glossary suggestions](#glossary-suggestions)
- [Reporting](#reporting)
  - [Proofreaders of a project](#proofreaders-of-a-project)
  - [Contributors of a project in a language](#contributors-of-a-project-in-a-language)
- [Development](#development)

## Requirements

- PHP 8.5 with the extensions `json` and `zip`
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

### JSON output for scripts

Every command except `interactive` accepts `--format=json`, to get one JSON document on stdout instead of tables:

```bash
./crowdin-cli project:status 368815 --format=json | jq '.languages[] | select(.approved < 100)'
./crowdin-cli translations:approve 368815 fr --user=jdoe --yes --format=json
```

- Progress and notes go to stderr, so stdout can be piped as is. Warnings are returned in a `warnings` key.
- On failure the exit code is not 0 and the document is `{"error": {"message": "..."}}`.
- A command cannot ask for confirmation in this mode: `translations:approve`, `translations:undo`, `translations:dedupe` and
  `translations:upload` require `--yes` (or `--dry-run`, for the ones which have it).
- `translations:approve` and `translations:upload` list what happened to every translation or file (`status`:
  `approved`, `uploaded`, `failed`, ...), also with `--dry-run`, where the status is `pending`.

### Guided review (interactive)

`interactive` (alias `review`) walks through the whole review workflow with questions, instead of you chaining the
commands described further down by hand:

```bash
./crowdin-cli interactive
./crowdin-cli interactive --language=fr                 # work on French only, no language question
./crowdin-cli interactive -P 368815 -L fr -u jdoe -s 20 # no question at all except what to approve
```

1. **Project**: shows the output of `projects:list` and asks for the project ID (or identifier). Skipped with `--project`.
2. **Status**: shows the output of `project:status` (only the given language with `--language`).
3. **Language**: lists the languages that have translations waiting for approval and asks which one to work on, by
   number or ID. Languages without anything pending are not offered, and with `--language` a language at 0 % ends the
   session ("nothing to do"). The percentages are rounded, so a language at 0 % may still hold a few pending
   translations: use `translations:unapproved` if in doubt.
4. **Suggester**: shows who suggested the pending translations and how many, most active first, and asks for a
   number (`a` for everybody, `b` to go back, `q` to quit). Skipped with `--user`. The equivalent
   `translations:unapproved` command is printed, so you can reuse it outside the guided mode.
5. **Review loop**: shows the translations of that suggester (grouped by file, ordered by path) in chunks of
   `--page-size` (default 10), with source and translation side by side, and asks what to do with the chunk:

   | Answer      | Action                                                                       |
   |-------------|------------------------------------------------------------------------------|
   | `a`         | Approve the whole chunk.                                                     |
   | `e 3,7`     | Approve all *except* these ones (numbers as displayed; ranges like `2-4` work too). |
   | `o 1-4,9`   | Approve *only* these ones.                                                   |
   | `d 2,5`     | Drop (permanently delete) these suggestions, after a confirmation. Cannot be undone. |
   | `s` (Enter) | Skip the chunk, they stay pending.                                           |
   | `v`         | Show details (translation ID, string ID, date, plural form), then ask again. |
   | `q`         | Quit.                                                                        |

   Chunks only hold strings with a *single* suggestion. Strings which have no translation yet come first, then the
   ones which already have an approved translation and only get a suggestion to improve it (shown in an extra
   "Current translation" column): those are less urgent.

   Strings for which *several* suggestions compete (Crowdin only lists one of them per string, the others are looked
   up for what you review) are reviewed afterwards, one at a time, showing the source, the current translation (if
   any) and every suggestion, oldest first:

   | Answer      | Action                                                                       |
   |-------------|------------------------------------------------------------------------------|
   | `2`         | Approve suggestion 2, then ask whether to permanently delete the competing ones (default: yes). |
   | `d 1,3`     | Drop (permanently delete) these suggestions, after a confirmation, then show what is left. |
   | `k`         | Only offered if the string has an approved translation: keep it and delete all suggestions, after a confirmation. |
   | `s` (Enter) | Skip the string, the suggestions stay pending.                               |
   | `q`         | Quit.                                                                        |

   After the last chunk the suggester list comes back (with updated counts), then the language list.

| Option                  | Description                                                                 |
|-------------------------|-----------------------------------------------------------------------------|
| `-P`, `--project=ID`    | Project ID (or identifier), skips the project question.                      |
| `-L`, `--language=ID`   | Only work on this language, skips the language question.                     |
| `-u`, `--user=USERNAME` | Only review the suggestions of this user, skips the suggester question.      |
| `-p`, `--path=PREFIX`   | Only review translations of files whose path starts with this value.         |
| `-s`, `--page-size=N`   | Translations per chunk (default: 10).                                        |
| `--dry-run`             | Go through everything without approving anything.                            |

Everything is loaded once per language and source texts once per suggester, and the competing suggestions and current
translations (two requests per string) are looked up once per suggester, so after the loading phase the chunks
follow each other without waiting. The command needs a terminal and refuses to run with `--no-interaction`; use
`translations:unapproved` and `translations:approve` for scripts.

Every approval made by `interactive` and `translations:approve` is recorded in `var/approvals.log` (see
[Revoke approvals](#revoke-approvals)). At the end of the session its ID is displayed.

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

Use `--language=fr` (`-L`) to only show one language.

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

For each string with unapproved translations the command shows the translation ID, who suggested it (name and
username), the date, the source text and the suggestion, so you can check that it looks fine. When several
suggestions compete for the same string they are all listed, oldest first, under a "N SUGGESTIONS FOR THIS STRING"
marker. When the string already has an approved translation it is shown in an extra "Current translation (approved)"
column, and such strings are listed after the strings which have no translation yet, since a suggestion which only
improves a translation is less urgent. Crowdin does not list a suggestion at all as long as its string has an approved
translation: those are looked up separately and added at the end, as far as `--limit` leaves room (the number of the
ones left out is shown). A summary of the number of pending strings per user follows the list (it counts one
suggestion per string, the one Crowdin lists, and none of the suggestions found behind an approved translation).

In the guided mode, such suggestions are added for the suggester you pick (or give with `--user`), since looking
them up costs two requests per string.

| Option                  | Description                                              |
|-------------------------|----------------------------------------------------------|
| `-u`, `--user=USERNAME` | Only show suggestions of this username (or numeric user ID). |
| `-p`, `--path=PREFIX`   | Only show translations of files whose path in Crowdin *starts with* this value. |
| `-l`, `--limit=N`       | Maximum number of strings displayed (default: 100). Files are cut in order of path, never in the middle of a string. |

The language is the Crowdin language ID, as shown by `project:status` (for example `de`, `fr`, `es-ES`).
The path filter matches the beginning of the file path as shown in the section headings of the output, for example
`--path=/14.3/typo3/sysext/backend` (a leading slash is optional). Filters can be combined, e.g. `--user` and `--path`.
Source texts of all pending strings are resolved first, then the competing suggestions of the displayed strings are
looked up (two requests per string), so large limits take a while.

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
| `-p`, `--path` | Approve all unapproved translations of files whose path starts with this value.  |
| `--drop-rivals`| Also permanently delete the other suggestions competing for the same strings. Cannot be undone. |
| `--dry-run`    | Only count what would be approved, nothing is changed.                      |
| `-y`, `--yes`  | Do not ask for confirmation.                                                |
| `-v`           | List every translation that is about to be approved (and, with `--drop-rivals`, deleted). |

Only translations that are currently unapproved in the given language are touched; other IDs are skipped with a
warning. Combining IDs with `--user` and/or `--path` approves only the given translations matching these filters.

When several suggestions compete for a string, `translations:unapproved` lists them all and you approve the one you
prefer by its ID. The others stay pending, unless you add `--drop-rivals`: they are then deleted right after the
approval succeeded (never if it failed), and the confirmation question tells how many will be deleted. Selecting two
competing suggestions of the same string together with `--drop-rivals` is refused, since one would delete the other:
approve one of them by ID. Deletions are not recorded in `var/approvals.log` and cannot be undone by
`translations:undo`; use `--dry-run -v` to see exactly what would be deleted first.

```bash
./crowdin-cli translations:approve 368815 de 1951695 --drop-rivals --dry-run -v
./crowdin-cli translations:approve 368815 de 1951695 --drop-rivals
```

Recommended workflow for bulk approval:

```bash
./crowdin-cli translations:unapproved 368815 de --user=jdoe      # review
./crowdin-cli translations:approve 368815 de --user=jdoe --dry-run -v   # check what would be approved
./crowdin-cli translations:approve 368815 de --user=jdoe          # approve (asks for confirmation)
```

Approve everything below a given path, optionally restricted to a user:

```bash
./crowdin-cli translations:approve 368815 de --path=/14.3/typo3/sysext/backend --dry-run
./crowdin-cli translations:approve 368815 de --path=/14.3/typo3/sysext/backend --user=jdoe
```

### Revoke approvals

Approvals made with `interactive` or `translations:approve` are logged in `var/approvals.log`, grouped by session (the
date and time of the run, for example `20261020-163045`). If you approved something by mistake, revoke the approvals of
a session:

```bash
./crowdin-cli translations:undo --list          # sessions which can be revoked
./crowdin-cli translations:undo                 # revoke the last session (asks for confirmation)
./crowdin-cli translations:undo 20261020-163045 # revoke a specific session
```

The translations are unapproved again and show up in `translations:unapproved`. Use `-y` to skip the confirmation.
Approvals that cannot be revoked stay in the log. Approvals done in the Crowdin web interface are not logged and cannot
be revoked with this command.

### Clean up duplicate suggestions

Suggestions pile up which add nothing: several users (or imports) suggest the same text for a string, or suggest
what is already the approved translation. `translations:dedupe` finds and deletes them:

```bash
./crowdin-cli translations:dedupe 368815 de --dry-run                  # the whole language, only show what would go
./crowdin-cli translations:dedupe 368815 de --path=/main/typo3/sysext/backend
./crowdin-cli translations:dedupe 368815 de --yes                      # no confirmation
```

A suggestion is deleted if, for the same string (and plural form):

- its text is identical to the **approved translation**, or
- its text is identical to an **older suggestion**: the oldest one is kept, so its author keeps the credit.

Texts are compared ignoring surrounding and repeated whitespace, nothing else (case and punctuation count). Only
suggestions of the *same* string are compared: the same translation suggested for two different strings which happen to
share a source text (for example several "Cancel" buttons) is not a duplicate, each string needs its suggestion.
Different suggestions are never touched, even if you disagree with them: review them with `translations:unapproved`.

| Option         | Description                                                                 |
|----------------|-----------------------------------------------------------------------------|
| `-p`, `--path` | Only clean up files whose path starts with this value.                      |
| `--dry-run`    | Only show what would be deleted, and why.                                   |
| `-y`, `--yes`  | Do not ask for confirmation (required with `--format=json`, unless `--dry-run`). |

The deletion is permanent and cannot be undone by `translations:undo`: always start with `--dry-run`. Finding the
suggestions costs two requests per string with pending suggestions, so on a whole language it takes a while: use
`--path` to go faster, or to clean up one area at a time.

## Translation files

Unlike the commands above, which work on the strings and translations stored in Crowdin, these ones exchange whole
translation files: you download the files of a language, work on them locally, then upload them back.

### Download translation files

```bash
./crowdin-cli translations:download 368815 fr                         # extracted to ./typo3-cms-fr/
./crowdin-cli translations:download typo3-cms fr -o /path/to/target   # extracted to the given directory
./crowdin-cli translations:download 368815 fr --approved-only -v      # skip suggestions, list the files
```

Asks Crowdin to build the translations of the project in the given language, waits for the build, downloads the
archive and extracts it. The files keep the structure and names Crowdin exports them with.

Without `--output-dir` (`-o`), the files go to a new directory named `<project identifier>-<language>` in the
current directory, so they never get mixed with other files. With `--output-dir`, that directory is used as is
(created if needed): files with the same name are overwritten, other files are left alone.

| Option                | Description                                                                    |
|-----------------------|--------------------------------------------------------------------------------|
| `-o`, `--output-dir`  | Directory to extract the files to.                                             |
| `--approved-only`     | Only export approved translations (by default, suggestions are included too).  |

There is no `--path` option, unlike in the other commands: Crowdin cannot build only a subtree of a project, so the
whole project would be built and downloaded anyway. Filtering what is extracted would only hide files locally, without
saving any time or bandwidth, and the exported paths (given by the export pattern of the project) do not necessarily
match the source paths `--path` works with elsewhere. Copy the folders you need from the downloaded directory instead.

The project can be given by ID or identifier. This needs the PHP extension `zip`.

### Upload translation files

```bash
./crowdin-cli translations:upload 368815 fr typo3-cms-fr --dry-run      # see what would be uploaded
./crowdin-cli translations:upload 368815 fr typo3-cms-fr                # upload everything that changed
./crowdin-cli translations:upload 368815 fr typo3-cms-fr -p master/Resources/Private/Language/fr.locallang.xlf   # a single file
```

The counterpart of `translations:download`: give the project, the language and the directory holding the files
(unchanged structure and names). For each file, one line tells what is going to be sent, then, after a confirmation,
one line per file shows the upload as it happens.

A file `<folder>/fr.locallang.xlf` belongs to the source file `/<folder>/locallang.xlf` of the project. Only files
which

- start with the language (`fr.`, so a file of another language is never uploaded to this one),
- have such a source file in the project, and
- are XLIFF files

are sent; any other file is listed as skipped, with the reason, and left alone. Of the matching files, only the
translations Crowdin does not already show for a string are uploaded (a reduced copy of the file is sent), so nothing
is suggested which is identical to what is approved, and files you did not touch upload nothing at all.

Translations are uploaded as **suggestions**, to be reviewed with `translations:unapproved` and approved like any
other. Translations identical to their source text are left out by default, as Crowdin would mark them untranslated;
use `--allow-same-as-source` when a text really is the same in the language.

| Option                   | Description                                                                         |
|--------------------------|-------------------------------------------------------------------------------------|
| `-p`, `--path=PREFIX`    | Only upload files whose path in the directory starts with this value.               |
| `--auto-approve`         | Approve the uploaded translations instead of suggesting them (the question says so). |
| `--allow-same-as-source` | Also upload translations identical to their source text.                            |
| `--dry-run`              | Only show what would be uploaded, nothing is sent.                                  |
| `-y`, `--yes`            | Do not ask for confirmation.                                                        |

Unlike for the download, `--path` does save time here, as files which are not selected are not even compared.
The token needs write access to the project translations. The whole comparison relies on the identifier of a
string being the `id` of its `trans-unit`, which is the case for the TYPO3 files.

## Glossary suggestions

In the Crowdin web interface, the pending approvals of a language list a "Glossary" entry next to your source files.
Crowdin stores the glossary of a project as a regular file (title "Glossary", path `/typo3-cms.tbx` in the TYPO3
projects) whose strings are the glossary terms. Suggested glossary translations are therefore listed and approved
like any other translation, under the path `/typo3-cms.tbx`:

```bash
./crowdin-cli translations:unapproved 368815 fr --path=/typo3-cms.tbx
./crowdin-cli translations:approve 368815 fr --path=/typo3-cms.tbx --dry-run -v
./crowdin-cli interactive -P 368815 -L fr --path=/typo3-cms.tbx   # or review them chunk by chunk
```

Without `--path`, glossary entries are mixed in the list with the other files, and the default `--limit` may hide
them. Note that this is different from the glossary resource itself (*Resources > Glossaries*), whose terms and
concepts have no review state in the Crowdin API.

## Reporting

Overviews of the people behind a project, rather than steps of the translation workflow.

### Proofreaders of a project

```bash
./crowdin-cli project:proofreaders 368815
./crowdin-cli project:proofreaders 368815 --language=fr
```

Shows, per language, the members with the proofreader permission for it (`-` if there is none). The owner and the
managers can proofread every language and are listed below the table instead of in every row. Blocked and pending
members are ignored.

This reads the members of the project (`GET /projects/{projectId}/members`), which is more restricted than the other
commands: the token needs a scope that allows reading project members (most likely *Project Members* in the token
scopes, the Crowdin documentation does not state it in a form we could verify) and its owner needs to be a manager of
the project. Without it, the command stops with a message mentioning HTTP 401 (invalid token), 403 (missing scope or
role) or 404 (unknown project) instead of a table.

### Contributors of a project in a language

```bash
./crowdin-cli project:contributors 368815 fr
./crowdin-cli project:contributors 368815 fr --by=approved --from=2026-01-01 --limit=50
```

Ranks the members who contributed to a project in one language. Both the project and the language are required: the
data comes from the "Top Members" report of Crowdin, which Crowdin generates on request for one project and language
(a few API calls and a few seconds each time), so there is no overview of all languages at once.

| Column      | Description                                                                          |
|-------------|--------------------------------------------------------------------------------------|
| Rank        | Position, contributors with the same figures share their rank.                       |
| Translated  | Translations (or suggestions) the member made.                                       |
| Approved    | Translations the member approved (as proofreader), not the approvals they received.  |
| Votes       | Votes the member cast on translations of others.                                     |

| Option                | Description                                                                          |
|-----------------------|--------------------------------------------------------------------------------------|
| `-b`, `--by=VALUE`    | Rank by `translated` (default) or `approved`.                                        |
| `--unit=VALUE`        | Count `strings` (default), `words`, `chars` or `chars_with_spaces`.                  |
| `--from=YYYY-MM-DD`   | Only count contributions as of this date (UTC). By default, since the project exists. |
| `--to=YYYY-MM-DD`     | Only count contributions until the end of this day (UTC).                            |
| `-l`, `--limit=N`     | Maximum number of contributors displayed (default: 20), `0` for all.                  |

Members without any activity are left out. Note that automated accounts, such as the `TYPO3` account which imports
translations in bulk, rank like anyone else.

Like `project:proofreaders`, this needs a token allowed to generate project reports (most likely the *Reports* scope, to
be verified in the Crowdin documentation) and the manager role in the project. Errors such as 401, 403 and 404 are
reported with a message instead of a ranking.

## Development

```bash
composer cs      # check coding style
composer csfix   # fix coding style
composer stan    # static analysis (PHPStan)
composer tests   # unit tests (PHPUnit)
```
