<?php

class pluginBluditGithub extends Plugin
{
    public function init()
    {
        $this->dbFields = [
            'token'        => '',
            'owner'        => '',
            'repo'         => '',
            'branch'       => 'main',
            'path'         => '',
            'exportDrafts' => false,
            'autoExport'   => true,
        ];
    }

    public function form()
    {
        $token        = $this->getValue('token');
        $owner        = $this->getValue('owner');
        $repo         = $this->getValue('repo');
        $branch       = $this->getValue('branch');
        $path         = $this->getValue('path');
        $exportDrafts = $this->getValue('exportDrafts') ? 'checked' : '';
        $status       = $this->getStatus();

        $html  = '<div>';
        $html .= '<label>GitHub Personal Access Token</label>';
        $html .= '<input name="token" type="password" value="' . htmlspecialchars($token) . '" placeholder="ghp_...">';
        $html .= '<span class="tip">Requires <strong>public_repo</strong> scope (or <strong>repo</strong> for private repositories). <a href="https://github.com/settings/tokens/new" target="_blank">Generate token</a></span>';
        $html .= '</div>';

        $html .= '<div>';
        $html .= '<label>Repository Owner</label>';
        $html .= '<input name="owner" type="text" value="' . htmlspecialchars($owner) . '" placeholder="username or org">';
        $html .= '</div>';

        $html .= '<div>';
        $html .= '<label>Repository Name</label>';
        $html .= '<input name="repo" type="text" value="' . htmlspecialchars($repo) . '" placeholder="my-blog">';
        $html .= '</div>';

        $html .= '<div>';
        $html .= '<label>Branch</label>';
        $html .= '<input name="branch" type="text" value="' . htmlspecialchars($branch) . '" placeholder="main">';
        $html .= '</div>';

        $html .= '<div>';
        $html .= '<label>Path Prefix <em>(optional)</em></label>';
        $html .= '<input name="path" type="text" value="' . htmlspecialchars($path) . '" placeholder="Leave empty to export at repo root">';
        $html .= '<span class="tip">Each article is exported as <code>{key}/index.md</code>. Set a prefix (e.g. <code>articles</code>) to nest under a subfolder.</span>';
        $html .= '</div>';

        $html .= '<div>';
        $html .= '<label><input name="exportDrafts" type="hidden" value="0">';
        $html .= '<input name="exportDrafts" type="checkbox" value="1" ' . $exportDrafts . '> Export draft articles</label>';
        $html .= '</div>';

        $autoExport   = $this->getValue('autoExport') ? 'checked' : '';
        $html .= '<div>';
        $html .= '<label><input name="autoExport" type="hidden" value="0">';
        $html .= '<input name="autoExport" type="checkbox" value="1" ' . $autoExport . '> Auto-export on save <em>(uncheck to only use manual bulk export)</em></label>';
        $html .= '</div>';

        $html .= '<div style="margin-top:1.5em;padding-top:1em;border-top:1px solid #eee">';
        $html .= '<label>Bulk Export</label>';
        $html .= '<p>Push all ' . ($exportDrafts ? '' : 'published ') . 'articles to GitHub at once.</p>';
        $html .= '<input name="bulkExport" type="submit" class="btn btn-secondary" value="Export all articles now">';
        $html .= '</div>';

        if ($status) {
            $isError    = stripos($status, 'error') !== false;
            $alertClass = $isError ? 'alert-danger' : 'alert-success';
            $html .= '<div class="alert ' . $alertClass . '" role="alert" style="margin-top:1em">' . htmlspecialchars($status) . '</div>';
        }

        return $html;
    }

    public function post()
    {
        parent::post();

        if (!empty($_POST['bulkExport'])) {
            $this->bulkExport();
        }
    }

    public function afterPageCreate($key)
    {
        try {
            if (empty($key) || !$this->isConfigured() || !$this->getValue('autoExport')) {
                return;
            }
            $page = new Page($key);
            if ($this->shouldExport($page)) {
                $this->exportPage($page);
            }
        } catch (Throwable $e) {
            $this->setStatus('Error (afterPageCreate): ' . $e->getMessage());
        }
    }

    public function afterPageModify($key)
    {
        try {
            if (empty($key) || !$this->isConfigured() || !$this->getValue('autoExport')) {
                return;
            }
            $page = new Page($key);
            if ($this->shouldExport($page)) {
                $this->exportPage($page);
            }
        } catch (Throwable $e) {
            $this->setStatus('Error (afterPageModify): ' . $e->getMessage());
        }
    }

