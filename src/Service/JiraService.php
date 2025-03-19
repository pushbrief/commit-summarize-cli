<?php

namespace Pushbrief\PreCommitSummarize\Service;

use JiraRestApi\Issue\IssueService;
use JiraRestApi\Project\ProjectService;
use JiraRestApi\JiraException;
use JiraRestApi\Configuration\ArrayConfiguration;

class JiraService
{
    private $issueService;
    private $projectService;
    private $config;
    
    /**
     * Constructor
     * 
     * @param array $config Jira configuration
     */
    public function __construct(array $config = [])
    {
        // Load config from environment variables if not provided
        $this->config = $this->loadConfig($config);
        $this->initializeServices();
    }
    
    /**
     * Load configuration from environment variables if not provided
     * 
     * @param array $config Initial configuration
     * @return array Complete configuration
     */
    private function loadConfig(array $config): array
    {
        // Check for host
        if (empty($config['host'])) {
            // First try system environment variables (OS level)
            $sysEnv = getenv('JIRA_HOST', true) ?: null;
            // Then try local environment variables (.env file loaded into $_ENV)
            $localEnv = $_ENV['JIRA_HOST'] ?? null;
            // Finally fallback to regular getenv which might check both
            $fallback = getenv('JIRA_HOST') ?: '';
            
            $config['host'] = $sysEnv ?? $localEnv ?? $fallback;
        }
        
        // Check for username
        if (empty($config['username'])) {
            $sysEnv = getenv('JIRA_USERNAME', true) ?: null;
            $localEnv = $_ENV['JIRA_USERNAME'] ?? null;
            $fallback = getenv('JIRA_USERNAME') ?: '';
            
            $config['username'] = $sysEnv ?? $localEnv ?? $fallback;
        }
        
        // Check for password
        if (empty($config['password'])) {
            $sysEnv = getenv('JIRA_PASSWORD', true) ?: null;
            $localEnv = $_ENV['JIRA_PASSWORD'] ?? null;
            $fallback = getenv('JIRA_PASSWORD') ?: '';
            
            $config['password'] = $sysEnv ?? $localEnv ?? $fallback;
        }
        
        return $config;
    }
    
    /**
     * Initialize Jira API services
     */
    private function initializeServices(): void
    {
        try {
            $jiraConfig = new ArrayConfiguration([
                'jiraHost' => $this->config['host'] ?? '',
                'jiraUser' => $this->config['username'] ?? '',
                'jiraPassword' => $this->config['password'] ?? '',
                'apiVersion' => 2,
                'useV3RestApi' => true,
            ]);
            
            $this->issueService = new IssueService($jiraConfig);
            $this->projectService = new ProjectService($jiraConfig);
        } catch (JiraException $e) {
            throw new \RuntimeException('Failed to initialize Jira services: ' . $e->getMessage());
        }
    }
    
    /**
     * Get issue information by key
     * 
     * @param string $issueKey The Jira issue key (e.g., PROJECT-123)
     * @return array Issue information
     */
    public function getIssue(string $issueKey): array
    {
        try {
            $issue = $this->issueService->get($issueKey);
            
            return [
                'key' => $issue->key,
                'summary' => $issue->fields->summary,
                'description' => $issue->fields->description,
                'status' => $issue->fields->status->name,
                'project' => [
                    'key' => $issue->fields->project->key,
                    'name' => $issue->fields->project->name,
                ],
            ];
        } catch (JiraException $e) {
            throw new \RuntimeException('Failed to get issue: ' . $e->getMessage());
        }
    }
    
