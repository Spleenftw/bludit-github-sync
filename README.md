# bludit-github

A [Bludit](https://www.bludit.com) plugin that automatically exports your blog articles to a GitHub repository as Markdown files.

## What it does

Whenever you create, update, or delete an article in Bludit, the plugin pushes the change to a GitHub repository via the GitHub API. Each article is exported as its own folder containing an `index.md` file:

```
my-article/
  index.md
another-post/
  index.md
```

Each `index.md` contains a YAML frontmatter block followed by the article content:

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

Article content here...
```

## Installation

1. Copy the `bludit-github` folder into your Bludit `bl-plugins/` directory.
2. Go to **Admin → Plugins** and enable **GitHub Export**.
3. Open the plugin settings and fill in your GitHub credentials.

## Settings

| Field | Description |
|---|---|
| Personal Access Token | A GitHub token with `repo` scope. [Generate one here](https://github.com/settings/tokens/new). |
| Repository Owner | Your GitHub username or organization name. |
| Repository Name | The target repository (e.g. `my-blog`). |
| Branch | Branch to push to (default: `main`). |
| Path Prefix | Optional subfolder prefix. Leave empty to export at the repo root. |
| Export drafts | When checked, draft articles are also exported. |

## Bulk export

The settings page includes an **Export all articles now** button that pushes all your existing articles to GitHub in one go. Useful on first install or after changing the target repository.

## Behavior

- **On article save:** the article's `index.md` is created or updated in the repository.
- **On article delete:** the article's `index.md` is deleted from the repository (GitHub removes the empty folder automatically).
- **Errors** are displayed in the plugin settings panel and never interfere with Bludit's own save process.

## Requirements

- Bludit 3.20+
- PHP with `curl` extension enabled
