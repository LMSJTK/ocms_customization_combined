<?php
/**
 * S3Client - S3 client using AWS CLI
 *
 * Uses the AWS CLI for S3 operations, which handles authentication
 * via IAM roles, environment variables, or ~/.aws/credentials automatically.
 *
 * Requirements:
 * - AWS CLI installed and accessible in PATH
 * - Appropriate IAM permissions for S3 operations
 */

class S3Client {
    private $region;
    private $bucket;
    private $prefix;
    private $cdnUrl;
    private $enabled;

    public function __construct($config) {
        $this->enabled = $config['enabled'] ?? false;
        $this->region = $config['region'] ?? 'us-east-1';
        $this->bucket = $config['bucket'] ?? '';
        $this->prefix = rtrim($config['prefix'] ?? 'content/', '/') . '/';
        $this->cdnUrl = rtrim($config['cdn_url'] ?? '', '/');
    }

    /**
     * Check if S3 storage is enabled and configured
     */
    public function isEnabled() {
        return $this->enabled && !empty($this->bucket);
    }

    /**
     * Get the public URL for a content item
     * Uses CDN URL if configured, otherwise direct S3 URL
     */
    public function getContentUrl($contentId, $filename = 'index.html') {
        $key = $this->prefix . $contentId . '/' . $filename;

        if (!empty($this->cdnUrl)) {
            return $this->cdnUrl . '/' . $key;
        }

        return 'https://' . $this->bucket . '.s3.' . $this->region . '.amazonaws.com/' . $key;
    }

    /**
     * Get the base URL for content assets (for relative URL resolution)
     */
    public function getContentBaseUrl($contentId) {
        $key = $this->prefix . $contentId . '/';

        if (!empty($this->cdnUrl)) {
            return $this->cdnUrl . '/' . $key;
        }

        return 'https://' . $this->bucket . '.s3.' . $this->region . '.amazonaws.com/' . $key;
    }

    /**
     * Upload a single file to S3
     *
     * @param string $contentId Content identifier
     * @param string $filename Relative path/filename within content directory
     * @param string $localPath Local file path to upload
     * @param string|null $contentType Optional content type override
     * @return bool Success
     */
    public function uploadFile($contentId, $filename, $localPath, $contentType = null) {
        if (!$this->isEnabled()) {
            throw new Exception('S3 storage is not enabled');
        }

        $s3Uri = 's3://' . $this->bucket . '/' . $this->prefix . $contentId . '/' . $filename;

        // SECURITY: All user-controlled inputs are properly escaped with escapeshellarg()
        // to prevent command injection. This is the recommended PHP approach for exec().
        $cmd = 'aws s3 cp ' . escapeshellarg($localPath) . ' ' . escapeshellarg($s3Uri);
        $cmd .= ' --region ' . escapeshellarg($this->region);

        if ($contentType) {
            $cmd .= ' --content-type ' . escapeshellarg($contentType);
        }

        $cmd .= ' 2>&1';

        // @phpcs:ignore PHPCS_SecurityAudit.BadFunctions.SystemExecFunctions.WarnSystemExec
        exec($cmd, $output, $returnVar);

        if ($returnVar !== 0) {
            $errorMsg = implode("\n", $output);
            throw new Exception("S3 upload failed: {$errorMsg}");
        }

        return true;
    }

    /**
     * Upload an entire directory to S3 recursively
     *
     * @param string $contentId Content identifier
     * @param string $localDir Local directory path to upload
     * @param array $excludePatterns Patterns to exclude (converted to --exclude flags)
     * @return array List of uploaded files (from aws s3 sync output)
     */
    public function uploadDirectory($contentId, $localDir, $excludePatterns = []) {
        if (!$this->isEnabled()) {
            throw new Exception('S3 storage is not enabled');
        }

        $localDir = rtrim($localDir, '/') . '/';
        $s3Uri = 's3://' . $this->bucket . '/' . $this->prefix . $contentId . '/';

        // Use 'aws s3 sync' for efficient directory upload
        // sync only uploads changed files and handles parallelism
        // SECURITY: All inputs are escaped with escapeshellarg() to prevent injection
        $cmd = 'aws s3 sync ' . escapeshellarg($localDir) . ' ' . escapeshellarg($s3Uri);
        $cmd .= ' --region ' . escapeshellarg($this->region);

        // Add exclude patterns
        foreach ($excludePatterns as $pattern) {
            $cmd .= ' --exclude ' . escapeshellarg($pattern);
        }

        $cmd .= ' 2>&1';

        // @phpcs:ignore PHPCS_SecurityAudit.BadFunctions.SystemExecFunctions.WarnSystemExec
        exec($cmd, $output, $returnVar);

        if ($returnVar !== 0) {
            $errorMsg = implode("\n", $output);
            throw new Exception("S3 sync failed: {$errorMsg}");
        }

        // Parse output to get list of uploaded files
        $uploadedFiles = [];
        foreach ($output as $line) {
            // Output format: "upload: ./file.html to s3://bucket/path/file.html"
            if (preg_match('/^upload:\s+(.+?)\s+to\s+s3:\/\//', $line, $matches)) {
                $localFile = $matches[1];
                // Get relative path
                $relativePath = str_replace($localDir, '', $localFile);
                $relativePath = ltrim($relativePath, './');
                if (!empty($relativePath)) {
                    $uploadedFiles[] = $relativePath;
                }
            }
        }

        error_log("S3: Synced directory to {$s3Uri} - " . count($uploadedFiles) . " files uploaded");

        return $uploadedFiles;
    }

