<?php

namespace Pushbrief\PreCommitSummarize;

use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;

class GitInfo
{
    private string $repoPath;

    public function __construct(string $repoPath = null)
    {
        $this->repoPath = $repoPath ?? getcwd();
        
        if (!$this->isGitRepository()) {
            throw new \RuntimeException("The specified path '{$this->repoPath}' is not a git repository.");
        }
    }
    
    /**
     * Check if the current directory is a git repository
     *
     * @return bool True if the directory is a git repository, false otherwise
     */
    public function isGitRepository(): bool
    {
        try {
            $process = new Process(['git', 'rev-parse', '--is-inside-work-tree'], $this->repoPath);
            $process->run();
            
            return $process->isSuccessful() && trim($process->getOutput()) === 'true';
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Execute a git command and return the output
     *
     * @param array $command The git command to execute
     * @return string The command output
     * @throws ProcessFailedException If the process fails
     */
    private function executeGitCommand(array $command): string
    {
        $fullCommand = array_merge(['git'], $command);
        $process = new Process($fullCommand, $this->repoPath);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }

        return trim($process->getOutput());
    }

    /**
     * Get the current branch name
     *
     * @return string The current branch name
     */
    public function getCurrentBranch(): string
    {
        return $this->executeGitCommand(['rev-parse', '--abbrev-ref', 'HEAD']);
    }

    /**
     * Get the latest commit information
     *
     * @param int $count Number of commits to retrieve
     * @return array Array of commit information
     */
    public function getLatestCommits(int $count = 5): array
    {
        $format = '%H|%an|%ae|%at|%s';
        $output = $this->executeGitCommand([
            'log',
            "--pretty=format:{$format}",
            "-n",
            (string)$count
        ]);

        $commits = [];
        foreach (explode("\n", $output) as $line) {
            if (empty($line)) continue;
            
            list($hash, $author, $email, $timestamp, $message) = explode('|', $line);
            $commits[] = [
                'hash' => $hash,
                'author' => $author,
                'email' => $email,
                'date' => date('Y-m-d H:i:s', (int)$timestamp),
                'message' => $message
            ];
        }

        return $commits;
    }

    /**
     * Get the changed files in the current working directory
     *
     * @return array Array of changed files with their status
     */
    public function getChangedFiles(): array
    {
        $output = $this->executeGitCommand(['status', '--porcelain']);
        $files = [];

        foreach (explode("\n", $output) as $line) {
            if (empty($line)) continue;
            
            $status = substr($line, 0, 2);
            $file = substr($line, 3);
            
            $statusText = $this->parseStatus($status);
            
            $files[] = [
                'file' => $file,
                'status' => $statusText,
                'status_code' => trim($status)
            ];
        }

        return $files;
    }

    /**
     * Parse the status code from git status
     *
     * @param string $status The status code
     * @return string Human-readable status
     */
    private function parseStatus(string $status): string
    {
        $status = trim($status);
        
        $statusMap = [
            'M' => 'Modified',
            'A' => 'Added',
            'D' => 'Deleted',
            'R' => 'Renamed',
            'C' => 'Copied',
            'U' => 'Updated but unmerged',
            '??' => 'Untracked'
        ];

        if (isset($statusMap[$status])) {
            return $statusMap[$status];
        }
        
        // Handle combined status codes
        if (strlen($status) === 2) {
            $indexStatus = $status[0] === ' ' ? '' : ($statusMap[$status[0]] ?? 'Unknown');
            $workingStatus = $status[1] === ' ' ? '' : ($statusMap[$status[1]] ?? 'Unknown');
            
            if ($indexStatus && $workingStatus) {
                return "$indexStatus in index, $workingStatus in working tree";
            } elseif ($indexStatus) {
                return $indexStatus . ' in index';
            } elseif ($workingStatus) {
                return $workingStatus . ' in working tree';
            }
        }
        
        return 'Unknown status';
    }

    /**
     * Get the diff for a specific file
     *
     * @param string $file The file path
     * @param bool $staged Whether to get the staged diff
     * @return string The diff output
     */
    public function getDiff(string $file, bool $staged = false): string
    {
        try {
            $command = ['diff'];
            if ($staged) {
                $command[] = '--staged';
            }
            // Add -- to separate paths from revisions
            $command[] = '--';
            $command[] = $file;
            
            return $this->executeGitCommand($command);
        } catch (\Exception $e) {
            // Return empty string if there's an error getting the diff
            return '';
        }
    }
    
    /**
     * Get diffs for all changed files
     *
     * @param bool $staged Whether to get the staged diffs
     * @return array Array of file diffs with file name and patch content
     */
    public function getDiffs(bool $staged = false): array
    {
        try {
            $changedFiles = $this->getChangedFiles();
            $diffs = [];
            
            // If there are no changed files, try to get a unified diff
            if (empty($changedFiles)) {
                return [];
            }
            
            // First try to get a unified diff for all files
            $unifiedDiff = $this->getUnifiedDiff($staged);
            if (!empty($unifiedDiff)) {
                // Parse the unified diff to extract per-file diffs
                $fileDiffs = $this->parseUnifiedDiff($unifiedDiff);
                if (!empty($fileDiffs)) {
                    return $fileDiffs;
                }
            }
            
            // If unified diff approach didn't work, fall back to per-file diffs
            foreach ($changedFiles as $file) {
                // Skip untracked files if we're looking at staged changes
                if ($staged && $file['status_code'] === '??') {
                    continue;
                }
                
                // Get the diff for this file
                $diffContent = $this->getDiff($file['file'], $staged);
                
                // If there's no diff content for staged files, skip
                if ($staged && empty($diffContent)) {
                    continue;
                }
                
                // If there's no diff content for untracked files, create a simple diff
                if (empty($diffContent) && $file['status_code'] === '??') {
                    $diffContent = "New file: {$file['file']}\n";
                }
                
                if (!empty($diffContent)) {
                    $diffs[] = [
                        'file' => $file['file'],
                        'status' => $file['status'],
                        'patch' => $diffContent
                    ];
                }
            }
            
            return $diffs;
        } catch (\Exception $e) {
            // Return empty array if there's an error getting the diffs
            return [];
        }
    }
    
    /**
     * Parse a unified diff to extract per-file diffs
     *
     * @param string $unifiedDiff The unified diff output
     * @return array Array of file diffs with file name and patch content
     */
    private function parseUnifiedDiff(string $unifiedDiff): array
    {
        if (empty($unifiedDiff)) {
            return [];
        }
        
        $fileDiffs = [];
        $lines = explode("\n", $unifiedDiff);
        $currentFile = null;
        $currentPatch = [];
        $diffStarted = false;
        
        foreach ($lines as $line) {
            // Check for diff header (diff --git a/file b/file)
            if (preg_match('/^diff --git a\/(.+) b\/(.+)$/', $line, $matches)) {
                // If we already have a file, add it to the results
                if ($currentFile && !empty($currentPatch)) {
                    $fileDiffs[] = [
                        'file' => $currentFile,
                        'status' => 'Modified', // Default status
                        'patch' => implode("\n", $currentPatch)
                    ];
                }
                
                // Start a new file
                $currentFile = $matches[1];
                $currentPatch = [$line];
                $diffStarted = true;
                continue;
            }
            
            if ($diffStarted) {
                $currentPatch[] = $line;
            }
        }
        
        // Add the last file if there is one
        if ($currentFile && !empty($currentPatch)) {
            $fileDiffs[] = [
                'file' => $currentFile,
                'status' => 'Modified', // Default status
                'patch' => implode("\n", $currentPatch)
            ];
        }
        
        return $fileDiffs;
    }
    
    /**
     * Get a unified diff of all changes
     * 
     * @param bool $staged Whether to get the staged diff
     * @return string The unified diff output
     */
    public function getUnifiedDiff(bool $staged = false): string
    {
        try {
            $command = ['diff'];
            if ($staged) {
                $command[] = '--staged';
            }
            
            // Add color option for better parsing
            $command[] = '--color=never';
            
            // Add -- to separate paths from revisions
            $command[] = '--';
            
            return $this->executeGitCommand($command);
        } catch (\Exception $e) {
            // Return empty string if there's an error getting the unified diff
            return '';
        }
    }

    /**
     * Get repository information
     *
     * @return array Repository information
     */
    public function getRepoInfo(): array
    {
        $remoteUrl = $this->executeGitCommand(['config', '--get', 'remote.origin.url']);
        $totalCommits = $this->executeGitCommand(['rev-list', '--count', 'HEAD']);
        $contributors = $this->executeGitCommand(['shortlog', '-sn', 'HEAD']);
        
        // Parse contributors
        $contributorsList = [];
        foreach (explode("\n", $contributors) as $line) {
            if (empty($line)) continue;
            
            preg_match('/^\s*(\d+)\s+(.+)$/', $line, $matches);
            if (count($matches) === 3) {
                $contributorsList[] = [
                    'name' => trim($matches[2]),
                    'commits' => (int)$matches[1]
                ];
            }
        }
        
        return [
            'remote_url' => $remoteUrl,
            'total_commits' => (int)$totalCommits,
            'contributors' => $contributorsList,
            'current_branch' => $this->getCurrentBranch()
        ];
    }
    
    /**
     * Get changes in API format
     * 
     * @param bool $staged Whether to get staged changes
     * @param bool $includeDiffs Whether to include diffs in the response
     * @return array Array of changes in API format
     */
    public function getChangesApi(bool $staged = false, bool $includeDiffs = true): array
    {
        $changedFiles = $this->getChangedFiles();
        $result = [];
        
        foreach ($changedFiles as $file) {
            // Skip untracked files if we're looking at staged changes
            if ($staged && $file['status_code'] === '??') {
                continue;
            }
            
            $item = [
                'file' => $file['file'],
                'status' => $file['status'],
                'status_code' => $file['status_code']
            ];
            
            // Include diffs if requested
            if ($includeDiffs) {
                $item['patch'] = $this->getDiff($file['file'], $staged);
            } else {
                $item['patch'] = '';
            }
            
            $result[] = $item;
        }
        
        return $result;
    }
}
