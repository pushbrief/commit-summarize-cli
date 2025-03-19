<?php

namespace Pushbrief\PreCommitSummarize\Command;

use Pushbrief\PreCommitSummarize\GitInfo;
use Pushbrief\PreCommitSummarize\Service\AiService;
use Pushbrief\PreCommitSummarize\Service\JiraService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class JiraAnalyzeCommand extends Command
{
    protected static string $defaultName = 'jira:analyze';
    protected static string $defaultDescription = 'Analyze changes with AI and add as a Jira comment';

    private $gitInfo;
    private $jiraService;
    private $aiService;

    public function __construct(?GitInfo $gitInfo = null, ?JiraService $jiraService = null, ?AiService $aiService = null)
    {
        parent::__construct(self::$defaultName);
        $this->gitInfo = $gitInfo ?? new GitInfo();
        $this->jiraService = $jiraService ?? new JiraService();
        $this->aiService = $aiService ?? new AiService();
    }

    protected function configure(): void
    {
        $this
            ->setDescription(self::$defaultDescription)
            ->addOption('repo-path', 'r', InputOption::VALUE_OPTIONAL, 'Path to the git repository', getcwd())
            ->addOption('jira-host', null, InputOption::VALUE_OPTIONAL, 'Jira host URL')
            ->addOption('jira-username', null, InputOption::VALUE_OPTIONAL, 'Jira username')
            ->addOption('jira-password', null, InputOption::VALUE_OPTIONAL, 'Jira password or API token')
            ->addOption('openai-api-key', null, InputOption::VALUE_OPTIONAL, 'OpenAI API key')
            ->addOption('issue-key', 'i', InputOption::VALUE_OPTIONAL, 'Jira issue key (e.g., PROJECT-123)')
            ->addOption('default-issue', 'd', InputOption::VALUE_OPTIONAL, 'Default Jira issue key to use if not specified')
            ->addOption('from-branch', 'b', InputOption::VALUE_NONE, 'Extract issue key from branch name')
            ->addOption('staged', 's', InputOption::VALUE_NONE, 'Analyze only staged changes')
            ->addOption('model', 'm', InputOption::VALUE_OPTIONAL, 'OpenAI model to use', 'grok-2-latest');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Analyzing Changes with AI and Adding as Jira Comment');

        // Set repository path
        $repoPath = $input->getOption('repo-path');
        // GitInfo sınıfında setRepoPath metodu yok, yeni bir instance oluşturuyoruz
        $this->gitInfo = new GitInfo($repoPath);

        // Setup Jira service with credentials
        $jiraHost = $input->getOption('jira-host');
        $jiraUsername = $input->getOption('jira-username');
        $jiraPassword = $input->getOption('jira-password');

        try {
            $this->jiraService->setup($jiraHost, $jiraUsername, $jiraPassword);
        } catch (\Exception $e) {
            $io->error('Failed to setup Jira service: ' . $e->getMessage());
            return Command::FAILURE;
        }

        // Setup AI service
        $openaiApiKey = $input->getOption('openai-api-key');
        $model = $input->getOption('model');

        try {
            $this->aiService = new AiService($openaiApiKey, $model);
        } catch (\Exception $e) {
            $io->error('Failed to setup AI service: ' . $e->getMessage());
            return Command::FAILURE;
        }

        // Get issue key
        $issueKey = $this->getIssueKey($input, $io);

        if (empty($issueKey)) {
            $io->error('No Jira issue key provided or found.');
            return Command::FAILURE;
        }

        // Get changes
        $staged = $input->getOption('staged');
        $changes = $this->gitInfo->getChangesApi($staged, true);

        if (empty($changes)) {
            $io->warning('No changes found to analyze.');
            return Command::SUCCESS;
        }

        $io->section('Analyzing ' . count($changes) . ' changed files...');

        // Analyze changes with AI
        try {
            $analysisResults = $this->aiService->analyzeChanges($changes);
        } catch (\Exception $e) {
            $io->error('Failed to analyze changes: ' . $e->getMessage());
            return Command::FAILURE;
        }

        if (empty($analysisResults)) {
            $io->warning('No analysis results generated.');
            return Command::FAILURE;
        }

        // Format analysis results as Jira comment
        $comment = $this->formatJiraComment($analysisResults);

        // Add comment to Jira issue
        try {
            $this->jiraService->addComment($issueKey, $comment);
            $io->success("Analysis added as a comment to Jira issue $issueKey");
        } catch (\Exception $e) {
            $io->error('Failed to add comment to Jira issue: ' . $e->getMessage());
            return Command::FAILURE;
        }

        return Command::SUCCESS;
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
            
            if (str_contains($branchName, '/')){
                $branchName = explode('/', $branchName);
                
                $branchName = strtoupper($branchName[1]);
            }
            
            $issueKey = $this->jiraService->getIssueKeyFromBranch($branchName);

            if (!empty($issueKey)) {
                $io->info("Extracted issue key from branch name: $issueKey");
                return $issueKey;
            } else {
                $io->warning("Could not extract issue key from branch name: $branchName");

                // Branch adından issue key çıkarılamadığında, kullanıcıya aktif konuları seçme imkanı sun
                $io->note("Branch adından issue key çıkarılamadı. Aktif konuları listeliyorum...");

                // Aktif konuları listele ve kullanıcıya seçtir
                try {
                    // Varsayılan proje kontrolü
                    $defaultProject = $this->getDefaultProjectFromEnv();

                    if (!empty($defaultProject)) {
                        $io->info("Varsayılan proje kullanılıyor: $defaultProject");
                        $selectedProjectKey = $defaultProject;
                    } else {
                        // Tüm projeleri al
                        $projects = $this->jiraService->getProjects();

                        if (empty($projects)) {
                            $io->warning('Jira projeleri bulunamadı.');
                            return null;
                        }

                        // Proje seçimi için seçenekler oluştur
                        $projectChoices = [];
                        foreach ($projects as $project) {
                            $projectChoices[$project['key']] = $project['name'] . ' (' . $project['key'] . ')';
                        }

                        // Kullanıcıya proje seçtir
                        $selectedProjectKey = $io->choice('Bir Jira projesi seçin', $projectChoices);
                    }

                    // Seçilen proje için aktif konuları al
                    $issues = $this->jiraService->getIssues($selectedProjectKey, true); // true parametresi sadece aktif konuları getirmek için

                    if (empty($issues)) {
                        $io->warning("$selectedProjectKey projesi için aktif konu bulunamadı.");
                        return null;
                    }

                    // Konu seçimi için seçenekler oluştur
                    $issueChoices = [];
                    foreach ($issues as $issue) {
                        $issueChoices[$issue['key']] = sprintf('%s - %s <comment><%s></comment>', $issue['key'], $issue['summary'], $issue['assignee']->displayName);
                    }

                    // Kullanıcıya konu seçtir
                    $selectedIssueKey = $io->choice('Bir Jira konusu seçin', $issueChoices);
                    return $selectedIssueKey;
                } catch (\Exception $e) {
                    $io->error('Jira projelerini veya konularını alırken hata: ' . $e->getMessage());
                }
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

        // Get available projects
        $projects = $this->jiraService->getProjects();

        try {

            if (empty($projects)) {
                $io->warning('No Jira projects found.');
                return null;
            }

            // Create project choices
            $projectChoices = [];
            foreach ($projects as $project) {
                $projectChoices[$project['key']] = $project['name'] . ' (' . $project['key'] . ')';
            }

            // Ask user to select a project
            $selectedProjectKey = $io->choice('Select a Jira project', $projectChoices);

            // Get issues for selected project
            $issues = $this->jiraService->getIssues($selectedProjectKey);

            if (empty($issues)) {
                $io->warning("No issues found for project $selectedProjectKey.");
                return null;
            }

            // Create issue choices
            $issueChoices = [];
            foreach ($issues as $issue) {
                $issueChoices[$issue['key']] = sprintf('%s - %s <comment><%s></comment>', $issue['key'], $issue['summary'], $issue['assignee']->displayName);
            }

            // Ask user to select an issue
            $selectedIssueKey = $io->choice('Select a Jira issue', $issueChoices);

            return $selectedIssueKey;
        } catch (\Exception $e) {
            $io->error('Failed to get Jira projects or issues: ' . $e->getMessage());
            return null;
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
     * Get default project key from environment variables
     * 
     * @return string|null Default project key or null if not set
     */
    private function getDefaultProjectFromEnv(): ?string
    {
        // Try system environment variables (OS level)
        $sysEnv = getenv('JIRA_DEFAULT_PROJECT', true) ?: null;
        // Then try local environment variables (.env file loaded into $_ENV)
        $localEnv = $_ENV['JIRA_DEFAULT_PROJECT'] ?? null;
        // Finally fallback to regular getenv which might check both
        $fallback = getenv('JIRA_DEFAULT_PROJECT') ?: null;

        // Varsayılan proje yoksa, varsayılan issue'dan proje kodu çıkarmayı dene
        $defaultIssue = $this->getDefaultIssueFromEnv();
        $projectFromIssue = null;

        if (!empty($defaultIssue) && preg_match('/^([A-Z]+)-\d+$/', $defaultIssue, $matches)) {
            $projectFromIssue = $matches[1];
        }

        return $sysEnv ?? $localEnv ?? $fallback ?? $projectFromIssue;
    }

    /**
     * Format analysis results as Jira comment
     * 
     * @param array $analysisResults Analysis results from AI
     * @return string Formatted Jira comment
     */
    private function formatJiraComment(array $analysisResults): string
    {
        // Jira ADF formatında içerik oluştur - CURL örneğine göre
        $content = [];

        // Başlık
        $content[] = [
            "type" => "paragraph",
            "content" => [
                [
                    "text" => "AI Analiz Raporu",
                    "type" => "text"
                ]
            ]
        ];

        foreach ($analysisResults as $file => $analysis) {
            // Dosya adı
            $content[] = [
                "type" => "paragraph",
                "content" => [
                    [
                        "text" => "Dosya: {$file}",
                        "type" => "text"
                    ]
                ]
            ];

            // Değişiklik özeti
            if (isset($analysis['summary'])) {
                $content[] = [
                    "type" => "paragraph",
                    "content" => [
                        [
                            "text" => "Değişiklik Özeti:",
                            "type" => "text"
                        ]
                    ]
                ];

                $content[] = [
                    "type" => "paragraph",
                    "content" => [
                        [
                            "text" => $analysis['summary'],
                            "type" => "text"
                        ]
                    ]
                ];
            }

            // Kod kalitesi
            if (isset($analysis['quality_score'])) {
                $content[] = [
                    "type" => "paragraph",
                    "content" => [
                        [
                            "text" => "Kod Kalitesi: {$analysis['quality_score']}/10",
                            "type" => "text"
                        ]
                    ]
                ];

                // Nedenler
                if (isset($analysis['quality_reasons']) && is_array($analysis['quality_reasons'])) {
                    $content[] = [
                        "type" => "paragraph",
                        "content" => [
                            [
                                "text" => "Nedenler:",
                                "type" => "text"
                            ]
                        ]
                    ];

                    foreach ($analysis['quality_reasons'] as $reason) {
                        $content[] = [
                            "type" => "paragraph",
                            "content" => [
                                [
                                    "text" => "- " . $reason,
                                    "type" => "text"
                                ]
                            ]
                        ];
                    }
                }
            }

            // Öneriler
            if (isset($analysis['suggestions']) && is_array($analysis['suggestions']) && !empty($analysis['suggestions'])) {
                $content[] = [
                    "type" => "paragraph",
                    "content" => [
                        [
                            "text" => "Öneriler:",
                            "type" => "text"
                        ]
                    ]
                ];

                foreach ($analysis['suggestions'] as $suggestion) {
                    $content[] = [
                        "type" => "paragraph",
                        "content" => [
                            [
                                "text" => "- " . $suggestion,
                                "type" => "text"
                            ]
                        ]
                    ];
                }
            }

            // Ayırıcı çizgi (normal paragraf olarak)
            $content[] = [
                "type" => "paragraph",
                "content" => [
                    [
                        "text" => "---",
                        "type" => "text"
                    ]
                ]
            ];
        }

        // Son not
        $content[] = [
            "type" => "paragraph",
            "content" => [
                [
                    "text" => "Bu analiz yapay zeka tarafından otomatik olarak oluşturulmuştur.",
                    "type" => "text"
                ]
            ]
        ];

        // CURL örneğine göre tam olarak aynı format
        return json_encode([
            "body" => [
                "content" => $content,
                "type" => "doc",
                "version" => 1
            ]
        ]);
    }
}