    public function afterPageDelete($key)
    {
        try {
            if (empty($key) || !$this->isConfigured()) {
                return;
            }

            $prefix = trim($this->getValue('path'), '/');
            $base   = ($prefix ? $prefix . '/' : '') . $key;

            // Delete article/index.md
            $mdPath = $base . '/article/index.md';
            $sha    = $this->getFileSha($mdPath);
            if ($sha) {
                $this->githubRequest('DELETE', '/contents/' . $mdPath, [
                    'message' => 'Delete: ' . $key,
                    'sha'     => $sha,
                    'branch'  => $this->getValue('branch'),
                ]);
            }

            // Delete all files in img/
            $items = $this->githubRequest('GET', '/contents/' . $base . '/img');
            if (is_array($items)) {
                foreach ($items as $item) {
                    if ($item['type'] === 'file') {
                        $this->githubRequest('DELETE', '/contents/' . $item['path'], [
                            'message' => 'Delete image: ' . $key . '/img/' . $item['name'],
                            'sha'     => $item['sha'],
                            'branch'  => $this->getValue('branch'),
                        ]);
                    }
                }
            }
        } catch (Throwable $e) {
            $this->setStatus('Error (afterPageDelete): ' . $e->getMessage());
        }
    }

    // --- Private ---

    private function shouldExport($page)
    {
        return $page->getValue('status') !== 'draft' || $this->getValue('exportDrafts');
    }

    private function exportPage($page, $saveStatus = true)
    {
        $key        = $page->getValue('key');
        $rawContent = $page->content();

        // Export images using URLs found in the content (before rewriting)
        $this->exportImages($key, $rawContent);

        $content  = $this->buildMarkdown($page, $key, $rawContent);
        $filePath = $this->getFilePath($key);
        $sha      = $this->getFileSha($filePath);

        $data = [
            'message' => ($sha ? 'Update' : 'Add') . ': ' . $page->title(),
            'content' => base64_encode($content),
            'branch'  => $this->getValue('branch'),
        ];
        if ($sha) {
            $data['sha'] = $sha;
        }

        $result = $this->githubRequest('PUT', '/contents/' . $filePath, $data);

        if ($result !== false && $saveStatus) {
            $this->setStatus(date('Y-m-d H:i:s') . ' — Exported: ' . $page->title());
        }
    }

    private function exportImages($key, $content)
    {
        preg_match_all(
            '/https?:\/\/[^\/]+\/bl-content\/uploads\/pages\/([^\/]+)\/([^"\')\s<>]+)/',
            $content,
            $matches,
            PREG_SET_ORDER
        );

        if (empty($matches)) {
            return;
        }

        $prefix = trim($this->getValue('path'), '/');
        $seen   = [];

        foreach ($matches as $match) {
            $fullUrl  = $match[0];
            $uuid     = $match[1];
            $filename = $match[2];

            if (isset($seen[$filename])) {
                continue;
            }
            $seen[$filename] = true;

            $imageData = $this->readImageData($key, $uuid, $filename, $fullUrl);
            if ($imageData === null) {
                continue;
            }

            $repoPath = ($prefix ? $prefix . '/' : '') . $key . '/img/' . $filename;
            $sha      = $this->getFileSha($repoPath);

            $data = [
                'message' => ($sha ? 'Update' : 'Add') . ' image: ' . $key . '/img/' . $filename,
                'content' => base64_encode($imageData),
                'branch'  => $this->getValue('branch'),
            ];
            if ($sha) {
                $data['sha'] = $sha;
            }

            $this->githubRequest('PUT', '/contents/' . $repoPath, $data);
        }
    }

