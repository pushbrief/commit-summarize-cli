<?php

namespace Pushbrief\PreCommitSummarize\Command;

use Pushbrief\PreCommitSummarize\GitInfo;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class GitApiCommand extends Command
{
    protected static $defaultName = 'git:api';
    protected static $defaultDescription = 'Get git information in API format (JSON)';

    public function __construct()
    {
        parent::__construct(self::$defaultName);
    }

    protected function configure(): void
    {
        $this
            ->setDescription(self::$defaultDescription)
            ->addOption('repo-path', 'r', InputOption::VALUE_OPTIONAL, 'Path to the git repository', getcwd())
            ->addOption('staged', 's', InputOption::VALUE_NONE, 'Show staged changes instead of working directory changes')
            ->addOption('include-diffs', 'd', InputOption::VALUE_NONE, 'Include diffs in the output')
            ->addOption('format', 'f', InputOption::VALUE_OPTIONAL, 'Output format (json or php)', 'json');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $repoPath = $input->getOption('repo-path');
            $staged = $input->getOption('staged');
            $includeDiffs = $input->getOption('include-diffs');
            $format = strtolower($input->getOption('format'));

            try {
                $gitInfo = new GitInfo($repoPath);
            } catch (\RuntimeException $e) {
                // Special handling for not a git repository error
                $output->writeln(json_encode([
                    'error' => $e->getMessage(),
                    'code' => 'not_git_repository'
                ]));
                return Command::FAILURE;
            }

            // Get changes in API format
            $changes = $gitInfo->getChangesApi($staged, $includeDiffs);

            // Output the result in the requested format
            if ($format === 'php') {
                $output->writeln(var_export($changes, true));
            } else {
                $output->writeln(json_encode($changes, JSON_PRETTY_PRINT));
            }

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $output->writeln(json_encode([
                'error' => $e->getMessage(),
                'code' => 'general_error'
            ]));
            return Command::FAILURE;
        }
    }
}