    /**
     * Delete all objects with a given content ID prefix
     *
     * @param string $contentId Content identifier
     * @return int Number of deleted objects (approximate)
     */
    public function deleteContent($contentId) {
        if (!$this->isEnabled()) {
            throw new Exception('S3 storage is not enabled');
        }

        $s3Uri = 's3://' . $this->bucket . '/' . $this->prefix . $contentId . '/';

        // SECURITY: All inputs are escaped with escapeshellarg() to prevent injection
        $cmd = 'aws s3 rm ' . escapeshellarg($s3Uri) . ' --recursive';
        $cmd .= ' --region ' . escapeshellarg($this->region);
        $cmd .= ' 2>&1';

        // @phpcs:ignore PHPCS_SecurityAudit.BadFunctions.SystemExecFunctions.WarnSystemExec
        exec($cmd, $output, $returnVar);

        if ($returnVar !== 0) {
            $errorMsg = implode("\n", $output);
            error_log("S3 delete warning: {$errorMsg}");
            // Don't throw - deletion may partially succeed
        }

        // Count deleted files from output
        $deletedCount = 0;
        foreach ($output as $line) {
            if (strpos($line, 'delete:') === 0) {
                $deletedCount++;
            }
        }

        error_log("S3: Deleted {$deletedCount} objects from {$s3Uri}");

        return $deletedCount;
    }

    /**
     * Check if a specific object exists in S3
     *
     * @param string $contentId Content identifier
     * @param string $filename Filename to check
     * @return bool True if exists
     */
    public function objectExists($contentId, $filename = 'index.html') {
        if (!$this->isEnabled()) {
            return false;
        }

        $s3Uri = 's3://' . $this->bucket . '/' . $this->prefix . $contentId . '/' . $filename;

        // SECURITY: All inputs are escaped with escapeshellarg() to prevent injection
        $cmd = 'aws s3 ls ' . escapeshellarg($s3Uri);
        $cmd .= ' --region ' . escapeshellarg($this->region);
        $cmd .= ' 2>&1';

        // @phpcs:ignore PHPCS_SecurityAudit.BadFunctions.SystemExecFunctions.WarnSystemExec
        exec($cmd, $output, $returnVar);

        return $returnVar === 0 && !empty($output);
    }

    /**
     * Get pre-signed URL for temporary access to private content
     *
     * @param string $contentId Content identifier
     * @param string $filename Filename
     * @param int $expiresIn Seconds until expiration (default 1 hour)
     * @return string Pre-signed URL
     */
    public function getPresignedUrl($contentId, $filename = 'index.html', $expiresIn = 3600) {
        if (!$this->isEnabled()) {
            throw new Exception('S3 storage is not enabled');
        }

        $s3Uri = 's3://' . $this->bucket . '/' . $this->prefix . $contentId . '/' . $filename;

        // SECURITY: All inputs are escaped (escapeshellarg for strings, intval for numbers)
        $cmd = 'aws s3 presign ' . escapeshellarg($s3Uri);
        $cmd .= ' --expires-in ' . intval($expiresIn);
        $cmd .= ' --region ' . escapeshellarg($this->region);
        $cmd .= ' 2>&1';

        // @phpcs:ignore PHPCS_SecurityAudit.BadFunctions.SystemExecFunctions.WarnSystemExec
        exec($cmd, $output, $returnVar);

        if ($returnVar !== 0 || empty($output)) {
            throw new Exception("Failed to generate presigned URL: " . implode("\n", $output));
        }

        return trim($output[0]);
    }

