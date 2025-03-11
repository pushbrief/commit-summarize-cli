<?php

namespace Pushbrief\PreCommitSummarize\Command;

use Pushbrief\PreCommitSummarize\GitInfo;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class GitInfoCommand extends Command
{
    protected static $defaultName = 'git:info';
    protected static $defaultDescription = 'Display information about the git repository';
    
    public function __construct()
    {
        parent::__construct(self::$defaultName);
    }

    protected function configure(): void
    {
        $this
            ->setDescription(self::$defaultDescription)
            ->addOption('repo-path', 'r', InputOption::VALUE_OPTIONAL, 'Path to the git repository', getcwd())
            ->addOption('show-commits', 'c', InputOption::VALUE_OPTIONAL, 'Number of commits to show', 5)
            ->addOption('show-changes', null, InputOption::VALUE_NONE, 'Show changed files')
            ->addOption('show-repo-info', null, InputOption::VALUE_NONE, 'Show repository information')
            ->addOption('show-diffs', 'd', InputOption::VALUE_NONE, 'Show diffs (patches) for changed files')
            ->addOption('diff-lines', null, InputOption::VALUE_OPTIONAL, 'Maximum number of lines to show per diff (0 for unlimited)', 100)
            ->addOption('staged', 's', InputOption::VALUE_NONE, 'Show staged changes instead of working directory changes')
            ->addOption('all', 'a', InputOption::VALUE_NONE, 'Show all information');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $repoPath = $input->getOption('repo-path');
        $showCommits = $input->getOption('show-commits');
        $showChanges = $input->getOption('show-changes') || $input->getOption('all');
        $showRepoInfo = $input->getOption('show-repo-info') || $input->getOption('all');
        $showDiffs = $input->getOption('show-diffs') || $input->getOption('all');
        $diffLines = (int) $input->getOption('diff-lines');
        $staged = $input->getOption('staged');
        
        // If no specific options are provided, show all
        if (!$showCommits && !$showChanges && !$showRepoInfo && !$showDiffs) {
            $showCommits = 5;
            $showChanges = true;
            $showRepoInfo = true;
        }

        try {
            try {
                $gitInfo = new GitInfo($repoPath);
            } catch (\RuntimeException $e) {
                // Special handling for not a git repository error
                $io->error([
                    'Error: ' . $e->getMessage(),
                    'Please make sure you are running this command in a git repository.',
                    'You can initialize a git repository with: git init'
                ]);
                return Command::FAILURE;
            }
            
            // Show repository information
            if ($showRepoInfo) {
                try {
                    $repoInfo = $gitInfo->getRepoInfo();
                    $io->section('Repository Information');
                    $io->table(
                        ['Property', 'Value'],
                        [
                            ['Remote URL', $repoInfo['remote_url'] ?: 'Not set'],
                            ['Current Branch', $repoInfo['current_branch']],
                            ['Total Commits', (string)$repoInfo['total_commits']]
                        ]
                    );
                    
                    if (!empty($repoInfo['contributors'])) {
                        $io->section('Contributors');
                        $contributorsData = array_map(function ($contributor) {
                            return [$contributor['name'], (string)$contributor['commits']];
                        }, $repoInfo['contributors']);
                        
                        $io->table(['Name', 'Commits'], $contributorsData);
                    }
                } catch (\Exception $e) {
                    $io->warning('Could not retrieve repository information: ' . $e->getMessage());
                }
            }
            
            // Show commits
            if ($showCommits) {
                try {
                    $commits = $gitInfo->getLatestCommits((int)$showCommits);
                    $io->section("Latest {$showCommits} Commits");
                    
                    if (empty($commits)) {
                        $io->info('No commits found in this repository.');
                    } else {
                        $commitsData = array_map(function ($commit) {
                            return [
                                substr($commit['hash'], 0, 8),
                                $commit['author'],
                                $commit['date'],
                                $commit['message']
                            ];
                        }, $commits);
                        
                        $io->table(['Hash', 'Author', 'Date', 'Message'], $commitsData);
                    }
                } catch (\Exception $e) {
                    $io->warning('Could not retrieve commit information: ' . $e->getMessage());
                }
            }
            
            // Show changed files
            if ($showChanges) {
                try {
                    $changedFiles = $gitInfo->getChangedFiles();
                    
                    $io->section($staged ? 'Staged Files' : 'Changed Files');
                    if (empty($changedFiles)) {
                        $io->success($staged ? 'No staged changes.' : 'Working directory is clean. No changes detected.');
                    } else {
                        $changedFilesData = array_map(function ($file) {
                            return [$file['status'], $file['file']];
                        }, $changedFiles);
                        
                        $io->table(['Status', 'File'], $changedFilesData);
                    }
                } catch (\Exception $e) {
                    $io->warning('Could not retrieve changed files: ' . $e->getMessage());
                }
            }
            
            // Show diffs for changed files
            if ($showDiffs) {
                try {
                    $diffs = $gitInfo->getDiffs($staged);
                    
                    $io->section($staged ? 'Diffs for Staged Files' : 'Diffs for Changed Files');
                    
                    if (empty($diffs)) {
                        $io->success($staged ? 'No staged changes to display.' : 'No changes to display.');
                    } else {
                        foreach ($diffs as $index => $diff) {
                            if ($index > 0) {
                                $io->writeln('');
                            }
                            
                            $io->writeln("<info>File:</info> {$diff['file']} <comment>({$diff['status']})</comment>");
                            $io->writeln('<info>' . str_repeat('-', 80) . '</info>');
                            
                            // Format the patch content with colors
                            $lines = explode("\n", $diff['patch']);
                            $totalLines = count($lines);
                            $displayLines = $diffLines > 0 ? min($totalLines, $diffLines) : $totalLines;
                            
                            // Display warning if we're truncating the output
                            if ($diffLines > 0 && $totalLines > $diffLines) {
                                $io->writeln("<comment>Showing {$displayLines} of {$totalLines} lines. Use --diff-lines=0 to show all.</comment>");
                            }
                            
                            // Calculate which lines to show
                            if ($diffLines > 0 && $totalLines > $diffLines) {
                                // Show first half and last half of the lines if truncating
                                $halfLines = (int)($diffLines / 2);
                                $firstHalf = array_slice($lines, 0, $halfLines);
                                $secondHalf = array_slice($lines, $totalLines - $halfLines, $halfLines);
                                
                                // Display first half
                                foreach ($firstHalf as $line) {
                                    $this->formatDiffLine($line, $io);
                                }
                                
                                // Display truncation indicator
                                $io->writeln("<comment>... {$totalLines} - {$diffLines} lines not shown ...</comment>");
                                
                                // Display second half
                                foreach ($secondHalf as $line) {
                                    $this->formatDiffLine($line, $io);
                                }
                            } else {
                                // Show all lines if not truncating
                                foreach ($lines as $line) {
                                    $this->formatDiffLine($line, $io);
                                }
                            }
                            
                            $io->writeln('<info>' . str_repeat('-', 80) . '</info>');
                        }
                    }
                } catch (\Exception $e) {
                    $io->warning('Could not retrieve diffs: ' . $e->getMessage());
                }
            }
            
            return Command::SUCCESS;
        } catch (\Exception $e) {
            $io->error('Error: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
    
    /**
     * Format a diff line with appropriate colors
     *
     * @param string $line The line to format
     * @param SymfonyStyle $io The IO interface
     */
    private function formatDiffLine(string $line, SymfonyStyle $io): void
    {
        if (empty($line)) {
            $io->writeln('');
            return;
        }
        
        $firstChar = substr($line, 0, 1);
        switch ($firstChar) {
            case '+':
                $io->writeln("<fg=green>{$line}</>");
                break;
            case '-':
                $io->writeln("<fg=red>{$line}</>");
                break;
            case '@':
                $io->writeln("<fg=cyan>{$line}</>");
                break;
            default:
                $io->writeln($line);
        }
    }
}