    private function readImageData($key, $uuid, $filename, $fallbackUrl)
    {
        // Try filesystem first (UUID path, then page key symlink)
        if (defined('PATH_UPLOADS_PAGES')) {
            foreach ([PATH_UPLOADS_PAGES . $uuid . DS . $filename,
                      PATH_UPLOADS_PAGES . $key  . DS . $filename] as $path) {
                if (file_exists($path)) {
                    return file_get_contents($path);
                }
            }
        }

        // Fall back to fetching the image from the live URL
        $ch = curl_init($fallbackUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT      => 'Bludit-GitHub-Export',
        ]);
        $data     = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ($data !== false && $httpCode === 200) ? $data : null;
    }

    private function bulkExport()
    {
        if (!$this->isConfigured()) {
            $this->setStatus('Error: GitHub settings not configured.');
            return;
        }

        global $pages;

        $includeDrafts = (bool)$this->getValue('exportDrafts');
        $list          = $pages->getList(1, 9999, true, true, true, $includeDrafts, false);
        $count         = 0;

        foreach ($list as $key) {
            try {
                $page = new Page($key);
                if ($this->shouldExport($page)) {
                    $this->exportPage($page, false);
                    $count++;
                }
            } catch (Exception $e) {
                continue;
            }
        }

        $this->setStatus(date('Y-m-d H:i:s') . " — Bulk export: {$count} articles pushed.");
    }

    private function buildMarkdown($page, $key, $rawContent = null)
    {
        $content = $rawContent ?? $page->content();

        // Rewrite absolute Bludit upload URLs to relative img/ paths
        // e.g. https://domain/bl-content/uploads/pages/UUID/image.png → ../img/image.png
        $content = preg_replace(
            '/https?:\/\/[^\/]+\/bl-content\/uploads\/pages\/[^\/]+\/([^"\')\s<>]+)/',
            '../img/$1',
            $content
        );

        // Unwrap carousel code blocks: fenced blocks containing only image lines
        // become plain image lists so GitHub renders them instead of showing raw text.
        $content = preg_replace_callback(
            '/^```[^\n]*\n(.*?)^```[ \t]*$/ms',
            function ($m) {
                $lines      = explode("\n", $m[1]);
                $imageLines = [];
                foreach ($lines as $line) {
                    $trimmed = trim($line);
                    if ($trimmed === '') {
                        continue;
                    }
                    if (!preg_match('/^!\[[^\]]*\]\([^)]+\)$/', $trimmed)) {
                        return $m[0]; // contains non-image content — leave the block intact
                    }
                    $imageLines[] = $trimmed;
                }
                if (empty($imageLines)) {
                    return $m[0];
                }
                return implode("\n", $imageLines) . "\n";
            },
            $content
        );

        // Tags
        $rawTags  = $page->getValue('tags');
        $tagLines = '';
        if (!empty($rawTags)) {
            $tagArray = is_array($rawTags) ? $rawTags : array_map('trim', explode(',', $rawTags));
            foreach ($tagArray as $tag) {
                $tag = trim((string)$tag);
                if ($tag !== '') {
                    $tagLines .= "\n  - " . $tag;
                }
            }
        }

        $fm  = "---\n";
        $fm .= 'title: "' . str_replace('"', '\"', $page->title()) . "\"\n";
        $fm .= 'date: ' . date('Y-m-d', strtotime($page->getValue('dateRaw'))) . "\n";
        $fm .= 'slug: ' . $key . "\n";
        $fm .= 'status: ' . $page->getValue('status') . "\n";
        $fm .= 'tags:' . ($tagLines ?: ' []') . "\n";

        $description = $page->getValue('description');
        if (!empty($description)) {
            $fm .= 'description: "' . str_replace('"', '\"', $description) . "\"\n";
        }

        $category = $page->getValue('category');
        if (!empty($category) && $category !== 'uncategorized') {
            $fm .= 'category: ' . $category . "\n";
        }

        $cover = $page->coverImage();
        if (!empty($cover)) {
            $fm .= 'cover: ' . $cover . "\n";
        }

        $fm .= "---\n\n";

        return $fm . $content;
    }

    private function getFilePath($key)
    {
        $prefix = trim($this->getValue('path'), '/');
        return ($prefix ? $prefix . '/' : '') . $key . '/article/index.md';
    }

    private function getFileSha($filePath)
    {
        $result = $this->githubRequest('GET', '/contents/' . $filePath);
        return (is_array($result) && isset($result['sha'])) ? $result['sha'] : null;
    }

    private function githubRequest($method, $endpoint, $data = null)
    {
        $token = $this->getValue('token');
        $owner = $this->getValue('owner');
        $repo  = $this->getValue('repo');

        if (!$token || !$owner || !$repo) {
            return false;
        }

        $url = 'https://api.github.com/repos/' . $owner . '/' . $repo . $endpoint;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $token,
                'Accept: application/vnd.github+json',
                'X-GitHub-Api-Version: 2022-11-28',
                'User-Agent: Bludit-GitHub-Export',
                'Content-Type: application/json',
            ],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_TIMEOUT        => 15,
        ]);

        if ($method === 'PUT') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        } elseif ($method === 'DELETE') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }

        $response  = curl_exec($ch);
        $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            $this->setStatus('Error: cURL — ' . $curlError);
            return false;
        }

        // 404 on GET = file/dir doesn't exist yet, not an error
        if ($httpCode === 404) {
            return null;
        }

        $decoded = json_decode($response, true);

        if ($httpCode >= 400) {
            $message = isset($decoded['message']) ? $decoded['message'] : 'HTTP ' . $httpCode;
            $this->setStatus('Error: GitHub API (' . $httpCode . ') — ' . $message);
            return false;
        }

        return $decoded;
    }

    private function isConfigured()
    {
        return $this->getValue('token') && $this->getValue('owner') && $this->getValue('repo');
    }

    private function setStatus($message)
    {
        file_put_contents($this->workspace() . 'status.txt', $message);
    }

    private function getStatus()
    {
        $file = $this->workspace() . 'status.txt';
        return file_exists($file) ? file_get_contents($file) : '';
    }
}
