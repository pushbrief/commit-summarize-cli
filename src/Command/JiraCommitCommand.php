<?php

namespace Pushbrief\PreCommitSummarize\Command;

use Pushbrief\PreCommitSummarize\GitInfo;
use Pushbrief\PreCommitSummarize\Service\JiraService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Process\Process;

class JiraCommitCommand extends Command
{
    protected static $defaultName = 'jira:commit';
    protected static $defaultDescription = 'Create a commit message based on Jira issue';

    private $gitInfo;
    private $jiraService;

    public function __construct()
    {
        parent::__construct(self::$defaultName);
    }

    protected function configure(): void
    {
        $this
            ->setDescription(self::$defaultDescription)
            ->addOption('repo-path', 'r', InputOption::VALUE_OPTIONAL, 'Path to the git repository', getcwd())
            ->addOption('jira-host', null, InputOption::VALUE_OPTIONAL, 'Jira host URL')
            ->addOption('jira-username', null, InputOption::VALUE_OPTIONAL, 'Jira username')
            ->addOption('jira-password', null, InputOption::VALUE_OPTIONAL, 'Jira password or API token')
            ->addOption('issue-key', 'i', InputOption::VALUE_OPTIONAL, 'Jira issue key (e.g., PROJECT-123)')
            ->addOption('default-issue', 'd', InputOption::VALUE_OPTIONAL, 'Default Jira issue key to use if not specified')
            ->addOption('message', 'm', InputOption::VALUE_OPTIONAL, 'Additional commit message')
            ->addOption('from-branch', 'b', InputOption::VALUE_NONE, 'Extract issue key from branch name')
            ->addOption('commit', 'c', InputOption::VALUE_NONE, 'Create the commit after generating the message')
            ->addOption('staged', 's', InputOption::VALUE_NONE, 'Commit only staged changes')
            ->addOption('all', 'a', InputOption::VALUE_NONE, 'Stage all changes before committing');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $repoPath = $input->getOption('repo-path');

        try {
            $this->gitInfo = new GitInfo($repoPath);
        } catch (\RuntimeException $e) {
            $io->error([
                'Error: ' . $e->getMessage(),
                'Please make sure you are running this command in a git repository.',
                'You can initialize a git repository with: git init'
            ]);
            return Command::FAILURE;
        }

        // Get Jira configuration
        $jiraConfig = $this->getJiraConfig($input, $io);
        if (empty($jiraConfig)) {
            return Command::FAILURE;
        }

        try {
            $this->jiraService = new JiraService($jiraConfig);
        } catch (\RuntimeException $e) {
            $io->error('Failed to connect to Jira: ' . $e->getMessage());
            return Command::FAILURE;
        }

        // Get issue key
        $issueKey = $this->getIssueKey($input, $io);
        if (empty($issueKey)) {
            return Command::FAILURE;
        }

        // Get additional message
        $message = $input->getOption('message');

        // Create commit message
        try {
            $commitMessage = $this->jiraService->createCommitMessage($issueKey, $message);
            $io->success('Generated commit message:');
            $io->writeln($commitMessage);

            // Create commit if requested
            if ($input->getOption('commit')) {
                $this->createCommit($commitMessage, $input, $io);
            }

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $io->error('Failed to create commit message: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }

    /**
     * Get Jira configuration from input or environment
     * 
     * @param InputInterface $input
     * @param SymfonyStyle $io
     * @return array Jira configuration
     */
    private function getJiraConfig(InputInterface $input, SymfonyStyle $io): array
    {
        $config = [];

        // Check for command line options first
        $host = $input->getOption('jira-host');
        $username = $input->getOption('jira-username');
        $password = $input->getOption('jira-password');

        // Then check for environment variables (both $_ENV and getenv for compatibility)
        if (empty($host)) {
            $host = $_ENV['JIRA_HOST'] ?? getenv('JIRA_HOST') ?? null;
        }

        if (empty($username)) {
            $username = $_ENV['JIRA_USERNAME'] ?? getenv('JIRA_USERNAME') ?? null;
        }

        if (empty($password)) {
            $password = $_ENV['JIRA_PASSWORD'] ?? getenv('JIRA_PASSWORD') ?? null;
        }

        // If still not provided, prompt for Jira credentials
        if (empty($host)) {
            $io->note('Jira host not found in environment variables. You can set it in the .env file with JIRA_HOST=your-jira-url');
            $host = $io->ask('Enter your Jira host URL (e.g., https://your-domain.atlassian.net)');
        } else {
            $io->info("Using Jira host: {$host}");
        }

        if (empty($username)) {
            $io->note('Jira username not found in environment variables. You can set it in the .env file with JIRA_USERNAME=your-email');
            $username = $io->ask('Enter your Jira username (email)');
        } else {
            $io->info("Using Jira username: {$username}");
        }

        if (empty($password)) {
            $io->note('Jira password/token not found in environment variables. You can set it in the .env file with JIRA_PASSWORD=your-token');
            $question = new Question('Enter your Jira API token or password');
            $question->setHidden(true);
            $question->setHiddenFallback(false);
            $password = $io->askQuestion($question);
        } else {
            $io->info("Using Jira password/token from environment variables");
        }

        if (empty($host) || empty($username) || empty($password)) {
            $io->error('Jira credentials are required');
            return [];
        }

        $config['host'] = $host;
        $config['username'] = $username;
        $config['password'] = $password;

        return $config;
    }

    /**
     * Get issue key from input, branch name, or prompt user to select
     * 
     * @param InputInterface $input
     * @param SymfonyStyle $io
     * @return string|null Issue key
     */
    private function getIssueKey(InputInterface $input, SymfonyStyle $io): ?string
    {
        // Check if issue key is provided directly
        $issueKey = $input->getOption('issue-key');
        if (!empty($issueKey)) {
            return $issueKey;
        }

        // Check if we should extract from branch name
        if ($input->getOption('from-branch')) {
            $branchName = $this->gitInfo->getCurrentBranch();
            $jiraDefaultBranch = getenv('JIRA_DEFAULT_ISSUE', true);
            $issueKey = $this->jiraService->getIssueKeyFromBranch($jiraDefaultBranch ?? $branchName);

            if (!empty($issueKey)) {
                $io->info("Extracted issue key from branch name: $issueKey");
                return $issueKey;
            } else {
                $io->warning("Could not extract issue key from branch name: $branchName");
            }
        }

        // Check for default issue key from command line or environment variables
        $defaultIssue = $this->getDefaultIssueFromEnv($input);
        if (!empty($defaultIssue)) {
            $io->info("Using default issue key from environment: $defaultIssue");
            return $defaultIssue;
        }

        // If not found, prompt user to select project and issue
        $io->info('Please select a Jira project and issue:');

        try {
            // Get projects
            $projects = $this->jiraService->getProjects();
            if (empty($projects)) {
                $io->error('No projects found in Jira');
                return null;
            }

            // Format project choices
            $projectChoices = [];
            foreach ($projects as $project) {
                $projectChoices[$project['key']] = "{$project['key']} - {$project['name']}";
            }

            // Ask user to select a project
            $question = new ChoiceQuestion(
                'Select a project',
                $projectChoices
            );
            $projectKey = $io->askQuestion($question);

            // Get issues for selected project
            $issues = $this->jiraService->getProjectIssues($projectKey);
            if (empty($issues)) {
                $io->error("No open issues found for project $projectKey");
                return null;
            }

            // Format issue choices
            $issueChoices = [];
            foreach ($issues as $issue) {
                $issueChoices[$issue['key']] = "{$issue['key']} - {$issue['summary']} ({$issue['status']})";
            }

            // Ask user to select an issue
            $question = new ChoiceQuestion(
                'Select an issue',
                $issueChoices
            );
            return $io->askQuestion($question);

        } catch (\Exception $e) {
            $io->error('Failed to get projects or issues: ' . $e->getMessage());

            // Fallback to manual entry
            return $io->ask('Enter the Jira issue key manually (e.g., PROJECT-123)');
        }
    }

    /**
     * Get default issue key from command line option or environment variables
     * 
     * @param InputInterface|null $input Input interface for command line options
     * @return string|null Default issue key or null if not set
     */
    private function getDefaultIssueFromEnv(?InputInterface $input = null): ?string
    {
        // First check command line option if available
        if ($input !== null && $input->hasOption('default-issue')) {
            $cmdOption = $input->getOption('default-issue');
            if (!empty($cmdOption)) {
                return $cmdOption;
            }
        }

        // Then try system environment variables (OS level)
        $sysEnv = getenv('JIRA_DEFAULT_ISSUE', true) ?: null;
        // Then try local environment variables (.env file loaded into $_ENV)
        $localEnv = $_ENV['JIRA_DEFAULT_ISSUE'] ?? null;
        // Finally fallback to regular getenv which might check both
        $fallback = getenv('JIRA_DEFAULT_ISSUE') ?: null;

        return $sysEnv ?? $localEnv ?? $fallback;
    }

    /**
     * Create a git commit with the generated message
     * 
     * @param string $commitMessage The commit message
     * @param InputInterface $input
     * @param SymfonyStyle $io
     * @return bool Success status
     */
    private function createCommit(string $commitMessage, InputInterface $input, SymfonyStyle $io): bool
    {
        $repoPath = $input->getOption('repo-path');
        $staged = $input->getOption('staged');
        $all = $input->getOption('all');

        try {
            // Stage all changes if requested
            if ($all && !$staged) {
                $io->info('Staging all changes...');
                $process = new Process(['git', 'add', '--all'], $repoPath);
                $process->run();

                if (!$process->isSuccessful()) {
                    $io->error('Failed to stage changes: ' . $process->getErrorOutput());
                    return false;
                }
            }

            // Create commit
            $io->info('Creating commit...');
            $process = new Process(['git', 'commit', '-m', $commitMessage], $repoPath);
            $process->run();

            if (!$process->isSuccessful()) {
                $io->error('Failed to create commit: ' . $process->getErrorOutput());
                return false;
            }

            $io->success('Commit created successfully!');
            return true;
        } catch (\Exception $e) {
            $io->error('Failed to create commit: ' . $e->getMessage());
            return false;
        }
    }
}
