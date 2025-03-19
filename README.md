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

## Jira Integration

This package provides integration with Jira, allowing you to create commits based on Jira issues and analyze changes with AI:

### Creating Commits with Jira

```bash
# Create a commit with a Jira issue
vendor/bin/pre-commit-summarize jira:commit --issue-key=PROJECT-123

# Extract issue key from branch name
vendor/bin/pre-commit-summarize jira:commit --from-branch

# Add additional commit message
vendor/bin/pre-commit-summarize jira:commit --from-branch --message="Fixed bug"

# Create the commit after generating the message
vendor/bin/pre-commit-summarize jira:commit --from-branch --commit

# Stage all changes before committing
vendor/bin/pre-commit-summarize jira:commit --from-branch --commit --all

# Use default issue key from environment
vendor/bin/pre-commit-summarize jira:commit --commit
```

### Analyzing Changes with AI and Adding as Jira Comment

```bash
# Analyze changes and add as a comment to a Jira issue
vendor/bin/pre-commit-summarize jira:analyze --issue-key=PROJECT-123

# Extract issue key from branch name
vendor/bin/pre-commit-summarize jira:analyze --from-branch

# Analyze only staged changes
vendor/bin/pre-commit-summarize jira:analyze --from-branch --staged

# Use a different OpenAI model
vendor/bin/pre-commit-summarize jira:analyze --from-branch --model="gpt-4"
```

### Jira Integration Options

- `--jira-host`: Jira host URL
- `--jira-username`: Jira username
- `--jira-password`: Jira password or API token
- `--issue-key`, `-i`: Jira issue key (e.g., PROJECT-123)
- `--default-issue`, `-d`: Default Jira issue key to use if not specified
- `--from-branch`, `-b`: Extract issue key from branch name
- `--staged`, `-s`: Use staged changes instead of working directory changes
- `--openai-api-key`: OpenAI API key
- `--model`, `-m`: OpenAI model to use (default: gpt-3.5-turbo)
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

## Jira Integration

This package includes Jira integration that allows you to create commit messages based on Jira issues. You can extract issue information from branch names or select issues interactively.

### Usage

```bash
# Create a commit message from a Jira issue key
vendor/bin/pre-commit-summarize jira:commit --issue-key=PROJECT-123

# Extract issue key from branch name
vendor/bin/pre-commit-summarize jira:commit --from-branch

# Select a project and issue interactively
vendor/bin/pre-commit-summarize jira:commit

# Add an additional message to the commit
vendor/bin/pre-commit-summarize jira:commit --message="Additional details about the changes"

# Create the commit after generating the message
vendor/bin/pre-commit-summarize jira:commit --commit

# Stage all changes before committing
vendor/bin/pre-commit-summarize jira:commit --commit --all

# Commit only staged changes
vendor/bin/pre-commit-summarize jira:commit --commit --staged
```

### Available Options

- `--jira-host`: Jira host URL (e.g., https://your-domain.atlassian.net)
- `--jira-username`: Jira username (email)
- `--jira-password`: Jira password or API token
- `--issue-key`, `-i`: Jira issue key (e.g., PROJECT-123)
- `--message`, `-m`: Additional commit message
- `--from-branch`, `-b`: Extract issue key from branch name
- `--commit`, `-c`: Create the commit after generating the message
- `--staged`, `-s`: Commit only staged changes
- `--all`, `-a`: Stage all changes before committing

### Environment Variables

You can also set Jira credentials using environment variables:

```bash
export JIRA_HOST=https://your-domain.atlassian.net
export JIRA_USERNAME=your-email@example.com
export JIRA_PASSWORD=your-api-token
```

## Future Plans

In future versions, this package will include AI-powered commit summarization to provide more meaningful insights into your code changes.

## License

The MIT License (MIT). Please see [License File](LICENSE) for more information.