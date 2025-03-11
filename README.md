# Pre-Commit Summarize

A PHP package that summarizes git commit changes. This tool provides detailed information about your git repository including commit history, changed files, and repository statistics.

## Installation

You can install the package via composer:

```bash
composer require pushbrief/pre-commit-summarize
```

## Usage

After installation, you can use the command-line tool to retrieve git information:

```bash
# Show all git information
vendor/bin/pre-commit-summarize git:info

# Show only the last 10 commits
vendor/bin/pre-commit-summarize git:info --show-commits=10

# Show only changed files
vendor/bin/pre-commit-summarize git:info --show-changes

# Show only repository information
vendor/bin/pre-commit-summarize git:info --show-repo-info

# Show diffs (patches) for changed files
vendor/bin/pre-commit-summarize git:info --show-diffs

# Show diffs for staged files
vendor/bin/pre-commit-summarize git:info --show-diffs --staged

# Limit the number of lines shown per diff
vendor/bin/pre-commit-summarize git:info --show-diffs --diff-lines=50

# Specify a different repository path
vendor/bin/pre-commit-summarize git:info --repo-path=/path/to/repo
```

## Available Options

- `--repo-path`, `-r`: Path to the git repository (default: current directory)
- `--show-commits`, `-c`: Number of commits to show (default: 5)
- `--show-changes`: Show changed files
- `--show-repo-info`: Show repository information
- `--show-diffs`, `-d`: Show diffs (patches) for changed files
- `--diff-lines`: Maximum number of lines to show per diff (default: 100, 0 for unlimited)
- `--staged`, `-s`: Show staged changes instead of working directory changes
- `--all`, `-a`: Show all information

## API Usage

You can also use the API to retrieve git information in JSON format:

```bash
# Get all git information in JSON format
vendor/bin/pre-commit-summarize git:api

# Get only changed files in JSON format
vendor/bin/pre-commit-summarize git:api --show-changes

# Get only repository information in JSON format
vendor/bin/pre-commit-summarize git:api --show-repo-info

# Get only diffs in JSON format
vendor/bin/pre-commit-summarize git:api --show-diffs
```

## Future Plans

In future versions, this package will include AI-powered commit summarization to provide more meaningful insights into your code changes.

## License

The MIT License (MIT). Please see [License File](LICENSE) for more information.