# bludit-github

A [Bludit](https://www.bludit.com) plugin that automatically exports your blog articles to a GitHub repository as Markdown files.

## What it does

Whenever you create, update, or delete an article in Bludit, the plugin pushes the change to a GitHub repository via the GitHub API. Each article is exported as its own folder, with the markdown file inside `content/` and images inside `img/`:

```
my-article/
  content/
    index.md
  img/
    photo.png
    screenshot.jpg
another-post/
  content/
    index.md
```

Each `index.md` contains a YAML frontmatter block followed by the article content. Image references are written as relative paths from the `content/` subfolder:

```markdown
---
title: "My Article"
date: 2026-05-01
slug: my-article
status: published
tags:
  - php
  - bludit
description: "Optional meta description"
category: tech
---

Article content with images like ![](../img/photo.png)
```

## Installation

1. Copy the `bludit-github` folder into your Bludit `bl-plugins/` directory.
2. Go to **Admin → Plugins** and enable **GitHub Export**.
3. Open the plugin settings and fill in your GitHub credentials.

## Settings

| Field | Description |
|---|---|
| Personal Access Token | A GitHub token with `public_repo` scope (or `repo` if the repository is private). [Generate one here](https://github.com/settings/tokens/new). |
| Repository Owner | Your GitHub username or organization name. |
| Repository Name | The target repository (e.g. `my-blog`). |
| Branch | Branch to push to (default: `main`). |
| Path Prefix | Optional subfolder prefix. Leave empty to export at the repo root. |
| Auto-export draft articles on save | When checked, draft articles are automatically pushed to GitHub when saved or modified. **Default is OFF**. |
| Auto-export published articles on save | When checked, published articles are automatically pushed to GitHub when saved or modified. **Default is ON**. |

## Bulk export

The settings page includes two export buttons:
- **Export everything** — pushes all articles (both drafts and published) to GitHub in one go.
- **Export published only** — pushes only published articles to GitHub.

Useful on first install or after changing the target repository.

## Behavior

- **On article save** (if auto-export is enabled): `content/index.md` is created or updated, and all images referenced in the content are uploaded to `img/`.
- **On article delete** (if auto-export is enabled): both `content/index.md` and all files in `img/` are deleted (GitHub removes the empty folders automatically).
- **Manual bulk export:** Use the "Export everything" or "Export published only" button to push articles regardless of auto-export setting.
- **Errors** are displayed in the plugin settings panel and never interfere with Bludit's own save process.

## Requirements

- Bludit 3.20+
- PHP with `curl` extension enabled