    /**
     * Get issue key from branch name
     * 
     * @param string $branchName The git branch name
     * @return string|null The issue key or null if not found
     */
    public function getIssueKeyFromBranch(string $branchName): ?string
    {
        // Common branch naming patterns
        $patterns = [
            '/^([A-Z]+-\d+)/', // PROJECT-123
            '/^([A-Z]+)[-_](\d+)/', // PROJECT-123 or PROJECT_123
            '/^feature\/([A-Z]+-\d+)/', // feature/PROJECT-123
            '/^bugfix\/([A-Z]+-\d+)/', // bugfix/PROJECT-123
            '/^hotfix\/([A-Z]+-\d+)/', // hotfix/PROJECT-123
        ];
        
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $branchName, $matches)) {
                return $matches[1];
            }
        }
        
        return null;
    }
    
    /**
     * Get all projects
     * 
     * @return array List of projects
     */
    public function getProjects(): array
    {
        try {
            $projects = $this->projectService->getAllProjects();
            $result = [];
            
            foreach ($projects as $project) {
                $result[] = [
                    'key' => $project->key,
                    'name' => $project->name,
                ];
            }
            
            return $result;
        } catch (JiraException $e) {
            throw new \RuntimeException('Failed to get projects: ' . $e->getMessage());
        }
    }
    
    /**
     * Get issues for a project
     * 
     * @param string $projectKey The project key
     * @return array List of issues
     * @deprecated Use getIssues() instead
     */
    public function getProjectIssues(string $projectKey): array
    {
        return $this->getIssues($projectKey);
    }
    
    /**
     * Get issues for a project with option to filter active issues only
     * 
     * @param string $projectKey The project key
     * @param bool $activeOnly Whether to return only active issues
     * @return array List of issues
     */
    public function getIssues(string $projectKey, bool $activeOnly = false): array
    {
        try {
            $jql = "project = $projectKey";
            
            if ($activeOnly) {
                $jql .= " AND resolution = Unresolved";
            }
            
            $jql .= " ORDER BY priority ASC, updated DESC";
            $issues = $this->issueService->search($jql, 0, 100); // 0: başlangıç indeksi, 30: maksimum sonuç sayısı
            $result = [];
            
            foreach ($issues->issues as $issue) {
                $result[] = [
                    'key' => $issue->key,
                    'summary' => $issue->fields->summary,
                    'status' => $issue->fields->status->name,
                    'assignee' => $issue->fields->assignee
                ];
            }
            
            return $result;
        } catch (JiraException $e) {
            throw new \RuntimeException('Failed to get project issues: ' . $e->getMessage());
        }
    }
    
    /**
     * Create a commit message for a Jira issue
     * 
     * @param string $issueKey The Jira issue key
     * @param string $message Additional commit message
     * @return string Formatted commit message
     */
    public function createCommitMessage(string $issueKey, string $message = ''): string
    {
        
        try {
            $issue = $this->getIssue($issueKey);
            $commitMessage = "{$issueKey}: {$issue['summary']}";
            
            if (!empty($message)) {
                $commitMessage .= "\n\n{$message}";
            }
            
            return $commitMessage;
        } catch (\Exception $e) {
            // If we can't get the issue details, just use the key and message
            if (!empty($message)) {
                return "{$issueKey}: {$message}";
            }
            
            return $issueKey;
        }
    }
    
    /**
     * Add a comment to a Jira issue
     * 
     * @param string $issueKey Issue key
     * @param string $comment Comment JSON in Atlassian Document Format
     * @return bool Success status
     */
    public function addComment(string $issueKey, string $comment): bool
    {
        try {
            // Jira API endpoint for adding comments
            $jiraHost = $this->config['host'];
            $uri = $jiraHost . '/rest/api/3/issue/' . $issueKey . '/comment';
            
            // Prepare the request with basic auth
            $username = $this->config['username'];
            $password = $this->config['password'];
            
            // Prepare CURL request
            $ch = curl_init($uri);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_USERPWD, "$username:$password");
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $comment);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'Accept: application/json'
            ]);
            
            // Execute the request
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);
            
            // Check if the request was successful
            if ($httpCode >= 200 && $httpCode < 300) {
                return true;
            } else {
                throw new \RuntimeException('CURL HTTP Request Failed: Status Code : ' . $httpCode . ', URL:' . $uri . '\nError Message : ' . $response);
            }
        } catch (\Exception $e) {
            throw new \RuntimeException('Failed to add comment: ' . $e->getMessage());
        }
    }
    
    /**
     * Setup Jira service with new credentials
     * 
     * @param string|null $host Jira host URL
     * @param string|null $username Jira username
     * @param string|null $password Jira password or API token
     * @return void
     */
    public function setup(?string $host = null, ?string $username = null, ?string $password = null): void
    {
        $newConfig = $this->config;
        
        if ($host !== null) {
            $newConfig['host'] = $host;
        }
        
        if ($username !== null) {
            $newConfig['username'] = $username;
        }
        
        if ($password !== null) {
            $newConfig['password'] = $password;
        }
        
        $this->config = $newConfig;
        $this->initializeServices();
    }
}
