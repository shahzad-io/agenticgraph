# Contributing

Thank you for helping. This guide tells you how to send a change.

## Before you start

- For a bug, open an issue with the bug template first, unless the fix is very small.
- For a new feature, open an issue with the feature template. Agree on the design before you write the code.
- For a security problem, do not open an issue. Read [SECURITY.md](SECURITY.md).

## Gates

Run these commands before you open a pull request. All of them must pass.

```bash
composer install
vendor/bin/pest
vendor/bin/phpstan analyse
vendor/bin/pint --test
```

PHP 8.3+. A test that calls a real provider (for example `NodeSeqCoherenceTest`) runs only when its key is set (`OPENAI_API_KEY`).

## Rules for code and text

- Add a test that fails without your change and passes with it.
- Do not use em-dashes (the character U+2014) in code, comments, tests, docs or commit messages. Use a colon, a comma, parentheses or a new sentence.
- Write docs, comments and messages in Simplified Technical English: short sentences, one instruction in each sentence, active voice, and the same word for the same thing.
- If you change public behavior, add a line to `CHANGELOG.md` under the unreleased version.
- Keep a pull request to one change.

## Pull requests

1. Fork the repository and make a branch from `main`.
2. Make your change and run the gates.
3. Open a pull request. Tell what changed and why, and link the issue.