    /**
     * Upload a brand kit asset to S3
     *
     * @param string $companyId Company identifier
     * @param string $brandKitId Brand kit identifier
     * @param string $assetType Asset type ('logo', 'font', 'icon')
     * @param string $filename Original filename
     * @param string $localPath Local file path to upload
     * @param string|null $contentType MIME type
     * @return string The S3/CDN URL for the uploaded asset
     */
    public function uploadBrandKitAsset($companyId, $brandKitId, $assetType, $filename, $localPath, $contentType = null) {
        if (!$this->isEnabled()) {
            throw new Exception('S3 storage is not enabled');
        }

        $key = "brand-kits/{$companyId}/{$brandKitId}/{$assetType}s/{$filename}";
        $s3Uri = 's3://' . $this->bucket . '/' . $key;

        $cmd = 'aws s3 cp ' . escapeshellarg($localPath) . ' ' . escapeshellarg($s3Uri);
        $cmd .= ' --region ' . escapeshellarg($this->region);

        if ($contentType) {
            $cmd .= ' --content-type ' . escapeshellarg($contentType);
        }

        $cmd .= ' 2>&1';

        exec($cmd, $output, $returnVar);

        if ($returnVar !== 0) {
            throw new Exception("S3 brand kit upload failed: " . implode("\n", $output));
        }

        return $this->getBrandKitAssetUrl($companyId, $brandKitId, $assetType, $filename);
    }

    /**
     * Get the public URL for a brand kit asset
     *
     * @param string $companyId Company identifier
     * @param string $brandKitId Brand kit identifier
     * @param string $assetType Asset type ('logo', 'font', 'icon')
     * @param string $filename Filename
     * @return string Public URL
     */
    public function getBrandKitAssetUrl($companyId, $brandKitId, $assetType, $filename) {
        $key = "brand-kits/{$companyId}/{$brandKitId}/{$assetType}s/{$filename}";

        if (!empty($this->cdnUrl)) {
            return $this->cdnUrl . '/' . $key;
        }

        return 'https://' . $this->bucket . '.s3.' . $this->region . '.amazonaws.com/' . $key;
    }

    /**
     * Delete all S3 assets for a brand kit
     *
     * @param string $companyId Company identifier
     * @param string $brandKitId Brand kit identifier
     * @return int Number of deleted objects
     */
    public function deleteBrandKitAssets($companyId, $brandKitId) {
        if (!$this->isEnabled()) {
            throw new Exception('S3 storage is not enabled');
        }

        $s3Uri = 's3://' . $this->bucket . '/brand-kits/' . $companyId . '/' . $brandKitId . '/';

        $cmd = 'aws s3 rm ' . escapeshellarg($s3Uri) . ' --recursive';
        $cmd .= ' --region ' . escapeshellarg($this->region);
        $cmd .= ' 2>&1';

        exec($cmd, $output, $returnVar);

        if ($returnVar !== 0) {
            error_log("S3 brand kit delete warning: " . implode("\n", $output));
        }

        $deletedCount = 0;
        foreach ($output as $line) {
            if (strpos($line, 'delete:') === 0) {
                $deletedCount++;
            }
        }

        error_log("S3: Deleted {$deletedCount} brand kit assets from {$s3Uri}");
        return $deletedCount;
    }

    /**
     * Fetch content from an S3/CDN URL via server-side HTTP request.
     * Used by launch.php to proxy S3 content through the server
     * so placeholders and tracking can be injected at runtime.
     *
     * @param string $url The full S3 or CDN URL to fetch
     * @param int $timeout Request timeout in seconds
     * @return string The fetched HTML content
     * @throws Exception If the fetch fails
     */
    public function fetchContent($url, $timeout = 10) {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => $timeout,
                'follow_location' => true,
                'max_redirects' => 3,
                'ignore_errors' => true,
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);

        $content = @file_get_contents($url, false, $context);

        if ($content === false) {
            throw new Exception("Failed to fetch content from S3: {$url}");
        }

        // Check HTTP response code from $http_response_header (set by file_get_contents)
        if (isset($http_response_header) && is_array($http_response_header)) {
            $statusLine = $http_response_header[0] ?? '';
            if (preg_match('/\s(\d{3})\s/', $statusLine, $matches)) {
                $statusCode = (int) $matches[1];
                if ($statusCode >= 400) {
                    throw new Exception("S3 fetch returned HTTP {$statusCode} for: {$url}");
                }
            }
        }

        return $content;
    }
}
